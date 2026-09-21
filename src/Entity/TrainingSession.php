<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainingSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One logged training session, recorded as a single unit together with all
 * of its sets (DATENMODELL.md, "Training", "training_session"; T-0302
 * design.md §2). Derived values (setCount, exerciseCount, maxKneeLoad) are
 * never stored here, only computed in the response - same convention as
 * App\Entity\SkateSession.
 */
#[ORM\Entity(repositoryClass: TrainingSessionRepository::class)]
#[ORM\Table(name: 'training_session')]
#[ORM\Index(name: 'idx_training_session_date', columns: ['session_date'])]
final class TrainingSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'session_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $sessionDate;

    #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT)]
    private int $durationMinutes;

    #[ORM\Column(name: 'perceived_exertion', type: Types::SMALLINT, nullable: true)]
    private ?int $perceivedExertion;

    #[ORM\Column(name: 'knee_pain', type: Types::SMALLINT, nullable: true)]
    private ?int $kneePain;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, TrainingSet>
     */
    #[ORM\OneToMany(targetEntity: TrainingSet::class, mappedBy: 'trainingSession', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $sets;

    public function __construct(
        \DateTimeImmutable $sessionDate,
        int $durationMinutes,
        ?int $perceivedExertion,
        ?int $kneePain,
        ?string $notes,
        \DateTimeImmutable $createdAt,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->sessionDate = $sessionDate;
        $this->durationMinutes = $durationMinutes;
        $this->perceivedExertion = $perceivedExertion;
        $this->kneePain = $kneePain;
        $this->notes = $notes;
        $this->createdAt = $createdAt;
        $this->sets = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSessionDate(): \DateTimeImmutable
    {
        return $this->sessionDate;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function getPerceivedExertion(): ?int
    {
        return $this->perceivedExertion;
    }

    public function getKneePain(): ?int
    {
        return $this->kneePain;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, TrainingSet>
     */
    public function getSets(): Collection
    {
        return $this->sets;
    }

    public function addSet(TrainingSet $set): void
    {
        if ($this->sets->contains($set)) {
            return;
        }

        $this->sets->add($set);
    }
}
