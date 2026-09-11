<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Trick;
use App\Repository\TrickRepository;
use App\Tests\Factory\SessionTrickFactory;
use App\Tests\Factory\SkateSessionFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test). Exercises
 * `GET /api/trick-tree` end to end, deliberately against the *real* seeded
 * catalog (TrickCatalogSeedTest: 16 tricks, 18 prerequisite edges,
 * Version20260909100001) rather than tricks built via TrickFactory: the
 * ticket's Tests table assigns this file the prerequisite-graph criteria
 * (1, 4, 5), and there is no `TrickPrerequisiteFactory` in tests/Factory/ to
 * build a custom graph with (see tests.md, "Lücken" - EPIC-01 factories are
 * not to be modified/extended, ticket "Tests" section, last paragraph). The
 * real catalog's chain rolling -> ollie-stand -> ollie -> pop-shove-it gives
 * every dependency shape criteria 1, 4 and 5 need, without touching
 * TrickFactory/TrickPrerequisiteFactory/SessionTrickFactory/SkateSessionFactory.
 *
 * `updated_at`/mastery timing assertions on `trick_progress` itself
 * (criteria 7, 11) live in
 * tests/Functional/Service/Trick/TrickProgressRefresherTest.php; this file
 * only ever observes the row indirectly, through the JSON response.
 */
final class TrickTreeTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/trick-tree';

    public function testItMarksEveryRootTrickReadyAndEveryDependentTrickLockedWhenNoSessionsExist(): void
    {
        // Criterion 1. Fresh catalog, no SessionTrick rows created in this
        // test method at all - the real seed data's own baseline state.
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        $nodes = $this->nodes($body);
        $edges = $this->edges($body);

        $dependentSlugs = array_unique(array_column($edges, 'to'));

        foreach ($nodes as $node) {
            self::assertIsString($node['slug']);
            $slug = $node['slug'];
            self::assertArrayHasKey('successRate', $node);
            self::assertNull($node['successRate'], \sprintf('successRate must be null for "%s" with no sessions', $slug));

            if (\in_array($node['slug'], $dependentSlugs, true)) {
                self::assertSame('gesperrt', $node['status'], \sprintf('"%s" has a prerequisite and none is mastered, expected gesperrt', $slug));
            } else {
                self::assertSame('bereit', $node['status'], \sprintf('"%s" has no prerequisite, expected bereit', $slug));
            }
        }
    }

    public function testItReportsEdgeDirectionFromThePrerequisiteToTheDependentTrick(): void
    {
        // API-Vertrag §3, "Kanten-Richtung": edge.from is the prerequisite,
        // edge.to is the dependent trick. ollie requires ollie-stand
        // (Version20260909100001::edges()).
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $edges = $this->edges(self::jsonResponse($client));

        self::assertContains(['from' => 'ollie-stand', 'to' => 'ollie'], $edges);
    }

    public function testItSortsNodesByGoalOrderThenDifficultyThenSlugAndEdgesByFromThenTo(): void
    {
        // API-Vertrag §3, "Sortierung nodes"/"edges sortiert".
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $body = self::jsonResponse($client);
        $nodes = $this->nodes($body);
        $edges = $this->edges($body);

        $actualNodeOrder = array_map(
            static fn (array $node): array => [$node['goalOrder'] ?? \PHP_INT_MAX, $node['difficulty'], $node['slug']],
            $nodes,
        );
        $expectedNodeOrder = $actualNodeOrder;
        // Numeric-indexed arrays of equal shape compare element by element in
        // order, so <=> alone reproduces the goalOrder/difficulty/slug chain.
        usort($expectedNodeOrder, static fn (array $a, array $b): int => $a <=> $b);
        self::assertSame($expectedNodeOrder, $actualNodeOrder);

        $actualEdgeOrder = array_map(static fn (array $edge): array => [$edge['from'], $edge['to']], $edges);
        $expectedEdgeOrder = $actualEdgeOrder;
        usort($expectedEdgeOrder, static fn (array $a, array $b): int => $a <=> $b);
        self::assertSame($expectedEdgeOrder, $actualEdgeOrder);
    }

    public function testItReturnsThePolicyThresholdsAlongsideTheTree(): void
    {
        // API-Vertrag §3, "policy" - the confirmed R-02 values
        // (check-tickets.py --show T-0201), not the ticket text's own
        // placeholder example (0.7/3/10).
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $body = self::jsonResponse($client);
        $policy = self::arrayAt($body, 'policy');

        self::assertSame(0.75, $policy['masteryRate'] ?? null);
        self::assertSame(3, $policy['masterySessions'] ?? null);
        self::assertSame(15, $policy['masteryMinAttempts'] ?? null);
        self::assertSame('each_session', $policy['masteryMode'] ?? null);
    }

    public function testItComputesSuccessRateAndPracticingStatusBelowMasteryThreshold(): void
    {
        // Criterion 2: 20 attempts, 6 landed (rate 0.3), well under
        // MASTERY_RATE and with only one session on record.
        $client = static::createClient();
        $trick = $this->trickBySlug('ollie-stand');
        $session = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01')]);
        SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $trick, 'attempts' => 20, 'landed' => 6]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $node = $this->findBySlug('ollie-stand', $this->nodes(self::jsonResponse($client)));
        self::assertSame('uebe', $node['status']);
        self::assertSame(0.3, $node['successRate']);
        self::assertSame(20, $node['attemptsTotal']);
        self::assertSame(6, $node['landedTotal']);
    }

    public function testItMastersATrickAfterThreeQualifyingSessionsAndRecordsFirstLandedOn(): void
    {
        // Criterion 3: three sessions, each 20 attempts / 16 landed (rate
        // 0.8 >= MASTERY_RATE, attempts >= MASTERY_MIN_ATTEMPTS).
        $client = static::createClient();
        $trick = $this->trickBySlug('shove-it-stand');
        $this->addQualifyingSessions($trick, ['2026-07-01', '2026-08-01', '2026-09-01']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $node = $this->findBySlug('shove-it-stand', $this->nodes(self::jsonResponse($client)));
        self::assertSame('sitzt', $node['status']);
        self::assertSame('2026-07-01', $node['firstLandedOn']);
    }

    public function testItReadiesADependentTrickWithNoAttemptsWhenItsOnlyPrerequisiteIsMastered(): void
    {
        // Criterion 4: ollie's only prerequisite is ollie-stand
        // (Version20260909100001::edges()). ollie itself gets no session data.
        $client = static::createClient();
        $ollieStand = $this->trickBySlug('ollie-stand');
        $this->addQualifyingSessions($ollieStand, ['2026-07-01', '2026-08-01', '2026-09-01']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $body = self::jsonResponse($client);
        self::assertSame('sitzt', $this->findBySlug('ollie-stand', $this->nodes($body))['status']);
        self::assertSame('bereit', $this->findBySlug('ollie', $this->nodes($body))['status']);
    }

    public function testItKeepsADependentTrickPracticingNotLockedWhenItsPrerequisiteIsNotMastered(): void
    {
        // Criterion 5: ollie gets below-threshold attempts of its own while
        // its prerequisite ollie-stand is left untouched (stays "bereit",
        // never "sitzt").
        $client = static::createClient();
        $ollie = $this->trickBySlug('ollie');
        $session = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01')]);
        SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $ollie, 'attempts' => 10, 'landed' => 3]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $body = self::jsonResponse($client);
        // ollie-stand itself requires rolling in the real seed catalog and is
        // untouched here, so it is "gesperrt", not "bereit" - either way it
        // is not "sitzt", which is the only thing this criterion needs.
        self::assertNotSame('sitzt', $this->findBySlug('ollie-stand', $this->nodes($body))['status'], 'prerequisite must not be mastered for this criterion to be meaningful');
        self::assertSame('uebe', $this->findBySlug('ollie', $this->nodes($body))['status']);
    }

    public function testItDoesNotMasterATrickWithOnlyOneQualifyingSessionBelowMinimumAttempts(): void
    {
        // Criterion 6: rate 0.875 clears MASTERY_RATE, but 8 attempts is
        // below MASTERY_MIN_ATTEMPTS = 15, so the session never qualifies.
        $client = static::createClient();
        $trick = $this->trickBySlug('shove-it-stand');
        $session = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01')]);
        SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $trick, 'attempts' => 8, 'landed' => 7]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $node = $this->findBySlug('shove-it-stand', $this->nodes(self::jsonResponse($client)));
        self::assertNotSame('sitzt', $node['status']);
        self::assertNull($node['recentSuccessRate']);
    }

    public function testItPersistsTheNewStatusAfterASessionCrossesTheMasteryThreshold(): void
    {
        // Criterion 8 (API-observable half; the updated_at half is proven in
        // TrickProgressRefresherTest, which can read the entity directly).
        $client = static::createClient();
        $trick = $this->trickBySlug('shove-it-stand');
        $this->addQualifyingSessions($trick, ['2026-07-01', '2026-08-01']);

        self::apiRequest($client, 'GET', self::ENDPOINT);
        $before = $this->findBySlug('shove-it-stand', $this->nodes(self::jsonResponse($client)));
        self::assertNotSame('sitzt', $before['status'], 'only two of three qualifying sessions exist so far');

        // The session that crosses the threshold.
        $this->addQualifyingSessions($trick, ['2026-09-01']);

        self::apiRequest($client, 'GET', self::ENDPOINT);
        $after = $this->findBySlug('shove-it-stand', $this->nodes(self::jsonResponse($client)));
        self::assertSame('sitzt', $after['status']);
    }

    public function testItReturnsEmptyListsWhenTheCatalogIsEmpty(): void
    {
        // Criterion 9. No SessionTrick rows exist yet in this test method,
        // so deleting every trick cascades to trick_prerequisite
        // (ON DELETE CASCADE) without hitting session_trick's
        // ON DELETE RESTRICT.
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->getConnection()->executeStatement('DELETE FROM trick');
        $entityManager->clear();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertSame([], $body['nodes'] ?? 'MISSING');
        self::assertSame([], $body['edges'] ?? 'MISSING');
    }

    public function testItReturnsUnauthorizedWithoutApiKey(): void
    {
        // Criterion 10.
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItReturnsUnauthorizedWithAWrongApiKey(): void
    {
        // Criterion 10.
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: 'definitely-wrong');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItRejectsPostRequests(): void
    {
        // API-Vertrag §3, statuscodes table - not itself one of the twelve
        // numbered criteria, but part of "Fertig, wenn" / the documented
        // error contract, and the cheapest possible regression guard.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT);

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
     * Attaches one qualifying (20 attempts / 16 landed, rate 0.8) session per
     * given date - see TrickProgressPolicy's real R-02 values (MASTERY_RATE
     * 0.75, MASTERY_MIN_ATTEMPTS 15).
     *
     * @param list<string> $dates Y-m-d, oldest first
     */
    private function addQualifyingSessions(Trick $trick, array $dates): void
    {
        foreach ($dates as $date) {
            $session = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable($date)]);
            SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $trick, 'attempts' => 20, 'landed' => 16]);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function nodes(array $body): array
    {
        self::assertIsArray($body['nodes'] ?? null);

        $nodes = [];
        foreach ($body['nodes'] as $node) {
            self::assertIsArray($node);
            $nodes[] = $node;
        }

        /** @var list<array<string, mixed>> $nodes */
        return $nodes;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array{from: string, to: string}>
     */
    private function edges(array $body): array
    {
        self::assertIsArray($body['edges'] ?? null);

        $edges = [];
        foreach ($body['edges'] as $edge) {
            self::assertIsArray($edge);
            self::assertIsString($edge['from'] ?? null);
            self::assertIsString($edge['to'] ?? null);
            $edges[] = ['from' => $edge['from'], 'to' => $edge['to']];
        }

        return $edges;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, mixed>
     */
    private function findBySlug(string $slug, array $nodes): array
    {
        foreach ($nodes as $node) {
            if (($node['slug'] ?? null) === $slug) {
                return $node;
            }
        }

        self::fail(\sprintf('trick with slug "%s" not found among nodes', $slug));
    }
}
