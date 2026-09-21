<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Entity\Habit;
use App\Entity\HabitEntry;
use App\Repository\HabitDayRow;
use App\Repository\HabitReaderInterface;

/**
 * In-memory test double for the read side of the habit tickets (no kernel, no
 * database). `App\Repository\HabitRepository` is `final` and therefore not
 * mockable, so the services depend on the narrow `HabitReaderInterface`
 * (T-0402 design.md §4.2/§4.3), the same seam pattern as
 * InMemoryHabitCatalogStore.
 *
 * `findActiveWithEntry()` mirrors the real query: active habits only, ordered
 * by sort order, name and slug, each paired with the entry of exactly the
 * requested day or `null`. `$dayReadCount` is the evidence that a request
 * rejected before the read never touched the reader.
 */
final class InMemoryHabitReader implements HabitReaderInterface
{
    public int $dayReadCount = 0;

    /**
     * @param list<Habit>      $habits
     * @param list<HabitEntry> $entries
     */
    public function __construct(private readonly array $habits = [], private readonly array $entries = [])
    {
    }

    public function findHabitById(string $habitId): ?Habit
    {
        foreach ($this->habits as $habit) {
            if ($habit->getId()->toRfc4122() === $habitId) {
                return $habit;
            }
        }

        return null;
    }

    public function findActiveWithEntry(\DateTimeImmutable $date): array
    {
        ++$this->dayReadCount;

        $active = array_values(array_filter(
            $this->habits,
            static fn (Habit $habit): bool => $habit->isActive(),
        ));
        usort($active, static fn (Habit $a, Habit $b): int => [$a->getSortOrder(), $a->getName(), $a->getSlug()]
            <=> [$b->getSortOrder(), $b->getName(), $b->getSlug()]);

        return array_map(
            fn (Habit $habit): HabitDayRow => new HabitDayRow($habit, $this->entryOn($habit, $date)),
            $active,
        );
    }

    private function entryOn(Habit $habit, \DateTimeImmutable $date): ?HabitEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->getHabit()->getId()->equals($habit->getId())
                && $entry->getEntryDate()->format('Y-m-d') === $date->format('Y-m-d')) {
                return $entry;
            }
        }

        return null;
    }
}
