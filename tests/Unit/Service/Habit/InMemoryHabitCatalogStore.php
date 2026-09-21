<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Entity\Habit;
use App\Repository\HabitCatalogStoreInterface;

/**
 * In-memory test double backing HabitCatalogSynchronizerTest (no kernel, no
 * database). `App\Repository\HabitRepository` is `final` and therefore not
 * mockable, so the synchronizer depends on the narrow
 * `HabitCatalogStoreInterface` (design.md §4.1/§4.3), the same seam pattern as
 * InMemoryBodyWeightRepository.
 *
 * The public counters are the assertions' evidence: `$readCount` proves that a
 * rejected catalog never touched the store, `$added` and `$flushCount` prove
 * what a dry run did not write.
 */
final class InMemoryHabitCatalogStore implements HabitCatalogStoreInterface
{
    /**
     * @var array<string, Habit> rows currently "in the database", keyed by slug
     */
    public array $bySlug = [];

    /**
     * @var list<Habit> rows handed to add(), kept apart from the stored rows
     */
    public array $added = [];

    public int $readCount = 0;

    public int $flushCount = 0;

    /**
     * @param list<Habit> $habits
     */
    public function __construct(array $habits = [])
    {
        foreach ($habits as $habit) {
            $this->bySlug[$habit->getSlug()] = $habit;
        }
    }

    public function findAllIndexedBySlug(): array
    {
        ++$this->readCount;

        return $this->bySlug;
    }

    public function add(Habit $habit): void
    {
        $this->added[] = $habit;
    }

    public function flush(): void
    {
        ++$this->flushCount;
    }
}
