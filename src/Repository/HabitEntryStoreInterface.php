<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Habit;
use App\Entity\HabitEntry;

/**
 * Write seam of App\Service\Habit\HabitEntryService (`HabitEntryRepository` is
 * `final`, so it cannot be mocked).
 */
interface HabitEntryStoreInterface
{
    public function findByHabitAndDate(Habit $habit, \DateTimeImmutable $date): ?HabitEntry;

    /**
     * Persists and flushes the new entry. After a conflict the store is usable
     * again: the implementation has reset the closed entity manager.
     *
     * @throws HabitEntryAlreadyExistsException when the unique index refuses the insert
     */
    public function insert(HabitEntry $entry): void;

    /**
     * Writes the changes made to entries that were loaded through this store.
     */
    public function flush(): void;

    /**
     * Removes and flushes.
     */
    public function remove(HabitEntry $entry): void;
}
