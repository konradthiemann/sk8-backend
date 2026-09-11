<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\BodyWeight;
use App\Enum\BodyWeightContext;
use App\Repository\BodyWeightRepository;
use App\Tests\Functional\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Proves T-0103's core promise via the T-0102 write endpoints (design.md §3:
 * "Kein neuer Endpunkt ... Deshalb pruefen die Tests dieses Tickets ueber die
 * vorhandenen Endpunkte und lesen das Ergebnis mit BodyWeightRepository").
 * Every request goes through POST/PUT/DELETE /api/skate-sessions exactly as
 * SkateSessionWriteTest does; only the assertions differ, reading the
 * body_weight side effect back through the repository instead of the
 * response body.
 *
 * All fixture dates are fixed, already-past calendar dates (2026-09-0x), not
 * relative ones: several assertions pin exact derived instants from
 * design.md's own worked example, which only stays reproducible with a fixed
 * sessionDate/startedAt pair.
 */
final class SkateSessionBodyWeightTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/skate-sessions';

    public function testItCreatesTwoBodyWeightRowsWithDerivedTimestampsWhenBothWeightsAreGiven(): void
    {
        // Criterion 1.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sessionDate' => '2026-09-06',
            'startedAt' => '2026-09-06T16:30:00+02:00',
            'durationMinutes' => 95,
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => 77.1,
        ]));
        self::assertResponseStatusCodeSame(201);
        $sessionId = Uuid::fromString($this->createdSessionId($client));

        $rows = $this->bodyWeightRepository()->findBySession($sessionId);
        self::assertCount(2, $rows);

        [$before, $after] = $this->byContext($rows);
        self::assertSame('78.40', $before->getWeightKg());
        self::assertSame('2026-09-06', $before->getMeasuredOn()->format('Y-m-d'));
        self::assertSame(
            '2026-09-06T14:30:00+00:00',
            $before->getMeasuredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );

        self::assertSame('77.10', $after->getWeightKg());
        self::assertSame('2026-09-06', $after->getMeasuredOn()->format('Y-m-d'));
        self::assertSame(
            '2026-09-06T16:05:00+00:00',
            $after->getMeasuredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );
    }

    public function testItUpdatesTheAfterSessionRowKeepingItsIdWhenWeightAfterChangesViaPut(): void
    {
        // Criterion 5.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => 77.1,
        ]));
        self::assertResponseStatusCodeSame(201);
        $sessionId = $this->createdSessionId($client);

        $before = $this->bodyWeightRepository()->findOneBySessionAndContext(Uuid::fromString($sessionId), BodyWeightContext::BeforeSession);
        $after = $this->bodyWeightRepository()->findOneBySessionAndContext(Uuid::fromString($sessionId), BodyWeightContext::AfterSession);
        self::assertNotNull($before);
        self::assertNotNull($after);
        $beforeId = $before->getId();
        $afterId = $after->getId();

        self::apiRequest($client, 'PUT', \sprintf('%s/%s', self::ENDPOINT, $sessionId), $this->validPayload([
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => 76.8,
        ]));
        self::assertResponseStatusCodeSame(200);

        $updatedAfter = $this->bodyWeightRepository()->findOneBySessionAndContext(Uuid::fromString($sessionId), BodyWeightContext::AfterSession);
        $stillBefore = $this->bodyWeightRepository()->findOneBySessionAndContext(Uuid::fromString($sessionId), BodyWeightContext::BeforeSession);
        self::assertNotNull($updatedAfter);
        self::assertNotNull($stillBefore);

        self::assertSame($afterId->toRfc4122(), $updatedAfter->getId()->toRfc4122());
        self::assertSame('76.80', $updatedAfter->getWeightKg());
        self::assertSame($beforeId->toRfc4122(), $stillBefore->getId()->toRfc4122());
        self::assertSame('78.40', $stillBefore->getWeightKg());
    }

    public function testItDeletesTheAfterSessionRowWhenWeightAfterIsSetToNullViaPut(): void
    {
        // Criterion 6.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => 77.1,
        ]));
        self::assertResponseStatusCodeSame(201);
        $sessionId = $this->createdSessionId($client);

        self::apiRequest($client, 'PUT', \sprintf('%s/%s', self::ENDPOINT, $sessionId), $this->validPayload([
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => null,
        ]));
        self::assertResponseStatusCodeSame(200);

        $rows = $this->bodyWeightRepository()->findBySession(Uuid::fromString($sessionId));
        self::assertCount(1, $rows);
        self::assertSame(BodyWeightContext::BeforeSession, $rows[0]->getContext());
        self::assertSame('78.40', $rows[0]->getWeightKg());
    }

    public function testItCreatesTheTwoRowsWhenBothWeightsAreAddedViaPutToASessionThatHadNone(): void
    {
        // Criterion 7.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload());
        self::assertResponseStatusCodeSame(201);
        $sessionId = $this->createdSessionId($client);
        self::assertSame([], $this->bodyWeightRepository()->findBySession(Uuid::fromString($sessionId)));

        self::apiRequest($client, 'PUT', \sprintf('%s/%s', self::ENDPOINT, $sessionId), $this->validPayload([
            'weightBeforeKg' => 75.0,
            'weightAfterKg' => 74.2,
        ]));
        self::assertResponseStatusCodeSame(200);

        $rows = $this->bodyWeightRepository()->findBySession(Uuid::fromString($sessionId));
        self::assertCount(2, $rows);
        [$before, $after] = $this->byContext($rows);
        self::assertSame('75.00', $before->getWeightKg());
        self::assertSame('74.20', $after->getWeightKg());
    }

    public function testItShiftsMeasuredOnAndMeasuredAtForBothRowsWhenSessionDateChangesViaPut(): void
    {
        // Criterion 8.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sessionDate' => '2026-09-06',
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => 77.1,
        ]));
        self::assertResponseStatusCodeSame(201);
        $sessionId = $this->createdSessionId($client);

        self::apiRequest($client, 'PUT', \sprintf('%s/%s', self::ENDPOINT, $sessionId), $this->validPayload([
            'sessionDate' => '2026-09-07',
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => 77.1,
        ]));
        self::assertResponseStatusCodeSame(200);

        $rows = $this->bodyWeightRepository()->findBySession(Uuid::fromString($sessionId));
        self::assertCount(2, $rows);
        [$before, $after] = $this->byContext($rows);

        self::assertSame('2026-09-07', $before->getMeasuredOn()->format('Y-m-d'));
        self::assertSame('2026-09-07', $after->getMeasuredOn()->format('Y-m-d'));
        // startedAt stayed null (validPayload default), so the base
        // timestamp is sessionDate 12:00 Europe/Berlin = 10:00 UTC.
        self::assertSame(
            '2026-09-07T10:00:00+00:00',
            $before->getMeasuredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );
        self::assertSame(
            '2026-09-07T11:00:00+00:00',
            $after->getMeasuredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );
    }

    public function testItRemovesBothBodyWeightRowsWhenTheSessionIsDeleted(): void
    {
        // Criterion 9.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => 77.1,
        ]));
        self::assertResponseStatusCodeSame(201);
        $sessionId = $this->createdSessionId($client);
        self::assertCount(2, $this->bodyWeightRepository()->findBySession(Uuid::fromString($sessionId)));

        self::apiRequest($client, 'DELETE', \sprintf('%s/%s', self::ENDPOINT, $sessionId));
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], $this->bodyWeightRepository()->findBySession(Uuid::fromString($sessionId)));
    }

    public function testItKeepsBodyWeightRowsOfTwoSessionsOnTheSameDaySeparate(): void
    {
        // Criterion 10.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sessionDate' => '2026-09-06',
            'startedAt' => '2026-09-06T09:00:00+02:00',
            'durationMinutes' => 60,
            'weightBeforeKg' => 78.4,
            'weightAfterKg' => 77.9,
        ]));
        self::assertResponseStatusCodeSame(201);
        $morningSessionId = $this->createdSessionId($client);

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sessionDate' => '2026-09-06',
            'startedAt' => '2026-09-06T18:00:00+02:00',
            'durationMinutes' => 60,
            'weightBeforeKg' => 79.0,
            'weightAfterKg' => 78.3,
        ]));
        self::assertResponseStatusCodeSame(201);
        $eveningSessionId = $this->createdSessionId($client);

        self::assertNotSame($morningSessionId, $eveningSessionId);

        $morningRows = $this->bodyWeightRepository()->findBySession(Uuid::fromString($morningSessionId));
        $eveningRows = $this->bodyWeightRepository()->findBySession(Uuid::fromString($eveningSessionId));
        self::assertCount(2, $morningRows);
        self::assertCount(2, $eveningRows);

        [$morningBefore, $morningAfter] = $this->byContext($morningRows);
        [$eveningBefore, $eveningAfter] = $this->byContext($eveningRows);
        self::assertSame('78.40', $morningBefore->getWeightKg());
        self::assertSame('77.90', $morningAfter->getWeightKg());
        self::assertSame('79.00', $eveningBefore->getWeightKg());
        self::assertSame('78.30', $eveningAfter->getWeightKg());
    }

    private function createdSessionId(KernelBrowser $client): string
    {
        $body = self::jsonResponse($client);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    private function bodyWeightRepository(): BodyWeightRepository
    {
        $repository = static::getContainer()->get(BodyWeightRepository::class);
        self::assertInstanceOf(BodyWeightRepository::class, $repository);

        return $repository;
    }

    /**
     * @param list<BodyWeight> $rows
     *
     * @return array{0: BodyWeight, 1: BodyWeight}
     */
    private function byContext(array $rows): array
    {
        $before = null;
        $after = null;
        foreach ($rows as $row) {
            if (BodyWeightContext::BeforeSession === $row->getContext()) {
                $before = $row;
            }
            if (BodyWeightContext::AfterSession === $row->getContext()) {
                $after = $row;
            }
        }

        self::assertNotNull($before, 'expected a vor_session row');
        self::assertNotNull($after, 'expected a nach_session row');

        return [$before, $after];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'sessionDate' => '2026-09-06',
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
