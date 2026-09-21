<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Habit;
use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Service\Habit\HabitDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Pure object test, no kernel, no database. Covers the three behaviours the
 * synchronizer relies on (design.md §4.2): `differingFields()` is a pure
 * comparison, `updateFrom()` overwrites every field and reactivates,
 * `deactivate()` only flips the flag. The purity of `differingFields()` is
 * what keeps `--dry-run` from leaking changes through Doctrine's identity map.
 */
final class HabitTest extends TestCase
{
    public function testItGetsAUuidV7IdInItsConstructor(): void
    {
        self::assertInstanceOf(UuidV7::class, $this->durationHabit()->getId());
    }

    public function testItGivesEveryHabitItsOwnId(): void
    {
        self::assertFalse($this->durationHabit()->getId()->equals($this->durationHabit()->getId()));
    }

    public function testItIsActiveByDefault(): void
    {
        $habit = new Habit('mood', 'Stimmung', HabitValueType::Scale, null, 1, 5, null, null, 50);

        self::assertTrue($habit->isActive());
    }

    public function testItExposesAllConstructorValues(): void
    {
        $habit = $this->durationHabit();

        self::assertSame('sleep-duration', $habit->getSlug());
        self::assertSame('Schlafdauer', $habit->getName());
        self::assertSame(HabitValueType::Duration, $habit->getValueType());
        self::assertSame('h', $habit->getUnit());
        self::assertNull($habit->getScaleMin());
        self::assertNull($habit->getScaleMax());
        self::assertSame(HabitTargetDirection::High, $habit->getTargetDirection());
        self::assertSame('8.00', $habit->getTargetValue());
        self::assertSame(20, $habit->getSortOrder());
    }

    public function testItReportsNoDifferingFieldsForAnIdenticalDefinition(): void
    {
        self::assertSame([], $this->durationHabit()->differingFields(self::durationDefinition()));
    }

    public function testItComparesTheFloatTargetAsTheDecimalString(): void
    {
        // 8.0 (definition) vs '8.00' (column) is no difference.
        self::assertSame([], $this->durationHabit()->differingFields(self::durationDefinition(targetValue: 8.0)));
    }

    #[DataProvider('differingDefinitions')]
    public function testItNamesTheDifferingField(HabitDefinition $definition, string $expectedField): void
    {
        self::assertSame([$expectedField], $this->durationHabit()->differingFields($definition));
    }

    /**
     * @return array<string, array{HabitDefinition, string}>
     */
    public static function differingDefinitions(): array
    {
        return [
            'name' => [self::durationDefinition(name: 'Schlaf'), 'name'],
            'unit' => [self::durationDefinition(unit: 'min'), 'unit'],
            'target direction' => [self::durationDefinition(targetDirection: HabitTargetDirection::Low), 'targetDirection'],
            'target value' => [self::durationDefinition(targetValue: 7.5), 'targetValue'],
            'sort order' => [self::durationDefinition(sortOrder: 25), 'sortOrder'],
        ];
    }

    public function testItNamesValueTypeAndBoundsWhenTheKindOfHabitChanges(): void
    {
        $scaleDefinition = new HabitDefinition(
            slug: 'sleep-duration',
            name: 'Schlafdauer',
            valueType: HabitValueType::Scale,
            sortOrder: 20,
            unit: 'h',
            scaleMin: 1,
            scaleMax: 5,
            targetDirection: HabitTargetDirection::High,
            targetValue: 8.0,
        );

        $fields = $this->durationHabit()->differingFields($scaleDefinition);

        self::assertEqualsCanonicalizing(['valueType', 'scaleMin', 'scaleMax'], $fields);
    }

    public function testItNamesIsActiveForAnInactiveHabitEvenWhenEverythingElseMatches(): void
    {
        $habit = $this->durationHabit();
        $habit->deactivate();

        self::assertSame(['isActive'], $habit->differingFields(self::durationDefinition()));
    }

    public function testItDoesNotChangeAnythingWhileComparing(): void
    {
        $habit = $this->durationHabit();
        $habit->deactivate();

        $fields = $habit->differingFields(self::durationDefinition(name: 'Schlaf', sortOrder: 99, targetValue: 6.0));

        self::assertEqualsCanonicalizing(['name', 'targetValue', 'sortOrder', 'isActive'], $fields);
        self::assertSame('Schlafdauer', $habit->getName());
        self::assertSame(20, $habit->getSortOrder());
        self::assertSame('8.00', $habit->getTargetValue());
        self::assertFalse($habit->isActive());
    }

    public function testItOverwritesEveryFieldFromTheDefinitionAndReactivates(): void
    {
        $habit = $this->durationHabit();
        $habit->deactivate();
        $idBefore = $habit->getId()->toRfc4122();
        $definition = new HabitDefinition(
            slug: 'sleep-duration',
            name: 'Schlaf',
            valueType: HabitValueType::Scale,
            sortOrder: 30,
            unit: null,
            scaleMin: 1,
            scaleMax: 5,
            targetDirection: HabitTargetDirection::Low,
            targetValue: null,
        );

        $habit->updateFrom($definition);

        self::assertSame('Schlaf', $habit->getName());
        self::assertSame(HabitValueType::Scale, $habit->getValueType());
        self::assertNull($habit->getUnit());
        self::assertSame(1, $habit->getScaleMin());
        self::assertSame(5, $habit->getScaleMax());
        self::assertSame(HabitTargetDirection::Low, $habit->getTargetDirection());
        self::assertNull($habit->getTargetValue());
        self::assertSame(30, $habit->getSortOrder());
        self::assertTrue($habit->isActive());
        self::assertSame('sleep-duration', $habit->getSlug());
        self::assertSame($idBefore, $habit->getId()->toRfc4122());
    }

    public function testItStoresTheTargetAsTheDecimalStringWhenUpdating(): void
    {
        $habit = $this->durationHabit();

        $habit->updateFrom(self::durationDefinition(targetValue: 7.5));

        self::assertSame('7.50', $habit->getTargetValue());
    }

    public function testItOnlyClearsTheActiveFlagWhenDeactivating(): void
    {
        $habit = $this->durationHabit();

        $habit->deactivate();

        self::assertFalse($habit->isActive());
        self::assertSame('Schlafdauer', $habit->getName());
        self::assertSame('8.00', $habit->getTargetValue());
        self::assertSame(20, $habit->getSortOrder());
    }

    private function durationHabit(): Habit
    {
        return new Habit(
            slug: 'sleep-duration',
            name: 'Schlafdauer',
            valueType: HabitValueType::Duration,
            unit: 'h',
            scaleMin: null,
            scaleMax: null,
            targetDirection: HabitTargetDirection::High,
            targetValue: '8.00',
            sortOrder: 20,
        );
    }

    private static function durationDefinition(
        string $name = 'Schlafdauer',
        ?string $unit = 'h',
        HabitTargetDirection $targetDirection = HabitTargetDirection::High,
        ?float $targetValue = 8.0,
        int $sortOrder = 20,
    ): HabitDefinition {
        return new HabitDefinition(
            slug: 'sleep-duration',
            name: $name,
            valueType: HabitValueType::Duration,
            sortOrder: $sortOrder,
            unit: $unit,
            targetDirection: $targetDirection,
            targetValue: $targetValue,
        );
    }
}
