<?php

declare(strict_types=1);

namespace App\Dto\Training;

use App\Entity\FitnessAssessment;
use App\Service\Training\BalanceDifference;
use OpenApi\Attributes as OA;

/**
 * One fitness assessment as returned by POST (201) and by
 * GET /api/fitness-assessments (FitnessAssessmentListResponse.items). All
 * fields are always present, missing measurements are `null`.
 * balanceDifferenceSeconds and weakerBalanceSide are derived here, in the
 * one and only place, never stored (DATENMODELL.md: "abgeleitete Werte
 * gehören nicht in die Tabelle").
 */
final readonly class FitnessAssessmentView
{
    public function __construct(
        #[OA\Property(description: 'Assessment ID', example: '0192f5c0-1d44-7a88-9b02-77ce1a4b0e93')]
        public string $id,
        #[OA\Property(description: 'Date of the test, day precision', format: 'date', example: '2026-09-08')]
        public string $assessedOn,
        #[OA\Property(description: 'Maximum push-ups in one set', example: 24, nullable: true)]
        public ?int $pushUpsMax,
        #[OA\Property(description: 'Maximum bodyweight squats in one set', example: 40, nullable: true)]
        public ?int $squatsMax,
        #[OA\Property(description: 'Maximum ring pull-ups in one set', example: 5, nullable: true)]
        public ?int $ringPullUpsMax,
        #[OA\Property(description: 'Plank hold in seconds', example: 95, nullable: true)]
        public ?int $plankSeconds,
        #[OA\Property(description: 'Single-leg balance on the left leg in seconds', example: 28, nullable: true)]
        public ?int $singleLegBalanceLeftSeconds,
        #[OA\Property(description: 'Single-leg balance on the right leg in seconds', example: 51, nullable: true)]
        public ?int $singleLegBalanceRightSeconds,
        #[OA\Property(description: 'Wall sit hold in seconds', example: 70, nullable: true)]
        public ?int $wallSitSeconds,
        #[OA\Property(description: 'Standing broad jump distance in centimetres', example: 185, nullable: true)]
        public ?int $standingBroadJumpCm,
        #[OA\Property(description: 'Free-form notes', example: 'Links deutlich wackliger, Bandage getragen', nullable: true)]
        public ?string $notes,
        #[OA\Property(description: 'Derived: absolute difference between the two single-leg balance values in seconds; null if one side is missing', example: 23, nullable: true)]
        public ?int $balanceDifferenceSeconds,
        #[OA\Property(description: 'Derived: the side with the shorter balance time; null on a tie or if one side is missing', enum: ['links', 'rechts'], example: 'links', nullable: true)]
        public ?string $weakerBalanceSide,
    ) {
    }

    public static function fromEntity(FitnessAssessment $assessment): self
    {
        $balance = BalanceDifference::between(
            $assessment->getSingleLegBalanceLeftSeconds(),
            $assessment->getSingleLegBalanceRightSeconds(),
        );

        return new self(
            $assessment->getId()->toRfc4122(),
            $assessment->getAssessedOn()->format('Y-m-d'),
            $assessment->getPushUpsMax(),
            $assessment->getSquatsMax(),
            $assessment->getRingPullUpsMax(),
            $assessment->getPlankSeconds(),
            $assessment->getSingleLegBalanceLeftSeconds(),
            $assessment->getSingleLegBalanceRightSeconds(),
            $assessment->getWallSitSeconds(),
            $assessment->getStandingBroadJumpCm(),
            $assessment->getNotes(),
            $balance->seconds,
            $balance->weakerSide?->value,
        );
    }
}
