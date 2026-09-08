<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telemetry;

use App\Dto\Telemetry\TelemetryBatchRequest;
use App\Dto\Telemetry\TelemetryEventInput;
use App\Entity\UxEvent;
use App\Enum\TelemetryApp;
use App\Enum\UxEventType;
use App\Service\Telemetry\TelemetryIngestService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TelemetryIngestServiceTest extends TestCase
{
    public function testItMapsEveryEventToAnEntityAndPersistsThem(): void
    {
        /** @var list<UxEvent> $persisted */
        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                self::assertInstanceOf(UxEvent::class, $entity);
                $persisted[] = $entity;
            });
        $entityManager->expects($this->once())->method('flush');

        $clock = new MockClock('2026-09-07 12:00:00', 'UTC');
        $service = new TelemetryIngestService($entityManager, $clock);

        $batch = new TelemetryBatchRequest(
            app: 'habits',
            sessionId: '0192d1a0-7c2a-7a6a-9b1e-2f5d3a4b5c6d',
            events: [
                new TelemetryEventInput(
                    type: 'screen_view',
                    screen: 'today',
                    occurredAt: new \DateTimeImmutable('2026-09-07T11:59:00+00:00'),
                ),
                new TelemetryEventInput(
                    type: 'interaction',
                    screen: 'today',
                    occurredAt: new \DateTimeImmutable('2026-09-07T11:59:30+00:00'),
                    target: 'habit-check',
                    meta: ['habitId' => 42],
                ),
            ],
        );

        $accepted = $service->ingest($batch);

        self::assertSame(2, $accepted);
        self::assertCount(2, $persisted);

        [$first, $second] = $persisted;

        self::assertSame(TelemetryApp::Habits, $first->getApp());
        self::assertSame('0192d1a0-7c2a-7a6a-9b1e-2f5d3a4b5c6d', $first->getSessionId()->toRfc4122());
        self::assertSame(UxEventType::ScreenView, $first->getType());
        self::assertSame('today', $first->getScreen());
        self::assertNull($first->getTarget());
        self::assertSame([], $first->getMeta());
        self::assertSame('2026-09-07T11:59:00+00:00', $first->getOccurredAt()->format(\DateTimeInterface::ATOM));

        self::assertSame(UxEventType::Interaction, $second->getType());
        self::assertSame('habit-check', $second->getTarget());
        self::assertSame(['habitId' => 42], $second->getMeta());
    }

    public function testItStampsReceivedAtFromTheClock(): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $captured = null;
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$captured): void {
            $captured = $entity;
        });

        $clock = new MockClock('2026-09-07 12:34:56', 'UTC');
        $service = new TelemetryIngestService($entityManager, $clock);

        $service->ingest(new TelemetryBatchRequest(
            app: 'skate',
            sessionId: '6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f',
            events: [
                new TelemetryEventInput(
                    type: 'navigation',
                    screen: 'spots',
                    occurredAt: new \DateTimeImmutable('2026-09-07T12:34:50+02:00'),
                ),
            ],
        ));

        self::assertInstanceOf(UxEvent::class, $captured);
        self::assertSame('2026-09-07T12:34:56+00:00', $captured->getReceivedAt()->format(\DateTimeInterface::ATOM));
        self::assertNotSame('', $captured->getId()->toRfc4122());
    }

    public function testItGeneratesADistinctIdPerEvent(): void
    {
        /** @var list<UxEvent> $persisted */
        $persisted = [];
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            self::assertInstanceOf(UxEvent::class, $entity);
            $persisted[] = $entity;
        });

        $service = new TelemetryIngestService($entityManager, new MockClock());

        $event = new TelemetryEventInput(type: 'screen_view', screen: 'home', occurredAt: new \DateTimeImmutable());
        $service->ingest(new TelemetryBatchRequest(app: 'skate', sessionId: '6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f', events: [$event, $event]));

        self::assertCount(2, $persisted);
        self::assertFalse($persisted[0]->getId()->equals($persisted[1]->getId()));
    }
}
