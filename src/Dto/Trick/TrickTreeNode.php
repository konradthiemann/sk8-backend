<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use App\Entity\Trick;
use App\Enum\TrickStatus;
use App\Service\Skate\SessionMetrics;
use App\Service\Trick\TrickAggregateStats;
use App\Service\Trick\TrickProgressPolicy;
use OpenApi\Attributes as OA;

/**
 * One entry of GET /api/trick-tree's `nodes`.
 */
final readonly class TrickTreeNode
{
    public function __construct(
        #[OA\Property(description: 'Public trick key, not the UUID', example: 'ollie')]
        public string $slug,
        #[OA\Property(description: 'Display name (German)', example: 'Ollie')]
        public string $name,
        #[OA\Property(description: 'Movement category', example: 'flat')]
        public string $category,
        #[OA\Property(description: 'Position in the tree, 1 to 10', example: 2)]
        public int $difficulty,
        #[OA\Property(description: 'Whether this trick is one of the seven contest goals', example: true)]
        public bool $isGoal,
        #[OA\Property(description: 'Position among the seven goals, 1 to 7', example: 1, nullable: true)]
        public ?int $goalOrder,
        #[OA\Property(description: 'Derived progress status', example: 'sitzt')]
        public string $status,
        #[OA\Property(description: 'Total attempts across all sessions', example: 412)]
        public int $attemptsTotal,
        #[OA\Property(description: 'Total landed attempts across all sessions', example: 318)]
        public int $landedTotal,
        #[OA\Property(description: 'landedTotal / attemptsTotal, three decimals, via App\Service\Skate\SessionMetrics::successRate(); null when attemptsTotal is 0', example: 0.772, nullable: true)]
        public ?float $successRate,
        #[OA\Property(description: 'Pooled success rate over the most recent qualifying sessions; null when none qualify', example: 0.81, nullable: true)]
        public ?float $recentSuccessRate,
        #[OA\Property(description: 'Number of sessions this trick was practiced in', example: 14)]
        public int $sessionCount,
        #[OA\Property(description: 'Date of the first session with at least one landed attempt', format: 'date', example: '2026-04-19', nullable: true)]
        public ?string $firstLandedOn,
        #[OA\Property(description: 'Date of the most recent practiced session', format: 'date', example: '2026-09-06', nullable: true)]
        public ?string $lastPracticedOn,
    ) {
    }

    public static function fromTrick(Trick $trick, TrickStatus $status, TrickAggregateStats $stats): self
    {
        return new self(
            $trick->getSlug(),
            $trick->getName(),
            $trick->getCategory()->value,
            $trick->getDifficulty(),
            $trick->isGoal(),
            $trick->getGoalOrder(),
            $status->value,
            $stats->attemptsTotal,
            $stats->landedTotal,
            SessionMetrics::successRate($stats->attemptsTotal, $stats->landedTotal),
            self::recentSuccessRate($stats),
            $stats->sessionCount,
            $stats->firstLandedOn?->format('Y-m-d'),
            $stats->lastPracticedOn?->format('Y-m-d'),
        );
    }

    /**
     * A rule of its own, independent of TrickStatusResolver::isMastered()
     * (design.md §6): pools as many qualifying sessions as exist (0 to
     * MASTERY_SESSIONS), not only once at least MASTERY_SESSIONS qualify -
     * "null wenn keine qualifiziert", not "wenn weniger als drei".
     */
    private static function recentSuccessRate(TrickAggregateStats $stats): ?float
    {
        $qualifying = array_values(array_filter(
            $stats->recentSessions,
            static fn (array $session): bool => $session['attempts'] >= TrickProgressPolicy::MASTERY_MIN_ATTEMPTS,
        ));

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
