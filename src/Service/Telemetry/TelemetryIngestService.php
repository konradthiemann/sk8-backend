<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

use App\Dto\Telemetry\TelemetryBatchRequest;
use App\Entity\UxEvent;
use App\Enum\TelemetryApp;
use App\Enum\UxEventType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns a validated telemetry batch into ux_event rows.
 */
final readonly class TelemetryIngestService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Persists every event of the batch and returns how many were stored.
     *
     * The batch is expected to be validated already (see TelemetryBatchRequest).
     */
    public function ingest(TelemetryBatchRequest $batch): int
    {
        $receivedAt = \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));
        $app = TelemetryApp::from($batch->app);
        $sessionId = Uuid::fromString($batch->sessionId);

        $accepted = 0;
        foreach ($batch->events as $input) {
            $event = new UxEvent(
                app: $app,
                sessionId: $sessionId,
                type: UxEventType::from($input->type),
                screen: $input->screen,
                target: $input->target,
                meta: $input->meta ?? [],
                occurredAt: $input->occurredAt,
                receivedAt: $receivedAt,
            );

            $this->entityManager->persist($event);
            ++$accepted;
        }

        $this->entityManager->flush();

        return $accepted;
    }
}
