<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\TelemetryApp;
use App\Enum\UxEventType;
use App\Repository\UxEventRepository;
use App\Tests\Factory\UxEventFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

final class TelemetryEventsTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/telemetry/events';

    public function testItAcceptsABatchAndStoresTheEvents(): void
    {
        $client = static::createClient();
        $sessionId = Uuid::v4()->toRfc4122();

        self::apiRequest($client, 'POST', self::ENDPOINT, [
            'app' => 'skate',
            'sessionId' => $sessionId,
            'events' => [
                [
                    'type' => 'screen_view',
                    'screen' => 'home',
                    'occurredAt' => '2026-09-07T09:58:12.345Z',
                ],
                [
                    'type' => 'interaction',
                    'screen' => 'session-new',
                    'target' => 'save-button',
                    'meta' => ['trick' => 'kickflip', 'attempts' => 3],
                    'occurredAt' => '2026-09-07T11:59:01+02:00',
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['accepted' => 2], self::jsonResponse($client));

        $repository = static::getContainer()->get(UxEventRepository::class);
        self::assertInstanceOf(UxEventRepository::class, $repository);

        $events = $repository->findBySession(Uuid::fromString($sessionId));
        self::assertCount(2, $events);

        [$first, $second] = $events;

        self::assertSame(TelemetryApp::Skate, $first->getApp());
        self::assertSame(UxEventType::ScreenView, $first->getType());
        self::assertSame('home', $first->getScreen());
        self::assertNull($first->getTarget());
        self::assertSame([], $first->getMeta());
        self::assertSame('2026-09-07T09:58:12+00:00', $first->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
        self::assertEqualsWithDelta(time(), $first->getReceivedAt()->getTimestamp(), 5.0);

        self::assertSame(UxEventType::Interaction, $second->getType());
        self::assertSame('save-button', $second->getTarget());
        self::assertSame(['trick' => 'kickflip', 'attempts' => 3], $second->getMeta());
        self::assertSame('2026-09-07T09:59:01+00:00', $second->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
        self::assertTrue($first->getSessionId()->equals($second->getSessionId()));
    }

    public function testItOnlyReturnsEventsOfTheRequestedSession(): void
    {
        $client = static::createClient();
        $otherSession = Uuid::v4();
        UxEventFactory::createMany(3, ['sessionId' => $otherSession]);

        $sessionId = Uuid::v4()->toRfc4122();
        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['sessionId' => $sessionId]));

        self::assertResponseStatusCodeSame(202);

        $repository = static::getContainer()->get(UxEventRepository::class);
        self::assertInstanceOf(UxEventRepository::class, $repository);

        self::assertCount(1, $repository->findBySession(Uuid::fromString($sessionId)));
        self::assertCount(3, $repository->findBySession($otherSession));
    }

    public function testItReturnsUnauthorizedWithoutApiKey(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(), apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItReturnsUnauthorizedWithWrongApiKey(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(), apiKey: 'definitely-wrong');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItRejectsAnUnknownEventType(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'events' => [
                ['type' => 'screen_view', 'screen' => 'home', 'occurredAt' => '2026-09-07T10:00:00Z'],
                ['type' => 'rage_click', 'screen' => 'home', 'occurredAt' => '2026-09-07T10:00:01Z'],
            ],
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = self::jsonResponse($client);
        $violations = self::violations($body);
        self::assertSame(['events[1].type'], self::violationFields($body));
        self::assertSame(
            'Unbekannter Event-Typ. Erlaubt sind: screen_view, time_on_screen, interaction, navigation.',
            $violations[0]['message'],
        );
    }

    public function testItRejectsAnUnknownApp(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['app' => 'chess']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['app'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsAMissingSessionId(): void
    {
        $client = static::createClient();

        $payload = $this->validPayload();
        unset($payload['sessionId']);
        self::apiRequest($client, 'POST', self::ENDPOINT, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sessionId'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsAnInvalidSessionId(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['sessionId' => 'not-a-uuid']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sessionId'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsAnEmptyEventList(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['events' => []]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['events'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsMoreThanOneHundredEvents(): void
    {
        $client = static::createClient();

        $events = array_fill(0, 101, ['type' => 'screen_view', 'screen' => 'home', 'occurredAt' => '2026-09-07T10:00:00Z']);
        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['events' => $events]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['events'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsNonObjectMeta(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'events' => [
                ['type' => 'interaction', 'screen' => 'home', 'target' => 'x', 'meta' => 'not-an-object', 'occurredAt' => '2026-09-07T10:00:00Z'],
            ],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['events[0].meta'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsABlankScreen(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'events' => [
                ['type' => 'screen_view', 'screen' => '', 'occurredAt' => '2026-09-07T10:00:00Z'],
            ],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['events[0].screen'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsGetRequests(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(405);
        self::assertSame(['error' => 'method_not_allowed'], self::jsonResponse($client));
    }

    /**
     * The exact event shapes the three PWAs emit (telemetry hook contract):
     * screen_view {from}, time_on_screen {ms}, interaction {via, tag},
     * navigation {from, to, via}; "unknown" is a legal screen name and
     * occurredAt always carries milliseconds and a Z suffix.
     */
    public function testItAcceptsEveryEventShapeTheFrontendsSend(): void
    {
        $client = static::createClient();
        $sessionId = Uuid::v4()->toRfc4122();

        self::apiRequest($client, 'POST', self::ENDPOINT, [
            'app' => 'habits',
            'sessionId' => $sessionId,
            'events' => [
                ['type' => 'screen_view', 'screen' => 'today', 'meta' => ['from' => null], 'occurredAt' => '2026-09-07T09:58:12.345Z'],
                ['type' => 'screen_view', 'screen' => 'unknown', 'meta' => ['from' => '/today'], 'occurredAt' => '2026-09-07T09:58:13.000Z'],
                ['type' => 'time_on_screen', 'screen' => 'today', 'meta' => ['ms' => 4213], 'occurredAt' => '2026-09-07T09:58:16.558Z'],
                ['type' => 'interaction', 'screen' => 'today', 'target' => 'habit-check', 'meta' => ['via' => 'click', 'tag' => 'button'], 'occurredAt' => '2026-09-07T09:58:17.001Z'],
                ['type' => 'navigation', 'screen' => 'today', 'target' => '/stats', 'meta' => ['from' => '/today', 'to' => '/stats', 'via' => 'menu'], 'occurredAt' => '2026-09-07T09:58:18.900Z'],
            ],
        ]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['accepted' => 5], self::jsonResponse($client));

        $repository = static::getContainer()->get(UxEventRepository::class);
        self::assertInstanceOf(UxEventRepository::class, $repository);

        $events = $repository->findBySession(Uuid::fromString($sessionId));
        self::assertCount(5, $events);

        self::assertSame(['from' => null], $events[0]->getMeta());
        self::assertSame('unknown', $events[1]->getScreen());
        self::assertSame(['ms' => 4213], $events[2]->getMeta());
        self::assertSame(['via' => 'click', 'tag' => 'button'], $events[3]->getMeta());
        self::assertSame('/stats', $events[4]->getTarget());
        // Milliseconds are truncated by the timestamptz(0) column, the second is kept
        self::assertSame(
            '2026-09-07T09:58:12+00:00',
            $events[0]->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );
    }

    public function testItAcceptsTheMaximumBatchSizeTheFrontendsUse(): void
    {
        $client = static::createClient();

        // The frontends flush at 20 events; the endpoint allows up to 100.
        $events = array_fill(0, 20, ['type' => 'screen_view', 'screen' => 'home', 'meta' => ['from' => null], 'occurredAt' => '2026-09-07T10:00:00.000Z']);
        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['events' => $events]));

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['accepted' => 20], self::jsonResponse($client));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'app' => 'nutrition',
            'sessionId' => Uuid::v4()->toRfc4122(),
            'events' => [
                ['type' => 'screen_view', 'screen' => 'home', 'occurredAt' => '2026-09-07T10:00:00Z'],
            ],
        ], $overrides);
    }
}
