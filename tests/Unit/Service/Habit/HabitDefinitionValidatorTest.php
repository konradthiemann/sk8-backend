<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Service\Habit\HabitDefinition;
use App\Service\Habit\HabitDefinitionValidator;
use App\Service\Habit\InvalidHabitCatalogException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One rule per case (design.md §4.2, "Validator-Regeln"): each invalid list
 * violates exactly one rule and the message must name the offending slug. The
 * wording is free (German), so only the slug is asserted. The scale, unit and
 * target cases mirror `chk_habit_scale`, `chk_habit_duration_unit` and
 * `chk_habit_target`, so the PHP and the database side reject the same rows.
 *
 * Pure object test: no kernel, no database.
 */
final class HabitDefinitionValidatorTest extends TestCase
{
    /**
     * @param list<HabitDefinition> $definitions
     */
    #[DataProvider('invalidDefinitionLists')]
    public function testItRejectsTheListAndNamesTheOffendingSlug(array $definitions, string $offendingSlug): void
    {
        $this->expectException(InvalidHabitCatalogException::class);
        $this->expectExceptionMessage($offendingSlug);

        (new HabitDefinitionValidator())->validate($definitions);
    }

    /**
     * @return array<string, array{list<HabitDefinition>, string}>
     */
    public static function invalidDefinitionLists(): array
    {
        return [
            'slug with uppercase letters' => [[self::scale('Bad-Slug')], 'Bad-Slug'],
            'slug with underscore' => [[self::scale('bad_slug')], 'bad_slug'],
            'slug with a space' => [[self::scale('bad slug')], 'bad slug'],
            'slug with a leading dash' => [[self::scale('-bad')], '-bad'],
            'slug with a trailing dash' => [[self::scale('bad-')], 'bad-'],
            'slug with a double dash' => [[self::scale('bad--slug')], 'bad--slug'],
            'duplicate slug' => [[self::scale('twice', sortOrder: 10), self::scale('twice', sortOrder: 20)], 'twice'],
            'empty name' => [[self::scale('no-name', name: '')], 'no-name'],
            'blank name' => [[self::scale('blank-name', name: '   ')], 'blank-name'],
            'scale without bounds' => [[self::definition('scale-open', HabitValueType::Scale)], 'scale-open'],
            'scale with only a minimum' => [[self::definition('scale-min-only', HabitValueType::Scale, scaleMin: 1)], 'scale-min-only'],
            'scale with only a maximum' => [[self::definition('scale-max-only', HabitValueType::Scale, scaleMax: 5)], 'scale-max-only'],
            'scale with equal bounds' => [[self::definition('scale-equal', HabitValueType::Scale, scaleMin: 3, scaleMax: 3)], 'scale-equal'],
            'scale with inverted bounds' => [[self::definition('scale-inverted', HabitValueType::Scale, scaleMin: 5, scaleMax: 1)], 'scale-inverted'],
            'boolean with scale bounds' => [[self::definition('boolean-bounded', HabitValueType::Boolean, scaleMin: 1, scaleMax: 5)], 'boolean-bounded'],
            'number with a minimum only' => [[self::definition('number-bounded', HabitValueType::Number, scaleMin: 1)], 'number-bounded'],
            'duration with scale bounds' => [[self::definition('duration-bounded', HabitValueType::Duration, unit: 'h', scaleMin: 1, scaleMax: 5)], 'duration-bounded'],
            'duration without a unit' => [[self::definition('duration-no-unit', HabitValueType::Duration)], 'duration-no-unit'],
            'duration with an empty unit' => [[self::definition('duration-empty-unit', HabitValueType::Duration, unit: '')], 'duration-empty-unit'],
            'duration with a blank unit' => [[self::definition('duration-blank-unit', HabitValueType::Duration, unit: '  ')], 'duration-blank-unit'],
            'target value without a direction' => [[self::definition('target-no-direction', HabitValueType::Number, targetValue: 8.0)], 'target-no-direction'],
            'negative sort order' => [[self::scale('negative-order', sortOrder: -10)], 'negative-order'],
        ];
    }

    public function testItRejectsAnEmptyList(): void
    {
        // Guards against a catalog edit that would deactivate every habit at the next sync.
        $this->expectException(InvalidHabitCatalogException::class);

        (new HabitDefinitionValidator())->validate([]);
    }

    public function testItNamesTheFirstOffendingSlugWhenSeveralDefinitionsAreInvalid(): void
    {
        $definitions = [
            self::scale('fine'),
            self::definition('first-bad', HabitValueType::Duration, sortOrder: 20),
            self::definition('second-bad', HabitValueType::Duration, sortOrder: 30),
        ];

        try {
            (new HabitDefinitionValidator())->validate($definitions);
            self::fail('expected the list to be rejected');
        } catch (InvalidHabitCatalogException $exception) {
            self::assertStringContainsString('first-bad', $exception->getMessage());
            self::assertStringNotContainsString('second-bad', $exception->getMessage());
        }
    }

    public function testItGivesANonEmptyMessage(): void
    {
        try {
            (new HabitDefinitionValidator())->validate([self::definition('duration-no-unit', HabitValueType::Duration)]);
            self::fail('expected the list to be rejected');
        } catch (InvalidHabitCatalogException $exception) {
            self::assertNotSame('', trim($exception->getMessage()));
        }
    }

    /**
     * @param list<HabitDefinition> $definitions
     */
    #[DataProvider('validDefinitionLists')]
    public function testItAcceptsAValidList(array $definitions): void
    {
        $this->expectNotToPerformAssertions();

        (new HabitDefinitionValidator())->validate($definitions);
    }

    /**
     * @return array<string, array{list<HabitDefinition>}>
     */
    public static function validDefinitionLists(): array
    {
        return [
            'one row per value type' => [[
                self::scale('mood', sortOrder: 10),
                self::definition('mobility-stretch', HabitValueType::Boolean, sortOrder: 20),
                self::definition('water', HabitValueType::Number, sortOrder: 30, unit: 'Glas', targetDirection: HabitTargetDirection::High, targetValue: 8.0),
                self::definition('sleep-duration', HabitValueType::Duration, sortOrder: 40, unit: 'h', targetDirection: HabitTargetDirection::High, targetValue: 8.0),
            ]],
            'slug with digits' => [[self::scale('vitamin-d3')]],
            'sort order zero' => [[self::scale('first', sortOrder: 0)]],
            'zero-to-ten scale' => [[self::definition('knee-pain', HabitValueType::Scale, scaleMin: 0, scaleMax: 10)]],
            'direction without a target value' => [[self::definition('stress', HabitValueType::Scale, scaleMin: 1, scaleMax: 5, targetDirection: HabitTargetDirection::Low)]],
            'unit on a number without a target' => [[self::definition('steps', HabitValueType::Number, unit: 'Anzahl')]],
            'repeated sort order (a catalog content rule, not a validator rule)' => [[self::scale('one', sortOrder: 10), self::scale('two', sortOrder: 10)]],
        ];
    }

    private static function scale(string $slug, string $name = 'Testgewohnheit', int $sortOrder = 10): HabitDefinition
    {
        return self::definition($slug, HabitValueType::Scale, sortOrder: $sortOrder, scaleMin: 1, scaleMax: 5, name: $name);
    }

    private static function definition(
        string $slug,
        HabitValueType $valueType,
        int $sortOrder = 10,
        ?string $unit = null,
        ?int $scaleMin = null,
        ?int $scaleMax = null,
        ?HabitTargetDirection $targetDirection = null,
        ?float $targetValue = null,
        string $name = 'Testgewohnheit',
    ): HabitDefinition {
        return new HabitDefinition(
            slug: $slug,
            name: $name,
            valueType: $valueType,
            sortOrder: $sortOrder,
            unit: $unit,
            scaleMin: $scaleMin,
            scaleMax: $scaleMax,
            targetDirection: $targetDirection,
            targetValue: $targetValue,
        );
    }
}
