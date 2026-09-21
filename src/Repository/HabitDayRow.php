<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Habit;
use App\Entity\HabitEntry;

/**
 * One line of the day view: an active habit and its entry of that day, or `null`.
 */
final readonly class HabitDayRow
{
    public function __construct(
        public Habit $habit,
        public ?HabitEntry $entry,
    ) {
    }
}
