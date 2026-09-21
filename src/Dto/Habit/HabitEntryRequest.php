<?php

declare(strict_types=1);

namespace App\Dto\Habit;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PUT /api/habits/{habitId}/entries/{date}. Exactly one of the two
 * values must be set; which one and what range is judged against the habit by
 * App\Service\Habit\HabitValueValidator, not here. The serializer is strict:
 * `"7.5"` (a string) is rejected with 422, `valueBool` accepts only JSON
 * `true`/`false` (T-0402 design.md §3.1).
 */
final readonly class HabitEntryRequest
{
    public function __construct(
        #[OA\Property(description: 'Value of a scale, duration or number habit. 0 is a value. Send a JSON number, never a string.', type: 'number', example: 7.5, nullable: true)]
        public ?float $valueNumeric = null,
        #[OA\Property(description: 'Value of a yes/no habit. false is a value.', example: true, nullable: true)]
        public ?bool $valueBool = null,
        #[Assert\Length(max: 500, maxMessage: 'habit_entry.note.too_long')]
        #[OA\Property(description: 'Optional note, at most 500 characters. An empty string is stored as no note.', example: 'spät ins Bett, früh raus', nullable: true)]
        public ?string $note = null,
    ) {
    }
}
