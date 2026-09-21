<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Kind of change App\Service\Habit\HabitCatalogSynchronizer reports for one
 * slug. Only used in the sync report, there is no column for it (T-0401
 * design.md §2).
 */
enum HabitChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deactivated = 'deactivated';
}
