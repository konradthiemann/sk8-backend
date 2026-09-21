<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which side of the body a side-tracked training set belongs to
 * (DATENMODELL.md, "Training", `training_set.side`; T-0302 design.md §2).
 * Only relevant for sets of an exercise whose measure ends on `_per_side`
 * (`App\Enum\ExerciseMeasure::requiresSide()`).
 *
 * Case *names* stay English code identifiers (ADR-008), while the backing
 * *values* are the German strings `chk_training_set_side` checks against -
 * same split as App\Enum\KneeLoad.
 */
enum BodySide: string
{
    case Links = 'links';
    case Rechts = 'rechts';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
