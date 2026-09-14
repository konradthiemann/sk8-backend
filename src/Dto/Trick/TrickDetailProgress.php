<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use OpenApi\Attributes as OA;

/**
 * `progress` of GET /api/tricks/{slug}'s response (T-0202 design.md §3).
 */
final readonly class TrickDetailProgress
{
    public function __construct(
        #[OA\Property(description: 'Derived progress status', example: 'uebe')]
        public string $status,
        #[OA\Property(description: 'Total attempts across all sessions', example: 96)]
        public int $attemptsTotal,
        #[OA\Property(description: 'Total landed attempts across all sessions', example: 21)]
        public int $landedTotal,
        #[OA\Property(description: 'landedTotal / attemptsTotal, three decimals; null when attemptsTotal is 0', example: 0.219, nullable: true)]
        public ?float $successRate,
        #[OA\Property(description: 'Pooled success rate over the most recent qualifying sessions; null when none qualify', example: 0.267, nullable: true)]
        public ?float $recentSuccessRate,
        #[OA\Property(description: 'Number of sessions this trick was practiced in', example: 5)]
        public int $sessionCount,
        #[OA\Property(description: 'Date of the first session with at least one landed attempt', format: 'date', example: '2026-08-11', nullable: true)]
        public ?string $firstLandedOn,
        #[OA\Property(description: 'Date of the most recent practiced session', format: 'date', example: '2026-09-06', nullable: true)]
        public ?string $lastPracticedOn,
        #[OA\Property(description: 'Server time this trick_progress row was last written, ISO 8601', example: '2026-09-06T18:12:44+00:00')]
        public string $updatedAt,
    ) {
    }
}
