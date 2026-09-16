<?php

declare(strict_types=1);

namespace App\Dto\Training;

use App\Entity\Exercise;
use OpenApi\Attributes as OA;

/**
 * One entry of GET /api/exercises (T-0301 design.md §3). The entity's UUID
 * never leaves the API - `slug` is the public key.
 */
final readonly class ExerciseView
{
    public function __construct(
        #[OA\Property(description: 'Unique, stable slug', example: 'single-leg-balance')]
        public string $slug,
        #[OA\Property(description: 'Display name (German)', example: 'Einbeinstand')]
        public string $name,
        #[OA\Property(description: 'Equipment the exercise is performed with', example: 'bodyweight')]
        public string $equipment,
        /**
         * @var list<string>
         */
        #[OA\Property(description: 'Muscle groups trained', type: 'array', items: new OA\Items(type: 'string'), example: ['rumpf', 'wade', 'huefte'])]
        public array $muscleGroups,
        #[OA\Property(description: 'Knee load classification, the basis of the knee-protection constraint', example: 'niedrig')]
        public string $kneeLoad,
        #[OA\Property(description: 'How sets of this exercise are tracked', example: 'seconds_per_side')]
        public string $measure,
        #[OA\Property(description: 'Optional description of the execution', example: 'Auf einem Bein stehen, Knie leicht gebeugt, Blick geradeaus.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'Whether this exercise is targeted at injury prevention', example: true)]
        public bool $isPrevention,
    ) {
    }

    public static function fromEntity(Exercise $exercise): self
    {
        return new self(
            $exercise->getSlug(),
            $exercise->getName(),
            $exercise->getEquipment()->value,
            $exercise->getMuscleGroups(),
            $exercise->getKneeLoad()->value,
            $exercise->getMeasure()->value,
            $exercise->getDescription(),
            $exercise->isPrevention(),
        );
    }
}
