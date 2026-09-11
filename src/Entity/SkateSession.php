<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SkateSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One practiced skate session (DATENMODELL.md, "skate_session"). Derived
 * values (success rate, fluid loss, totals) are never stored here, only
 * computed in the response by App\Service\Skate\SessionMetrics (T-0102
 * design.md §1): a stored rate would silently go stale after a trick row's
 * numbers are corrected later.
 */
#[ORM\Entity(repositoryClass: SkateSessionRepository::class)]
#[ORM\Table(name: 'skate_session')]
#[ORM\Index(name: 'idx_skate_session_date', columns: ['session_date'])]
final class SkateSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'session_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $sessionDate;

    #[ORM\Column(name: 'started_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'duration_minutes', type: Types::SMALLINT)]
    private int $durationMinutes;

    #[ORM\Column(type: Types::TEXT)]
    private string $location;

    #[ORM\Column(name: 'weight_before_kg', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $weightBeforeKg;

    #[ORM\Column(name: 'weight_after_kg', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $weightAfterKg;

    #[ORM\Column(name: 'perceived_exertion', type: Types::SMALLINT, nullable: true)]
    private ?int $perceivedExertion;

    #[ORM\Column(name: 'knee_pain', type: Types::SMALLINT, nullable: true)]
    private ?int $kneePain;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, SessionTrick>
     */
    #[ORM\OneToMany(targetEntity: SessionTrick::class, mappedBy: 'skateSession', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $tricks;

    public function __construct(
        \DateTimeImmutable $sessionDate,
        ?\DateTimeImmutable $startedAt,
        int $durationMinutes,
        string $location,
        ?string $weightBeforeKg,
        ?string $weightAfterKg,
        ?int $perceivedExertion,
        ?int $kneePain,
        ?string $notes,
        \DateTimeImmutable $createdAt,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->sessionDate = $sessionDate;
        $this->startedAt = $startedAt;
        $this->durationMinutes = $durationMinutes;
        $this->location = $location;
        $this->weightBeforeKg = $weightBeforeKg;
        $this->weightAfterKg = $weightAfterKg;
        $this->perceivedExertion = $perceivedExertion;
        $this->kneePain = $kneePain;
        $this->notes = $notes;
        $this->createdAt = $createdAt;
        $this->tricks = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSessionDate(): \DateTimeImmutable
    {
        return $this->sessionDate;
    }

    public function setSessionDate(\DateTimeImmutable $sessionDate): void
    {
        $this->sessionDate = $sessionDate;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): void
    {
        $this->durationMinutes = $durationMinutes;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): void
    {
        $this->location = $location;
    }

    /**
     * Doctrine's `numeric`/`decimal` DBAL type always returns a string in
     * PHP, never a float - see design.md "Nachkommastellen". Converting to
     * float happens exactly once, in App\Service\Skate\SessionMetrics.
     */
    public function getWeightBeforeKg(): ?string
    {
        return $this->weightBeforeKg;
    }

    public function setWeightBeforeKg(?string $weightBeforeKg): void
    {
        $this->weightBeforeKg = $weightBeforeKg;
    }

    public function getWeightAfterKg(): ?string
    {
        return $this->weightAfterKg;
    }

    public function setWeightAfterKg(?string $weightAfterKg): void
    {
        $this->weightAfterKg = $weightAfterKg;
    }

    public function getPerceivedExertion(): ?int
    {
        return $this->perceivedExertion;
    }

    public function setPerceivedExertion(?int $perceivedExertion): void
    {
        $this->perceivedExertion = $perceivedExertion;
    }

    public function getKneePain(): ?int
    {
        return $this->kneePain;
    }

    public function setKneePain(?int $kneePain): void
    {
        $this->kneePain = $kneePain;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, SessionTrick>
     */
    public function getTricks(): Collection
    {
        return $this->tricks;
    }

    public function addTrick(SessionTrick $trick): void
    {
        if ($this->tricks->contains($trick)) {
            return;
        }

        $this->tricks->add($trick);
    }

    /**
     * Removing via the Collection API (rather than deleting the row
     * directly) is what makes `orphanRemoval: true` delete it on flush -
     * this is how PUT drops a trick row whose slug is no longer submitted
     * (T-0102 design.md, "PUT-Trick-Abgleich").
     */
    public function removeTrick(SessionTrick $trick): void
    {
        $this->tricks->removeElement($trick);
    }
}
