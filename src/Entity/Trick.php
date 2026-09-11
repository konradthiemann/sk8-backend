<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrickCategory;
use App\Repository\TrickRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One trick of the fixed catalog (DATENMODELL.md, "Skateboard"). Seeded by a
 * data migration; there is no write path in this epic (T-0101).
 */
#[ORM\Entity(repositoryClass: TrickRepository::class)]
#[ORM\Table(name: 'trick')]
#[ORM\UniqueConstraint(name: 'uniq_trick_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_trick_goal_order', columns: ['goal_order'], options: ['where' => '(goal_order IS NOT NULL)'])]
#[ORM\Index(name: 'idx_trick_difficulty', columns: ['difficulty'])]
final class Trick
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, enumType: TrickCategory::class)]
    private TrickCategory $category;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $difficulty;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isGoal;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $goalOrder;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, TrickPrerequisite>
     */
    #[ORM\OneToMany(targetEntity: TrickPrerequisite::class, mappedBy: 'trick')]
    private Collection $prerequisites;

    public function __construct(
        string $slug,
        string $name,
        TrickCategory $category,
        int $difficulty,
        ?string $description,
        bool $isGoal,
        ?int $goalOrder,
        \DateTimeImmutable $createdAt,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->category = $category;
        $this->difficulty = $difficulty;
        $this->description = $description;
        $this->isGoal = $isGoal;
        $this->goalOrder = $goalOrder;
        $this->createdAt = $createdAt;
        $this->prerequisites = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCategory(): TrickCategory
    {
        return $this->category;
    }

    public function getDifficulty(): int
    {
        return $this->difficulty;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isGoal(): bool
    {
        return $this->isGoal;
    }

    public function getGoalOrder(): ?int
    {
        return $this->goalOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, TrickPrerequisite>
     */
    public function getPrerequisites(): Collection
    {
        return $this->prerequisites;
    }

    /**
     * Synchronizes the inverse side of the OneToMany collection. Only called
     * by tests: production data only ever arrives through Doctrine hydration,
     * which fills the collection itself.
     */
    public function addPrerequisite(TrickPrerequisite $prerequisite): void
    {
        if ($this->prerequisites->contains($prerequisite)) {
            return;
        }

        $this->prerequisites->add($prerequisite);
    }
}
