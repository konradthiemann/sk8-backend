<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Repository\HabitRepository;
use App\Tests\Factory\HabitEntryFactory;
use App\Tests\Factory\HabitFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test). The two
 * other lookups T-0402 adds to `HabitRepository` (design.md §4.2 and §4.5):
 * `findHabitById()`, which the entry service uses, and
 * `findSlugsWithEntries()`, the read behind the sync guard (a habit with
 * entries keeps its value type, unit and scale).
 */
final class HabitRepositoryLookupTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager()->createQuery('DELETE FROM App\Entity\Habit h')->execute();
    }

    public function testItFindsAHabitById(): void
    {
        $habit = HabitFactory::new()->duration()->create(['slug' => 'sleep-duration']);
        $this->entityManager()->clear();

        $found = $this->repository()->findHabitById($habit->getId()->toRfc4122());

        self::assertNotNull($found);
        self::assertSame('sleep-duration', $found->getSlug());
        self::assertSame($habit->getId()->toRfc4122(), $found->getId()->toRfc4122());
    }

    public function testItFindsAnInactiveHabitByIdToo(): void
    {
        // Whether an inactive habit may be written to is the service's decision, not the lookup's.
        $habit = HabitFactory::new()->duration()->inactive()->create();
        $this->entityManager()->clear();

        $found = $this->repository()->findHabitById($habit->getId()->toRfc4122());

        self::assertNotNull($found);
        self::assertFalse($found->isActive());
    }

    public function testItReturnsNullForAnUnknownId(): void
    {
        HabitFactory::createOne();

        self::assertNull($this->repository()->findHabitById(Uuid::v7()->toRfc4122()));
    }

    public function testItListsTheSlugsOfHabitsThatHaveEntries(): void
    {
        $withEntry = HabitFactory::new()->scale()->create(['slug' => 'with-entry']);
        HabitFactory::new()->scale()->create(['slug' => 'without-entry']);
        HabitEntryFactory::createOne(['habit' => $withEntry]);
        $this->entityManager()->clear();

        self::assertSame(['with-entry'], $this->repository()->findSlugsWithEntries());
    }

    public function testItListsASlugOnlyOnceHoweverManyEntriesItHas(): void
    {
        $habit = HabitFactory::new()->scale()->create(['slug' => 'busy']);
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-06')]);
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-07')]);
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-08')]);
        $this->entityManager()->clear();

        self::assertSame(['busy'], $this->repository()->findSlugsWithEntries());
    }

    public function testItListsInactiveHabitsWithEntriesToo(): void
    {
        $retired = HabitFactory::new()->scale()->inactive()->create(['slug' => 'retired']);
        HabitEntryFactory::createOne(['habit' => $retired]);
        $this->entityManager()->clear();

        self::assertSame(['retired'], $this->repository()->findSlugsWithEntries());
    }

    public function testItListsNothingWhenNoHabitHasEntries(): void
    {
        HabitFactory::createOne();

        self::assertSame([], $this->repository()->findSlugsWithEntries());
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
