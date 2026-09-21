<?php

declare(strict_types=1);

namespace App\Service\Habit;

/**
 * Outcome of one App\Service\Habit\HabitCatalogSynchronizer::sync() run. On a
 * dry run the numbers describe the planned changes. Invariant:
 * created + updated + unchanged equals the number of definitions.
 */
final readonly class SyncResult
{
    /**
     * @param list<HabitChange> $changes created/updated/deactivated only, in definition order followed by the deactivations
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $deactivated,
        public int $unchanged,
        public array $changes,
    ) {
    }
}
