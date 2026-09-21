<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which way is "better" for a habit (`habit.target_direction`, T-0401
 * design.md §2). Same split as App\Enum\KneeLoad: English case names, German
 * backing values shown to the user.
 */
enum HabitTargetDirection: string
{
    case High = 'hoch';
    case Low = 'niedrig';
}
