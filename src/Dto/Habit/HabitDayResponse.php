<?php

declare(strict_types=1);

namespace App\Dto\Habit;

use App\Repository\HabitDayRow;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/habits/day: all active habits with the entry of
 * one day. `completedCount` counts habits with an entry - an entry with the
 * value 0 or `false` counts.
 */
final readonly class HabitDayResponse
{
    /**
     * @param list<HabitDayItem> $items
     */
    public function __construct(
        #[OA\Property(description: 'The day, YYYY-MM-DD', format: 'date', example: '2026-09-08')]
        public string $date,
        #[OA\Property(description: 'Number of active habits', example: 2)]
        public int $totalCount,
        #[OA\Property(description: 'Number of active habits with an entry on that day (0 and false count)', example: 1)]
        public int $completedCount,
        /**
         * @var list<HabitDayItem>
         */
        #[OA\Property(description: 'Active habits ascending by sortOrder (ties: name, then slug), each with its entry or null', type: 'array', items: new OA\Items(ref: new Model(type: HabitDayItem::class)))]
        public array $items,
    ) {
    }

    /**
     * @param list<HabitDayRow> $rows
     */
    public static function fromRows(string $date, array $rows): self
    {
        $items = [];
        $completed = 0;
        foreach ($rows as $row) {
            $items[] = new HabitDayItem(
                HabitResponse::fromEntity($row->habit),
                null === $row->entry ? null : HabitEntryResponse::fromEntity($row->entry),
            );

            if (null !== $row->entry) {
                ++$completed;
            }
        }

        return new self($date, \count($rows), $completed, $items);
    }
}
