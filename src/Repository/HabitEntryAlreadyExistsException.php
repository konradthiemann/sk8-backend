<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Signals that the unique index `uniq_habit_entry_habit_date` refused an
 * insert: another request wrote the same habit and day first.
 * App\Service\Habit\HabitEntryService answers by loading and changing that row.
 */
final class HabitEntryAlreadyExistsException extends \RuntimeException
{
}
