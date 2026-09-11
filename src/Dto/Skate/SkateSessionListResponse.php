<?php

declare(strict_types=1);

namespace App\Dto\Skate;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/skate-sessions.
 */
final readonly class SkateSessionListResponse
{
    /**
     * @param list<SkateSessionSummary> $items
     */
    public function __construct(
        /**
         * @var list<SkateSessionSummary>
         */
        #[OA\Property(description: 'At most `limit` sessions, newest first', type: 'array', items: new OA\Items(ref: new Model(type: SkateSessionSummary::class)))]
        public array $items,
        #[OA\Property(description: 'Total number of matches before limit was applied', example: 5)]
        public int $total,
    ) {
    }
}
