<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use App\Service\Trick\TrickProgressPolicy;
use OpenApi\Attributes as OA;

/**
 * Mirrors App\Service\Trick\TrickProgressPolicy's four constants 1:1 in
 * GET /api/trick-tree's `policy`, so the frontend can display the mastery
 * thresholds instead of duplicating them (ticket).
 */
final readonly class TrickPolicyView
{
    public function __construct(
        #[OA\Property(description: 'Success rate from which a trick counts as mastered', example: 0.75)]
        public float $masteryRate,
        #[OA\Property(description: 'Number of most-recent qualifying sessions considered', example: 3)]
        public int $masterySessions,
        #[OA\Property(description: 'Minimum attempts a session needs before it qualifies', example: 15)]
        public int $masteryMinAttempts,
        #[OA\Property(description: 'each_session or pooled', example: 'each_session')]
        public string $masteryMode,
    ) {
    }

    public static function current(): self
    {
        return new self(
            TrickProgressPolicy::MASTERY_RATE,
            TrickProgressPolicy::MASTERY_SESSIONS,
            TrickProgressPolicy::MASTERY_MIN_ATTEMPTS,
            TrickProgressPolicy::MASTERY_MODE,
        );
    }
}
