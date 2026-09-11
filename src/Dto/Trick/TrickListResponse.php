<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/tricks.
 */
final readonly class TrickListResponse
{
    /**
     * @param list<TrickResponse> $items
     */
    public function __construct(
        /**
         * @var list<TrickResponse>
         */
        #[OA\Property(description: 'The full trick catalog', type: 'array', items: new OA\Items(ref: new Model(type: TrickResponse::class)))]
        public array $items,
    ) {
    }
}
