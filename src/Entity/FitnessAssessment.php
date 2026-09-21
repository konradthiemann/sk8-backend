<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FitnessAssessmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One fitness baseline test: a date plus up to eight optional measurements
 * (DATENMODELL.md, "Training", "fitness_assessment"; T-0303 design.md §2).
 * At most one test exists per day (unique index). Immutable by design: no
 * setters, a wrongly logged test is deleted and re-entered. Derived values
 * (the balance difference between the two legs) are never stored here, only
 * computed in the response.
 */
#[ORM\Entity(repositoryClass: FitnessAssessmentRepository::class)]
#[ORM\Table(name: 'fitness_assessment')]
#[ORM\UniqueConstraint(name: 'uniq_fitness_assessment_assessed_on', columns: ['assessed_on'])]
final class FitnessAssessment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'assessed_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $assessedOn;

    #[ORM\Column(name: 'push_ups_max', type: Types::SMALLINT, nullable: true)]
    private ?int $pushUpsMax;

    #[ORM\Column(name: 'squats_max', type: Types::SMALLINT, nullable: true)]
    private ?int $squatsMax;

    #[ORM\Column(name: 'ring_pull_ups_max', type: Types::SMALLINT, nullable: true)]
    private ?int $ringPullUpsMax;

    #[ORM\Column(name: 'plank_seconds', type: Types::SMALLINT, nullable: true)]
    private ?int $plankSeconds;

    #[ORM\Column(name: 'single_leg_balance_left_seconds', type: Types::SMALLINT, nullable: true)]
    private ?int $singleLegBalanceLeftSeconds;

    #[ORM\Column(name: 'single_leg_balance_right_seconds', type: Types::SMALLINT, nullable: true)]
    private ?int $singleLegBalanceRightSeconds;

    #[ORM\Column(name: 'wall_sit_seconds', type: Types::SMALLINT, nullable: true)]
    private ?int $wallSitSeconds;

    #[ORM\Column(name: 'standing_broad_jump_cm', type: Types::SMALLINT, nullable: true)]
    private ?int $standingBroadJumpCm;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    public function __construct(
        \DateTimeImmutable $assessedOn,
        ?int $pushUpsMax,
        ?int $squatsMax,
        ?int $ringPullUpsMax,
        ?int $plankSeconds,
        ?int $singleLegBalanceLeftSeconds,
        ?int $singleLegBalanceRightSeconds,
        ?int $wallSitSeconds,
        ?int $standingBroadJumpCm,
        ?string $notes,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->assessedOn = $assessedOn;
        $this->pushUpsMax = $pushUpsMax;
        $this->squatsMax = $squatsMax;
        $this->ringPullUpsMax = $ringPullUpsMax;
        $this->plankSeconds = $plankSeconds;
        $this->singleLegBalanceLeftSeconds = $singleLegBalanceLeftSeconds;
        $this->singleLegBalanceRightSeconds = $singleLegBalanceRightSeconds;
        $this->wallSitSeconds = $wallSitSeconds;
        $this->standingBroadJumpCm = $standingBroadJumpCm;
        $this->notes = $notes;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAssessedOn(): \DateTimeImmutable
    {
        return $this->assessedOn;
    }

    public function getPushUpsMax(): ?int
    {
        return $this->pushUpsMax;
    }

    public function getSquatsMax(): ?int
    {
        return $this->squatsMax;
    }

    public function getRingPullUpsMax(): ?int
    {
        return $this->ringPullUpsMax;
    }

    public function getPlankSeconds(): ?int
    {
        return $this->plankSeconds;
    }

    public function getSingleLegBalanceLeftSeconds(): ?int
    {
        return $this->singleLegBalanceLeftSeconds;
    }

    public function getSingleLegBalanceRightSeconds(): ?int
    {
        return $this->singleLegBalanceRightSeconds;
    }

    public function getWallSitSeconds(): ?int
    {
        return $this->wallSitSeconds;
    }

    public function getStandingBroadJumpCm(): ?int
    {
        return $this->standingBroadJumpCm;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }
}
