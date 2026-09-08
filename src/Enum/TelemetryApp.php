<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The frontend application that emitted a telemetry event.
 */
enum TelemetryApp: string
{
    case Skate = 'skate';
    case Nutrition = 'nutrition';
    case Habits = 'habits';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
