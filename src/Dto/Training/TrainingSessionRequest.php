<?php

declare(strict_types=1);

namespace App\Dto\Training;

use App\Validator\NotInFuture;
use App\Validator\TrainingSetsMatchExercises;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload of POST /api/training-sessions: a training session is
 * recorded as a single unit together with all of its sets, or not at all
 * (T-0302 design.md §1). No NotBlank/NotNull on `durationMinutes` - a
 * missing or null value for a non-nullable typed int constructor argument
 * already fails denormalization before the validator ever runs (400, not
 * 422) - same reasoning as App\Dto\Skate\SessionTrickInput::$attempts
 * (design.md §3).
 */
#[OA\Schema(required: ['sessionDate', 'durationMinutes', 'sets'])]
#[TrainingSetsMatchExercises]
final readonly class TrainingSessionRequest
{
    public const int MAX_SETS = 60;

    /**
     * @param list<TrainingSetInput> $sets
     */
    public function __construct(
        #[Assert\NotBlank(message: 'training.session_date.blank')]
        #[Assert\Date(message: 'training.session_date.blank')]
        #[NotInFuture(mode: 'date', message: 'training.session_date.future')]
        #[OA\Property(description: 'Session date, day precision', format: 'date', example: '2026-09-08')]
        public string $sessionDate,

        #[Assert\Range(min: 1, max: 600, notInRangeMessage: 'training.duration.range')]
        #[OA\Property(description: 'Duration in minutes, 1 to 600', example: 38)]
        public int $durationMinutes,

        #[Assert\Range(min: 1, max: 10, notInRangeMessage: 'training.exertion.range')]
        #[OA\Property(description: 'Perceived exertion, 1 to 10', example: 7, nullable: true)]
        public ?int $perceivedExertion = null,

        #[Assert\Range(min: 0, max: 10, notInRangeMessage: 'training.knee_pain.range')]
        #[OA\Property(description: 'Knee pain, 0 to 10', example: 2, nullable: true)]
        public ?int $kneePain = null,

        #[Assert\Length(max: 2000, maxMessage: 'training.notes.too_long')]
        #[OA\Property(description: 'Free-form notes', example: 'Ringe am Tuerrahmen, Knie ruhig', nullable: true)]
        public ?string $notes = null,

        #[Assert\Count(min: 1, max: self::MAX_SETS, minMessage: 'training.sets.min', maxMessage: 'training.sets.max')]
        #[Assert\Valid]
        #[OA\Property(description: 'Sets, at least 1 and at most 60 rows')]
        public array $sets = [],
    ) {
    }
}
