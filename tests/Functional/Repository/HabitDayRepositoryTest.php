<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Repository\HabitDayRow;
use App\Repository\HabitRepository;
use App\Tests\Factory\HabitEntryFactory;
use App\Tests\Factory\HabitFactory;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test, pattern of
 * TrainingSessionRepositoryTest). `HabitRepository::findActiveWithEntry()`
 * (T-0402 design.md §3.3): one LEFT JOIN query whose flat Doctrine result
 * (`[Habit, Entry|null, Habit, ...]`, no pairs) the repository must pair up by
 * the foreign key. Only a real EntityManager against a real schema can prove
 * the pairing, the order and the single round trip.
 *
 * The entity manager is cleared before every call, so nothing comes from the
 * identity map that the fixtures filled. The query count is read off the
 * profiler's debug data holder (`doctrine.debug_data_holder`), the same way as
 * in TrainingSessionRepositoryTest.
 */
final class HabitDayRepositoryTest extends KernelTestCase
{
    use Factories;

    private const string DAY = '2026-09-08';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager()->createQuery('DELETE FROM App\Entity\Habit h')->execute();
    }

    public function testItPairsEveryActiveHabitWithTheEntryOfThatDay(): void
    {
        $first = HabitFactory::new()->scale()->create(['slug' => 'first', 'sortOrder' => 10]);
        $second = HabitFactory::new()->scale()->create(['slug' => 'second', 'sortOrder' => 20]);
        $third = HabitFactory::new()->scale()->create(['slug' => 'third', 'sortOrder' => 30]);
        $firstEntry = HabitEntryFactory::createOne(['habit' => $first, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $thirdEntry = HabitEntryFactory::createOne(['habit' => $third, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $this->entityManager()->clear();

        $rows = $this->repository()->findActiveWithEntry(new \DateTimeImmutable(self::DAY));

        self::assertSame(['first', 'second', 'third'], $this->slugs($rows));
        self::assertSame($firstEntry->getId()->toRfc4122(), $rows[0]->entry?->getId()->toRfc4122());
        self::assertNull($rows[1]->entry);
        self::assertSame($thirdEntry->getId()->toRfc4122(), $rows[2]->entry?->getId()->toRfc4122());
        self::assertSame($second->getId()->toRfc4122(), $rows[1]->habit->getId()->toRfc4122());
    }

    public function testItLeavesOutInactiveHabitsEvenWhenTheyHaveAnEntryThatDay(): void
    {
        $active = HabitFactory::new()->scale()->create(['slug' => 'active-one', 'sortOrder' => 20]);
        $retired = HabitFactory::new()->scale()->inactive()->create(['slug' => 'retired', 'sortOrder' => 10]);
        HabitEntryFactory::createOne(['habit' => $active, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        HabitEntryFactory::createOne(['habit' => $retired, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $this->entityManager()->clear();

        $rows = $this->repository()->findActiveWithEntry(new \DateTimeImmutable(self::DAY));

        self::assertSame(['active-one'], $this->slugs($rows));
    }

    public function testItIgnoresTheEntriesOfOtherDays(): void
    {
        $habit = HabitFactory::new()->scale()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-07')]);
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-09')]);
        $this->entityManager()->clear();

        $rows = $this->repository()->findActiveWithEntry(new \DateTimeImmutable(self::DAY));

        self::assertCount(1, $rows, 'a habit without an entry that day stays in the result');
        self::assertNull($rows[0]->entry);
    }

    public function testItPicksTheEntryOfTheRequestedDayAmongSeveralOfTheSameHabit(): void
    {
        $habit = HabitFactory::new()->scale()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-07'), 'valueNumeric' => '1.00']);
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY), 'valueNumeric' => '4.00']);
        $this->entityManager()->clear();

        $rows = $this->repository()->findActiveWithEntry(new \DateTimeImmutable(self::DAY));

        self::assertSame('4.00', $rows[0]->entry?->getValueNumeric());
    }

    public function testItHandsOutAZeroValueAsAnEntry(): void
    {
        $habit = HabitFactory::new()->number()->create();
        HabitEntryFactory::new()->withNumeric('0.00')->create(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $this->entityManager()->clear();

        $rows = $this->repository()->findActiveWithEntry(new \DateTimeImmutable(self::DAY));

        self::assertNotNull($rows[0]->entry);
        self::assertSame('0.00', $rows[0]->entry->getValueNumeric());
    }

    public function testItSortsBySortOrderThenNameThenSlug(): void
    {
        HabitFactory::createOne(['slug' => 'c-slug', 'name' => 'same', 'sortOrder' => 20]);
        HabitFactory::createOne(['slug' => 'a-slug', 'name' => 'same', 'sortOrder' => 20]);
        HabitFactory::createOne(['slug' => 'b-slug', 'name' => 'aaa', 'sortOrder' => 20]);
        HabitFactory::createOne(['slug' => 'late', 'name' => 'aaa', 'sortOrder' => 30]);
        HabitFactory::createOne(['slug' => 'early', 'name' => 'zzz', 'sortOrder' => 10]);
        $this->entityManager()->clear();

        $rows = $this->repository()->findActiveWithEntry(new \DateTimeImmutable(self::DAY));

        self::assertSame(['early', 'b-slug', 'a-slug', 'c-slug', 'late'], $this->slugs($rows));
    }

    public function testItReturnsAnEmptyListForAnEmptyCatalog(): void
    {
        self::assertSame([], $this->repository()->findActiveWithEntry(new \DateTimeImmutable(self::DAY)));
    }

    public function testItReadsHabitsAndEntriesInASingleQuery(): void
    {
        // No N+1: the whole day is one round trip, and touching the results triggers no lazy load.
        $habits = [];
        for ($position = 1; $position <= 4; ++$position) {
            $habits[] = HabitFactory::new()->scale()->create(['sortOrder' => 10 * $position]);
        }
        HabitEntryFactory::createOne(['habit' => $habits[0], 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        HabitEntryFactory::createOne(['habit' => $habits[2], 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $this->entityManager()->clear();
        $repository = $this->repository();

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $rows = $repository->findActiveWithEntry(new \DateTimeImmutable(self::DAY));
        $touched = [];
        foreach ($rows as $row) {
            $touched[] = [$row->habit->getSlug(), $row->entry?->getHabit()->getSlug(), $row->entry?->getValueNumeric()];
        }

        self::assertCount(4, $touched);
        /** @var array<string, list<array<string, mixed>>> $data */
        $data = $holder->getData();
        self::assertCount(1, $data['default'] ?? [], 'findActiveWithEntry() must load habits and entries in a single query');
    }

    /**
     * @param list<HabitDayRow> $rows
     *
     * @return list<string>
     */
    private function slugs(array $rows): array
    {
        return array_map(static fn (HabitDayRow $row): string => $row->habit->getSlug(), $rows);
    }

    private function repository(): HabitRepository
    {
        $repository = static::getContainer()->get(HabitRepository::class);
        self::assertInstanceOf(HabitRepository::class, $repository);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
