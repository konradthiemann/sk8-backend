<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One practiced trick row of a skate session (DATENMODELL.md,
 * "session_trick"). `trick_id` is `ON DELETE RESTRICT` on purpose: a catalog
 * entry that has already been practiced must not disappear unnoticed, and
 * T-0101 has no delete path for tricks anyway.
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_trick')]
#[ORM\UniqueConstraint(name: 'uniq_session_trick', columns: ['skate_session_id', 'trick_id'])]
#[ORM\Index(name: 'idx_session_trick_session', columns: ['skate_session_id'])]
final class SessionTrick
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SkateSession::class, inversedBy: 'tricks')]
    #[ORM\JoinColumn(name: 'skate_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SkateSession $skateSession;

    #[ORM\ManyToOne(targetEntity: Trick::class)]
    #[ORM\JoinColumn(name: 'trick_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Trick $trick;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempts;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $landed;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes;

    public function __construct(SkateSession $skateSession, Trick $trick, int $attempts, int $landed, ?string $notes)
    {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->skateSession = $skateSession;
        $this->trick = $trick;
        $this->attempts = $attempts;
        $this->landed = $landed;
        $this->notes = $notes;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSkateSession(): SkateSession
    {
        return $this->skateSession;
    }

    public function getTrick(): Trick
    {
        return $this->trick;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function getLanded(): int
    {
        return $this->landed;
    }

    public function setLanded(int $landed): void
    {
        $this->landed = $landed;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }
}
