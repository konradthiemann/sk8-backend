<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Entity\Habit;
use App\Enum\HabitValueType;
use App\Service\Habit\FieldViolation;
use App\Service\Habit\HabitValueValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure object test, no kernel, no database. Covers the whole rule table of
 * T-0402 design.md §3.1 (ticket criteria 3 and 6-10): which value fits which
 * `value_type`, the inclusive bounds, the step rules, and the rule that
 * `0` is a value. The validator reports at most one violation, in table order.
 *
 * Case data names a habit kind instead of holding a Habit, so every test
 * builds its own fresh habit.
 */
final class HabitValueValidatorTest extends TestCase
{
    private const string EXACTLY_ONE = 'habit_entry.value.exactly_one';
    private const string BOOL_EXPECTED = 'habit_entry.value.bool_expected';
    private const string NUMERIC_EXPECTED = 'habit_entry.value.numeric_expected';
    private const string OUT_OF_SCALE = 'habit_entry.value.out_of_scale';
    private const string OUT_OF_RANGE = 'habit_entry.value.out_of_range';
    private const string NOT_ON_STEP = 'habit_entry.value.not_on_step';

    #[DataProvider('validValues')]
    public function testItAcceptsAValueThatFitsTheHabit(string $kind, ?float $numeric, ?bool $bool): void
    {
        self::assertSame([], (new HabitValueValidator())->validate(self::habit($kind), $numeric, $bool));
    }

    /**
     * @return array<string, array{string, ?float, ?bool}>
     */
    public static function validValues(): array
    {
        return [
            'zero on a 0-10 scale' => ['scale-0-10', 0.0, null],
            'maximum of a 0-10 scale' => ['scale-0-10', 10.0, null],
            'middle of a 0-10 scale' => ['scale-0-10', 5.0, null],
            'minimum of a 1-5 scale' => ['scale-1-5', 1.0, null],
            'maximum of a 1-5 scale' => ['scale-1-5', 5.0, null],
            'zero hours' => ['duration-h', 0.0, null],
            'one quarter hour' => ['duration-h', 0.25, null],
            'seven and a quarter hours' => ['duration-h', 7.25, null],
            'eight hours' => ['duration-h', 8.0, null],
            'twenty-four hours' => ['duration-h', 24.0, null],
            'zero minutes' => ['duration-min', 0.0, null],
            'ninety minutes' => ['duration-min', 90.0, null],
            'a full day of minutes' => ['duration-min', 1440.0, null],
            'zero count' => ['number', 0.0, null],
            'negative zero count' => ['number', -0.0, null],
            'smallest step of a count' => ['number', 0.01, null],
            'count with two decimals' => ['number', 12.34, null],
            'count that is not exact in binary' => ['number', 1.15, null],
            'whole count' => ['number', 8.0, null],
            'maximum of a count' => ['number', 1000.0, null],
            'yes' => ['boolean', null, true],
            'no' => ['boolean', null, false],
        ];
    }

    /**
     * @param array<string, int|string> $parameters
     */
    #[DataProvider('invalidValues')]
    public function testItRejectsAValueThatBreaksARule(
        string $kind,
        ?float $numeric,
        ?bool $bool,
        string $field,
        string $messageKey,
        array $parameters,
    ): void {
        $violations = (new HabitValueValidator())->validate(self::habit($kind), $numeric, $bool);

        self::assertCount(1, $violations);
        self::assertViolation($violations[0], $field, $messageKey, $parameters);
    }

    /**
     * @return array<string, array{string, ?float, ?bool, string, string, array<string, int|string>}>
     */
    public static function invalidValues(): array
    {
        $scale010 = ['min' => 0, 'max' => 10];
        $scale15 = ['min' => 1, 'max' => 5];

        return [
            'neither value on a scale' => ['scale-0-10', null, null, 'valueNumeric', self::EXACTLY_ONE, []],
            'neither value on a yes/no habit' => ['boolean', null, null, 'valueNumeric', self::EXACTLY_ONE, []],
            'both values' => ['scale-0-10', 7.0, true, 'valueNumeric', self::EXACTLY_ONE, []],
            'both values on a yes/no habit' => ['boolean', 1.0, false, 'valueNumeric', self::EXACTLY_ONE, []],
            'a number on a yes/no habit' => ['boolean', 1.0, null, 'valueNumeric', self::BOOL_EXPECTED, []],
            'zero on a yes/no habit' => ['boolean', 0.0, null, 'valueNumeric', self::BOOL_EXPECTED, []],
            'yes on a scale' => ['scale-0-10', null, true, 'valueBool', self::NUMERIC_EXPECTED, []],
            'no on a duration' => ['duration-h', null, false, 'valueBool', self::NUMERIC_EXPECTED, []],
            'yes on a count' => ['number', null, true, 'valueBool', self::NUMERIC_EXPECTED, []],
            'just above a 0-10 scale' => ['scale-0-10', 11.0, null, 'valueNumeric', self::OUT_OF_SCALE, $scale010],
            'just below a 0-10 scale' => ['scale-0-10', -1.0, null, 'valueNumeric', self::OUT_OF_SCALE, $scale010],
            'fraction on a 0-10 scale' => ['scale-0-10', 3.5, null, 'valueNumeric', self::OUT_OF_SCALE, $scale010],
            'infinity on a 0-10 scale' => ['scale-0-10', \INF, null, 'valueNumeric', self::OUT_OF_SCALE, $scale010],
            'negative infinity on a 0-10 scale' => ['scale-0-10', -\INF, null, 'valueNumeric', self::OUT_OF_SCALE, $scale010],
            'not a number on a 0-10 scale' => ['scale-0-10', \NAN, null, 'valueNumeric', self::OUT_OF_SCALE, $scale010],
            'zero on a 1-5 scale' => ['scale-1-5', 0.0, null, 'valueNumeric', self::OUT_OF_SCALE, $scale15],
            'six on a 1-5 scale' => ['scale-1-5', 6.0, null, 'valueNumeric', self::OUT_OF_SCALE, $scale15],
            'fraction on a 1-5 scale' => ['scale-1-5', 2.5, null, 'valueNumeric', self::OUT_OF_SCALE, $scale15],
            'just above a day in hours' => ['duration-h', 24.25, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 24]],
            'negative hours' => ['duration-h', -0.25, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 24]],
            'infinite hours' => ['duration-h', \INF, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 24]],
            'hours off the quarter step' => ['duration-h', 7.3, null, 'valueNumeric', self::NOT_ON_STEP, ['step' => '0,25']],
            'a tenth of an hour' => ['duration-h', 0.1, null, 'valueNumeric', self::NOT_ON_STEP, ['step' => '0,25']],
            'hours just past a quarter' => ['duration-h', 7.26, null, 'valueNumeric', self::NOT_ON_STEP, ['step' => '0,25']],
            'just above a day in minutes' => ['duration-min', 1440.5, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 1440]],
            'negative minutes' => ['duration-min', -1.0, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 1440]],
            'infinite minutes' => ['duration-min', \INF, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 1440]],
            'fractional minutes' => ['duration-min', 90.5, null, 'valueNumeric', self::NOT_ON_STEP, ['step' => '1']],
            'just above the count maximum' => ['number', 1000.01, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 1000]],
            'negative count' => ['number', -0.01, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 1000]],
            'infinite count' => ['number', \INF, null, 'valueNumeric', self::OUT_OF_RANGE, ['min' => 0, 'max' => 1000]],
            'three decimals on a count' => ['number', 1.005, null, 'valueNumeric', self::NOT_ON_STEP, ['step' => '0,01']],
            'a thousandth on a count' => ['number', 0.001, null, 'valueNumeric', self::NOT_ON_STEP, ['step' => '0,01']],
        ];
    }

    public function testItReportsOnlyTheFirstViolationWhenSeveralRulesAreBroken(): void
    {
        // 11.5 is out of range AND not a whole number: one violation, the range rule.
        $violations = (new HabitValueValidator())->validate(self::habit('scale-0-10'), 11.5, null);

        self::assertCount(1, $violations);
        self::assertSame(self::OUT_OF_SCALE, $violations[0]->messageKey);
    }

    public function testItReportsTheRangeBeforeTheStepForHours(): void
    {
        $violations = (new HabitValueValidator())->validate(self::habit('duration-h'), 24.1, null);

        self::assertCount(1, $violations);
        self::assertSame(self::OUT_OF_RANGE, $violations[0]->messageKey);
    }

    public function testItReportsTheRangeBeforeTheStepForCounts(): void
    {
        $violations = (new HabitValueValidator())->validate(self::habit('number'), 1000.005, null);

        self::assertCount(1, $violations);
        self::assertSame(self::OUT_OF_RANGE, $violations[0]->messageKey);
    }

    public function testItUsesTheBoundsOfTheHabitInTheParameters(): void
    {
        $habit = new Habit('custom', 'Eigene Skala', HabitValueType::Scale, null, 2, 9, null, null, 10);

        $violations = (new HabitValueValidator())->validate($habit, 10.0, null);

        self::assertCount(1, $violations);
        self::assertViolation($violations[0], 'valueNumeric', self::OUT_OF_SCALE, ['min' => 2, 'max' => 9]);
    }

    public function testItAcceptsEveryTwoDecimalCountUpToOneThousand(): void
    {
        // Guards the float trap of design.md §3.1: `$v * 100 === floor($v * 100)`
        // misjudges thousands of typed values (1.15 among them), `round($v, 2) === $v` does not.
        $validator = new HabitValueValidator();
        $habit = self::habit('number');

        $rejected = [];
        for ($hundredths = 0; $hundredths <= 100000; ++$hundredths) {
            $value = $hundredths / 100.0;
            if ([] !== $validator->validate($habit, $value, null)) {
                $rejected[] = $value;
            }
        }

        self::assertSame([], $rejected, 'every value typed with at most two decimals is a valid count');
    }

    public function testItAcceptsExactlyTheMultiplesOfAQuarterHourAmongThreeDecimalDurations(): void
    {
        $validator = new HabitValueValidator();
        $habit = self::habit('duration-h');

        $misjudged = [];
        for ($thousandths = 0; $thousandths <= 24000; ++$thousandths) {
            $value = $thousandths / 1000.0;
            $isQuarterHour = 0 === $thousandths % 250;
            $accepted = [] === $validator->validate($habit, $value, null);
            if ($accepted !== $isQuarterHour) {
                $misjudged[] = $value;
            }
        }

        self::assertSame([], $misjudged, 'hours are valid exactly when they are a multiple of 0.25');
    }

    public function testItFailsLoudlyForADurationWithAnUnknownUnit(): void
    {
        // A catalog error, not a user error: it must not surface as a 422 (design.md §3.1, R8).
        $habit = new Habit('stretching', 'Dehnen', HabitValueType::Duration, 'Tage', null, null, null, null, 10);

        $this->expectException(\LogicException::class);

        (new HabitValueValidator())->validate($habit, 1.0, null);
    }

    public function testItFailsLoudlyForAScaleWithoutBounds(): void
    {
        $habit = new Habit('mood', 'Stimmung', HabitValueType::Scale, null, null, null, null, null, 10);

        $this->expectException(\LogicException::class);

        (new HabitValueValidator())->validate($habit, 3.0, null);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private static function assertViolation(FieldViolation $violation, string $field, string $messageKey, array $parameters): void
    {
        self::assertSame($field, $violation->field);
        self::assertSame($messageKey, $violation->messageKey);
        self::assertSame($parameters, $violation->parameters);
    }

    private static function habit(string $kind): Habit
    {
        return match ($kind) {
            'scale-0-10' => new Habit('knee-pain', 'Knieschmerz', HabitValueType::Scale, null, 0, 10, null, null, 10),
            'scale-1-5' => new Habit('mood', 'Stimmung', HabitValueType::Scale, null, 1, 5, null, null, 20),
            'duration-h' => new Habit('sleep-duration', 'Schlafdauer', HabitValueType::Duration, 'h', null, null, null, null, 30),
            'duration-min' => new Habit('stretching', 'Dehnen', HabitValueType::Duration, 'min', null, null, null, null, 40),
            'number' => new Habit('water', 'Wasser', HabitValueType::Number, 'Glas', null, null, null, null, 50),
            'boolean' => new Habit('mobility-stretch', 'Beweglichkeit', HabitValueType::Boolean, null, null, null, null, null, 60),
            default => self::fail(\sprintf('unknown habit kind "%s"', $kind)),
        };
    }
}
