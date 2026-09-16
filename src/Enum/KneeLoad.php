<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Knee load classification of a training exercise (DATENMODELL.md,
 * "Training", `exercise.knee_load`) - the hard constraint from
 * PRODUCT-SPEC.md §3 made queryable (T-0301 design.md §2).
 *
 * Case *names* stay English code identifiers (ADR-008), while the backing
 * *values* are the German strings `chk_exercise_knee_load` checks against -
 * same split as App\Enum\TrickStatus and App\Enum\BodyWeightContext.
 */
enum KneeLoad: string
{
    case None = 'keine';
    case Low = 'niedrig';
    case Medium = 'mittel';
    case High = 'hoch';

    /**
     * Ascending numeric order, `None` lowest: the basis T-0304's alternative-
     * exercise search compares two knee loads with.
     */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
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
