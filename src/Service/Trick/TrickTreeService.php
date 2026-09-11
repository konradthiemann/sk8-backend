<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Dto\Trick\TrickPolicyView;
use App\Dto\Trick\TrickTreeEdge;
use App\Dto\Trick\TrickTreeNode;
use App\Dto\Trick\TrickTreeResponse;
use App\Entity\Trick;
use App\Entity\TrickPrerequisite;
use App\Enum\TrickStatus;
use App\Repository\TrickProgressRepository;
use App\Repository\TrickRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestrates the whole GET /api/trick-tree read path (T-0201 design.md
 * §6): loads the catalog and the two session-derived aggregates, resolves
 * every trick's status, refreshes the trick_progress projection, then builds
 * the sorted response.
 */
final readonly class TrickTreeService
{
    /**
     * How many of a trick's most recent sessions to fetch for mastery
     * evaluation - own decision, not from the ticket (design.md §2,
     * "Aufruf-Limit"): generous enough to almost always contain
     * MASTERY_SESSIONS qualifying sessions without loading a trick's full
     * history. See design.md §8 for the accepted edge case.
     */
    private const int RECENT_SESSION_WINDOW = 10;

    public function __construct(
        private TrickRepository $trickRepository,
        private TrickProgressRepository $trickProgressRepository,
        private TrickStatusResolver $statusResolver,
        private TrickProgressRefresher $refresher,
        #[Autowire(param: 'app.timezone')]
        private string $timezone,
    ) {
    }

    public function build(): TrickTreeResponse
    {
        $tricks = $this->trickRepository->findAllOrdered();
        $aggregates = $this->trickProgressRepository->aggregatesByTrickId();
        $recentSessions = $this->trickProgressRepository->recentSessionsByTrickId(self::RECENT_SESSION_WINDOW);

        [$stats, $prerequisiteIdsByTrickId] = $this->buildStatsAndPrerequisites($tricks, $aggregates, $recentSessions);

        $statuses = $this->statusResolver->resolveAll($stats, $prerequisiteIdsByTrickId);
        $this->refresher->refresh($tricks, $statuses, $stats);

        return new TrickTreeResponse(
            $this->buildNodes($tricks, $statuses, $stats),
            $this->buildEdges($tricks),
            TrickPolicyView::current(),
            (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<Trick>                                                                                                                                             $tricks
     * @param array<string, array{attemptsTotal: int, landedTotal: int, firstLandedOn: ?\DateTimeImmutable, lastPracticedOn: ?\DateTimeImmutable, sessionCount: int}> $aggregates
     * @param array<string, list<array{sessionDate: string, attempts: int, landed: int}>>                                                                             $recentSessions
     *
     * @return array{0: array<string, TrickAggregateStats>, 1: array<string, list<string>>}
     */
    private function buildStatsAndPrerequisites(array $tricks, array $aggregates, array $recentSessions): array
    {
        $stats = [];
        $prerequisiteIdsByTrickId = [];

        foreach ($tricks as $trick) {
            $trickId = $trick->getId()->toRfc4122();
            $aggregate = $aggregates[$trickId] ?? null;

            $stats[$trickId] = new TrickAggregateStats(
                attemptsTotal: $aggregate['attemptsTotal'] ?? 0,
                landedTotal: $aggregate['landedTotal'] ?? 0,
                firstLandedOn: $aggregate['firstLandedOn'] ?? null,
                lastPracticedOn: $aggregate['lastPracticedOn'] ?? null,
                sessionCount: $aggregate['sessionCount'] ?? 0,
                recentSessions: $recentSessions[$trickId] ?? [],
            );

            $prerequisiteIdsByTrickId[$trickId] = array_values(array_map(
                static fn (TrickPrerequisite $prerequisite): string => $prerequisite->getRequiresTrick()->getId()->toRfc4122(),
                $trick->getPrerequisites()->toArray(),
            ));
        }

        return [$stats, $prerequisiteIdsByTrickId];
    }

    /**
     * @param list<Trick>                        $tricks
     * @param array<string, TrickStatus>         $statuses
     * @param array<string, TrickAggregateStats> $stats
     *
     * @return list<TrickTreeNode>
     */
    private function buildNodes(array $tricks, array $statuses, array $stats): array
    {
        $nodes = array_map(
            static function (Trick $trick) use ($statuses, $stats): TrickTreeNode {
                $trickId = $trick->getId()->toRfc4122();

                return TrickTreeNode::fromTrick($trick, $statuses[$trickId], $stats[$trickId]);
            },
            $tricks,
        );

        usort(
            $nodes,
            static function (TrickTreeNode $a, TrickTreeNode $b): int {
                $byGoalOrder = ($a->goalOrder ?? \PHP_INT_MAX) <=> ($b->goalOrder ?? \PHP_INT_MAX);
                if (0 !== $byGoalOrder) {
                    return $byGoalOrder;
                }

                $byDifficulty = $a->difficulty <=> $b->difficulty;

                return 0 !== $byDifficulty ? $byDifficulty : strcmp($a->slug, $b->slug);
            },
        );

        return $nodes;
    }

    /**
     * @param list<Trick> $tricks
     *
     * @return list<TrickTreeEdge>
     */
    private function buildEdges(array $tricks): array
    {
        $edges = [];
        foreach ($tricks as $trick) {
            foreach ($trick->getPrerequisites() as $prerequisite) {
                \assert($prerequisite instanceof TrickPrerequisite);
                $edges[] = new TrickTreeEdge($prerequisite->getRequiresTrick()->getSlug(), $prerequisite->getTrick()->getSlug());
            }
        }

        usort(
            $edges,
            static function (TrickTreeEdge $a, TrickTreeEdge $b): int {
                $byFrom = strcmp($a->from, $b->from);

                return 0 !== $byFrom ? $byFrom : strcmp($a->to, $b->to);
            },
        );

        return $edges;
    }
}
