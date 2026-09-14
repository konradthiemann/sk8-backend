<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use App\Service\Trick\TrickRecommendationPolicy;
use OpenApi\Attributes as OA;

/**
 * Mirrors App\Service\Trick\TrickRecommendationPolicy's four dosage
 * constants 1:1 in GET /api/trick-recommendation's `dosage` - identical for
 * every suggestion (T-0202 design.md §3), same pattern as
 * App\Dto\Trick\TrickPolicyView::current().
 */
final readonly class TrickDosage
{
    public function __construct(
        #[OA\Property(description: 'Minimum recommended attempts this session', example: 15)]
        public int $attemptsMin,
        #[OA\Property(description: 'Maximum recommended attempts this session', example: 30)]
        public int $attemptsMax,
        #[OA\Property(description: 'Minimum recommended minutes this session', example: 10)]
        public int $minutesMin,
        #[OA\Property(description: 'Maximum recommended minutes this session', example: 20)]
        public int $minutesMax,
    ) {
    }

    public static function current(): self
    {
        return new self(
            TrickRecommendationPolicy::PRACTICE_ATTEMPTS_MIN,
            TrickRecommendationPolicy::PRACTICE_ATTEMPTS_MAX,
            TrickRecommendationPolicy::PRACTICE_MINUTES_MIN,
            TrickRecommendationPolicy::PRACTICE_MINUTES_MAX,
        );
    }
}
