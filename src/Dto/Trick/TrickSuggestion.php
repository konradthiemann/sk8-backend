<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One entry of `primary`/`secondary` in GET /api/trick-recommendation's
 * response (T-0202 design.md §3).
 */
final readonly class TrickSuggestion
{
    public function __construct(
        #[OA\Property(description: 'Public trick key, not the UUID', example: 'pop-shove-it')]
        public string $slug,
        #[OA\Property(description: 'Display name (German)', example: 'Pop Shove-it')]
        public string $name,
        #[OA\Property(description: 'Derived progress status', example: 'uebe')]
        public string $status,
        #[OA\Property(description: 'Machine-readable reason code', example: 'almost_landed')]
        public string $reasonCode,
        #[OA\Property(description: 'German explanation for this suggestion', example: 'Du landest diesen Trick schon öfter, aber die Erfolgsquote ist noch nicht über mehrere Einheiten stabil – bleib dran.')]
        public string $reason,
        #[OA\Property(description: 'Recommended attempts/minutes range for this session - identical across every suggestion', ref: new Model(type: TrickDosage::class))]
        public TrickDosage $dosage,
    ) {
    }
}
