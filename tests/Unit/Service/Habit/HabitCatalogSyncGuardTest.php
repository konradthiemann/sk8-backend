<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Entity\Habit;
use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Service\Habit\HabitCatalogSynchronizer;
use App\Service\Habit\HabitDefinition;
use App\Service\Habit\HabitDefinitionValidator;
use App\Service\Habit\InvalidHabitCatalogException;
use App\Service\Habit\SyncResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP object test, no kernel, no database. The sync guard of T-0402
 * (design.md §4.5, criteria 21-26): the synchronizer refuses to change
 * `value_type`, `unit`, `scale_min` or `scale_max` of a habit that already has
 * entries, because that would silently devalue the recorded history. The
 * "has entries" answer comes from `InMemoryHabitCatalogStore::$slugsWithEntries`.
 *
 * A refused run must write nothing at all: not the guarded change, and not any
 * other change of the same run either. Provider and store are the in-memory
 * doubles next to this file, the validator is the real one.
 */
final class HabitCatalogSyncGuardTest extends TestCase
{
    /**
     * The message points to a new slug; design.md §4.5 words it "neuem Slug" in the text but names the
     * test contract "neuen Slug", so both grammatical forms are accepted.
     */
    private const string NEW_SLUG_HINT = '/neue[mn] Slug/';

    /**
     * @return array<string, array{bool}>
     */
    public static function runModes(): array
    {
        return ['real run' => [false], 'dry run' => [true]];
    }

    #[DataProvider('runModes')]
    public function testItRefusesAChangedValueTypeForAHabitWithEntries(bool $dryRun): void
    {
        // Criteria 21 and 25.
        $mood = $this->habit(self::scale('mood', 10));
        $store = $this->storeWith([$mood], withEntries: ['mood']);

        try {
            $this->sync([self::number('mood', 10)], $store, $dryRun);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException $exception) {
            self::assertStringContainsString('mood', $exception->getMessage());
            self::assertMatchesRegularExpression(self::NEW_SLUG_HINT, $exception->getMessage());
        }

        self::assertSame(HabitValueType::Scale, $mood->getValueType());
        self::assertSame([], $store->added);
        self::assertSame(0, $store->flushCount);
    }

    #[DataProvider('guardedChanges')]
    public function testItRefusesEachGuardedFieldChangeForAHabitWithEntries(Habit $habit, HabitDefinition $changed, string $field): void
    {
        // Criterion 22: one case per guarded field.
        $store = $this->storeWith([$habit], withEntries: [$habit->getSlug()]);

        try {
            $this->sync([$changed], $store);
            self::fail(\sprintf('expected a changed %s to be refused', $field));
        } catch (InvalidHabitCatalogException $exception) {
            self::assertStringContainsString($habit->getSlug(), $exception->getMessage());
        }

        self::assertSame(0, $store->flushCount, \sprintf('a changed %s must not be written', $field));
    }

    /**
     * @return array<string, array{Habit, HabitDefinition, string}>
     */
    public static function guardedChanges(): array
    {
        return [
            'scale minimum' => [
                self::habitOf(self::scale('mood', 10, 1, 5)),
                self::scale('mood', 10, 0, 5),
                'scaleMin',
            ],
            'scale maximum' => [
                self::habitOf(self::scale('mood', 10, 1, 5)),
                self::scale('mood', 10, 1, 10),
                'scaleMax',
            ],
            'unit' => [
                self::habitOf(self::duration('sleep-duration', 20, 'h')),
                self::duration('sleep-duration', 20, 'min'),
                'unit',
            ],
            'value type' => [
                self::habitOf(self::scale('mood', 10, 1, 5)),
                self::boolean('mood', 10),
                'valueType',
            ],
        ];
    }

    public function testItNamesEveryAffectedSlugInTheMessage(): void
    {
        $store = $this->storeWith(
            [$this->habit(self::scale('mood', 10)), $this->habit(self::scale('sleep-quality', 20))],
            withEntries: ['mood', 'sleep-quality'],
        );

        try {
            $this->sync([self::scale('mood', 10, 0, 5), self::scale('sleep-quality', 20, 1, 7)], $store);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException $exception) {
            self::assertStringContainsString('mood', $exception->getMessage());
            self::assertStringContainsString('sleep-quality', $exception->getMessage());
            self::assertMatchesRegularExpression(self::NEW_SLUG_HINT, $exception->getMessage());
        }
    }

    public function testItNamesOnlyTheSlugsThatHaveEntries(): void
    {
        $store = $this->storeWith(
            [$this->habit(self::scale('mood', 10)), $this->habit(self::scale('sleep-quality', 20))],
            withEntries: ['mood'],
        );

        try {
            $this->sync([self::scale('mood', 10, 0, 5), self::scale('sleep-quality', 20, 1, 7)], $store);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException $exception) {
            self::assertStringContainsString('mood', $exception->getMessage());
            self::assertStringNotContainsString('sleep-quality', $exception->getMessage());
        }
    }

    #[DataProvider('runModes')]
    public function testItWritesNoOtherChangeOfTheSameRunWhenItRefuses(bool $dryRun): void
    {
        // Criterion 21: a new habit, a rename and a deactivation of the same run stay unwritten as well.
        $mood = $this->habit(self::scale('mood', 10));
        $stress = $this->habit(self::scale('stress', 20));
        $orphan = $this->habit(self::scale('orphan', 30));
        $store = $this->storeWith([$mood, $stress, $orphan], withEntries: ['mood']);
        $renamedStress = new HabitDefinition(slug: 'stress', name: 'Neuer Name', valueType: HabitValueType::Scale, sortOrder: 20, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High);

        try {
            $this->sync([self::number('mood', 10), $renamedStress, self::scale('brand-new', 40)], $store, $dryRun);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException) {
        }

        self::assertSame([], $store->added, 'the new habit must not be created');
        self::assertSame('Testgewohnheit', $stress->getName(), 'the rename must not be applied');
        self::assertTrue($orphan->isActive(), 'the orphan must not be deactivated');
        self::assertSame(0, $store->flushCount);
    }

    #[DataProvider('unguardedChanges')]
    public function testItLetsAnUnguardedChangeThroughForAHabitWithEntries(HabitDefinition $changed, string $field): void
    {
        // Criterion 23.
        $habit = $this->habit(self::scale('mood', 10));
        $store = $this->storeWith([$habit], withEntries: ['mood']);

        $result = $this->sync([$changed], $store);

        self::assertSame(1, $result->updated, \sprintf('a changed %s is allowed', $field));
        self::assertSame(0, $result->unchanged);
        self::assertSame(1, $store->flushCount);
    }

    /**
     * @return array<string, array{HabitDefinition, string}>
     */
    public static function unguardedChanges(): array
    {
        return [
            'name' => [new HabitDefinition(slug: 'mood', name: 'Neuer Name', valueType: HabitValueType::Scale, sortOrder: 10, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High), 'name'],
            'target direction' => [new HabitDefinition(slug: 'mood', name: 'Testgewohnheit', valueType: HabitValueType::Scale, sortOrder: 10, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::Low), 'targetDirection'],
            'target value' => [new HabitDefinition(slug: 'mood', name: 'Testgewohnheit', valueType: HabitValueType::Scale, sortOrder: 10, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High, targetValue: 4.0), 'targetValue'],
            'sort order' => [new HabitDefinition(slug: 'mood', name: 'Testgewohnheit', valueType: HabitValueType::Scale, sortOrder: 99, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High), 'sortOrder'],
        ];
    }

    public function testItAppliesAnUnguardedChangeToTheHabit(): void
    {
        $habit = $this->habit(self::scale('mood', 10));
        $store = $this->storeWith([$habit], withEntries: ['mood']);

        $this->sync([new HabitDefinition(slug: 'mood', name: 'Neuer Name', valueType: HabitValueType::Scale, sortOrder: 10, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High)], $store);

        self::assertSame('Neuer Name', $habit->getName());
    }

    #[DataProvider('guardedChanges')]
    public function testItLetsEveryGuardedChangeThroughForAHabitWithoutEntries(Habit $habit, HabitDefinition $changed, string $field): void
    {
        // Criterion 24.
        $store = $this->storeWith([$habit], withEntries: []);

        $result = $this->sync([$changed], $store);

        self::assertSame(1, $result->updated, \sprintf('a changed %s is allowed without entries', $field));
        self::assertSame(1, $store->flushCount);
    }

    public function testItLetsAGuardedChangeThroughWhenOnlyAnotherHabitHasEntries(): void
    {
        // Criterion 24: the entries of "sleep-quality" say nothing about "mood".
        $mood = $this->habit(self::scale('mood', 10));
        $store = $this->storeWith([$mood, $this->habit(self::scale('sleep-quality', 20))], withEntries: ['sleep-quality']);

        $result = $this->sync([self::scale('mood', 10, 0, 5), self::scale('sleep-quality', 20)], $store);

        self::assertSame(1, $result->updated);
        self::assertSame(0, $mood->getScaleMin());
    }

    public function testItDeactivatesAHabitWithEntriesWhoseSlugLeftTheCatalog(): void
    {
        // Criterion 26: the entries stay, the habit is only switched off.
        $retired = $this->habit(self::scale('retired', 10));
        $store = $this->storeWith([$retired, $this->habit(self::scale('mood', 20))], withEntries: ['retired']);

        $result = $this->sync([self::scale('mood', 20)], $store);

        self::assertSame(1, $result->deactivated);
        self::assertFalse($retired->isActive());
    }

    public function testItReactivatesAnInactiveHabitWithEntriesWhoseSlugIsBackInTheCatalog(): void
    {
        // Criterion 26.
        $habit = $this->habit(self::scale('mood', 10), isActive: false);
        $store = $this->storeWith([$habit], withEntries: ['mood']);

        $result = $this->sync([self::scale('mood', 10)], $store);

        self::assertSame(1, $result->updated);
        self::assertSame(['mood', 'updated', ['isActive']], [$result->changes[0]->slug, $result->changes[0]->kind->value, $result->changes[0]->fields]);
        self::assertTrue($habit->isActive());
    }

    public function testItAsksForTheHabitsWithEntriesOnlyOnceHoweverManyChangesAreGuarded(): void
    {
        $store = $this->storeWith(
            [$this->habit(self::scale('mood', 10)), $this->habit(self::scale('stress', 20)), $this->habit(self::scale('sleep-quality', 30))],
            withEntries: [],
        );

        $this->sync([self::scale('mood', 10, 0, 5), self::scale('stress', 20, 0, 5), self::scale('sleep-quality', 30, 0, 5)], $store);

        self::assertSame(1, $store->entryLookupCount);
    }

    public function testItDoesNotAskForEntriesWhenNoGuardedFieldChanges(): void
    {
        $store = $this->storeWith([$this->habit(self::scale('mood', 10)), $this->habit(self::scale('gone', 20))], withEntries: ['mood']);
        $renamed = new HabitDefinition(slug: 'mood', name: 'Neuer Name', valueType: HabitValueType::Scale, sortOrder: 10, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::High);

        // one rename, one creation, one deactivation ("gone"), no guarded change
        $this->sync([$renamed, self::scale('brand-new', 30)], $store);

        self::assertSame(0, $store->entryLookupCount);
    }

    public function testItDoesNotAskForEntriesWhenNothingChanges(): void
    {
        $store = $this->storeWith([$this->habit(self::scale('mood', 10))], withEntries: ['mood']);

        $this->sync([self::scale('mood', 10)], $store);

        self::assertSame(0, $store->entryLookupCount);
    }

    public function testItStillRejectsAnInvalidCatalogBeforeTouchingTheStore(): void
    {
        $store = $this->storeWith([$this->habit(self::scale('mood', 10))], withEntries: ['mood']);

        try {
            $this->sync([self::scale('Not A Slug', 10)], $store);
            self::fail('expected InvalidHabitCatalogException');
        } catch (InvalidHabitCatalogException) {
        }

        self::assertSame(0, $store->readCount);
        self::assertSame(0, $store->entryLookupCount);
    }

    /**
     * @param list<HabitDefinition> $definitions
     */
    private function sync(array $definitions, InMemoryHabitCatalogStore $store, bool $dryRun = false): SyncResult
    {
        return (new HabitCatalogSynchronizer(
            new InMemoryHabitDefinitionProvider($definitions),
            $store,
            new HabitDefinitionValidator(),
        ))->sync($dryRun);
    }

    /**
     * @param list<Habit>  $habits
     * @param list<string> $withEntries
     */
    private function storeWith(array $habits, array $withEntries): InMemoryHabitCatalogStore
    {
        $store = new InMemoryHabitCatalogStore($habits);
        $store->slugsWithEntries = $withEntries;

        return $store;
    }

    private function habit(HabitDefinition $definition, bool $isActive = true): Habit
    {
        return self::habitOf($definition, $isActive);
    }

    private static function habitOf(HabitDefinition $definition, bool $isActive = true): Habit
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

    private static function scale(string $slug, int $sortOrder, int $scaleMin = 1, int $scaleMax = 5): HabitDefinition
    {
        return new HabitDefinition(slug: $slug, name: 'Testgewohnheit', valueType: HabitValueType::Scale, sortOrder: $sortOrder, scaleMin: $scaleMin, scaleMax: $scaleMax, targetDirection: HabitTargetDirection::High);
    }

    private static function duration(string $slug, int $sortOrder, string $unit): HabitDefinition
    {
        return new HabitDefinition(slug: $slug, name: 'Testgewohnheit', valueType: HabitValueType::Duration, sortOrder: $sortOrder, unit: $unit, targetDirection: HabitTargetDirection::High, targetValue: 8.0);
    }

    private static function boolean(string $slug, int $sortOrder): HabitDefinition
    {
        return new HabitDefinition(slug: $slug, name: 'Testgewohnheit', valueType: HabitValueType::Boolean, sortOrder: $sortOrder, targetDirection: HabitTargetDirection::High);
    }

    private static function number(string $slug, int $sortOrder): HabitDefinition
    {
        return new HabitDefinition(slug: $slug, name: 'Testgewohnheit', valueType: HabitValueType::Number, sortOrder: $sortOrder, unit: 'Glas', targetDirection: HabitTargetDirection::High);
    }
}
