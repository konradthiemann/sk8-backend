<?php

declare(strict_types=1);

namespace App\Dto\Training;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/exercises.
 */
final readonly class ExerciseListResponse
{
    /**
     * @param list<ExerciseView> $items
     */
    public function __construct(
        /**
         * @var list<ExerciseView>
         */
        #[OA\Property(description: 'The full exercise catalog', type: 'array', items: new OA\Items(ref: new Model(type: ExerciseView::class)))]
        public array $items,
        #[OA\Property(description: 'Number of items in the catalog', example: 16)]
        public int $total,
    ) {
    }
}
