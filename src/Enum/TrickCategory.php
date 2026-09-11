<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Movement category of a trick (DATENMODELL.md, "Skateboard").
 */
enum TrickCategory: string
{
    case Flat = 'flat';
    case Rotation = 'rotation';
    case Balance = 'balance';
    case Slide = 'slide';
    case Grind = 'grind';
    case Air = 'air';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
