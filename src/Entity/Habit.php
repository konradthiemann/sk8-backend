<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Repository\HabitRepository;
use App\Service\Habit\HabitDefinition;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One habit of the curated catalog (T-0401 design.md §2). Curated master
 * data, not user content: there is no write path through the API, only
 * App\Command\HabitsSyncCommand creates or changes rows, from
 * App\Service\Habit\HabitCatalog. Rows are never deleted, a habit that leaves
 * the catalog is deactivated.
 *
 * The four CHECK constraints (`chk_habit_scale`, `chk_habit_duration_unit`,
 * `chk_habit_target`, `chk_habit_sort_order`) exist only in the migration,
 * Doctrine cannot map them.
 */
#[ORM\Entity(repositoryClass: HabitRepository::class)]
#[ORM\Table(name: 'habit')]
#[ORM\UniqueConstraint(name: 'uniq_habit_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_habit_active_sort', columns: ['is_active', 'sort_order'])]
final class Habit
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(name: 'value_type', type: Types::TEXT, enumType: HabitValueType::class)]
    private HabitValueType $valueType;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $unit;

    #[ORM\Column(name: 'scale_min', type: Types::SMALLINT, nullable: true)]
    private ?int $scaleMin;

    #[ORM\Column(name: 'scale_max', type: Types::SMALLINT, nullable: true)]
    private ?int $scaleMax;

    #[ORM\Column(name: 'target_direction', type: Types::TEXT, nullable: true, enumType: HabitTargetDirection::class)]
    private ?HabitTargetDirection $targetDirection;

    /**
     * DECIMAL(8,2) as Doctrine hands it out: a string like '8.00', never a float.
     */
    #[ORM\Column(name: 'target_value', type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $targetValue;

    #[ORM\Column(name: 'sort_order', type: Types::SMALLINT)]
    private int $sortOrder;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive;

    public function __construct(
        string $slug,
        string $name,
        HabitValueType $valueType,
        ?string $unit,
        ?int $scaleMin,
        ?int $scaleMax,
        ?HabitTargetDirection $targetDirection,
        ?string $targetValue,
        int $sortOrder,
        bool $isActive = true,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->valueType = $valueType;
        $this->unit = $unit;
        $this->scaleMin = $scaleMin;
        $this->scaleMax = $scaleMax;
        $this->targetDirection = $targetDirection;
        $this->targetValue = $targetValue;
        $this->sortOrder = $sortOrder;
        $this->isActive = $isActive;
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

    public function getValueType(): HabitValueType
    {
        return $this->valueType;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getScaleMin(): ?int
    {
        return $this->scaleMin;
    }

    public function getScaleMax(): ?int
    {
        return $this->scaleMax;
    }

    public function getTargetDirection(): ?HabitTargetDirection
    {
        return $this->targetDirection;
    }

    public function getTargetValue(): ?string
    {
        return $this->targetValue;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Names the definition fields this row differs in, plus `isActive` when
     * the row is inactive (a defined slug must be active). Changes nothing:
     * a dry run must not leave modified entities in Doctrine's identity map.
     * `targetValue` is compared as the two-decimal string.
     *
     * @return list<string>
     */
    public function differingFields(HabitDefinition $definition): array
    {
        $fields = [];

        if ($this->name !== $definition->name) {
            $fields[] = 'name';
        }

        if ($this->valueType !== $definition->valueType) {
            $fields[] = 'valueType';
        }

        if ($this->unit !== $definition->unit) {
            $fields[] = 'unit';
        }

        if ($this->scaleMin !== $definition->scaleMin) {
            $fields[] = 'scaleMin';
        }

        if ($this->scaleMax !== $definition->scaleMax) {
            $fields[] = 'scaleMax';
        }

        if ($this->targetDirection !== $definition->targetDirection) {
            $fields[] = 'targetDirection';
        }

        if ($this->targetValue !== $definition->targetValueAsDecimal()) {
            $fields[] = 'targetValue';
        }

        if ($this->sortOrder !== $definition->sortOrder) {
            $fields[] = 'sortOrder';
        }

        if (!$this->isActive) {
            $fields[] = 'isActive';
        }

        return $fields;
    }

    /**
     * Applies every definition field (`slug` and `id` stay) and reactivates the row.
     */
    public function updateFrom(HabitDefinition $definition): void
    {
        $this->name = $definition->name;
        $this->valueType = $definition->valueType;
        $this->unit = $definition->unit;
        $this->scaleMin = $definition->scaleMin;
        $this->scaleMax = $definition->scaleMax;
        $this->targetDirection = $definition->targetDirection;
        $this->targetValue = $definition->targetValueAsDecimal();
        $this->sortOrder = $definition->sortOrder;
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }
}
