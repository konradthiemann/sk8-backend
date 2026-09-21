<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Entity\Habit;
use App\Enum\HabitChangeKind;
use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Service\Habit\HabitCatalogSynchronizer;
use App\Service\Habit\HabitChange;
use App\Service\Habit\HabitDefinition;
use App\Service\Habit\HabitDefinitionValidator;
use App\Service\Habit\InvalidHabitCatalogException;
use App\Service\Habit\SyncResult;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP object test, no kernel, no database (design.md §4.3/§4.4). Provider
 * and store are the in-memory doubles next to this file; the validator is the
 * real one. Covers ticket criteria 3-6 on the object level and criteria 12-13
 * ("bricht ab ... schreibt nichts", "ohne Datenbank").
 */
final class HabitCatalogSynchronizerTest extends TestCase
{
    public function testItCreatesEveryDefinitionInAnEmptyStoreAndFlushesExactlyOnce(): void
    {
        $definitions = [
            $this->scale('mood', 10),
            $this->duration('sleep-duration', 20),
            $this->boolean('mobility-stretch', 30),
        ];
        $store = new InMemoryHabitCatalogStore();

        $result = $this->sync($definitions, $store);

        self::assertSame(3, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->deactivated);
        self::assertSame(0, $result->unchanged);
        self::assertCount(3, $store->added);
        self::assertSame(1, $store->flushCount);
    }

    public function testItAddsHabitsWithTheDefinitionsFieldsAndADecimalStringTarget(): void
    {
        $store = new InMemoryHabitCatalogStore();

        $this->sync([$this->duration('sleep-duration', 20)], $store);

        self::assertCount(1, $store->added);
        $habit = $store->added[0];
        self::assertSame('sleep-duration', $habit->getSlug());
        self::assertSame('Testgewohnheit', $habit->getName());
        self::assertSame(HabitValueType::Duration, $habit->getValueType());
        self::assertSame('h', $habit->getUnit());
        self::assertNull($habit->getScaleMin());
        self::assertNull($habit->getScaleMax());
        self::assertSame(HabitTargetDirection::High, $habit->getTargetDirection());
        self::assertSame('8.00', $habit->getTargetValue());
        self::assertSame(20, $habit->getSortOrder());
        self::assertTrue($habit->isActive());
    }

    public function testItAddsAScaleHabitWithItsBoundsAndNoTarget(): void
    {
        $store = new InMemoryHabitCatalogStore();

        $this->sync([$this->scale('knee-pain', 10, scaleMin: 0, scaleMax: 10)], $store);

        $habit = $store->added[0];
        self::assertSame(0, $habit->getScaleMin());
        self::assertSame(10, $habit->getScaleMax());
        self::assertNull($habit->getUnit());
        self::assertNull($habit->getTargetValue());
    }

    public function testItReportsEveryRowAsUnchangedWhenTheStoreAlreadyMatches(): void
    {
        // Criterion 4.
        $definitions = [$this->scale('mood', 10), $this->duration('sleep-duration', 20), $this->boolean('mobility-stretch', 30)];
        $store = new InMemoryHabitCatalogStore($this->habitsFor($definitions));

        $result = $this->sync($definitions, $store);

        self::assertSame(3, $result->unchanged);
        self::assertSame(0, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->deactivated);
        self::assertSame([], $result->changes);
        self::assertSame([], $store->added);
    }

    public function testItKeepsEveryIdWhenTheStoreAlreadyMatches(): void
    {
        // Criterion 4: no id changes on a repeated run.
        $definitions = [$this->scale('mood', 10), $this->duration('sleep-duration', 20)];
        $store = new InMemoryHabitCatalogStore($this->habitsFor($definitions));
        $idsBefore = array_map(static fn (Habit $habit): string => $habit->getId()->toRfc4122(), $store->bySlug);

        $this->sync($definitions, $store);

        $idsAfter = array_map(static fn (Habit $habit): string => $habit->getId()->toRfc4122(), $store->bySlug);
        self::assertSame($idsBefore, $idsAfter);
    }

    public function testItIsIdempotentWhenRunTwiceAgainstTheSameStore(): void
    {
        // Criterion 4 end to end on the object level: what run one adds is what run two finds.
        $definitions = [$this->scale('mood', 10), $this->duration('sleep-duration', 20), $this->boolean('mobility-stretch', 30)];
        $store = new InMemoryHabitCatalogStore();

        $first = $this->sync($definitions, $store);
        foreach ($store->added as $habit) {
            $store->bySlug[$habit->getSlug()] = $habit;
        }
        $idsAfterFirstRun = array_map(static fn (Habit $habit): string => $habit->getId()->toRfc4122(), $store->bySlug);
        $second = $this->sync($definitions, $store);

        self::assertSame(3, $first->created);
        self::assertSame(3, $second->unchanged);
        self::assertSame(0, $second->created + $second->updated + $second->deactivated);
        self::assertCount(3, $store->added, 'the second run must not add anything');
        self::assertSame($idsAfterFirstRun, array_map(static fn (Habit $habit): string => $habit->getId()->toRfc4122(), $store->bySlug));
    }

    public function testItTreatsAFloatTargetAndTheStoredDecimalStringAsEqual(): void
    {
        $definition = $this->duration('sleep-duration', 20);
        $stored = $this->habitFor($definition);
        self::assertSame('8.00', $stored->getTargetValue());
        $store = new InMemoryHabitCatalogStore([$stored]);

        $result = $this->sync([$definition], $store);

        self::assertSame(1, $result->unchanged);
        self::assertSame(0, $result->updated);
    }

    public function testItUpdatesAChangedFieldInPlaceAndKeepsTheId(): void
    {
        $existing = $this->habitFor($this->scale('mood', 10));
        $idBefore = $existing->getId()->toRfc4122();
        $store = new InMemoryHabitCatalogStore([$existing]);

        $result = $this->sync([$this->scale('mood', 20)], $store);

        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->created);
        self::assertSame(0, $result->unchanged);
        self::assertCount(1, $result->changes);
        self::assertSame('mood', $result->changes[0]->slug);
        self::assertSame(HabitChangeKind::Updated, $result->changes[0]->kind);
        self::assertSame(['sortOrder'], $result->changes[0]->fields);
        self::assertSame(20, $existing->getSortOrder());
        self::assertSame($idBefore, $existing->getId()->toRfc4122());
        self::assertSame([], $store->added);
        self::assertSame(1, $store->flushCount);
    }

    public function testItListsEveryChangedFieldOfAnUpdatedRow(): void
    {
        $existing = $this->habitFor($this->duration('sleep-duration', 20));
        $store = new InMemoryHabitCatalogStore([$existing]);
        $changed = new HabitDefinition(
            slug: 'sleep-duration',
            name: 'Schlaf',
            valueType: HabitValueType::Duration,
            sortOrder: 20,
            unit: 'min',
            targetDirection: HabitTargetDirection::High,
            targetValue: 7.5,
        );

        $result = $this->sync([$changed], $store);

        self::assertSame(1, $result->updated);
        self::assertEqualsCanonicalizing(['name', 'unit', 'targetValue'], $result->changes[0]->fields);
        self::assertSame('Schlaf', $existing->getName());
        self::assertSame('min', $existing->getUnit());
        self::assertSame('7.50', $existing->getTargetValue());
    }

    public function testItDeactivatesAHabitThatIsNoLongerDefinedAndKeepsItsRow(): void
    {
        // Criterion 5.
        $orphan = $this->habitFor($this->scale('retired', 90));
        $idBefore = $orphan->getId()->toRfc4122();
        $store = new InMemoryHabitCatalogStore([$orphan]);

        $result = $this->sync([$this->scale('mood', 10)], $store);

        self::assertSame(1, $result->deactivated);
        self::assertSame(1, $result->created);
        self::assertFalse($orphan->isActive());
        self::assertArrayHasKey('retired', $store->bySlug, 'the row must stay, sync never deletes');
        self::assertSame($idBefore, $orphan->getId()->toRfc4122());
        self::assertSame(HabitChangeKind::Deactivated, $result->changes[1]->kind);
        self::assertSame('retired', $result->changes[1]->slug);
        self::assertSame([], $result->changes[1]->fields);
    }

    public function testItIgnoresAnOrphanThatIsAlreadyInactive(): void
    {
        $inactiveOrphan = $this->habitFor($this->scale('retired', 90), isActive: false);
        $store = new InMemoryHabitCatalogStore([$inactiveOrphan]);

        $result = $this->sync([$this->scale('mood', 10)], $store);

        self::assertSame(0, $result->deactivated);
        self::assertFalse($inactiveOrphan->isActive());
        foreach ($result->changes as $change) {
            self::assertNotSame('retired', $change->slug, 'an already inactive orphan is neither counted nor listed');
        }
    }

    public function testItReactivatesAnInactiveHabitWhoseSlugIsDefinedAgain(): void
    {
        $definition = $this->scale('mood', 10);
        $inactive = $this->habitFor($definition, isActive: false);
        $store = new InMemoryHabitCatalogStore([$inactive]);

        $result = $this->sync([$definition], $store);

        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->unchanged);
        self::assertSame(['isActive'], $result->changes[0]->fields);
        self::assertTrue($inactive->isActive());
    }

    public function testItSatisfiesTheCountInvariantOverAllDefinitions(): void
    {
        $unchangedDefinition = $this->scale('unchanged-one', 10);
        $updatedDefinition = $this->scale('updated-one', 20);
        $store = new InMemoryHabitCatalogStore([
            $this->habitFor($unchangedDefinition),
            $this->habitFor($this->scale('updated-one', 25)),
            $this->habitFor($this->scale('orphan-one', 90)),
        ]);
        $definitions = [$unchangedDefinition, $updatedDefinition, $this->scale('created-one', 30)];

        $result = $this->sync($definitions, $store);

        self::assertSame(1, $result->created);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->deactivated);
        self::assertSame(1, $result->unchanged);
        self::assertSame(\count($definitions), $result->created + $result->updated + $result->unchanged);
    }

    public function testItListsChangesInDefinitionOrderFollowedByDeactivations(): void
    {
        $store = new InMemoryHabitCatalogStore([
            $this->habitFor($this->scale('orphan-one', 80)),
            $this->habitFor($this->scale('changed-one', 15)),
            $this->habitFor($this->scale('same-one', 10)),
        ]);
        $definitions = [
            $this->scale('new-one', 5),
            $this->scale('same-one', 10),
            $this->scale('changed-one', 20),
        ];

        $result = $this->sync($definitions, $store);

        self::assertSame(
            [['new-one', 'created'], ['changed-one', 'updated'], ['orphan-one', 'deactivated']],
            $this->describeChanges($result),
        );
    }

    public function testItReportsTheSameResultOnADryRunAsOnARealRun(): void
    {
        // Criterion 6 (counts and change list are the plan; only the writing is skipped).
        $definitions = $this->mixedScenarioDefinitions();

        $real = $this->sync($definitions, new InMemoryHabitCatalogStore($this->mixedScenarioHabits()));
        $dry = $this->sync($definitions, new InMemoryHabitCatalogStore($this->mixedScenarioHabits()), dryRun: true);

        self::assertSame(1, $dry->created);
        self::assertSame(1, $dry->updated);
        self::assertSame(1, $dry->deactivated);
        self::assertSame(1, $dry->unchanged);
        self::assertSame($this->describeChanges($real), $this->describeChanges($dry));
        self::assertSame($real->changes[1]->fields, $dry->changes[1]->fields);
    }

    public function testItAddsFlushesAndMutatesNothingOnADryRun(): void
    {
        // Criterion 6, object level: no add(), no flush(), stored habits keep their old values.
        $store = new InMemoryHabitCatalogStore($this->mixedScenarioHabits());

        $this->sync($this->mixedScenarioDefinitions(), $store, dryRun: true);

        self::assertSame([], $store->added);
        self::assertSame(0, $store->flushCount);
        self::assertSame(15, $store->bySlug['changed-one']->getSortOrder(), 'a dry run must not touch a changed row (identity map)');
        self::assertTrue($store->bySlug['orphan-one']->isActive(), 'a dry run must not deactivate an orphan');
    }

    public function testItRejectsADuplicateSlugAndNamesItWithoutTouchingTheStore(): void
    {
        // Criterion 13.
        $store = new InMemoryHabitCatalogStore([$this->habitFor($this->scale('existing-one', 10))]);
        $definitions = [$this->scale('twice', 20), $this->scale('twice', 30)];

        try {
            $this->sync($definitions, $store);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException $exception) {
            self::assertStringContainsString('twice', $exception->getMessage());
        }

        $this->assertStoreUntouched($store);
    }

    public function testItRejectsADurationWithoutAUnitAndNamesItWithoutTouchingTheStore(): void
    {
        // Criterion 12.
        $store = new InMemoryHabitCatalogStore([$this->habitFor($this->scale('existing-one', 10))]);
        $definitions = [
            $this->scale('mood', 10),
            new HabitDefinition(slug: 'sleep-duration', name: 'Schlafdauer', valueType: HabitValueType::Duration, sortOrder: 20),
        ];

        try {
            $this->sync($definitions, $store);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException $exception) {
            self::assertStringContainsString('sleep-duration', $exception->getMessage());
        }

        $this->assertStoreUntouched($store);
    }

    public function testItRejectsAnInvalidCatalogOnADryRunToo(): void
    {
        $store = new InMemoryHabitCatalogStore();
        $definitions = [new HabitDefinition(slug: 'sleep-duration', name: 'Schlafdauer', valueType: HabitValueType::Duration, sortOrder: 20)];

        try {
            $this->sync($definitions, $store, dryRun: true);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException $exception) {
            self::assertStringContainsString('sleep-duration', $exception->getMessage());
        }

        self::assertSame(0, $store->readCount);
    }

    public function testItRefusesAnEmptyCatalogInsteadOfDeactivatingEveryHabit(): void
    {
        $existing = $this->habitFor($this->scale('mood', 10));
        $store = new InMemoryHabitCatalogStore([$existing]);

        try {
            $this->sync([], $store);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException) {
            self::assertTrue($existing->isActive(), 'an empty catalog must not deactivate anything');
        }

        $this->assertStoreUntouched($store);
    }

    private function assertStoreUntouched(InMemoryHabitCatalogStore $store): void
    {
        self::assertSame(0, $store->readCount, 'a rejected catalog must not even read the store');
        self::assertSame([], $store->added);
        self::assertSame(0, $store->flushCount);
        foreach ($store->bySlug as $habit) {
            self::assertTrue($habit->isActive());
        }
    }

    /**
     * @param list<HabitDefinition> $definitions
     */
    private function sync(array $definitions, InMemoryHabitCatalogStore $store, bool $dryRun = false): SyncResult
    {
        $synchronizer = new HabitCatalogSynchronizer(
            new InMemoryHabitDefinitionProvider($definitions),
            $store,
            new HabitDefinitionValidator(),
        );

        return $synchronizer->sync($dryRun);
    }

    /**
     * @return list<HabitDefinition> one to create, one to update, one unchanged (an orphan sits in the store)
     */
    private function mixedScenarioDefinitions(): array
    {
        return [
            $this->scale('new-one', 5),
            $this->scale('same-one', 10),
            $this->scale('changed-one', 20),
        ];
    }

    /**
     * @return list<Habit>
     */
    private function mixedScenarioHabits(): array
    {
        return [
            $this->habitFor($this->scale('same-one', 10)),
            $this->habitFor($this->scale('changed-one', 15)),
            $this->habitFor($this->scale('orphan-one', 80)),
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    private function describeChanges(SyncResult $result): array
    {
        return array_map(
            static fn (HabitChange $change): array => [$change->slug, $change->kind->value],
            $result->changes,
        );
    }

    /**
     * @param list<HabitDefinition> $definitions
     *
     * @return list<Habit>
     */
    private function habitsFor(array $definitions): array
    {
        return array_map(fn (HabitDefinition $definition): Habit => $this->habitFor($definition), $definitions);
    }

    private function habitFor(HabitDefinition $definition, bool $isActive = true): Habit
    {
        return new Habit(
            slug: $definition->slug,
            name: $definition->name,
            valueType: $definition->valueType,
            unit: $definition->unit,
            scaleMin: $definition->scaleMin,
            scaleMax: $definition->scaleMax,
            targetDirection: $definition->targetDirection,
            targetValue: $definition->targetValueAsDecimal(),
            sortOrder: $definition->sortOrder,
            isActive: $isActive,
        );
    }

    private function scale(string $slug, int $sortOrder, int $scaleMin = 1, int $scaleMax = 5): HabitDefinition
    {
        return new HabitDefinition(
            slug: $slug,
            name: 'Testgewohnheit',
            valueType: HabitValueType::Scale,
            sortOrder: $sortOrder,
            scaleMin: $scaleMin,
            scaleMax: $scaleMax,
            targetDirection: HabitTargetDirection::High,
        );
    }

    private function duration(string $slug, int $sortOrder): HabitDefinition
    {
        return new HabitDefinition(
            slug: $slug,
            name: 'Testgewohnheit',
            valueType: HabitValueType::Duration,
            sortOrder: $sortOrder,
            unit: 'h',
            targetDirection: HabitTargetDirection::High,
            targetValue: 8.0,
        );
    }

    private function boolean(string $slug, int $sortOrder): HabitDefinition
    {
        return new HabitDefinition(slug: $slug, name: 'Testgewohnheit', valueType: HabitValueType::Boolean, sortOrder: $sortOrder, targetDirection: HabitTargetDirection::High);
    }
}
