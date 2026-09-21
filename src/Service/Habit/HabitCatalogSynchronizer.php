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
    /**
     * Fields a habit with entries must keep (T-0402 design.md §4.5): changing the value type, the
     * unit or the scale would silently devalue the recorded history.
     */
    private const array GUARDED_FIELDS = ['valueType', 'unit', 'scaleMin', 'scaleMax'];

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

        $this->refuseGuardedChangesOfHabitsWithEntries($changes);

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
     * Runs for a dry run as well, so the preview predicts the refusal. Nothing has been written yet.
     * The entry lookup only happens when a guarded field changes at all.
     *
     * @param list<HabitChange> $changes
     *
     * @throws InvalidHabitCatalogException when a habit with entries would change a guarded field
     */
    private function refuseGuardedChangesOfHabitsWithEntries(array $changes): void
    {
        $guarded = [];
        foreach ($changes as $change) {
            if (HabitChangeKind::Updated === $change->kind && [] !== array_intersect(self::GUARDED_FIELDS, $change->fields)) {
                $guarded[] = $change->slug;
            }
        }

        if ([] === $guarded) {
            return;
        }

        $affected = array_values(array_intersect($guarded, $this->store->findSlugsWithEntries()));
        if ([] === $affected) {
            return;
        }

        throw new InvalidHabitCatalogException(\sprintf('Die Gewohnheit %s hat bereits Einträge; Werttyp, Einheit und Skala dürfen sich nicht mehr ändern, sonst wird der Verlauf entwertet. Lege stattdessen eine Gewohnheit mit neuem Slug an und entferne den alten Slug aus dem Katalog; er wird dann deaktiviert, seine Einträge bleiben.', implode(', ', array_map(static fn (string $slug): string => '"'.$slug.'"', $affected))));
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
