<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Habit;

/**
 * Read seam of the habit entry services, so they can be unit-tested without a
 * kernel. App\Repository\HabitRepository is `final`, so PHPUnit cannot mock it
 * (same pattern as HabitCatalogStoreInterface).
 */
interface HabitReaderInterface
{
    /**
     * Active and inactive habits alike; `null` when the ID is unknown.
     */
    public function findHabitById(string $habitId): ?Habit;

    /**
     * Active habits ascending by sort order, name, slug, each with its entry of
     * exactly this day or `null`. One database query.
     *
     * @return list<HabitDayRow>
     */
    public function findActiveWithEntry(\DateTimeImmutable $date): array;
}
