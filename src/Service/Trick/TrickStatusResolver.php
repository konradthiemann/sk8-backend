<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Enum\TrickStatus;
use App\Service\Skate\SessionMetrics;

/**
 * Resolves every trick's status from its own aggregate stats and its direct
 * prerequisites' resolved status - two flat passes, no recursion, no graph
 * traversal (T-0201 design.md §6, ticket "Fachlogik"). Pure function: no
 * kernel, no Doctrine, unit-testable without booting the container.
 *
 * A cycle in trick_prerequisite (fachlich verboten, but see criterion 12)
 * cannot hang this resolver: pass 2 only ever reads pass 1's already-finished
 * snapshot, never re-enters pass 1. A trick that was already mastered once
 * can never fall back to Locked, because pass 1 never revisits a trick pass 2
 * has decided.
 */
final class TrickStatusResolver
{
    /**
     * @param array<string, TrickAggregateStats> $stats
     * @param array<string, list<string>>        $prerequisiteIdsByTrickId
     *
     * @return array<string, TrickStatus>
     */
    public function resolveAll(array $stats, array $prerequisiteIdsByTrickId): array
    {
        $result = [];
        $undecided = [];

        // Pass 1: from each trick's own data only.
        foreach ($stats as $trickId => $trickStats) {
            if ($this->isMastered($trickStats)) {
                $result[$trickId] = TrickStatus::Mastered;

                continue;
            }

            if ($trickStats->attemptsTotal > 0) {
                $result[$trickId] = TrickStatus::Practicing;

                continue;
            }

            $undecided[] = $trickId;
        }

        // Pass 2: from prerequisites, reading only pass 1's finished snapshot.
        foreach ($undecided as $trickId) {
            $prerequisiteIds = $prerequisiteIdsByTrickId[$trickId] ?? [];
            $result[$trickId] = $this->allPrerequisitesMastered($prerequisiteIds, $result) ? TrickStatus::Ready : TrickStatus::Locked;
        }

        return $result;
    }

    /**
     * @param list<string>               $prerequisiteIds
     * @param array<string, TrickStatus> $resolvedSoFar
     */
    private function allPrerequisitesMastered(array $prerequisiteIds, array $resolvedSoFar): bool
    {
        foreach ($prerequisiteIds as $prerequisiteId) {
            if (TrickStatus::Mastered !== ($resolvedSoFar[$prerequisiteId] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * design.md §6 pseudocode: among the most recent sessions with at least
     * MASTERY_MIN_ATTEMPTS attempts, take the newest MASTERY_SESSIONS of
     * them. Fewer than MASTERY_SESSIONS qualifying sessions is never
     * "mastered", regardless of how good those few sessions were - "zu wenig
     * Daten ist nie beherrscht" (ticket).
     */
    private function isMastered(TrickAggregateStats $stats): bool
    {
        $qualifying = $stats->qualifyingSessions();

        if (\count($qualifying) < TrickProgressPolicy::MASTERY_SESSIONS) {
            return false;
        }

        $window = \array_slice($qualifying, 0, TrickProgressPolicy::MASTERY_SESSIONS);

        // MASTERY_MODE is 'each_session' for the confirmed R-02 value (every
        // qualifying session in the window must individually clear
        // MASTERY_RATE) - see impl.md, "Abweichungen vom Design" for why the
        // 'pooled' branch the ticket/design also describe is not implemented
        // here: with the constant fixed at 'each_session', a live 'pooled'
        // code path is unreachable dead code under PHPStan level max, which
        // forbids exactly this kind of "just in case" branch.
        foreach ($window as $session) {
            $rate = SessionMetrics::successRate($session['attempts'], $session['landed']);
            if (null === $rate || $rate < TrickProgressPolicy::MASTERY_RATE) {
                return false;
            }
        }

        return true;
    }
}
