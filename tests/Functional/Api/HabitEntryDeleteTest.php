<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\HabitEntryFactory;
use App\Tests\Factory\HabitFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * Functional, against real Postgres (dama-rolled-back per test). Exercises
 * `DELETE /api/habits/{habitId}/entries/{date}` end to end (T-0402 design.md
 * §3.2): ticket criteria 12, 13 and (for this endpoint) 18.
 *
 * All fixtures are created before the first request: after a request the
 * kernel is rebooted, and Foundry would then talk to a stale container.
 */
final class HabitEntryDeleteTest extends HabitEntryApiTestCase
{
    private const string DAY = '2026-09-08';

    public function testItRemovesAnExistingEntryAndAnswersWith204(): void
    {
        // Criterion 12.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::DAY));

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', self::rawBody($client));
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItKeepsTheHabitItself(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::DAY));

        self::assertSame(1, self::connection()->fetchOne('SELECT count(*) FROM habit WHERE id = ?', [$habit->getId()->toRfc4122()]));
    }

    public function testItRemovesOnlyTheEntryOfThatHabitAndThatDay(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $otherHabit = HabitFactory::new()->number()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-07')]);
        HabitEntryFactory::createOne(['habit' => $otherHabit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::DAY));

        self::assertSame(['2026-09-07'], array_column(self::entryRows($habit), 'entry_date'));
        self::assertSame(1, self::entryCount($otherHabit));
    }

    public function testItAnswers404WhenTheDayHasNoEntry(): void
    {
        // Criterion 13.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::DAY));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_entry_not_found"}', self::rawBody($client));
    }

    public function testItAnswers404WhenOnlyAnotherDayHasAnEntry(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-07')]);

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::DAY));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_entry_not_found"}', self::rawBody($client));
        self::assertSame(1, self::entryCount($habit));
    }

    public function testItAnswers404WhenOnlyAnotherHabitHasAnEntryThatDay(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $otherHabit = HabitFactory::new()->number()->create();
        HabitEntryFactory::createOne(['habit' => $otherHabit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::DAY));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_entry_not_found"}', self::rawBody($client));
        self::assertSame(1, self::entryCount($otherHabit));
    }

    public function testItAnswers404ForARepeatedDelete(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $uri = self::entryUri($habit, self::DAY);

        self::apiRequest($client, 'DELETE', $uri);
        self::assertResponseStatusCodeSame(204);
        self::apiRequest($client, 'DELETE', $uri);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_entry_not_found"}', self::rawBody($client));
    }

    public function testItAnswers404ForAnUnknownHabit(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'DELETE', self::entryUri(Uuid::v7()->toRfc4122(), self::DAY));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_not_found"}', self::rawBody($client));
    }

    public function testItRemovesAnEntryOfAnInactiveHabit(): void
    {
        // Decision of design.md §3.2: a switched-off habit keeps its entries, and a slip stays correctable.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->inactive()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::DAY));

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItAnswers422ForADateThatIsNoCalendarDate(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'DELETE', self::entryUri($habit, '2026-02-30'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'date', 'message' => 'Das Datum muss ein gültiges Datum im Format JJJJ-MM-TT sein.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItAnswersAFutureDayWithAMissingEntryNotWithAValidationError(): void
    {
        // design.md §3.2: DELETE only checks that the date exists on the calendar.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::dayFromToday(5)));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_entry_not_found"}', self::rawBody($client));
    }

    public function testItAnswersADayBeforeTheProjectStartWithAMissingEntry(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'DELETE', self::entryUri($habit, '2025-12-31'));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_entry_not_found"}', self::rawBody($client));
    }

    #[DataProvider('malformedPaths')]
    public function testItAnswers404WhenTheRouteDoesNotMatch(string $habitId, string $date): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'DELETE', self::entryUri($habitId, $date));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"not_found"}', self::rawBody($client));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedPaths(): array
    {
        return [
            'habit id not a uuid' => ['not-a-uuid', self::DAY],
            'unpadded date' => ['0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d', '2026-9-8'],
            'date as words' => ['0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d', 'abc'],
        ];
    }

    public function testItAllowsANewEntryForTheSameDayAfterTheDelete(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $entry = HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        $idBefore = $entry->getId()->toRfc4122();
        $uri = self::entryUri($habit, self::DAY);

        self::apiRequest($client, 'DELETE', $uri);
        self::assertResponseStatusCodeSame(204);
        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 6]);

        self::assertResponseStatusCodeSame(201);
        self::assertNotSame($idBefore, self::jsonResponse($client)['id'] ?? null, 'a deleted entry is gone, the new one is a new row');
        self::assertSame(1, self::entryCount($habit));
    }

    public function testItAnswersUnauthorizedWithoutAnApiKey(): void
    {
        // Criterion 18.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);

        self::apiRequest($client, 'DELETE', self::entryUri($habit, self::DAY), apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
        self::assertSame(1, self::entryCount($habit));
    }
}
