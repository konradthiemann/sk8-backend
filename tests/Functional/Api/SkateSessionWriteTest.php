<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\SessionTrick;
use App\Tests\Factory\SessionTrickFactory;
use App\Tests\Factory\SkateSessionFactory;
use App\Tests\Factory\TrickFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Trick slugs used here are prefixed "qa-" on purpose: the real catalog
 * (T-0101's data migration) already seeds "ollie", "manual" and "kickflip"
 * into every test database, so reusing those exact slugs via `TrickFactory`
 * would collide with `uniq_trick_slug`.
 */
final class SkateSessionWriteTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/skate-sessions';

    public function testItCreatesASessionAndReturnsItWithALocationHeaderAndTrickRows(): void
    {
        // Criterion 1. Foundry needs the kernel booted before it can
        // persist, so createClient() (which owns the boot) always comes
        // first, factory calls after.
        $client = static::createClient();

        TrickFactory::createOne(['slug' => 'qa-ollie', 'name' => 'QA Ollie']);
        TrickFactory::createOne(['slug' => 'qa-manual', 'name' => 'QA Manual']);

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'tricks' => [
                ['trickSlug' => 'qa-ollie', 'attempts' => 30, 'landed' => 21, 'notes' => null],
                ['trickSlug' => 'qa-manual', 'attempts' => 24, 'landed' => 9, 'notes' => 'Zu frueh aufgesetzt.'],
            ],
        ]));

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);
        self::assertIsString($body['id']);

        $location = $client->getResponse()->headers->get('Location');
        self::assertSame(\sprintf('/api/skate-sessions/%s', $body['id']), $location);

        $tricks = $body['tricks'];
        self::assertIsArray($tricks);
        self::assertCount(2, $tricks);
        self::assertIsArray($tricks[0]);
        self::assertSame('qa-ollie', $tricks[0]['trickSlug']);
        self::assertSame('QA Ollie', $tricks[0]['trickName']);
        self::assertSame(0.7, $tricks[0]['successRate']);
        self::assertIsArray($tricks[1]);
        self::assertSame('qa-manual', $tricks[1]['trickSlug']);
        self::assertSame(0.375, $tricks[1]['successRate']);

        self::assertSame(54, $body['totalAttempts']);
        self::assertSame(30, $body['totalLanded']);
        self::assertSame(0.556, $body['successRate']);
    }

    public function testItCreatesASessionWithoutAnyTricks(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload());

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);

        self::assertSame([], $body['tricks']);
        self::assertSame(0, $body['totalAttempts']);
        self::assertSame(0, $body['totalLanded']);
        self::assertNull($body['successRate']);
    }

    public function testItRoundsWeightsToTwoDecimalPlacesWhenPersisting(): void
    {
        // Criterion 8: 78.456 stored (and re-read) as 78.46.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'weightBeforeKg' => 78.456,
            'weightAfterKg' => 77.1,
        ]));

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);
        self::assertSame(78.46, $body['weightBeforeKg']);
        self::assertIsString($body['id']);

        // Round-trips through the database, not just echoed from the request.
        self::apiRequest($client, 'GET', \sprintf('%s/%s', self::ENDPOINT, $body['id']));
        self::assertSame(78.46, self::jsonResponse($client)['weightBeforeKg']);
    }

    public function testItReplacesTrickRowsOnPutKeepingTheIdOfARowThatStays(): void
    {
        // Criterion 26.
        $client = static::createClient();

        $ollie = TrickFactory::createOne(['slug' => 'qa-ollie', 'name' => 'QA Ollie']);
        $manual = TrickFactory::createOne(['slug' => 'qa-manual', 'name' => 'QA Manual']);
        TrickFactory::createOne(['slug' => 'qa-kickflip', 'name' => 'QA Kickflip']);

        $session = SkateSessionFactory::createOne();
        $ollieRow = SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $ollie, 'attempts' => 10, 'landed' => 3]);
        SessionTrickFactory::createOne(['skateSession' => $session, 'trick' => $manual, 'attempts' => 8, 'landed' => 2]);

        self::apiRequest($client, 'PUT', \sprintf('%s/%s', self::ENDPOINT, $session->getId()->toRfc4122()), $this->validPayload([
            'tricks' => [
                ['trickSlug' => 'qa-ollie', 'attempts' => 40, 'landed' => 33, 'notes' => null],
                ['trickSlug' => 'qa-kickflip', 'attempts' => 12, 'landed' => 4, 'notes' => null],
            ],
        ]));

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        $tricks = $body['tricks'];
        self::assertIsArray($tricks);
        self::assertCount(2, $tricks);

        $bySlug = [];
        foreach ($tricks as $trick) {
            self::assertIsArray($trick);
            self::assertIsString($trick['trickSlug']);
            $bySlug[$trick['trickSlug']] = $trick;
        }

        self::assertArrayNotHasKey('qa-manual', $bySlug, 'the qa-manual row must be gone (slug no longer submitted)');
        self::assertSame($ollieRow->getId()->toRfc4122(), $bySlug['qa-ollie']['id'], 'the qa-ollie row must keep its id (slug stayed)');
        self::assertSame(40, $bySlug['qa-ollie']['attempts']);
        self::assertSame(33, $bySlug['qa-ollie']['landed']);
        self::assertArrayHasKey('qa-kickflip', $bySlug, 'qa-kickflip must be a new row (slug is new)');
    }

    public function testItDeletesASessionAndCascadesItsTrickRows(): void
    {
        // Criterion 27.
        $client = static::createClient();

        $session = SkateSessionFactory::createOne();
        $trickRow = SessionTrickFactory::createOne(['skateSession' => $session]);
        $trickRowId = $trickRow->getId();
        $sessionId = $session->getId()->toRfc4122();

        self::apiRequest($client, 'DELETE', \sprintf('%s/%s', self::ENDPOINT, $sessionId));

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $client->getResponse()->getContent());

        self::apiRequest($client, 'GET', \sprintf('%s/%s', self::ENDPOINT, $sessionId));
        self::assertResponseStatusCodeSame(404);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertNull($entityManager->find(SessionTrick::class, $trickRowId), 'session_trick row must be gone via ON DELETE CASCADE');
    }

    public function testItReturnsBadRequestForMalformedJsonBody(): void
    {
        // Criterion 29.
        $client = static::createClient();

        $client->request(
            'POST',
            self::ENDPOINT,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_KEY' => TEST_API_KEY],
            '{"sessionDate": "2026-09-01", "tricks": [',
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'bad_request'], self::jsonResponse($client));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'sessionDate' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'startedAt' => null,
            'durationMinutes' => 60,
            'location' => 'Skatepark Braunschweig',
            'weightBeforeKg' => null,
            'weightAfterKg' => null,
            'perceivedExertion' => null,
            'kneePain' => null,
            'notes' => null,
            'tricks' => [],
        ], $overrides);
    }
}
