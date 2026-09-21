<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\HabitEntry;
use App\Repository\HabitEntryAlreadyExistsException;
use App\Repository\HabitEntryRepository;
use App\Tests\Factory\HabitEntryFactory;
use App\Tests\Factory\HabitFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test). The write
 * side of the habit entries (T-0402 design.md §4.2): look-up, insert, flush,
 * remove, and above all what `insert()` does when the unique index fires - it
 * resets the closed entity manager and signals the conflict, so the service
 * can load and change the row the other request wrote (design.md §4.2,
 * "HabitEntryRepository::insert").
 */
final class HabitEntryRepositoryTest extends KernelTestCase
{
    use Factories;

    private const string DAY = '2026-09-08';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testItFindsTheEntryOfAHabitAndDay(): void
    {
        $habit = HabitFactory::new()->scale()->create();
        $entry = HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY), 'valueNumeric' => '4.00']);
        $this->entityManager()->clear();

        $found = $this->repository()->findByHabitAndDate($habit, new \DateTimeImmutable(self::DAY));

        self::assertNotNull($found);
        self::assertSame($entry->getId()->toRfc4122(), $found->getId()->toRfc4122());
        self::assertSame('4.00', $found->getValueNumeric());
    }

    public function testItFindsNothingForAnotherDayOrAnotherHabit(): void
    {
        $habit = HabitFactory::new()->scale()->create();
        $otherHabit = HabitFactory::new()->scale()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $this->entityManager()->clear();

        self::assertNull($this->repository()->findByHabitAndDate($habit, new \DateTimeImmutable('2026-09-09')));
        self::assertNull($this->repository()->findByHabitAndDate($otherHabit, new \DateTimeImmutable(self::DAY)));
    }

    public function testItInsertsAnEntry(): void
    {
        $habit = HabitFactory::new()->scale()->create();
        $entry = new HabitEntry($habit, new \DateTimeImmutable(self::DAY), '4.00', null, 'Notiz', new \DateTimeImmutable('2026-09-08T19:04:11+00:00'));

        $this->repository()->insert($entry);

        $row = $this->connection()->fetchAssociative('SELECT id, value_numeric, note FROM habit_entry WHERE habit_id = ?', [$habit->getId()->toRfc4122()]);
        self::assertSame(['id' => $entry->getId()->toRfc4122(), 'value_numeric' => '4.00', 'note' => 'Notiz'], $row);
    }

    public function testItWritesTheChangesOfALoadedEntryOnFlush(): void
    {
        $habit = HabitFactory::new()->scale()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY), 'valueNumeric' => '2.00']);
        $this->entityManager()->clear();
        $found = $this->repository()->findByHabitAndDate($habit, new \DateTimeImmutable(self::DAY));
        self::assertNotNull($found);

        $found->change('5.00', null, 'geändert');
        $this->repository()->flush();

        $row = $this->connection()->fetchAssociative('SELECT value_numeric, note FROM habit_entry WHERE habit_id = ?', [$habit->getId()->toRfc4122()]);
        self::assertSame(['value_numeric' => '5.00', 'note' => 'geändert'], $row);
    }

    public function testItRemovesAnEntry(): void
    {
        $habit = HabitFactory::new()->scale()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $this->entityManager()->clear();
        $found = $this->repository()->findByHabitAndDate($habit, new \DateTimeImmutable(self::DAY));
        self::assertNotNull($found);

        $this->repository()->remove($found);

        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM habit_entry WHERE habit_id = ?', [$habit->getId()->toRfc4122()]));
    }

    public function testItSignalsAConflictWhenTheDayIsAlreadyTaken(): void
    {
        $habit = HabitFactory::new()->scale()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $second = new HabitEntry($habit, new \DateTimeImmutable(self::DAY), '4.00', null, null, new \DateTimeImmutable());

        try {
            $this->repository()->insert($second);
            self::fail('expected HabitEntryAlreadyExistsException');
        } catch (HabitEntryAlreadyExistsException $exception) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious());
        }
    }

    public function testItIsUsableAgainAfterAConflictSoTheWinnersRowCanBeChanged(): void
    {
        // The service flow after a lost race: look the day up again, change the row, flush.
        $habit = HabitFactory::new()->scale()->create();
        $racingId = Uuid::v7()->toRfc4122();
        $this->connection()->executeStatement(
            'INSERT INTO habit_entry (id, habit_id, entry_date, value_numeric, created_at) VALUES (?, ?, ?, ?, ?)',
            [$racingId, $habit->getId()->toRfc4122(), self::DAY, '1.00', '2026-09-01 10:00:00+00'],
        );
        $repository = $this->repository();

        try {
            $repository->insert(new HabitEntry($habit, new \DateTimeImmutable(self::DAY), '4.00', null, null, new \DateTimeImmutable()));
            self::fail('expected HabitEntryAlreadyExistsException');
        } catch (HabitEntryAlreadyExistsException) {
        }

        $winner = $repository->findByHabitAndDate($habit, new \DateTimeImmutable(self::DAY));
        self::assertNotNull($winner);
        self::assertSame($racingId, $winner->getId()->toRfc4122());
        $winner->change('4.00', null, null);
        $repository->flush();

        $rows = $this->connection()->fetchAllAssociative('SELECT id, value_numeric FROM habit_entry WHERE habit_id = ?', [$habit->getId()->toRfc4122()]);
        self::assertSame([['id' => $racingId, 'value_numeric' => '4.00']], $rows);
    }

    private function repository(): HabitEntryRepository
    {
        $repository = static::getContainer()->get(HabitEntryRepository::class);
        self::assertInstanceOf(HabitEntryRepository::class, $repository);

        return $repository;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
