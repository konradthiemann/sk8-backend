<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Dto\Trick\TrickDetailProgress;
use App\Dto\Trick\TrickDetailResponse;
use App\Dto\Trick\TrickHistoryEntry;
use App\Dto\Trick\TrickPolicyView;
use App\Dto\Trick\TrickRefView;
use App\Entity\Trick;
use App\Entity\TrickPrerequisite;
use App\Enum\TrickStatus;
use App\Repository\TrickProgressRepository;
use App\Service\Skate\SessionMetrics;

/**
 * Orchestrates GET /api/tricks/{slug} (T-0202 design.md §4/§6): reuses
 * App\Service\Trick\TrickTreeService::currentSnapshot() for progress,
 * requires and unlocks (same catalog + status + trick_progress refresh as
 * GET /api/trick-tree), then loads the trick's own session history
 * separately via App\Repository\TrickProgressRepository::historyByTrickId().
 */
final readonly class TrickDetailService
{
    /**
     * How many of a trick's most recent sessions the `history` array
     * returns - design.md §3.
     */
    private const int HISTORY_LIMIT = 100;

    public function __construct(
        private TrickTreeService $trickTreeService,
        private TrickProgressRepository $trickProgressRepository,
    ) {
    }

    public function detail(Trick $trick): TrickDetailResponse
    {
        $snapshot = $this->trickTreeService->currentSnapshot();
        $trickId = $trick->getId()->toRfc4122();

        $status = $snapshot->statuses[$trickId] ?? TrickStatus::Locked;
        $stats = $snapshot->stats[$trickId] ?? null;
        \assert(null !== $stats, 'TrickTreeService::currentSnapshot() builds a stats entry for every trick in the catalog');

        $progressRow = $snapshot->progressRows[$trickId] ?? null;
        \assert(null !== $progressRow, 'TrickProgressRefresher::refresh() creates a trick_progress row for every trick in the catalog');

        return new TrickDetailResponse(
            $trick->getSlug(),
            $trick->getName(),
            $trick->getCategory()->value,
            $trick->getDifficulty(),
            $trick->isGoal(),
            $trick->getGoalOrder(),
            $trick->getDescription(),
            new TrickDetailProgress(
                $status->value,
                $stats->attemptsTotal,
                $stats->landedTotal,
                SessionMetrics::successRate($stats->attemptsTotal, $stats->landedTotal),
                $stats->recentSuccessRate(),
                $stats->sessionCount,
                $stats->firstLandedOn?->format('Y-m-d'),
                $stats->lastPracticedOn?->format('Y-m-d'),
                $progressRow->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ),
            $this->buildRequires($trick, $snapshot->statuses),
            $this->buildUnlocks($trick, $snapshot->tricks, $snapshot->statuses),
            $this->buildHistory($trick),
            TrickPolicyView::current(),
        );
    }

    /**
     * @param array<string, TrickStatus> $statuses
     *
     * @return list<TrickRefView>
     */
    private function buildRequires(Trick $trick, array $statuses): array
    {
        $requiredTricks = array_values(array_map(
            static fn (TrickPrerequisite $prerequisite): Trick => $prerequisite->getRequiresTrick(),
            $trick->getPrerequisites()->toArray(),
        ));

        return array_map(
            static fn (Trick $requiredTrick): TrickRefView => self::toRefView($requiredTrick, $statuses),
            self::sortByDifficultyThenSlug($requiredTricks),
        );
    }

    /**
     * @param list<Trick>                $catalog
     * @param array<string, TrickStatus> $statuses
     *
     * @return list<TrickRefView>
     */
    private function buildUnlocks(Trick $trick, array $catalog, array $statuses): array
    {
        $trickId = $trick->getId()->toRfc4122();

        $unlockedTricks = array_values(array_filter(
            $catalog,
            static function (Trick $candidate) use ($trickId): bool {
                foreach ($candidate->getPrerequisites() as $prerequisite) {
                    \assert($prerequisite instanceof TrickPrerequisite);
                    if ($prerequisite->getRequiresTrick()->getId()->toRfc4122() === $trickId) {
                        return true;
                    }
                }

                return false;
            },
        ));

        return array_map(
            static fn (Trick $unlockedTrick): TrickRefView => self::toRefView($unlockedTrick, $statuses),
            self::sortByDifficultyThenSlug($unlockedTricks),
        );
    }

    /**
     * @return list<TrickHistoryEntry>
     */
    private function buildHistory(Trick $trick): array
    {
        $rows = $this->trickProgressRepository->historyByTrickId($trick->getId(), self::HISTORY_LIMIT);

        return array_map(
            static fn (array $row): TrickHistoryEntry => new TrickHistoryEntry(
                $row['sessionId'],
                $row['sessionDate'],
                $row['attempts'],
                $row['landed'],
                SessionMetrics::successRate($row['attempts'], $row['landed']),
                $row['notes'],
            ),
            $rows,
        );
    }

    /**
     * @param array<string, TrickStatus> $statuses
     */
    private static function toRefView(Trick $trick, array $statuses): TrickRefView
    {
        $status = $statuses[$trick->getId()->toRfc4122()] ?? TrickStatus::Locked;

        return new TrickRefView($trick->getSlug(), $trick->getName(), $status->value);
    }

    /**
     * @param list<Trick> $tricks
     *
     * @return list<Trick>
     */
    private static function sortByDifficultyThenSlug(array $tricks): array
    {
        $sorted = $tricks;
        usort(
            $sorted,
            static function (Trick $a, Trick $b): int {
                $byDifficulty = $a->getDifficulty() <=> $b->getDifficulty();

                return 0 !== $byDifficulty ? $byDifficulty : strcmp($a->getSlug(), $b->getSlug());
            },
        );

        return $sorted;
    }
}
