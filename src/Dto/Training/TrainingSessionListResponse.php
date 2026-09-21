<?php

declare(strict_types=1);

namespace App\Dto\Training;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/training-sessions.
 */
final readonly class TrainingSessionListResponse
{
    /**
     * @param list<TrainingSessionSummary> $items
     */
    public function __construct(
        /**
         * @var list<TrainingSessionSummary>
         */
        #[OA\Property(description: 'At most `limit` sessions, newest first', type: 'array', items: new OA\Items(ref: new Model(type: TrainingSessionSummary::class)))]
        public array $items,
        #[OA\Property(description: 'Total number of sessions before limit/offset was applied', example: 3)]
        public int $total,
        #[OA\Property(description: 'The limit used for this page', example: 30)]
        public int $limit,
        #[OA\Property(description: 'The offset used for this page', example: 0)]
        public int $offset,
    ) {
    }
}
