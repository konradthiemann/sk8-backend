<?php

declare(strict_types=1);

namespace App\Dto\Training;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query parameters of GET /api/fitness-assessments (#[MapQueryString]).
 * Deliberately not shared with TrainingSessionListQuery: different message
 * keys, and reusing it would touch finished T-0302 code (T-0303 design.md §7).
 */
final readonly class FitnessAssessmentListQuery
{
    public function __construct(
        #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'fitness.list.limit.range')]
        #[OA\Property(description: 'Maximum number of items, 1 to 100', example: 30)]
        public int $limit = 30,

        #[Assert\GreaterThanOrEqual(value: 0, message: 'fitness.list.offset.range')]
        #[OA\Property(description: 'Number of items to skip', example: 0)]
        public int $offset = 0,
    ) {
    }
}
