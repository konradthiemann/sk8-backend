<?php

declare(strict_types=1);

namespace App\Dto\Training;

use App\Enum\BodySide;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One set row inside TrainingSessionRequest.sets. Which of `reps`/`seconds`
 * is required, and whether `side` is required or forbidden, depends on the
 * referenced exercise's measure - enforced by the request's class-level
 * App\Validator\TrainingSetsMatchExercises, not here (T-0302 design.md §3).
 */
#[OA\Schema(required: ['exerciseSlug', 'setNumber'])]
final readonly class TrainingSetInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'training.set.slug.blank')]
        #[Assert\Length(max: 60, maxMessage: 'training.set.slug.invalid')]
        #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'training.set.slug.invalid')]
        #[OA\Property(description: 'Slug of a catalog exercise (T-0301)', example: 'ring-row')]
        public string $exerciseSlug,

        #[Assert\Range(min: 1, max: 20, notInRangeMessage: 'training.set.number.range')]
        #[OA\Property(description: 'Set number, counted up per exercise (not per side), 1 to 20', example: 1)]
        public int $setNumber,

        #[Assert\Range(min: 1, max: 999, notInRangeMessage: 'training.set.reps.range')]
        #[OA\Property(description: 'Repetitions; required for a reps-measured exercise, forbidden otherwise', example: 10, nullable: true)]
        public ?int $reps = null,

        #[Assert\Range(min: 1, max: 3600, notInRangeMessage: 'training.set.seconds.range')]
        #[OA\Property(description: 'Seconds held; required for a seconds-measured exercise, forbidden otherwise', example: 45, nullable: true)]
        public ?int $seconds = null,

        #[Assert\Choice(callback: [BodySide::class, 'values'], message: 'training.set.side.invalid')]
        #[OA\Property(description: 'Body side; required for a *_per_side-measured exercise, forbidden otherwise', example: 'links', nullable: true)]
        public ?string $side = null,
    ) {
    }
}
