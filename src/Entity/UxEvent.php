<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TelemetryApp;
use App\Enum\UxEventType;
use App\Repository\UxEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One raw UX telemetry event as sent by a frontend (ADR-009).
 *
 * Immutable after construction: telemetry is append-only raw data.
 */
#[ORM\Entity(repositoryClass: UxEventRepository::class)]
#[ORM\Table(name: 'ux_event')]
#[ORM\Index(name: 'idx_ux_event_app_occurred_at', columns: ['app', 'occurred_at'])]
#[ORM\Index(name: 'idx_ux_event_type', columns: ['type'])]
final class UxEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT, enumType: TelemetryApp::class)]
    private TelemetryApp $app;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sessionId;

    #[ORM\Column(type: Types::TEXT, enumType: UxEventType::class)]
    private UxEventType $type;

    #[ORM\Column(type: Types::TEXT)]
    private string $screen;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $target;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $meta;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        TelemetryApp $app,
        Uuid $sessionId,
        UxEventType $type,
        string $screen,
        ?string $target,
        array $meta,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $receivedAt,
    ) {
        // UUID v7 is time-ordered, which keeps the primary key index append-friendly.
        $this->id = Uuid::v7();
        $this->app = $app;
        $this->sessionId = $sessionId;
        $this->type = $type;
        $this->screen = $screen;
        $this->target = $target;
        $this->meta = $meta;
        $this->occurredAt = $occurredAt;
        $this->receivedAt = $receivedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getApp(): TelemetryApp
    {
        return $this->app;
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getType(): UxEventType
    {
        return $this->type;
    }

    public function getScreen(): string
    {
        return $this->screen;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
