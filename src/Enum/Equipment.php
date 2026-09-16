<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Equipment an exercise of the training catalog is performed with
 * (DATENMODELL.md, "Training", `exercise.equipment`; T-0301 design.md §2).
 */
enum Equipment: string
{
    case Bodyweight = 'bodyweight';
    case Rings = 'rings';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
