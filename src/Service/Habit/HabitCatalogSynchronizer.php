<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Entity\Habit;
use App\Enum\HabitChangeKind;
use App\Repository\HabitCatalogStoreInterface;

/**
 * Mirrors the typed habit catalog into the `habit` table, idempotently: new
 * slugs are created, changed rows updated in place, and a row whose slug left
 * the catalog is deactivated - never deleted (T-0401 design.md §4.2).
 *
 * The diff is computed without touching any entity, so a dry run leaves both
 * the database and Doctrine's identity map exactly as they were. A real run
 * applies all changes and flushes once, in one transaction.
 */
final readonly class HabitCatalogSynchronizer
{
    public function __construct(
        private HabitDefinitionProviderInterface $definitions,
        private HabitCatalogStoreInterface $store,
        private HabitDefinitionValidator $validator,
    ) {
    }

    /**
     * @throws InvalidHabitCatalogException before the store is read or written
     */
    public function sync(bool $dryRun): SyncResult
    {
        $definitions = $this->definitions->definitions();
        $this->validator->validate($definitions);

        $existing = $this->store->findAllIndexedBySlug();

        $changes = [];
        $toCreate = [];
        $toUpdate = [];
        $toDeactivate = [];
        $unchanged = 0;
        $defined = [];

        foreach ($definitions as $definition) {
            $defined[$definition->slug] = true;
            $habit = $existing[$definition->slug] ?? null;

            if (null === $habit) {
                $toCreate[] = $definition;
                $changes[] = new HabitChange($definition->slug, HabitChangeKind::Created, []);

                continue;
            }

            $fields = $habit->differingFields($definition);
            if ([] === $fields) {
                ++$unchanged;

                continue;
            }

            $toUpdate[] = [$habit, $definition];
            $changes[] = new HabitChange($definition->slug, HabitChangeKind::Updated, $fields);
        }

        foreach ($existing as $slug => $habit) {
            // Orphans that are already inactive stay unreported, so a second run is fully "unchanged".
            if (isset($defined[$slug]) || !$habit->isActive()) {
                continue;
            }

            $toDeactivate[] = $habit;
            $changes[] = new HabitChange($slug, HabitChangeKind::Deactivated, []);
        }

        if (!$dryRun) {
            $this->apply($toCreate, $toUpdate, $toDeactivate);
        }

        return new SyncResult(
            created: \count($toCreate),
            updated: \count($toUpdate),
            deactivated: \count($toDeactivate),
            unchanged: $unchanged,
            changes: $changes,
        );
    }

    /**
     * @param list<HabitDefinition>               $toCreate
     * @param list<array{Habit, HabitDefinition}> $toUpdate
     * @param list<Habit>                         $toDeactivate
     */
    private function apply(array $toCreate, array $toUpdate, array $toDeactivate): void
    {
        foreach ($toCreate as $definition) {
            $this->store->add(new Habit(
                slug: $definition->slug,
                name: $definition->name,
                valueType: $definition->valueType,
                unit: $definition->unit,
                scaleMin: $definition->scaleMin,
                scaleMax: $definition->scaleMax,
                targetDirection: $definition->targetDirection,
                targetValue: $definition->targetValueAsDecimal(),
                sortOrder: $definition->sortOrder,
            ));
        }

        foreach ($toUpdate as [$habit, $definition]) {
            $habit->updateFrom($definition);
        }

        foreach ($toDeactivate as $habit) {
            $habit->deactivate();
        }

        $this->store->flush();
    }
}
