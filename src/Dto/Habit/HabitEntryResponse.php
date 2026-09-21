<?php

declare(strict_types=1);

namespace App\Dto\Habit;

use App\Entity\HabitEntry;
use OpenApi\Attributes as OA;

/**
 * One recorded day of a habit. `valueNumeric` is stored as DECIMAL (a string
 * in PHP) and converted to a number exactly once, here: 0.00 stays `0`, never
 * `null`. `createdAt` is always written in UTC: an entity loaded from the
 * database carries the time zone of the database session, and a `201` and a
 * later `200` for the same row must show the same string.
 */
final readonly class HabitEntryResponse
{
    public function __construct(
        #[OA\Property(description: 'Entry ID', example: '0199a112-8b3d-7c4e-a1f2-3d4e5f6a7b8c')]
        public string $id,
        #[OA\Property(description: 'ID of the habit', example: '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d')]
        public string $habitId,
        #[OA\Property(description: 'The calendar day, YYYY-MM-DD', format: 'date', example: '2026-09-08')]
        public string $entryDate,
        #[OA\Property(description: 'Value of a scale, duration or number habit; 0 is a value', type: 'number', example: 7.5, nullable: true)]
        public ?float $valueNumeric,
        #[OA\Property(description: 'Value of a yes/no habit; false is a value', example: null, nullable: true)]
        public ?bool $valueBool,
        #[OA\Property(description: 'Optional note', example: 'spät ins Bett, früh raus', nullable: true)]
        public ?string $note,
        #[OA\Property(description: 'When the entry was first recorded (ISO 8601, UTC); a correction does not change it', format: 'date-time', example: '2026-09-08T19:04:11+00:00')]
        public string $createdAt,
    ) {
    }

    public static function fromEntity(HabitEntry $entry): self
    {
        $valueNumeric = $entry->getValueNumeric();

        return new self(
            $entry->getId()->toRfc4122(),
            $entry->getHabit()->getId()->toRfc4122(),
            $entry->getEntryDate()->format('Y-m-d'),
            null === $valueNumeric ? null : (float) $valueNumeric,
            $entry->getValueBool(),
            $entry->getNote(),
            $entry->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        );
    }
}
