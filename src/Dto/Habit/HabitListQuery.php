<?php

declare(strict_types=1);

namespace App\Dto\Habit;

use OpenApi\Attributes as OA;

/**
 * Query parameters of GET /api/habits (#[MapQueryString]). `includeInactive`
 * must stay a plain `bool`: with `?bool` the serializer would turn a typo like
 * `abc` silently into `false` instead of answering 422 (T-0401 design.md §3.3).
 */
final readonly class HabitListQuery
{
    public function __construct(
        #[OA\Property(description: 'Also return deactivated habits. Accepts true/false, 1/0, on/off, yes/no; anything else is rejected with 422.', example: false)]
        public bool $includeInactive = false,
    ) {
    }
}
