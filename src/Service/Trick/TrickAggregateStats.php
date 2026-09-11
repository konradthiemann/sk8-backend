<?php

declare(strict_types=1);

namespace App\Service\Trick;

/**
 * Pure value object bridging TrickProgressRepository's two aggregate queries
 * and TrickStatusResolver (T-0201 design.md §4). Not a Doctrine entity, not
 * an API-facing DTO.
 */
final readonly class TrickAggregateStats
{
    /**
     * @param list<array{sessionDate: string, attempts: int, landed: int}> $recentSessions sorted descending by sessionDate
     */
    public function __construct(
        public int $attemptsTotal,
        public int $landedTotal,
        public ?\DateTimeImmutable $firstLandedOn,
        public ?\DateTimeImmutable $lastPracticedOn,
        public int $sessionCount,
        public array $recentSessions,
    ) {
    }
}
