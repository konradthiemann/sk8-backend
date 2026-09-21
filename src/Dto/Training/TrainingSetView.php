<?php

declare(strict_types=1);

namespace App\Dto\Training;

use App\Entity\TrainingSet;
use OpenApi\Attributes as OA;

/**
 * One set row inside TrainingSessionView.sets. Carries the exercise's own
 * name/measure/kneeLoad alongside the set data, so the client never has to
 * cross-reference the exercise catalog separately.
 */
final readonly class TrainingSetView
{
    public function __construct(
        #[OA\Property(description: 'Slug of the exercise', example: 'ring-row')]
        public string $exerciseSlug,
        #[OA\Property(description: 'Name of the exercise', example: 'Ruderzug an den Ringen')]
        public string $exerciseName,
        #[OA\Property(description: 'How the exercise is measured', example: 'reps')]
        public string $measure,
        #[OA\Property(description: 'Knee load of the exercise', example: 'keine')]
        public string $kneeLoad,
        #[OA\Property(description: 'Set number, counted up per exercise', example: 1)]
        public int $setNumber,
        #[OA\Property(description: 'Repetitions', example: 10, nullable: true)]
        public ?int $reps,
        #[OA\Property(description: 'Seconds held', example: 45, nullable: true)]
        public ?int $seconds,
        #[OA\Property(description: 'Body side', example: 'links', nullable: true)]
        public ?string $side,
    ) {
    }

    public static function fromEntity(TrainingSet $set): self
    {
        $exercise = $set->getExercise();

        return new self(
            $exercise->getSlug(),
            $exercise->getName(),
            $exercise->getMeasure()->value,
            $exercise->getKneeLoad()->value,
            $set->getSetNumber(),
            $set->getReps(),
            $set->getSeconds(),
            $set->getSide()?->value,
        );
    }
}
