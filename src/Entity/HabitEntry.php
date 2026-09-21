<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HabitEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The value of one habit on one calendar day (T-0402 design.md §2): exactly
 * one row per habit and day (unique index), and exactly one of `valueNumeric`
 * and `valueBool` is set. `0` and `false` are values; a day without a row is
 * "not recorded".
 *
 * The check constraint `chk_habit_entry_value` and the descending part of
 * `idx_habit_entry_date` exist only in the migration, Doctrine cannot map
 * them. The constructor and change() enforce the same "exactly one value"
 * rule in code, so a wrong call fails before it reaches the database.
 */
#[ORM\Entity(repositoryClass: HabitEntryRepository::class)]
#[ORM\Table(name: 'habit_entry')]
#[ORM\UniqueConstraint(name: 'uniq_habit_entry_habit_date', columns: ['habit_id', 'entry_date'])]
#[ORM\Index(name: 'idx_habit_entry_date', columns: ['entry_date'])]
final class HabitEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Habit::class)]
    #[ORM\JoinColumn(name: 'habit_id', nullable: false, onDelete: 'RESTRICT')]
    private Habit $habit;

    #[ORM\Column(name: 'entry_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $entryDate;

    /**
     * DECIMAL(8,2) as Doctrine hands it out: a string like '7.50', never a float.
     */
    #[ORM\Column(name: 'value_numeric', type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $valueNumeric;

    #[ORM\Column(name: 'value_bool', type: Types::BOOLEAN, nullable: true)]
    private ?bool $valueBool;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string|null $valueNumeric DECIMAL string such as '7.50', never a float
     * @param string|null $note         already normalized (an empty note is `null`)
     *
     * @throws \InvalidArgumentException unless exactly one of $valueNumeric and $valueBool is set
     */
    public function __construct(
        Habit $habit,
        \DateTimeImmutable $entryDate,
        ?string $valueNumeric,
        ?bool $valueBool,
        ?string $note,
        \DateTimeImmutable $createdAt,
    ) {
        self::assertExactlyOneValue($valueNumeric, $valueBool);

        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->habit = $habit;
        $this->entryDate = $entryDate;
        $this->valueNumeric = $valueNumeric;
        $this->valueBool = $valueBool;
        $this->note = $note;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHabit(): Habit
    {
        return $this->habit;
    }

    public function getEntryDate(): \DateTimeImmutable
    {
        return $this->entryDate;
    }

    public function getValueNumeric(): ?string
    {
        return $this->valueNumeric;
    }

    public function getValueBool(): ?bool
    {
        return $this->valueBool;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Overwrites the value and the note. `id`, `habit`, `entryDate` and
     * `createdAt` stay: a correction is the same entry, not a new one.
     *
     * @throws \InvalidArgumentException unless exactly one of $valueNumeric and $valueBool is set
     */
    public function change(?string $valueNumeric, ?bool $valueBool, ?string $note): void
    {
        self::assertExactlyOneValue($valueNumeric, $valueBool);

        $this->valueNumeric = $valueNumeric;
        $this->valueBool = $valueBool;
        $this->note = $note;
    }

    private static function assertExactlyOneValue(?string $valueNumeric, ?bool $valueBool): void
    {
        if ((null === $valueNumeric) === (null === $valueBool)) {
            throw new \InvalidArgumentException('Exactly one of valueNumeric and valueBool must be set.');
        }
    }
}
