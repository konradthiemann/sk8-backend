<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Service\Skate\SessionMetrics;

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

    /**
     * The subset of recentSessions with at least MASTERY_MIN_ATTEMPTS
     * attempts, still sorted descending by sessionDate (T-0202 design.md
     * §4). Single source for a filter that used to be duplicated in
     * TrickStatusResolver::isMastered() and TrickTreeNode::recentSuccessRate().
     *
     * @return list<array{sessionDate: string, attempts: int, landed: int}>
     */
    public function qualifyingSessions(): array
    {
        return array_values(array_filter(
            $this->recentSessions,
            static fn (array $session): bool => $session['attempts'] >= TrickProgressPolicy::MASTERY_MIN_ATTEMPTS,
        ));
    }

    /**
     * Pooled success rate over the newest MASTERY_SESSIONS qualifying
     * sessions (as many as exist, from 0 up to MASTERY_SESSIONS) - a rule of
     * its own, independent of TrickStatusResolver::isMastered(): null "wenn
     * keine qualifiziert", not "wenn weniger als drei" (T-0201 design.md §6,
     * moved here unchanged from the former TrickTreeNode::recentSuccessRate()).
     */
    public function recentSuccessRate(): ?float
    {
        $qualifying = $this->qualifyingSessions();

        if ([] === $qualifying) {
            return null;
        }

        $window = \array_slice($qualifying, 0, TrickProgressPolicy::MASTERY_SESSIONS);

        return SessionMetrics::successRate(
            (int) array_sum(array_column($window, 'attempts')),
            (int) array_sum(array_column($window, 'landed')),
        );
    }
}
