<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Entity\Habit;
use App\Enum\HabitValueType;

/**
 * Checks a submitted value against the habit it is for (T-0402 design.md
 * §3.1). Pure and kernel-free. At most one violation is reported, the first
 * rule that fails; `0` and `false` are values and pass wherever they fit.
 *
 * Steps are tested with float-safe checks: a multiple of 0.25 is exact in
 * binary (`$v * 4`), two decimals are checked with `round($v, 2) === $v`. The
 * naive `$v * 100 === floor($v * 100)` misjudges values like 1.15.
 */
final class HabitValueValidator
{
    private const int MAX_HOURS = 24;
    private const int MAX_MINUTES = 1440;
    private const int MAX_COUNT = 1000;

    /**
     * @return list<FieldViolation> empty when the value is valid
     *
     * @throws \LogicException for a duration whose unit is neither `h` nor `min`, or a scale without bounds
     *                         (a catalog error, not a user error)
     */
    public function validate(Habit $habit, ?float $numeric, ?bool $bool): array
    {
        $violation = $this->firstViolation($habit, $numeric, $bool);

        return null === $violation ? [] : [$violation];
    }

    private function firstViolation(Habit $habit, ?float $numeric, ?bool $bool): ?FieldViolation
    {
        if ((null === $numeric) === (null === $bool)) {
            return new FieldViolation('valueNumeric', 'habit_entry.value.exactly_one');
        }

        $type = $habit->getValueType();

        if (HabitValueType::Boolean === $type) {
            return null === $numeric ? null : new FieldViolation('valueNumeric', 'habit_entry.value.bool_expected');
        }

        if (null === $numeric) {
            return new FieldViolation('valueBool', 'habit_entry.value.numeric_expected');
        }

        return match ($type) {
            HabitValueType::Scale => $this->scaleViolation($habit, $numeric),
            HabitValueType::Duration => $this->durationViolation($habit, $numeric),
            HabitValueType::Number => $this->numberViolation($numeric),
        };
    }

    private function scaleViolation(Habit $habit, float $value): ?FieldViolation
    {
        $min = $habit->getScaleMin();
        $max = $habit->getScaleMax();
        if (null === $min || null === $max) {
            throw new \LogicException(\sprintf('Scale habit "%s" has no scale bounds.', $habit->getSlug()));
        }

        if (!is_finite($value) || floor($value) !== $value || $value < $min || $value > $max) {
            return new FieldViolation('valueNumeric', 'habit_entry.value.out_of_scale', ['min' => $min, 'max' => $max]);
        }

        return null;
    }

    private function durationViolation(Habit $habit, float $value): ?FieldViolation
    {
        return match ($habit->getUnit()) {
            'h' => $this->outOfRange($value, self::MAX_HOURS)
                ?? ($value * 4 !== floor($value * 4) ? $this->notOnStep('0,25') : null),
            'min' => $this->outOfRange($value, self::MAX_MINUTES)
                ?? (floor($value) !== $value ? $this->notOnStep('1') : null),
            default => throw new \LogicException(\sprintf('Duration habit "%s" has the unsupported unit "%s".', $habit->getSlug(), (string) $habit->getUnit())),
        };
    }

    private function numberViolation(float $value): ?FieldViolation
    {
        return $this->outOfRange($value, self::MAX_COUNT)
            ?? (round($value, 2) !== $value ? $this->notOnStep('0,01') : null);
    }

    private function outOfRange(float $value, int $max): ?FieldViolation
    {
        if (!is_finite($value) || $value < 0 || $value > $max) {
            return new FieldViolation('valueNumeric', 'habit_entry.value.out_of_range', ['min' => 0, 'max' => $max]);
        }

        return null;
    }

    private function notOnStep(string $step): FieldViolation
    {
        return new FieldViolation('valueNumeric', 'habit_entry.value.not_on_step', ['step' => $step]);
    }
}
