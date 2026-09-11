<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class TrickListTest extends ApiTestCase
{
    private const string ENDPOINT = '/api/tricks';

    public function testItReturnsAllSixteenSeededTricks(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(16, $this->items($client));
    }

    public function testItSortsByDifficultyAscendingThenNameAscending(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $items = $this->items($client);

        $actualOrder = array_map(
            static function (array $item): array {
                self::assertIsInt($item['difficulty']);
                self::assertIsString($item['name']);

                return [$item['difficulty'], $item['name']];
            },
            $items,
        );

        $expectedOrder = $actualOrder;
        usort(
            $expectedOrder,
            static function (array $a, array $b): int {
                $byDifficulty = $a[0] <=> $b[0];

                return 0 !== $byDifficulty ? $byDifficulty : $a[1] <=> $b[1];
            },
        );

        self::assertSame($expectedOrder, $actualOrder);
    }

    public function testItReturnsCamelCaseFieldsForEveryTrick(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        foreach ($this->items($client) as $item) {
            self::assertSame(
                ['id', 'slug', 'name', 'category', 'difficulty', 'description', 'isGoal', 'goalOrder', 'prerequisiteSlugs'],
                array_keys($item),
            );
        }
    }

    public function testItReturnsOllieAsAGoalWithItsSinglePrerequisite(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $ollie = $this->findBySlug('ollie', $this->items($client));

        self::assertTrue($ollie['isGoal']);
        self::assertSame(1, $ollie['goalOrder']);
        self::assertSame(['ollie-stand'], $ollie['prerequisiteSlugs']);
    }

    public function testItReturnsRollingAsTheRootTrickWithoutPrerequisitesOrGoalOrder(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $rolling = $this->findBySlug('rolling', $this->items($client));

        self::assertFalse($rolling['isGoal']);
        self::assertNull($rolling['goalOrder']);
        self::assertSame([], $rolling['prerequisiteSlugs']);
    }

    public function testItReturnsUnauthorizedWithoutApiKey(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItReturnsUnauthorizedWithAWrongApiKey(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: 'definitely-wrong');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItRejectsPostRequests(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT);

        self::assertResponseStatusCodeSame(405);
        self::assertSame(['error' => 'method_not_allowed'], self::jsonResponse($client));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(KernelBrowser $client): array
    {
        $body = self::jsonResponse($client);
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
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function findBySlug(string $slug, array $items): array
    {
        foreach ($items as $item) {
            if (($item['slug'] ?? null) === $slug) {
                return $item;
            }
        }

        self::fail(\sprintf('trick with slug "%s" not found in response', $slug));
    }
}
