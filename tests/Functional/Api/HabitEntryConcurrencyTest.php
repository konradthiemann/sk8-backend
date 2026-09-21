<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\HabitEntry;
use App\Tests\Factory\HabitEntryFactory;
use App\Tests\Factory\HabitFactory;
use Doctrine\Common\EventArgs;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * The second line of defence for "one entry per habit and day": the service
 * looks the entry up first, but look-up-then-insert is never atomic, so
 * `uniq_habit_entry_habit_date` has the last word. These tests prove (a) that
 * the index exists and fires, and (b) that a PUT which loses the race ends as
 * a correction (200) of the winner's row, never as a 500 (T-0402 design.md
 * §4.2, §10/A15).
 *
 * The literal ticket test ("two PUTs in one transaction") cannot be written:
 * under dama every request shares one connection, so there is no real
 * concurrency. Instead the race is replayed deterministically, like
 * FitnessAssessmentConflictTest: a `prePersist` listener inserts a row for the
 * very same habit and day through the raw connection. The service has
 * already looked the day up and found it free by then, so the `flush()` of
 * the new entity collides with the row the listener slipped in.
 */
final class HabitEntryConcurrencyTest extends HabitEntryApiTestCase
{
    public const string RACING_CREATED_AT = '2026-09-01 10:00:00+00';

    public function testItRejectsASecondEntryForTheSameHabitAndDayAtTheDatabaseLevel(): void
    {
        // Foundry needs the kernel booted before it can persist, so createClient()
        // (which owns the boot) always comes first.
        static::createClient();

        $habit = HabitFactory::new()->duration()->create();
        $sameDay = new \DateTimeImmutable('2026-09-08');
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => $sameDay]);

        try {
            HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => $sameDay]);
            self::fail('expected uniq_habit_entry_habit_date to reject a second entry for the same habit and day');
        } catch (UniqueConstraintViolationException $exception) {
            // Asserting the index name keeps this red until the right index genuinely
            // exists: any other unique violation (e.g. the primary key) would otherwise
            // satisfy a bare expectException().
            self::assertStringContainsString('uniq_habit_entry_habit_date', $exception->getMessage());
        }
    }

    public function testItTurnsALostRaceIntoACorrectionOfTheWinnersRow(): void
    {
        // Exactly one request per kernel: from the second request on, the
        // KernelBrowser reboots the kernel and the listener below would be gone.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $date = self::dayFromToday(0);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $racingRowId = Uuid::v7()->toRfc4122();
        $habitId = $habit->getId()->toRfc4122();
        $entityManager->getEventManager()->addEventListener(
            [Events::prePersist],
            new class($connection, $racingRowId, $habitId, $date) {
                private bool $fired = false;

                public function __construct(
                    private readonly Connection $connection,
                    private readonly string $rowId,
                    private readonly string $habitId,
                    private readonly string $date,
                ) {
                }

                public function prePersist(EventArgs $args): void
                {
                    if ($this->fired || !$args instanceof PrePersistEventArgs || !$args->getObject() instanceof HabitEntry) {
                        return;
                    }

                    $this->fired = true;
                    $this->connection->executeStatement(
                        'INSERT INTO habit_entry (id, habit_id, entry_date, value_numeric, note, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                        [$this->rowId, $this->habitId, $this->date, '1.00', 'von der anderen Anfrage', HabitEntryConcurrencyTest::RACING_CREATED_AT],
                    );
                }
            },
        );

        self::apiRequest($client, 'PUT', self::entryUri($habit, $date), ['valueNumeric' => 6.5]);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertSame(6.5, $body['valueNumeric'] ?? null);
        self::assertSame($racingRowId, $body['id'] ?? null, 'the answer describes the row the other request created');
        self::assertSame('2026-09-01T10:00:00+00:00', $body['createdAt'] ?? null);
        self::assertArrayHasKey('note', $body);
        self::assertNull($body['note'], 'the correction overwrites the note of the winner\'s row');

        // The entity manager was reset after the failed flush, so the aftermath is read
        // through the plain connection taken before the request.
        $rows = $connection->fetchAllAssociative('SELECT id, value_numeric, note FROM habit_entry WHERE habit_id = ?', [$habitId]);
        self::assertCount(1, $rows, 'exactly one row for that habit and day');
        self::assertSame($racingRowId, $rows[0]['id']);
        self::assertSame('6.50', $rows[0]['value_numeric']);
        self::assertNull($rows[0]['note']);
    }
}
