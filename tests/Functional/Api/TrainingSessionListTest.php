<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\TrainingSessionFactory;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Zenstruck\Foundry\Test\Factories;

final class TrainingSessionListTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/training-sessions';

    public function testItSortsThreeSessionsOnDifferentDaysNewestFirstAndReportsTheTotal(): void
    {
        // Criterion 11. Foundry needs the kernel booted before it can
        // persist, so createClient() (which owns the boot) always comes
        // first, factory calls after.
        $client = static::createClient();

        TrainingSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01')]);
        TrainingSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-03')]);
        TrainingSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-02')]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame(3, $body['total']);
        $items = $body['items'];
        self::assertIsArray($items);
        self::assertSame(
            ['2026-09-03', '2026-09-02', '2026-09-01'],
            array_column($items, 'sessionDate'),
        );
    }

    public function testItOrdersTwoSessionsOnTheSameDayByMostRecentlyEnteredFirst(): void
    {
        // Criterion 12: UUID v7 is time-ordered, so "id DESC" among equal
        // sessionDates is a valid "most recently entered" tiebreak.
        $client = static::createClient();

        $sameDay = new \DateTimeImmutable('2026-09-05');
        $first = TrainingSessionFactory::createOne(['sessionDate' => $sameDay]);
        $second = TrainingSessionFactory::createOne(['sessionDate' => $sameDay]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        $items = $body['items'];
        self::assertIsArray($items);
        self::assertCount(2, $items);
        /** @var list<array<string, mixed>> $items */
        self::assertSame($second->getId()->toRfc4122(), $items[0]['id'], 'the more recently created session must come first');
        self::assertSame($first->getId()->toRfc4122(), $items[1]['id']);
    }

    public function testItReturnsExactlyTheSecondItemWithLimitOneAndOffsetOneWhileTotalStaysTheFullCount(): void
    {
        // Criterion 13.
        $client = static::createClient();

        for ($day = 1; $day <= 3; ++$day) {
            TrainingSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable(\sprintf('2026-08-%02d', $day))]);
        }

        self::apiRequest($client, 'GET', self::ENDPOINT.'?limit=1&offset=1');

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame(3, $body['total']);
        $items = $body['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        /** @var list<array<string, mixed>> $items */
        // Newest-first order is 08-03, 08-02, 08-01; offset=1 skips 08-03.
        self::assertSame('2026-08-02', $items[0]['sessionDate']);
    }

    #[DataProvider('outOfRangeLimits')]
    public function testItRejectsALimitOutsideOneToOneHundred(int $limit): void
    {
        // Criterion 14.
        $client = static::createClient();
        self::apiRequest($client, 'GET', self::ENDPOINT.'?limit='.$limit);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['limit'], self::violationFields(self::jsonResponse($client)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRangeLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'above one hundred' => [101];
    }
}
