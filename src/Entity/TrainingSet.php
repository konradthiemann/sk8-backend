<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BodySide;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One set of one exercise within a training session (DATENMODELL.md,
 * "Training", "training_set"; T-0302 design.md §2). `exercise_id` is
 * `ON DELETE RESTRICT` on purpose: an exercise that already has sets logged
 * against it must not disappear unnoticed, and the T-0301 catalog sync never
 * deletes rows anyway.
 */
#[ORM\Entity]
#[ORM\Table(name: 'training_set')]
#[ORM\UniqueConstraint(name: 'uniq_training_set_session_exercise_number', columns: ['training_session_id', 'exercise_id', 'set_number'])]
#[ORM\Index(name: 'idx_training_set_session', columns: ['training_session_id'])]
final class TrainingSet
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: TrainingSession::class, inversedBy: 'sets')]
    #[ORM\JoinColumn(name: 'training_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TrainingSession $trainingSession;

    #[ORM\ManyToOne(targetEntity: Exercise::class)]
    #[ORM\JoinColumn(name: 'exercise_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Exercise $exercise;

    #[ORM\Column(name: 'set_number', type: Types::SMALLINT)]
    private int $setNumber;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $reps;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $seconds;

    #[ORM\Column(type: Types::TEXT, enumType: BodySide::class, nullable: true)]
    private ?BodySide $side;

    public function __construct(
        TrainingSession $trainingSession,
        Exercise $exercise,
        int $setNumber,
        ?int $reps,
        ?int $seconds,
        ?BodySide $side,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->trainingSession = $trainingSession;
        $this->exercise = $exercise;
        $this->setNumber = $setNumber;
        $this->reps = $reps;
        $this->seconds = $seconds;
        $this->side = $side;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrainingSession(): TrainingSession
    {
        return $this->trainingSession;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getSetNumber(): int
    {
        return $this->setNumber;
    }

    public function getReps(): ?int
    {
        return $this->reps;
    }

    public function getSeconds(): ?int
    {
        return $this->seconds;
    }

    public function getSide(): ?BodySide
    {
        return $this->side;
    }
}
