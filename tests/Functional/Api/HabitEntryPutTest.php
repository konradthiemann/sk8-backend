<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\HabitFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * Functional, against real Postgres (dama-rolled-back per test). Exercises
 * `PUT /api/habits/{habitId}/entries/{date}` end to end (T-0402 design.md
 * §3.1): ticket criteria 1-3, 5, 11, 18 and 20. The rejected inputs (422, bad
 * request shapes) live in HabitEntryValidationTest, the race in
 * HabitEntryConcurrencyTest.
 *
 * Every JSON assertion on a number goes through the decoded body, and the
 * ones that decide between "0" and "null" or between "7" and "7.0" also look
 * at the raw response text: the ticket's central rule is that `0` is a value.
 */
final class HabitEntryPutTest extends HabitEntryApiTestCase
{
    public function testItCreatesAnEntryAndAnswersWith201(): void
    {
        // Criterion 1.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $date = self::dayFromToday(0);

        self::apiRequest($client, 'PUT', self::entryUri($habit, $date), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);
        self::assertSame(7.5, $body['valueNumeric'] ?? null);
        self::assertSame($date, $body['entryDate'] ?? null);
        self::assertSame($habit->getId()->toRfc4122(), $body['habitId'] ?? null);
        self::assertSame(1, self::entryCount($habit));
    }

    public function testItAnswersWithAllSevenFieldsInContractOrder(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5, 'note' => 'spät ins Bett, früh raus']);

        self::assertSame(
            ['id', 'habitId', 'entryDate', 'valueNumeric', 'valueBool', 'note', 'createdAt'],
            array_keys(self::jsonResponse($client)),
        );
    }

    public function testItAnswersWithAV7IdAndTheStoredRowsId(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5]);

        $id = self::jsonResponse($client)['id'] ?? null;
        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
        self::assertSame([$id], array_column(self::entryRows($habit), 'id'));
    }

    public function testItAnswersWithTheCreationTimeInUtcAtTheMomentOfTheRequest(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5]);

        $createdAt = self::jsonResponse($client)['createdAt'] ?? null;
        self::assertIsString($createdAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $createdAt, 'design.md §3.1: createdAt is always written in UTC');
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($createdAt))->getTimestamp(), 60);
    }

    public function testItAnswersWithoutALocationHeader(): void
    {
        // Decision of design.md §10/A8: the target URI is the request URI, and it has no GET.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5]);

        self::assertFalse($client->getResponse()->headers->has('Location'));
    }

    public function testItStoresTheValueAsATwoDecimalNumberInTheRow(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $date = self::dayFromToday(0);

        self::apiRequest($client, 'PUT', self::entryUri($habit, $date), ['valueNumeric' => 7.5, 'note' => 'gut geschlafen']);

        $rows = self::entryRows($habit);
        self::assertCount(1, $rows);
        self::assertSame('7.50', $rows[0]['value_numeric']);
        self::assertNull($rows[0]['value_bool']);
        self::assertSame($date, $rows[0]['entry_date']);
        self::assertSame('gut geschlafen', $rows[0]['note']);
    }

    public function testItChangesTheEntryOnTheSecondCallAndKeepsIdAndCreationTime(): void
    {
        // Criterion 2: idempotent, one row, same id, same createdAt (as the very same string).
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $uri = self::entryUri($habit, self::dayFromToday(0));

        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 7.5]);
        self::assertResponseStatusCodeSame(201);
        $created = self::jsonResponse($client);
        $rowBefore = self::entryRows($habit);

        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 8]);

        self::assertResponseStatusCodeSame(200);
        $changed = self::jsonResponse($client);
        self::assertSame(8, $changed['valueNumeric'] ?? null);
        self::assertSame($created['id'] ?? null, $changed['id'] ?? null);
        self::assertSame($created['createdAt'] ?? null, $changed['createdAt'] ?? null);
        $rowAfter = self::entryRows($habit);
        self::assertCount(1, $rowAfter, 'the second call must correct the entry, not add one');
        self::assertSame('8.00', $rowAfter[0]['value_numeric']);
        self::assertSame($rowBefore[0]['id'], $rowAfter[0]['id']);
        self::assertEquals($rowBefore[0]['created_at'], $rowAfter[0]['created_at']);
    }

    public function testItAnswersTheSameRequestRepeatedlyWith200AndOneRow(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $uri = self::entryUri($habit, self::dayFromToday(0));

        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 7.5]);
        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 7.5]);
        self::assertResponseStatusCodeSame(200);
        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, self::entryCount($habit));
    }

    public function testItOverwritesTheNoteWhenTheCorrectionSendsNone(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $uri = self::entryUri($habit, self::dayFromToday(0));

        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 7.5, 'note' => 'alte Notiz']);
        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 7.5]);

        $body = self::jsonResponse($client);
        self::assertArrayHasKey('note', $body);
        self::assertNull($body['note']);
        self::assertNull(self::entryRows($habit)[0]['note']);
    }

    public function testItKeepsTheEntriesOfDifferentDaysAndHabitsApart(): void
    {
        $client = static::createClient();
        $sleep = HabitFactory::new()->duration()->create();
        $water = HabitFactory::new()->number()->create();

        self::apiRequest($client, 'PUT', self::entryUri($sleep, self::dayFromToday(0)), ['valueNumeric' => 7.5]);
        self::assertResponseStatusCodeSame(201);
        self::apiRequest($client, 'PUT', self::entryUri($sleep, self::dayFromToday(-1)), ['valueNumeric' => 6.5]);
        self::assertResponseStatusCodeSame(201);
        self::apiRequest($client, 'PUT', self::entryUri($water, self::dayFromToday(0)), ['valueNumeric' => 5]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(2, self::entryCount($sleep));
        self::assertSame(1, self::entryCount($water));
    }

    public function testItStoresZeroAsAValueAndAnswersWithZeroNotNull(): void
    {
        // Criterion 3: zero is an entry, "not entered" means there is no entry at all.
        $client = static::createClient();
        $habit = HabitFactory::new()->number()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 0]);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('"valueNumeric":0,', self::rawBody($client));
        self::assertSame(0, self::jsonResponse($client)['valueNumeric'] ?? null);
        $rows = self::entryRows($habit);
        self::assertCount(1, $rows);
        self::assertSame('0.00', $rows[0]['value_numeric']);
        self::assertNull($rows[0]['value_bool']);
    }

    public function testItStoresZeroOnAZeroToTenScale(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->scale()->create(['scaleMin' => 0, 'scaleMax' => 10]);

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 0]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, self::jsonResponse($client)['valueNumeric'] ?? null);
    }

    public function testItChangesAValueToZeroAndBack(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->number()->create();
        $uri = self::entryUri($habit, self::dayFromToday(0));

        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 4]);
        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 0]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, self::jsonResponse($client)['valueNumeric'] ?? null);
        self::assertSame('0.00', self::entryRows($habit)[0]['value_numeric']);

        self::apiRequest($client, 'PUT', $uri, ['valueNumeric' => 3]);

        self::assertSame(3, self::jsonResponse($client)['valueNumeric'] ?? null);
    }

    #[DataProvider('yesAndNo')]
    public function testItStoresAYesNoValueIncludingNo(bool $value): void
    {
        // Criterion 3, boolean flavour: "no" is a value, not a missing one.
        $client = static::createClient();
        $habit = HabitFactory::new()->boolean()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueBool' => $value]);

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);
        self::assertSame($value, $body['valueBool'] ?? null);
        self::assertArrayHasKey('valueNumeric', $body);
        self::assertNull($body['valueNumeric']);
        $rows = self::entryRows($habit);
        self::assertSame($value, $rows[0]['value_bool']);
        self::assertNull($rows[0]['value_numeric']);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function yesAndNo(): array
    {
        return ['yes' => [true], 'no' => [false]];
    }

    public function testItSwitchesAYesNoEntryFromNoToYes(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->boolean()->create();
        $uri = self::entryUri($habit, self::dayFromToday(0));

        self::apiRequest($client, 'PUT', $uri, ['valueBool' => false]);
        self::apiRequest($client, 'PUT', $uri, ['valueBool' => true]);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue(self::jsonResponse($client)['valueBool'] ?? null);
    }

    public function testItAnswersAWholeNumberWithoutADecimalPointAndAFractionWithoutTrailingZeros(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 8]);
        self::assertStringContainsString('"valueNumeric":8,', self::rawBody($client));

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.25]);
        self::assertStringContainsString('"valueNumeric":7.25,', self::rawBody($client));
    }

    public function testItAcceptsAPastDayThreeDaysBack(): void
    {
        // Criterion 5: a forgotten day can be entered afterwards.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();
        $date = self::dayFromToday(-3);

        self::apiRequest($client, 'PUT', self::entryUri($habit, $date), ['valueNumeric' => 7]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($date, self::jsonResponse($client)['entryDate'] ?? null);
        self::assertSame($date, self::entryRows($habit)[0]['entry_date']);
    }

    public function testItAcceptsYesterdayAndTheEarliestAllowedDay(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(-1)), ['valueNumeric' => 7]);
        self::assertResponseStatusCodeSame(201);

        self::apiRequest($client, 'PUT', self::entryUri($habit, '2026-01-01'), ['valueNumeric' => 7]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2026-01-01', self::jsonResponse($client)['entryDate'] ?? null);
    }

    public function testItTurnsAnEmptyNoteIntoNull(): void
    {
        // Criterion 20.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5, 'note' => '']);

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);
        self::assertArrayHasKey('note', $body);
        self::assertNull($body['note']);
        self::assertNull(self::entryRows($habit)[0]['note']);
    }

    public function testItKeepsANoteThatIsSent(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5, 'note' => 'Übung: Größe ändern']);

        self::assertSame('Übung: Größe ändern', self::jsonResponse($client)['note'] ?? null);
    }

    public function testItAnswersWithANullNoteWhenNoneIsSent(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5]);

        $body = self::jsonResponse($client);
        self::assertArrayHasKey('note', $body);
        self::assertNull($body['note']);
    }

    public function testItAnswers404ForAnInactiveHabitAndWritesNothing(): void
    {
        // Criterion 11.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->inactive()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_not_found"}', self::rawBody($client));
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItAnswers404ForAnUnknownHabit(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'PUT', self::entryUri(Uuid::v7()->toRfc4122(), self::dayFromToday(0)), ['valueNumeric' => 7.5]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"error":"habit_not_found"}', self::rawBody($client));
    }

    public function testItAnswersUnauthorizedWithoutAnApiKey(): void
    {
        // Criterion 18.
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5], apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
        self::assertSame(0, self::entryCount($habit));
    }

    public function testItAnswersUnauthorizedWithAWrongApiKey(): void
    {
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::apiRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), ['valueNumeric' => 7.5], apiKey: 'not-the-key');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItChecksTheApiKeyBeforeTheBody(): void
    {
        // design.md §3.0, order of checks: the firewall (401) comes before the payload (422).
        $client = static::createClient();
        $habit = HabitFactory::new()->duration()->create();

        self::rawRequest($client, 'PUT', self::entryUri($habit, self::dayFromToday(0)), '{}', apiKey: null);

        self::assertResponseStatusCodeSame(401);
    }
}
