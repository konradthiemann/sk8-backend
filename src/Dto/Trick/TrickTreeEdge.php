<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use OpenApi\Attributes as OA;

/**
 * One prerequisite edge of GET /api/trick-tree's `edges`. `from` is the
 * prerequisite (`trick_prerequisite.requires_trick_id`), `to` the dependent
 * trick (`trick_prerequisite.trick_id`) - the reading direction "from the
 * prerequisite to the goal" (API-Vertrag §3).
 */
final readonly class TrickTreeEdge
{
    public function __construct(
        #[OA\Property(description: 'Slug of the prerequisite trick', example: 'ollie-stand')]
        public string $from,
        #[OA\Property(description: 'Slug of the dependent trick', example: 'ollie')]
        public string $to,
    ) {
    }
}
