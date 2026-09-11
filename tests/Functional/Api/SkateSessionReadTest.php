<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\SessionTrickFactory;
use App\Tests\Factory\SkateSessionFactory;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

final class SkateSessionReadTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/skate-sessions';

    public function testItListsSessionsNewestFirstAndReportsTheTotal(): void
    {
        // Criterion 18. Foundry needs the kernel booted before it can
        // persist, so createClient() (which owns the boot) always comes
        // first, factory calls after.
        $client = static::createClient();

        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01')]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-03')]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-02')]);

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

    public function testItLimitsTheItemsWhileTotalStillCountsEveryMatch(): void
    {
        // Criterion 19.
        $client = static::createClient();

        for ($day = 1; $day <= 5; ++$day) {
            $session = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable(\sprintf('2026-08-%02d', $day))]);
            SessionTrickFactory::createMany(2, ['skateSession' => $session]);
        }

        self::apiRequest($client, 'GET', self::ENDPOINT.'?limit=2');

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame(5, $body['total']);
        $items = $body['items'];
        self::assertIsArray($items);
        self::assertCount(2, $items);

        foreach ($items as $item) {
            self::assertIsArray($item);
            self::assertSame(2, $item['trickCount']);
            self::assertIsInt($item['totalAttempts']);
            self::assertIsInt($item['totalLanded']);
            self::assertIsFloat($item['successRate']);
        }
    }

    public function testItFiltersByFromAndToInclusiveOfBothBoundaryDays(): void
    {
        // Criterion 20.
        $client = static::createClient();

        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-08-31')]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01')]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-02')]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-03')]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-04')]);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?from=2026-09-01&to=2026-09-03');

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

    public function testItRejectsFromAfterToWithValidationFailed(): void
    {
        // Criterion 21.
        $client = static::createClient();
        self::apiRequest($client, 'GET', self::ENDPOINT.'?from=2026-09-05&to=2026-09-01');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['from'], self::violationFields(self::jsonResponse($client)));
    }

    #[DataProvider('outOfRangeLimits')]
    public function testItRejectsALimitOutsideOneToTwoHundred(int $limit): void
    {
        // Criterion 22.
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
        yield 'above two hundred' => [201];
    }

    public function testItReturnsAnEmptyListWhenNoSessionsExist(): void
    {
        // Criterion 23.
        $client = static::createClient();
        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['items' => [], 'total' => 0], self::jsonResponse($client));
    }

    public function testItReturnsASessionWithAllItsTrickRowsOnDetailGet(): void
    {
        // Criterion 24.
        $client = static::createClient();

        $session = SkateSessionFactory::createOne();
        SessionTrickFactory::createMany(2, ['skateSession' => $session]);

        self::apiRequest($client, 'GET', \sprintf('%s/%s', self::ENDPOINT, $session->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame($session->getId()->toRfc4122(), $body['id']);
        $tricks = $body['tricks'];
        self::assertIsArray($tricks);
        self::assertCount(2, $tricks);
    }

    #[DataProvider('invalidIds')]
    public function testItReturnsNotFoundForAnUnknownOrFormallyInvalidId(string $id): void
    {
        // Criterion 25.
        $client = static::createClient();
        self::apiRequest($client, 'GET', self::ENDPOINT.'/'.$id);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'well-formed but unknown uuid' => [Uuid::v7()->toRfc4122()];
        yield 'not a uuid at all' => ['not-a-uuid'];
    }
}
