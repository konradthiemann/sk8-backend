<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a training exercise's sets are tracked (DATENMODELL.md, "Training",
 * `exercise.measure`; T-0301 design.md §2). `usesReps()`/`requiresSide()`
 * are the basis T-0302's set validation reads to decide which fields a
 * `training_set` row requires for a given exercise.
 */
enum ExerciseMeasure: string
{
    case Reps = 'reps';
    case Seconds = 'seconds';
    case RepsPerSide = 'reps_per_side';
    case SecondsPerSide = 'seconds_per_side';

    /**
     * True when a set of this measure counts repetitions rather than time.
     */
    public function usesReps(): bool
    {
        return match ($this) {
            self::Reps, self::RepsPerSide => true,
            self::Seconds, self::SecondsPerSide => false,
        };
    }

    /**
     * True when a set of this measure is tracked once per side (left/right)
     * rather than as a single value.
     */
    public function requiresSide(): bool
    {
        return match ($this) {
            self::RepsPerSide, self::SecondsPerSide => true,
            self::Reps, self::Seconds => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
