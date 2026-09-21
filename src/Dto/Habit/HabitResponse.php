<?php

declare(strict_types=1);

namespace App\Dto\Habit;

use App\Entity\Habit;
use OpenApi\Attributes as OA;

/**
 * One catalog habit as returned by GET /api/habits. All eleven fields are
 * always present. `targetValue` is stored as DECIMAL (a string in PHP) and
 * converted to a number exactly once, here: 8.00 is written as `8`, 2.50 as `2.5`.
 */
final readonly class HabitResponse
{
    public function __construct(
        #[OA\Property(description: 'Habit ID', example: '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d')]
        public string $id,
        #[OA\Property(description: 'Stable kebab-case identifier', example: 'sleep-duration')]
        public string $slug,
        #[OA\Property(description: 'German display name', example: 'Schlafdauer')]
        public string $name,
        #[OA\Property(description: 'How the habit is measured', enum: ['boolean', 'scale', 'number', 'duration'], example: 'duration')]
        public string $valueType,
        #[OA\Property(description: 'Unit of the value, mandatory for durations', example: 'h', nullable: true)]
        public ?string $unit,
        #[OA\Property(description: 'Lowest scale value, only for scales', example: 1, nullable: true)]
        public ?int $scaleMin,
        #[OA\Property(description: 'Highest scale value, only for scales', example: 5, nullable: true)]
        public ?int $scaleMax,
        #[OA\Property(description: 'Which direction is better', enum: ['hoch', 'niedrig'], example: 'hoch', nullable: true)]
        public ?string $targetDirection,
        #[OA\Property(description: 'Numeric target, as a JSON number', type: 'number', example: 8, nullable: true)]
        public ?float $targetValue,
        #[OA\Property(description: 'Ascending display order', example: 20)]
        public int $sortOrder,
        #[OA\Property(description: 'Whether the habit is currently part of the catalog', example: true)]
        public bool $isActive,
    ) {
    }

    public static function fromEntity(Habit $habit): self
    {
        $targetValue = $habit->getTargetValue();

        return new self(
            $habit->getId()->toRfc4122(),
            $habit->getSlug(),
            $habit->getName(),
            $habit->getValueType()->value,
            $habit->getUnit(),
            $habit->getScaleMin(),
            $habit->getScaleMax(),
            $habit->getTargetDirection()?->value,
            null === $targetValue ? null : (float) $targetValue,
            $habit->getSortOrder(),
            $habit->isActive(),
        );
    }
}
