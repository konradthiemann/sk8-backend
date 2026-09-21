<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Entity\Habit;
use App\Entity\HabitEntry;
use App\Repository\HabitEntryAlreadyExistsException;
use App\Repository\HabitEntryStoreInterface;

/**
 * In-memory test double for the write side of the habit tickets (no kernel,
 * no database; T-0402 design.md §4.3). `HabitEntryRepository` is `final`, so
 * the service depends on `HabitEntryStoreInterface` instead.
 *
 * It can replay the race the unique index `uniq_habit_entry_habit_date`
 * guards against, deterministically: set `$racingEntry` to the row another
 * request "wrote in the meantime". The row stays invisible to
 * `findByHabitAndDate()` until the first `insert()`, which then throws
 * `HabitEntryAlreadyExistsException` and does not store the new entity, just
 * like the real repository after a unique violation. With
 * `$racingEntryVanishes` the row is gone again when the service looks it up
 * after the conflict (the practically unreachable branch that answers 409).
 */
final class InMemoryHabitEntryStore implements HabitEntryStoreInterface
{
    /**
     * @var array<string, HabitEntry> rows currently "in the database", keyed by `habitId|Y-m-d`
     */
    public array $entries = [];

    public ?HabitEntry $racingEntry = null;

    public bool $racingEntryVanishes = false;

    public int $insertCount = 0;

    public int $flushCount = 0;

    public int $removeCount = 0;

    private bool $conflictRaised = false;

    /**
     * @param list<HabitEntry> $entries
     */
    public function __construct(array $entries = [])
    {
        foreach ($entries as $entry) {
            $this->entries[self::keyOf($entry)] = $entry;
        }
    }

    public function findByHabitAndDate(Habit $habit, \DateTimeImmutable $date): ?HabitEntry
    {
        if ($this->conflictRaised && $this->racingEntryVanishes) {
            return null;
        }

        return $this->entries[self::key($habit, $date)] ?? null;
    }

    public function insert(HabitEntry $entry): void
    {
        ++$this->insertCount;

        if (null !== $this->racingEntry && !$this->conflictRaised) {
            $this->conflictRaised = true;
            $this->entries[self::keyOf($this->racingEntry)] = $this->racingEntry;

            throw new HabitEntryAlreadyExistsException('the day was taken by a concurrent request');
        }

        $this->entries[self::keyOf($entry)] = $entry;
    }

    public function flush(): void
    {
        ++$this->flushCount;
    }

    public function remove(HabitEntry $entry): void
    {
        ++$this->removeCount;
        unset($this->entries[self::keyOf($entry)]);
    }

    private static function keyOf(HabitEntry $entry): string
    {
        return self::key($entry->getHabit(), $entry->getEntryDate());
    }

    private static function key(Habit $habit, \DateTimeImmutable $date): string
    {
        return $habit->getId()->toRfc4122().'|'.$date->format('Y-m-d');
    }
}
