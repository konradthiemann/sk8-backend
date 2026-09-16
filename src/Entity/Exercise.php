<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Equipment;
use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;
use App\Repository\ExerciseRepository;
use App\Service\Training\ExerciseCatalogEntry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One exercise of the curated training catalog (DATENMODELL.md, "Training",
 * T-0301 design.md §2). Curated master data, not user content: there is
 * deliberately no write path through the API - only App\Command\
 * SyncExercisesCommand creates or updates rows, from config/data/exercises.json.
 */
#[ORM\Entity(repositoryClass: ExerciseRepository::class)]
#[ORM\Table(name: 'exercise')]
#[ORM\UniqueConstraint(name: 'uniq_exercise_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_exercise_knee_load', columns: ['knee_load'])]
final class Exercise
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, enumType: Equipment::class)]
    private Equipment $equipment;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $muscleGroups;

    #[ORM\Column(type: Types::TEXT, enumType: KneeLoad::class)]
    private KneeLoad $kneeLoad;

    #[ORM\Column(type: Types::TEXT, enumType: ExerciseMeasure::class)]
    private ExerciseMeasure $measure;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isPrevention;

    /**
     * @param list<string> $muscleGroups
     */
    public function __construct(
        string $slug,
        string $name,
        Equipment $equipment,
        array $muscleGroups,
        KneeLoad $kneeLoad,
        ExerciseMeasure $measure,
        ?string $description,
        bool $isPrevention,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->equipment = $equipment;
        $this->muscleGroups = $muscleGroups;
        $this->kneeLoad = $kneeLoad;
        $this->measure = $measure;
        $this->description = $description;
        $this->isPrevention = $isPrevention;
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

    public function getEquipment(): Equipment
    {
        return $this->equipment;
    }

    /**
     * @return list<string>
     */
    public function getMuscleGroups(): array
    {
        return $this->muscleGroups;
    }

    public function getKneeLoad(): KneeLoad
    {
        return $this->kneeLoad;
    }

    public function getMeasure(): ExerciseMeasure
    {
        return $this->measure;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isPrevention(): bool
    {
        return $this->isPrevention;
    }

    /**
     * Applies every mutable field from a freshly loaded catalog entry
     * (`slug` is the immutable lookup key, never touched here). Used by
     * App\Command\SyncExercisesCommand to diff and update a row without
     * duplicating field-by-field comparison logic in the command itself.
     *
     * @return bool whether any field actually changed
     */
    public function updateFrom(ExerciseCatalogEntry $entry): bool
    {
        $changed = false;

        if ($this->name !== $entry->name) {
            $this->name = $entry->name;
            $changed = true;
        }

        if ($this->equipment !== $entry->equipment) {
            $this->equipment = $entry->equipment;
            $changed = true;
        }

        if ($this->muscleGroups !== $entry->muscleGroups) {
            $this->muscleGroups = $entry->muscleGroups;
            $changed = true;
        }

        if ($this->kneeLoad !== $entry->kneeLoad) {
            $this->kneeLoad = $entry->kneeLoad;
            $changed = true;
        }

        if ($this->measure !== $entry->measure) {
            $this->measure = $entry->measure;
            $changed = true;
        }

        if ($this->description !== $entry->description) {
            $this->description = $entry->description;
            $changed = true;
        }

        if ($this->isPrevention !== $entry->isPrevention) {
            $this->isPrevention = $entry->isPrevention;
            $changed = true;
        }

        return $changed;
    }
}
