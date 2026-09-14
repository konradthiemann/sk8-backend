<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Trick;
use App\Repository\TrickRepository;
use App\Tests\Factory\SessionTrickFactory;
use App\Tests\Factory\SkateSessionFactory;
use App\Tests\Factory\TrickFactory;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test). Exercises
 * `GET /api/tricks/{slug}` end to end (design.md §3, §4:
 * App\Controller\Api\TrickDetailController -> App\Service\Trick\TrickDetailService).
 *
 * `requires`/`unlocks` come from the real seeded catalog (TrickCatalogSeedTest:
 * 16 tricks, 18 prerequisite edges, migrations/Version20260909100001.php),
 * the same reasoning TrickTreeTest documents for reusing it: there is no
 * TrickPrerequisiteFactory in tests/Factory/ to build a custom graph with.
 * `history`/`progress`-only scenarios (criteria 2, 3) use a plain
 * TrickFactory-built trick instead, since they need no prerequisite graph at
 * all and a fresh, isolated trick keeps the attempt/landed numbers exact.
 */
final class TrickDetailTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/tricks/%s';

    public function testItReturnsTheFullDetailContractForATrickWithPrerequisitesAndUnlocks(): void
    {
        // Criterion 1: description, progress, requires (with status),
        // unlocks (with status) and history must all be present. "ollie"
        // requires "ollie-stand" (its only direct prerequisite) and is
        // itself a direct prerequisite of "pop-shove-it", "board-stall",
        // "kickflip-stand" and "ollie-to-manual" (migrations/Version20260909100001.php).
        $client = static::createClient();
        $ollie = $this->trickBySlug('ollie');
        $session = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01')]);
        SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $ollie, 'attempts' => 20, 'landed' => 5]);

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, 'ollie'));

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame('ollie', $body['slug'] ?? null);
        self::assertSame('Ollie', $body['name'] ?? null);
        self::assertSame('flat', $body['category'] ?? null);
        self::assertSame(3, $body['difficulty'] ?? null);
        self::assertTrue($body['isGoal'] ?? null);
        self::assertSame(1, $body['goalOrder'] ?? null);
        self::assertIsString($body['description'] ?? null);
        self::assertNotSame('', $body['description']);

        $progress = self::arrayAt($body, 'progress');
        self::assertSame('uebe', $progress['status'] ?? null);
        self::assertSame(20, $progress['attemptsTotal'] ?? null);
        self::assertSame(5, $progress['landedTotal'] ?? null);
        self::assertSame(0.25, $progress['successRate'] ?? null);

        $requires = $this->refs($body, 'requires');
        self::assertCount(1, $requires);
        self::assertSame('ollie-stand', $requires[0]['slug']);
        self::assertSame('Ollie im Stand', $requires[0]['name']);
        self::assertIsString($requires[0]['status']);

        $unlocks = $this->refs($body, 'unlocks');
        $unlockSlugs = array_column($unlocks, 'slug');
        self::assertContains('pop-shove-it', $unlockSlugs);
        foreach ($unlocks as $unlock) {
            self::assertIsString($unlock['status']);
        }

        self::assertIsArray($body['history'] ?? null);

        $policy = self::arrayAt($body, 'policy');
        self::assertSame(0.75, $policy['masteryRate'] ?? null);
        self::assertSame(3, $policy['masterySessions'] ?? null);
        self::assertSame(15, $policy['masteryMinAttempts'] ?? null);
        self::assertSame('each_session', $policy['masteryMode'] ?? null);
    }

    public function testItSortsRequirementsByDifficultyThenSlug(): void
    {
        // "pop-shove-it" requires both "ollie" (difficulty 3) and
        // "shove-it-stand" (difficulty 2) - the lower-difficulty prerequisite
        // must come first.
        $client = static::createClient();

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, 'pop-shove-it'));

        $requires = $this->refs(self::jsonResponse($client), 'requires');
        self::assertSame(['shove-it-stand', 'ollie'], array_column($requires, 'slug'));
    }

    public function testItSortsUnlocksByDifficultyThenSlugWhenDifficultiesTie(): void
    {
        // "manual" is required by "nose-manual", "ollie-to-manual" and
        // "pop-shove-it-to-manual" - all difficulty 6, so the slug alone
        // decides the order.
        $client = static::createClient();

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, 'manual'));

        $unlocks = $this->refs(self::jsonResponse($client), 'unlocks');
        self::assertSame(
            ['nose-manual', 'ollie-to-manual', 'pop-shove-it-to-manual'],
            array_column($unlocks, 'slug'),
        );
    }

    public function testItReturnsHistoryDescendingByDateWithCorrectSuccessRatePerEntry(): void
    {
        // Criterion 2: three sessions, descending by date, each with its own
        // correct successRate.
        $client = static::createClient();
        $trick = TrickFactory::createOne(['slug' => 'history-test-trick']);

        $oldest = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-07-01')]);
        SessionTrickFactory::createOne(['skateSession' => $oldest, 'trick' => $trick, 'attempts' => 10, 'landed' => 2, 'notes' => null]);
        $middle = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-08-01')]);
        SessionTrickFactory::createOne(['skateSession' => $middle, 'trick' => $trick, 'attempts' => 20, 'landed' => 4, 'notes' => null]);
        $newest = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-06')]);
        SessionTrickFactory::createOne(['skateSession' => $newest, 'trick' => $trick, 'attempts' => 24, 'landed' => 7, 'notes' => 'Rotation zu flach']);

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, 'history-test-trick'));

        self::assertResponseStatusCodeSame(200);
        $history = self::jsonResponse($client)['history'] ?? null;
        self::assertIsArray($history);
        self::assertCount(3, $history);

        [$first, $second, $third] = $history;
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertIsArray($third);

        self::assertSame($newest->getId()->toRfc4122(), $first['sessionId'] ?? null);
        self::assertSame('2026-09-06', $first['sessionDate'] ?? null);
        self::assertSame(24, $first['attempts'] ?? null);
        self::assertSame(7, $first['landed'] ?? null);
        self::assertSame(0.292, $first['successRate'] ?? null);
        self::assertSame('Rotation zu flach', $first['notes'] ?? null);

        self::assertSame($middle->getId()->toRfc4122(), $second['sessionId'] ?? null);
        self::assertSame('2026-08-01', $second['sessionDate'] ?? null);
        self::assertSame(0.2, $second['successRate'] ?? null);
        self::assertArrayHasKey('notes', $second);
        self::assertNull($second['notes']);

        self::assertSame($oldest->getId()->toRfc4122(), $third['sessionId'] ?? null);
        self::assertSame('2026-07-01', $third['sessionDate'] ?? null);
        self::assertSame(0.2, $third['successRate'] ?? null);
    }

    public function testItReturnsEmptyHistoryAndNullSuccessRateForATrickWithNoSessions(): void
    {
        // Criterion 3. The response must still be 200, not an error.
        $client = static::createClient();
        TrickFactory::createOne(['slug' => 'never-practiced-trick']);

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, 'never-practiced-trick'));

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertSame([], $body['history'] ?? 'MISSING');

        $progress = self::arrayAt($body, 'progress');
        self::assertArrayHasKey('successRate', $progress);
        self::assertNull($progress['successRate']);
        self::assertSame(0, $progress['attemptsTotal'] ?? null);
        self::assertSame(0, $progress['landedTotal'] ?? null);
    }

    public function testItReturnsNotFoundForAnUnknownSlug(): void
    {
        // Criterion 4.
        $client = static::createClient();

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, 'definitely-not-a-real-trick'));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugProvider(): iterable
    {
        yield 'uppercase' => ['Ollie'];
        yield 'special character' => ['ollie!'];
        yield 'space' => ['pop shove it'];
    }

    #[DataProvider('invalidSlugProvider')]
    public function testItReturnsNotFoundRatherThanAServerErrorForAFormallyInvalidSlug(string $invalidSlug): void
    {
        // Criterion 5: the route's own requirements regex ([a-z0-9-]+)
        // rejects this before it ever reaches the controller - 404, not 500.
        $client = static::createClient();

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, rawurlencode($invalidSlug)));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    public function testItReturnsUnauthorizedWithoutApiKey(): void
    {
        // Criterion 13.
        $client = static::createClient();

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, 'ollie'), apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItReturnsUnauthorizedWithAWrongApiKey(): void
    {
        // Criterion 13.
        $client = static::createClient();

        self::apiRequest($client, 'GET', \sprintf(self::ENDPOINT, 'ollie'), apiKey: 'definitely-wrong');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItRejectsPostRequests(): void
    {
        // API-Vertrag §3, statuscodes table - the cheapest possible
        // regression guard for the documented error contract.
        $client = static::createClient();

        self::apiRequest($client, 'POST', \sprintf(self::ENDPOINT, 'ollie'));

        self::assertResponseStatusCodeSame(405);
        self::assertSame(['error' => 'method_not_allowed'], self::jsonResponse($client));
    }

    private function trickBySlug(string $slug): Trick
    {
        $repository = static::getContainer()->get(TrickRepository::class);
        \assert($repository instanceof TrickRepository);

        $tricks = $repository->findBySlugs([$slug]);
        self::assertCount(1, $tricks, \sprintf('expected exactly one seeded trick with slug "%s"', $slug));

        return $tricks[0];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array{slug: string, name: string, status: mixed}>
     */
    private function refs(array $body, string $key): array
    {
        self::assertIsArray($body[$key] ?? null);

        $refs = [];
        foreach ($body[$key] as $ref) {
            self::assertIsArray($ref);
            self::assertIsString($ref['slug'] ?? null);
            self::assertIsString($ref['name'] ?? null);
            $refs[] = ['slug' => $ref['slug'], 'name' => $ref['name'], 'status' => $ref['status'] ?? null];
        }

        return $refs;
    }
}
