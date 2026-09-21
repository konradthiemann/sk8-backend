<?php

declare(strict_types=1);

namespace App\Dto\Habit;

use OpenApi\Attributes as OA;

/**
 * Query parameters of GET /api/habits/day (#[MapQueryString]). The date has no
 * validator attributes on purpose: format, calendar validity, future and
 * project start are all judged by App\Service\Habit\EntryDateRules, the same
 * rule set the write endpoints use (T-0402 design.md §3.3).
 */
final readonly class HabitDayQuery
{
    public function __construct(
        #[OA\Property(description: 'The day, YYYY-MM-DD. Defaults to today. Not in the future, not before 2026-01-01.', format: 'date', example: '2026-09-08', nullable: true)]
        public ?string $date = null,
    ) {
    }
}
