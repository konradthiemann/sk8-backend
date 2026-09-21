<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Entity\HabitEntry;

/**
 * Outcome of HabitEntryService::put(): the stored entry and whether the call
 * created it (HTTP 201) or corrected an existing one (HTTP 200).
 */
final readonly class HabitEntryWriteResult
{
    public function __construct(
        public HabitEntry $entry,
        public bool $created,
    ) {
    }
}
