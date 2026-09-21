<?php

declare(strict_types=1);

namespace App\Dto\Habit;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/habits.
 */
final readonly class HabitListResponse
{
    /**
     * @param list<HabitResponse> $habits
     */
    public function __construct(
        /**
         * @var list<HabitResponse>
         */
        #[OA\Property(description: 'The habit catalog, ascending by sortOrder (ties: name, then slug)', type: 'array', items: new OA\Items(ref: new Model(type: HabitResponse::class)))]
        public array $habits,
    ) {
    }
}
