<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use OpenApi\Attributes as OA;

/**
 * One entry of `requires`/`unlocks` in GET /api/tricks/{slug}'s response
 * (T-0202 design.md §3).
 */
final readonly class TrickRefView
{
    public function __construct(
        #[OA\Property(description: 'Public trick key, not the UUID', example: 'ollie')]
        public string $slug,
        #[OA\Property(description: 'Display name (German)', example: 'Ollie')]
        public string $name,
        #[OA\Property(description: 'Derived progress status', example: 'sitzt')]
        public string $status,
    ) {
    }
}
