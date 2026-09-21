<?php

declare(strict_types=1);

namespace App\Dto\Training;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/fitness-assessments.
 */
final readonly class FitnessAssessmentListResponse
{
    /**
     * @param list<FitnessAssessmentView> $items
     */
    public function __construct(
        /**
         * @var list<FitnessAssessmentView>
         */
        #[OA\Property(description: 'At most `limit` assessments, newest first', type: 'array', items: new OA\Items(ref: new Model(type: FitnessAssessmentView::class)))]
        public array $items,
        #[OA\Property(description: 'Total number of assessments before limit/offset was applied', example: 3)]
        public int $total,
        #[OA\Property(description: 'The limit used for this page', example: 30)]
        public int $limit,
        #[OA\Property(description: 'The offset used for this page', example: 0)]
        public int $offset,
    ) {
    }
}
