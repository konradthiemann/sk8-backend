<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\HabitEntryFactory;
use App\Tests\Factory\HabitFactory;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Functional, against real Postgres (dama-rolled-back per test). Exercises
 * `GET /api/habits/day` end to end (T-0402 design.md §3.3): ticket criteria
 * 14-18, the "0 counts as completed" rule (criterion 3) and the query count
 * that keeps the day view at one database query however large the catalog is.
 *
 * Every test starts from an empty habit table (`startClient()`), because the
 * catalog rows are a deployment concern and each scenario creates exactly the
 * habits it needs. Fixtures are created before the first request.
 */
final class HabitDayTest extends HabitEntryApiTestCase
{
    private const string ENDPOINT = '/api/habits/day';
    private const string DAY = '2026-09-08';

    public function testItReturnsAllActiveHabitsWithTheirEntriesAndCounts(): void
    {
        // Criterion 14: three active habits (two with an entry), one inactive habit that has an entry too.
        $client = $this->startClient();
        $today = self::dayFromToday(0);
        $knee = HabitFactory::new()->scale()->create(['slug' => 'knee-pain', 'sortOrder' => 10, 'scaleMin' => 0, 'scaleMax' => 10]);
        $sleep = HabitFactory::new()->duration()->create(['slug' => 'sleep-duration', 'sortOrder' => 20]);
        HabitFactory::new()->scale()->create(['slug' => 'mood', 'sortOrder' => 30]);
        $retired = HabitFactory::new()->scale()->inactive()->create(['slug' => 'retired', 'sortOrder' => 5]);
        HabitEntryFactory::createOne(['habit' => $knee, 'entryDate' => new \DateTimeImmutable($today), 'valueNumeric' => '3.00']);
        HabitEntryFactory::createOne(['habit' => $sleep, 'entryDate' => new \DateTimeImmutable($today), 'valueNumeric' => '7.50']);
        HabitEntryFactory::createOne(['habit' => $retired, 'entryDate' => new \DateTimeImmutable($today), 'valueNumeric' => '2.00']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertSame($today, $body['date'] ?? null);
        self::assertSame(3, $body['totalCount'] ?? null);
        self::assertSame(2, $body['completedCount'] ?? null);
        self::assertSame(['knee-pain', 'sleep-duration', 'mood'], $this->slugs($body));
        self::assertNotNull($this->items($body)[0]['entry'] ?? null);
        self::assertNotNull($this->items($body)[1]['entry'] ?? null);
        self::assertArrayHasKey('entry', $this->items($body)[2]);
        self::assertNull($this->items($body)[2]['entry']);
    }

    public function testItAnswersWithTheFourFieldsInContractOrder(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->scale()->create();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $body = self::jsonResponse($client);
        self::assertSame(['date', 'totalCount', 'completedCount', 'items'], array_keys($body));
        self::assertSame(['habit', 'entry'], array_keys($this->items($body)[0]));
    }

    public function testItDescribesEachHabitWithTheElevenFieldsOfTheCatalog(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->duration()->create(['slug' => 'sleep-duration']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $habit = self::arrayAt($this->items(self::jsonResponse($client))[0], 'habit');
        self::assertSame(
            ['id', 'slug', 'name', 'valueType', 'unit', 'scaleMin', 'scaleMax', 'targetDirection', 'targetValue', 'sortOrder', 'isActive'],
            array_keys($habit),
        );
        self::assertSame('sleep-duration', $habit['slug']);
        self::assertSame('duration', $habit['valueType']);
        self::assertSame('h', $habit['unit']);
    }

    public function testItAnswersAWholeTargetValueAsAJsonNumber(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->duration()->create(['targetValue' => '8.00']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertStringContainsString('"targetValue":8,', self::rawBody($client));
    }

    public function testItSortsBySortOrderThenNameThenSlug(): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'c-slug', 'name' => 'same', 'sortOrder' => 20]);
        HabitFactory::createOne(['slug' => 'a-slug', 'name' => 'same', 'sortOrder' => 20]);
        HabitFactory::createOne(['slug' => 'b-slug', 'name' => 'aaa', 'sortOrder' => 20]);
        HabitFactory::createOne(['slug' => 'late', 'name' => 'aaa', 'sortOrder' => 30]);
        HabitFactory::createOne(['slug' => 'early', 'name' => 'zzz', 'sortOrder' => 10]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertSame(['early', 'b-slug', 'a-slug', 'c-slug', 'late'], $this->slugs(self::jsonResponse($client)));
    }

    public function testItPairsEachEntryWithItsOwnHabit(): void
    {
        $client = $this->startClient();
        $first = HabitFactory::new()->scale()->create(['slug' => 'first', 'sortOrder' => 10]);
        HabitFactory::new()->scale()->create(['slug' => 'second', 'sortOrder' => 20]);
        $third = HabitFactory::new()->scale()->create(['slug' => 'third', 'sortOrder' => 30]);
        HabitEntryFactory::createOne(['habit' => $first, 'entryDate' => new \DateTimeImmutable(self::DAY), 'valueNumeric' => '1.00']);
        HabitEntryFactory::createOne(['habit' => $third, 'entryDate' => new \DateTimeImmutable(self::DAY), 'valueNumeric' => '5.00']);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date='.self::DAY);

        $items = $this->items(self::jsonResponse($client));
        self::assertSame(1, self::arrayAt($items[0], 'entry')['valueNumeric']);
        self::assertSame($first->getId()->toRfc4122(), self::arrayAt($items[0], 'entry')['habitId']);
        self::assertNull($items[1]['entry']);
        self::assertSame(5, self::arrayAt($items[2], 'entry')['valueNumeric']);
        self::assertSame($third->getId()->toRfc4122(), self::arrayAt($items[2], 'entry')['habitId']);
    }

    public function testItShowsTheEntryOfTheRequestedDayOnly(): void
    {
        $client = $this->startClient();
        $habit = HabitFactory::new()->scale()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-07'), 'valueNumeric' => '4.00']);
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY), 'valueNumeric' => '2.00']);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date=2026-09-07');

        $body = self::jsonResponse($client);
        self::assertSame('2026-09-07', $body['date'] ?? null);
        self::assertSame(4, self::arrayAt($this->items($body)[0], 'entry')['valueNumeric']);
    }

    public function testItAnswersADayWithoutAnyEntryWithNullEntries(): void
    {
        // Criterion 15.
        $client = $this->startClient();
        $habit = HabitFactory::new()->scale()->create();
        HabitFactory::new()->boolean()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable('2026-09-07')]);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date='.self::DAY);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertSame(0, $body['completedCount'] ?? null);
        self::assertSame(2, $body['totalCount'] ?? null);
        foreach ($this->items($body) as $item) {
            self::assertArrayHasKey('entry', $item);
            self::assertNull($item['entry']);
        }
        self::assertStringContainsString('"entry":null', self::rawBody($client));
    }

    public function testItAnswersAnEmptyCatalogWithAnEmptyList(): void
    {
        // Criterion 16.
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            \sprintf('{"date":"%s","totalCount":0,"completedCount":0,"items":[]}', self::dayFromToday(0)),
            self::rawBody($client),
        );
    }

    public function testItCountsAnEntryWithTheValueZeroAsCompleted(): void
    {
        // Criterion 3: zero is a value, so the habit counts as done and the entry is shown with 0.
        $client = $this->startClient();
        $habit = HabitFactory::new()->number()->create();
        HabitEntryFactory::new()->withNumeric('0.00')->create(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY)]);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date='.self::DAY);

        $body = self::jsonResponse($client);
        self::assertSame(1, $body['completedCount'] ?? null);
        self::assertSame(0, self::arrayAt($this->items($body)[0], 'entry')['valueNumeric']);
        self::assertStringContainsString('"valueNumeric":0,', self::rawBody($client));
    }

    public function testItCountsAnEntryWithNoAsCompleted(): void
    {
        $client = $this->startClient();
        $habit = HabitFactory::new()->boolean()->create();
        HabitEntryFactory::createOne(['habit' => $habit, 'entryDate' => new \DateTimeImmutable(self::DAY), 'valueNumeric' => null, 'valueBool' => false]);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date='.self::DAY);

        $body = self::jsonResponse($client);
        self::assertSame(1, $body['completedCount'] ?? null);
        self::assertFalse(self::arrayAt($this->items($body)[0], 'entry')['valueBool']);
    }

    public function testItDefaultsToTodayWhenTheDateIsEmpty(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->scale()->create();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date=');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::dayFromToday(0), self::jsonResponse($client)['date'] ?? null);
    }

    public function testItAcceptsTheEarliestAllowedDay(): void
    {
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date=2026-01-01');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('2026-01-01', self::jsonResponse($client)['date'] ?? null);
    }

    public function testItIgnoresUnknownQueryParameters(): void
    {
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?foo=bar');

        self::assertResponseStatusCodeSame(200);
    }

    public function testItRejectsAFutureDay(): void
    {
        // Criterion 17.
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date='.self::dayFromToday(400));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'date', 'message' => 'Du kannst keinen Wert für die Zukunft eintragen.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItRejectsTomorrow(): void
    {
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date='.self::dayFromToday(1));

        self::assertResponseStatusCodeSame(422);
    }

    public function testItRejectsTheDayBeforeTheProjectStart(): void
    {
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date=2025-12-31');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'date', 'message' => 'Das Datum liegt vor dem Projektstart.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    #[DataProvider('invalidDates')]
    public function testItRejectsADateThatIsNoCalendarDate(string $query): void
    {
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['date'], self::violationFields(self::jsonResponse($client)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDates(): array
    {
        return [
            'thirtieth of February' => ['date=2026-02-30'],
            'words' => ['date=abc'],
            'unpadded' => ['date=2026-9-8'],
            'no separators' => ['date=20260908'],
            'an array' => ['date[]=x'],
        ];
    }

    #[DataProvider('unsupportedMethods')]
    public function testItRejectsEveryMethodOtherThanGet(string $method): void
    {
        $client = $this->startClient();

        self::apiRequest($client, $method, self::ENDPOINT);

        self::assertResponseStatusCodeSame(405);
        self::assertSame(['error' => 'method_not_allowed'], self::jsonResponse($client));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedMethods(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    public function testItAnswersUnauthorizedWithoutAnApiKey(): void
    {
        // Criterion 18.
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItLoadsTheWholeDayWithASingleQueryHoweverManyHabitsThereAre(): void
    {
        // The day view must not grow with the catalog (ticket, "Tests"): five habits, three with entries, one query.
        $client = $this->startClient();
        $habits = [];
        for ($position = 1; $position <= 5; ++$position) {
            $habits[] = HabitFactory::new()->scale()->create(['sortOrder' => 10 * $position]);
        }
        foreach ([0, 2, 4] as $index) {
            HabitEntryFactory::createOne(['habit' => $habits[$index], 'entryDate' => new \DateTimeImmutable(self::DAY)]);
        }

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?date='.self::DAY);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(3, self::jsonResponse($client)['completedCount'] ?? null);
        /** @var array<string, list<array<string, mixed>>> $data */
        $data = $holder->getData();
        $queries = $data['default'] ?? [];
        self::assertCount(1, $queries, 'GET /api/habits/day must read habits and entries in exactly one query');
        $sql = $queries[0]['sql'] ?? null;
        self::assertIsString($sql);
        self::assertStringContainsString('habit_entry', $sql);
        self::assertStringContainsStringIgnoringCase('LEFT JOIN', $sql, 'habits without an entry must stay in the result');
    }

    /**
     * Boots the client and empties the habit table, so every test controls the complete catalog.
     */
    private function startClient(): KernelBrowser
    {
        $client = static::createClient();
        self::emptyHabitTable();

        return $client;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $body): array
    {
        self::assertIsArray($body['items'] ?? null);

        $items = [];
        foreach ($body['items'] as $item) {
            self::assertIsArray($item);
            $items[] = $item;
        }

        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function slugs(array $body): array
    {
        return array_map(
            static function (array $item): string {
                $habit = $item['habit'] ?? null;
                self::assertIsArray($habit);
                $slug = $habit['slug'] ?? null;

                return \is_string($slug) ? $slug : self::fail('Expected "slug" to be a string.');
            },
            $this->items($body),
        );
    }
}
