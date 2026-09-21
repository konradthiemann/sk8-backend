<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Enum\HabitChangeKind;

/**
 * One row of the sync report: which slug changed how, and for updates which
 * definition fields differed.
 */
final readonly class HabitChange
{
    /**
     * @param list<string> $fields names of the differing fields (updates only)
     */
    public function __construct(
        public string $slug,
        public HabitChangeKind $kind,
        public array $fields,
    ) {
    }
}
