<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Habit;

/**
 * Narrow seam so App\Service\Habit\HabitCatalogSynchronizer can be unit-tested
 * without a kernel. App\Repository\HabitRepository is `final`, so PHPUnit
 * cannot mock it (same pattern as App\Repository\ExerciseSlugProviderInterface).
 */
interface HabitCatalogStoreInterface
{
    /**
     * Every row, active and inactive, keyed by slug.
     *
     * @return array<string, Habit>
     */
    public function findAllIndexedBySlug(): array;

    public function add(Habit $habit): void;

    /**
     * Writes all added and changed rows in one transaction.
     */
    public function flush(): void;
}
