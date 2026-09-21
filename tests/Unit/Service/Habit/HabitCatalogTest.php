<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Service\Habit\HabitCatalog;
use App\Service\Habit\HabitDefinition;
use App\Service\Habit\HabitDefinitionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Standing data check on the typed catalog (design.md §4.2, "Katalog-Inhalt"):
 * the definition list is consistent in itself, so a violation of a database
 * check can never first show up as a SQL error during `app:habits:sync`.
 * Content rules of the R-04 catalog (seven rows, unique sort order, knee
 * scale 0-10, no `sleep-regularity`) live here and not in the validator.
 *
 * Pure object test: no kernel, no database.
 */
final class HabitCatalogTest extends TestCase
{
    private const int MAX_ROWS = 7;

    public function testItPassesTheDefinitionValidator(): void
    {
        $this->expectNotToPerformAssertions();

        (new HabitDefinitionValidator())->validate((new HabitCatalog())->definitions());
    }

    public function testItContainsExactlyTheSevenHabitsFromR04InTheirSortOrder(): void
    {
        $slugs = array_map(static fn (HabitDefinition $definition): string => $definition->slug, (new HabitCatalog())->definitions());

        self::assertSame(
            ['knee-pain', 'sleep-duration', 'sleep-quality', 'stress', 'mood', 'recovery-readiness', 'mobility-stretch'],
            $slugs,
        );
    }

    public function testItHasNoMoreRowsThanTheR04Limit(): void
    {
        self::assertLessThanOrEqual(self::MAX_ROWS, \count((new HabitCatalog())->definitions()));
    }

    public function testItHasUniqueKebabCaseSlugs(): void
    {
        $slugs = $this->slugs();

        self::assertSame($slugs, array_values(array_unique($slugs)), 'every slug must be unique');
        foreach ($slugs as $slug) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug, \sprintf('slug "%s" is not kebab-case', $slug));
        }
    }

    public function testItHasNoDuplicateSortOrder(): void
    {
        $sortOrders = array_map(static fn (HabitDefinition $definition): int => $definition->sortOrder, (new HabitCatalog())->definitions());

        self::assertSame($sortOrders, array_values(array_unique($sortOrders)), 'sort_order must not repeat');
    }

    public function testItListsTheHabitsInAscendingSortOrder(): void
    {
        $sortOrders = array_map(static fn (HabitDefinition $definition): int => $definition->sortOrder, (new HabitCatalog())->definitions());
        $sorted = $sortOrders;
        sort($sorted);

        self::assertSame($sorted, $sortOrders);
    }

    public function testItGivesEveryScaleHabitBothBoundsWithMinBelowMax(): void
    {
        $scaleDefinitions = array_filter(
            (new HabitCatalog())->definitions(),
            static fn (HabitDefinition $definition): bool => HabitValueType::Scale === $definition->valueType,
        );

        self::assertNotEmpty($scaleDefinitions);
        foreach ($scaleDefinitions as $definition) {
            self::assertNotNull($definition->scaleMin, \sprintf('%s needs scaleMin', $definition->slug));
            self::assertNotNull($definition->scaleMax, \sprintf('%s needs scaleMax', $definition->slug));
            self::assertLessThan($definition->scaleMax, $definition->scaleMin, \sprintf('%s: scaleMin must be below scaleMax', $definition->slug));
        }
    }

    public function testItGivesNoScaleBoundsToAHabitThatIsNotAScale(): void
    {
        foreach ((new HabitCatalog())->definitions() as $definition) {
            if (HabitValueType::Scale === $definition->valueType) {
                continue;
            }

            self::assertNull($definition->scaleMin, \sprintf('%s is not a scale and must have no scaleMin', $definition->slug));
            self::assertNull($definition->scaleMax, \sprintf('%s is not a scale and must have no scaleMax', $definition->slug));
        }
    }

    public function testItGivesEveryDurationHabitAUnit(): void
    {
        $durationDefinitions = array_filter(
            (new HabitCatalog())->definitions(),
            static fn (HabitDefinition $definition): bool => HabitValueType::Duration === $definition->valueType,
        );

        self::assertNotEmpty($durationDefinitions);
        foreach ($durationDefinitions as $definition) {
            self::assertNotNull($definition->unit, \sprintf('%s is a duration and needs a unit', $definition->slug));
            self::assertNotSame('', trim($definition->unit), \sprintf('%s needs a non-empty unit', $definition->slug));
        }
    }

    public function testItGivesEveryTargetValueATargetDirection(): void
    {
        foreach ((new HabitCatalog())->definitions() as $definition) {
            if (null !== $definition->targetValue) {
                self::assertNotNull($definition->targetDirection, \sprintf('%s has a target value but no direction', $definition->slug));
            }
        }
    }

    public function testItGivesEveryHabitANonEmptyGermanName(): void
    {
        foreach ((new HabitCatalog())->definitions() as $definition) {
            self::assertNotSame('', trim($definition->name), \sprintf('%s needs a name', $definition->slug));
        }
    }

    public function testItUsesTheZeroToTenScaleOnlyForKneePain(): void
    {
        $zeroToTen = [];
        foreach ((new HabitCatalog())->definitions() as $definition) {
            if (0 === $definition->scaleMin && 10 === $definition->scaleMax) {
                $zeroToTen[] = $definition->slug;
            }
        }

        self::assertSame(['knee-pain'], $zeroToTen);
    }

    public function testItUsesTheOneToFiveScaleForEveryOtherScaleHabit(): void
    {
        $checked = 0;
        foreach ((new HabitCatalog())->definitions() as $definition) {
            if (HabitValueType::Scale !== $definition->valueType || 'knee-pain' === $definition->slug) {
                continue;
            }

            self::assertSame(1, $definition->scaleMin, \sprintf('%s must start at 1', $definition->slug));
            self::assertSame(5, $definition->scaleMax, \sprintf('%s must end at 5', $definition->slug));
            ++$checked;
        }

        self::assertSame(4, $checked, 'sleep-quality, stress, mood and recovery-readiness are the 1-5 scales');
    }

    public function testItDoesNotContainSleepRegularity(): void
    {
        // Computed from the sleep-duration history in T-0403, never a catalog row (R-04).
        self::assertNotContains('sleep-regularity', $this->slugs());
    }

    public function testItDoesNotContainSubstanceHabits(): void
    {
        // Alcohol/cannabis belong to substance_entry (EPIC-04 constraint), not to the habit catalog.
        foreach ($this->slugs() as $slug) {
            self::assertDoesNotMatchRegularExpression('/alcohol|alkohol|cannabis|substance/', $slug);
        }
    }

    /**
     * @param array{
     *     name: string,
     *     valueType: HabitValueType,
     *     unit: ?string,
     *     scaleMin: ?int,
     *     scaleMax: ?int,
     *     targetDirection: ?HabitTargetDirection,
     *     targetValue: ?float,
     *     sortOrder: int,
     * } $expected
     */
    #[DataProvider('r04Rows')]
    public function testItMatchesTheR04RowForEachSlug(string $slug, array $expected): void
    {
        $definition = $this->definitionFor($slug);

        self::assertSame($expected['name'], $definition->name);
        self::assertSame($expected['valueType'], $definition->valueType);
        self::assertSame($expected['unit'], $definition->unit);
        self::assertSame($expected['scaleMin'], $definition->scaleMin);
        self::assertSame($expected['scaleMax'], $definition->scaleMax);
        self::assertSame($expected['targetDirection'], $definition->targetDirection);
        self::assertSame($expected['targetValue'], $definition->targetValue);
        self::assertSame($expected['sortOrder'], $definition->sortOrder);
    }

    /**
     * @return array<string, array{string, array{name: string, valueType: HabitValueType, unit: ?string, scaleMin: ?int, scaleMax: ?int, targetDirection: ?HabitTargetDirection, targetValue: ?float, sortOrder: int}}>
     */
    public static function r04Rows(): array
    {
        return [
            'knee-pain' => ['knee-pain', ['name' => 'Knieschmerz', 'valueType' => HabitValueType::Scale, 'unit' => null, 'scaleMin' => 0, 'scaleMax' => 10, 'targetDirection' => HabitTargetDirection::Low, 'targetValue' => null, 'sortOrder' => 10]],
            'sleep-duration' => ['sleep-duration', ['name' => 'Schlafdauer', 'valueType' => HabitValueType::Duration, 'unit' => 'h', 'scaleMin' => null, 'scaleMax' => null, 'targetDirection' => HabitTargetDirection::High, 'targetValue' => 8.0, 'sortOrder' => 20]],
            'sleep-quality' => ['sleep-quality', ['name' => 'Schlafqualität', 'valueType' => HabitValueType::Scale, 'unit' => null, 'scaleMin' => 1, 'scaleMax' => 5, 'targetDirection' => HabitTargetDirection::High, 'targetValue' => null, 'sortOrder' => 30]],
            'stress' => ['stress', ['name' => 'Stress', 'valueType' => HabitValueType::Scale, 'unit' => null, 'scaleMin' => 1, 'scaleMax' => 5, 'targetDirection' => HabitTargetDirection::Low, 'targetValue' => null, 'sortOrder' => 40]],
            'mood' => ['mood', ['name' => 'Stimmung', 'valueType' => HabitValueType::Scale, 'unit' => null, 'scaleMin' => 1, 'scaleMax' => 5, 'targetDirection' => HabitTargetDirection::High, 'targetValue' => null, 'sortOrder' => 50]],
            'recovery-readiness' => ['recovery-readiness', ['name' => 'Erholung', 'valueType' => HabitValueType::Scale, 'unit' => null, 'scaleMin' => 1, 'scaleMax' => 5, 'targetDirection' => HabitTargetDirection::High, 'targetValue' => null, 'sortOrder' => 60]],
            'mobility-stretch' => ['mobility-stretch', ['name' => 'Beweglichkeit', 'valueType' => HabitValueType::Boolean, 'unit' => null, 'scaleMin' => null, 'scaleMax' => null, 'targetDirection' => HabitTargetDirection::High, 'targetValue' => null, 'sortOrder' => 70]],
        ];
    }

    /**
     * @return list<string>
     */
    private function slugs(): array
    {
        return array_map(static fn (HabitDefinition $definition): string => $definition->slug, (new HabitCatalog())->definitions());
    }

    private function definitionFor(string $slug): HabitDefinition
    {
        foreach ((new HabitCatalog())->definitions() as $definition) {
            if ($slug === $definition->slug) {
                return $definition;
            }
        }

        self::fail(\sprintf('the catalog has no row for slug "%s"', $slug));
    }
}
