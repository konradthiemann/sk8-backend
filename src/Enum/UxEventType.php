<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Event types collected by the frontend telemetry hook (see ADR-009).
 */
enum UxEventType: string
{
    case ScreenView = 'screen_view';
    case TimeOnScreen = 'time_on_screen';
    case Interaction = 'interaction';
    case Navigation = 'navigation';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
