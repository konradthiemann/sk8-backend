<?php

declare(strict_types=1);

namespace App\Dto\Habit;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One habit of the day view with its entry of that day, `null` when nothing is recorded.
 */
final readonly class HabitDayItem
{
    public function __construct(
        #[OA\Property(ref: new Model(type: HabitResponse::class), description: 'The habit')]
        public HabitResponse $habit,
        #[OA\Property(ref: new Model(type: HabitEntryResponse::class), description: 'The entry of that day, null when not recorded', nullable: true)]
        public ?HabitEntryResponse $entry,
    ) {
    }
}
