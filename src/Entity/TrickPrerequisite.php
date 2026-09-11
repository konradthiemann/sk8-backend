<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One direct prerequisite edge of the trick graph: `trick` requires
 * `requiresTrick`. Only direct edges are stored (transitive reduction, see
 * design.md); there is no write path in this epic (T-0101).
 */
#[ORM\Entity]
#[ORM\Table(name: 'trick_prerequisite')]
#[ORM\UniqueConstraint(name: 'uniq_trick_prerequisite', columns: ['trick_id', 'requires_trick_id'])]
#[ORM\Index(name: 'idx_trick_prerequisite_requires', columns: ['requires_trick_id'])]
final class TrickPrerequisite
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Trick::class, inversedBy: 'prerequisites')]
    #[ORM\JoinColumn(name: 'trick_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Trick $trick;

    #[ORM\ManyToOne(targetEntity: Trick::class)]
    #[ORM\JoinColumn(name: 'requires_trick_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Trick $requiresTrick;

    public function __construct(Trick $trick, Trick $requiresTrick)
    {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->trick = $trick;
        $this->requiresTrick = $requiresTrick;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrick(): Trick
    {
        return $this->trick;
    }

    public function getRequiresTrick(): Trick
    {
        return $this->requiresTrick;
    }
}
