<?php

declare(strict_types=1);

namespace App\Dto\Training;

use App\Validator\AtLeastOneMeasurement;
use App\Validator\NotInFuture;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload of POST /api/fitness-assessments (T-0303 design.md §3.1).
 * Only `assessedOn` is required; all eight measurements and the notes are
 * optional, but at least one measurement must be set
 * (App\Validator\AtLeastOneMeasurement). A missing or wrongly typed field
 * is reported as 422 by the payload resolver, not as 400.
 */
#[OA\Schema(required: ['assessedOn'])]
#[AtLeastOneMeasurement]
final readonly class FitnessAssessmentRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'fitness.assessed_on.blank')]
        #[Assert\Date(message: 'fitness.assessed_on.blank')]
        #[NotInFuture(mode: 'date', message: 'fitness.assessed_on.future')]
        #[OA\Property(description: 'Date of the test, day precision; at most one test per day', format: 'date', example: '2026-09-08')]
        public string $assessedOn,

        #[Assert\Range(min: 0, max: 500, notInRangeMessage: 'fitness.push_ups.range')]
        #[OA\Property(description: 'Maximum push-ups in one set, 0 to 500', example: 24, nullable: true)]
        public ?int $pushUpsMax = null,

        #[Assert\Range(min: 0, max: 1000, notInRangeMessage: 'fitness.squats.range')]
        #[OA\Property(description: 'Maximum bodyweight squats in one set, 0 to 1000', example: 40, nullable: true)]
        public ?int $squatsMax = null,

        #[Assert\Range(min: 0, max: 200, notInRangeMessage: 'fitness.ring_pull_ups.range')]
        #[OA\Property(description: 'Maximum ring pull-ups in one set, 0 to 200', example: 5, nullable: true)]
        public ?int $ringPullUpsMax = null,

        #[Assert\Range(min: 0, max: 3600, notInRangeMessage: 'fitness.plank.range')]
        #[OA\Property(description: 'Plank hold in seconds, 0 to 3600', example: 95, nullable: true)]
        public ?int $plankSeconds = null,

        #[Assert\Range(min: 0, max: 3600, notInRangeMessage: 'fitness.balance.range')]
        #[OA\Property(description: 'Single-leg balance on the left leg in seconds, 0 to 3600', example: 28, nullable: true)]
        public ?int $singleLegBalanceLeftSeconds = null,

        #[Assert\Range(min: 0, max: 3600, notInRangeMessage: 'fitness.balance.range')]
        #[OA\Property(description: 'Single-leg balance on the right leg in seconds, 0 to 3600', example: 51, nullable: true)]
        public ?int $singleLegBalanceRightSeconds = null,

        #[Assert\Range(min: 0, max: 3600, notInRangeMessage: 'fitness.wall_sit.range')]
        #[OA\Property(description: 'Wall sit hold in seconds, 0 to 3600', example: 70, nullable: true)]
        public ?int $wallSitSeconds = null,

        #[Assert\Range(min: 0, max: 400, notInRangeMessage: 'fitness.broad_jump.range')]
        #[OA\Property(description: 'Standing broad jump distance in centimetres, 0 to 400', example: 185, nullable: true)]
        public ?int $standingBroadJumpCm = null,

        #[Assert\Length(max: 2000, maxMessage: 'fitness.notes.too_long')]
        #[OA\Property(description: 'Free-form notes', example: 'Links deutlich wackliger, Bandage getragen', nullable: true)]
        public ?string $notes = null,
    ) {
    }
}
