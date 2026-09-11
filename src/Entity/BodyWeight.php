<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BodyWeightContext;
use App\Repository\BodyWeightRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One weight measurement (DATENMODELL.md, "Körper"). Rows with
 * `context = vor_session` / `nach_session` are kept in sync with their
 * `SkateSession` by App\Service\Body\BodyWeightSynchronizer; a `morgens` or
 * `sonstiges` row has no session at all (T-0103 design.md §2).
 *
 * `skateSession` is a unidirectional `ManyToOne` on purpose (T-0103 ticket,
 * "Backend-Struktur"): SkateSession gets no inverse `OneToMany` side, because
 * the skate domain has no reason to know about the body-weight history that
 * happens to reference it.
 */
#[ORM\Entity(repositoryClass: BodyWeightRepository::class)]
#[ORM\Table(name: 'body_weight')]
#[ORM\UniqueConstraint(name: 'uniq_body_weight_session_context', columns: ['skate_session_id', 'context'], options: ['where' => '(skate_session_id IS NOT NULL)'])]
#[ORM\Index(name: 'idx_body_weight_measured_on', columns: ['measured_on'])]
final class BodyWeight
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'measured_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $measuredOn;

    #[ORM\Column(name: 'measured_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $measuredAt;

    #[ORM\Column(name: 'weight_kg', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $weightKg;

    #[ORM\Column(type: Types::TEXT, enumType: BodyWeightContext::class)]
    private BodyWeightContext $context;

    #[ORM\ManyToOne(targetEntity: SkateSession::class)]
    #[ORM\JoinColumn(name: 'skate_session_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?SkateSession $skateSession;

    public function __construct(
        \DateTimeImmutable $measuredOn,
        \DateTimeImmutable $measuredAt,
        string $weightKg,
        BodyWeightContext $context,
        ?SkateSession $skateSession,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->measuredOn = $measuredOn;
        $this->measuredAt = $measuredAt;
        $this->weightKg = $weightKg;
        $this->context = $context;
        $this->skateSession = $skateSession;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMeasuredOn(): \DateTimeImmutable
    {
        return $this->measuredOn;
    }

    public function setMeasuredOn(\DateTimeImmutable $measuredOn): void
    {
        $this->measuredOn = $measuredOn;
    }

    public function getMeasuredAt(): \DateTimeImmutable
    {
        return $this->measuredAt;
    }

    public function setMeasuredAt(\DateTimeImmutable $measuredAt): void
    {
        $this->measuredAt = $measuredAt;
    }

    /**
     * Doctrine's `numeric`/`decimal` DBAL type always returns a string in
     * PHP, never a float (mirrors SkateSession::getWeightBeforeKg()).
     */
    public function getWeightKg(): string
    {
        return $this->weightKg;
    }

    public function setWeightKg(string $weightKg): void
    {
        $this->weightKg = $weightKg;
    }

    public function getContext(): BodyWeightContext
    {
        return $this->context;
    }

    public function getSkateSession(): ?SkateSession
    {
        return $this->skateSession;
    }
}
