<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Habit;
use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for the habit tickets (T-0401, and later T-0402/T-0403).
 *
 * The defaults always describe a row that satisfies all four database checks
 * (`chk_habit_scale`, `chk_habit_duration_unit`, `chk_habit_target`,
 * `chk_habit_sort_order`): a 1-5 scale without unit or target. `slug` and
 * `sortOrder` are running sequences, so `uniq_habit_slug` never breaks across
 * repeated `create*()` calls and several rows never share a sort position.
 *
 * The named states fix every field that belongs to a value type at once, so a
 * test that asks for `HabitFactory::new()->duration()` never builds a row the
 * catalog could not contain:
 *
 *   HabitFactory::new()->duration()->create(['slug' => 'sleep-duration'])
 *
 * `targetValue` is always a DECIMAL string ('8.00'), never an int or float -
 * that is what Doctrine hands out and what `Habit::__construct()` takes.
 *
 * Assumes `Habit::__construct()` takes its columns as named parameters
 * (design.md §4.2): slug, name, valueType, unit, scaleMin, scaleMax,
 * targetDirection, targetValue, sortOrder, isActive.
 *
 * @extends PersistentObjectFactory<Habit>
 */
final class HabitFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Habit::class;
    }

    /**
     * A yes/no habit: no scale bounds, no unit, no target value.
     */
    public function boolean(): static
    {
        return $this->with([
            'valueType' => HabitValueType::Boolean,
            'unit' => null,
            'scaleMin' => null,
            'scaleMax' => null,
            'targetDirection' => HabitTargetDirection::High,
            'targetValue' => null,
        ]);
    }

    /**
     * A 1-5 rating with a direction but no numeric target.
     */
    public function scale(): static
    {
        return $this->with([
            'valueType' => HabitValueType::Scale,
            'unit' => null,
            'scaleMin' => 1,
            'scaleMax' => 5,
            'targetDirection' => HabitTargetDirection::High,
            'targetValue' => null,
        ]);
    }

    /**
     * A plain count with a unit and a target, no scale bounds.
     */
    public function number(): static
    {
        return $this->with([
            'valueType' => HabitValueType::Number,
            'unit' => 'Glas',
            'scaleMin' => null,
            'scaleMax' => null,
            'targetDirection' => HabitTargetDirection::High,
            'targetValue' => '8.00',
        ]);
    }

    /**
     * A duration: the unit is mandatory, the target is 8.00 hours.
     */
    public function duration(): static
    {
        return $this->with([
            'valueType' => HabitValueType::Duration,
            'unit' => 'h',
            'scaleMin' => null,
            'scaleMax' => null,
            'targetDirection' => HabitTargetDirection::High,
            'targetValue' => '8.00',
        ]);
    }

    public function inactive(): static
    {
        return $this->with(['isActive' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // Function-local static, not a class property: Foundry's factory base
        // class is annotated @immutable/@readonly, so PHPStan rejects a static
        // property on it (same reasoning as ExerciseFactory).
        /** @var int $sequence */
        static $sequence = 0;
        ++$sequence;

        return [
            'slug' => \sprintf('habit-%d', $sequence),
            'name' => self::faker()->words(2, true),
            'valueType' => HabitValueType::Scale,
            'unit' => null,
            'scaleMin' => 1,
            'scaleMax' => 5,
            'targetDirection' => null,
            'targetValue' => null,
            'sortOrder' => 10 * $sequence,
            'isActive' => true,
        ];
    }
}
