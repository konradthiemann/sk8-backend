<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a habit is measured (`habit.value_type`, T-0401 design.md §2). Case
 * names stay English code identifiers (ADR-008); the backing values are the
 * strings stored in the column and returned by the API.
 */
enum HabitValueType: string
{
    case Boolean = 'boolean';
    case Scale = 'scale';
    case Number = 'number';
    case Duration = 'duration';
}
