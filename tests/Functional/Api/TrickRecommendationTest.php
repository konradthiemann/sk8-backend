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
 * `GET /api/trick-recommendation` end to end (design.md §3, §4:
 * App\Controller\Api\TrickRecommendationController -> App\Service\Trick\TrickRecommender),
 * against the real seeded catalog (TrickCatalogSeedTest: 16 tricks, 18
 * prerequisite edges) - same reasoning as TrickTreeTest/TrickDetailTest for
 * reusing it rather than TrickFactory: this endpoint needs the real
 * derived-status graph, not an isolated trick.
 *
 * A fresh catalog (no sessions at all) has exactly one trick with no
 * prerequisite at all - "rolling" (migrations/Version20260909100001.php) -
 * which alone resolves to "bereit"; every other trick has at least one
 * unsatisfied prerequisite and starts "gesperrt" (TrickStatusResolverTest's
 * own rule). "tic-tac", "drop-in", "ollie-stand" and "shove-it-stand" are
 * "rolling"'s direct dependents: mastering "rolling" (three qualifying
 * sessions, the same pattern TrickTreeTest uses) turns all four of them
 * "bereit" in one step, which criterion 8's test below uses to get more
 * candidates than FOCUS_LIMIT = 2 without touching every one of the sixteen
 * tricks by hand.
 */
final class TrickRecommendationTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/trick-recommendation';

    public function testItPutsAPracticingTrickOnPrimaryOverReadyTricks(): void
    {
        // Criterion 6. "ollie-stand" itself has an unsatisfied prerequisite
        // ("rolling", untouched here) and would otherwise be "gesperrt" -
        // but TrickStatusResolver decides a trick's status from its own
        // attempts first (TrickStatusResolverTest: "keeps a trick with
        // attempts practicing rather than locked"), so below-mastery
        // attempts alone are enough to turn it "uebe".
        $client = static::createClient();
        $ollieStand = $this->trickBySlug('ollie-stand');
        $session = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01')]);
        SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $ollieStand, 'attempts' => 10, 'landed' => 3]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        $primary = self::arrayAt($body, 'primary');

        self::assertSame('ollie-stand', $primary['slug'] ?? null);
        self::assertSame('uebe', $primary['status'] ?? null);
        // 10 attempts is below MASTERY_MIN_ATTEMPTS = 15, so this single
        // session never qualifies and recentSuccessRate is null -> "no_data"
        // (the exact reasonCode branching itself is RecommendationReasonTest's
        // job; this assertion only pins down the one value this fixture
        // actually produces, as a regression guard).
        self::assertSame('no_data', $primary['reasonCode'] ?? null);
    }

    public function testItReturnsExactlyFocusLimitSuggestionsWhenMoreCandidatesExistThanTheLimit(): void
    {
        // Criterion 8. Mastering "rolling" (three qualifying sessions, same
        // 20 attempts/16 landed pattern as TrickTreeTest::addQualifyingSessions())
        // turns all four of its direct dependents - "tic-tac", "drop-in",
        // "ollie-stand", "shove-it-stand" - "bereit" in one step (none of
        // them has any attempts of its own), four candidates against
        // FOCUS_LIMIT = 2.
        $client = static::createClient();
        $rolling = $this->trickBySlug('rolling');
        foreach (['2026-07-01', '2026-08-01', '2026-09-01'] as $date) {
            $session = SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable($date)]);
            SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $rolling, 'attempts' => 20, 'landed' => 16]);
        }

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame(2, $body['focusLimit'] ?? null);
        self::assertNotNull($body['primary'] ?? null);
        self::assertIsArray($body['secondary'] ?? null);
        self::assertCount(1, $body['secondary'], 'exactly focusLimit (2) suggestions total: 1 primary + 1 secondary');
    }

    public function testItReturnsTheConfirmedDosageValuesOnEverySuggestion(): void
    {
        // "Fertig, wenn": dosage values come from the confirmed R-02
        // constants, identical for every suggestion (design.md §3).
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $primary = self::arrayAt(self::jsonResponse($client), 'primary');
        $dosage = $primary['dosage'] ?? null;
        self::assertIsArray($dosage);

        self::assertSame(15, $dosage['attemptsMin'] ?? null);
        self::assertSame(30, $dosage['attemptsMax'] ?? null);
        self::assertSame(10, $dosage['minutesMin'] ?? null);
        self::assertSame(20, $dosage['minutesMax'] ?? null);
    }

    public function testItReturnsNullPrimaryAndEmptySecondaryWhenNoCandidateTrickExists(): void
    {
        // Criterion 9. TrickRecommenderTest already proves the literal
        // "only gesperrt/sitzt candidates" input at the unit level directly
        // on selectSuggestions(); reproducing that exact state through the
        // real catalog's dependency graph would require mastering all
        // sixteen tricks. An emptied catalog is the same observable case at
        // the HTTP boundary: zero uebe/bereit candidates, 200 with
        // primary: null and secondary: [] (same technique TrickTreeTest uses
        // for its own empty-catalog criterion).
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->getConnection()->executeStatement('DELETE FROM trick');
        $entityManager->clear();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertArrayHasKey('primary', $body);
        self::assertNull($body['primary']);
        self::assertSame([], $body['secondary'] ?? 'MISSING');
    }

    public function testItIncludesAKneePainHintWhenTheMostRecentSessionReportsElevatedKneePain(): void
    {
        // Criterion 10.
        $client = static::createClient();
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-08'), 'kneePain' => 5]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $pauseHint = self::arrayAt(self::jsonResponse($client), 'pauseHint');
        self::assertSame('knee_pain', $pauseHint['code'] ?? null);
        self::assertIsString($pauseHint['message'] ?? null);
        self::assertNotSame('', $pauseHint['message']);
    }

    public function testItOmitsThePauseHintWhenKneeIsUnremarkableAndThereIsNoSessionStreak(): void
    {
        // Complements criterion 10/11: a fresh catalog has no sessions at
        // all, so neither threshold can be met.
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertArrayHasKey('pauseHint', $body);
        self::assertNull($body['pauseHint']);
    }

    public function testItReturnsUnauthorizedWithoutApiKey(): void
    {
        // Criterion 13.
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItReturnsUnauthorizedWithAWrongApiKey(): void
    {
        // Criterion 13.
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

    private function trickBySlug(string $slug): Trick
    {
        $repository = static::getContainer()->get(TrickRepository::class);
        \assert($repository instanceof TrickRepository);

        $tricks = $repository->findBySlugs([$slug]);
        self::assertCount(1, $tricks, \sprintf('expected exactly one seeded trick with slug "%s"', $slug));

        return $tricks[0];
    }
}
