<?php

declare(strict_types=1);

namespace App\Dto\Telemetry;

use App\Enum\UxEventType;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One event inside a telemetry batch (request payload).
 */
#[OA\Schema(required: ['type', 'screen', 'occurredAt'])]
final readonly class TelemetryEventInput
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        #[Assert\NotBlank(message: 'telemetry.event.type.blank')]
        #[Assert\Choice(callback: [UxEventType::class, 'values'], message: 'telemetry.event.type.invalid')]
        #[OA\Property(description: 'Event type', enum: ['screen_view', 'time_on_screen', 'interaction', 'navigation'], example: 'interaction')]
        public string $type,

        #[Assert\NotBlank(message: 'telemetry.event.screen.blank')]
        #[Assert\Length(max: 200, maxMessage: 'telemetry.event.screen.too_long')]
        #[OA\Property(description: 'Screen or route the event happened on', example: 'session-new')]
        public string $screen,

        #[OA\Property(description: 'When the event happened on the client (ISO 8601)', format: 'date-time', example: '2026-09-07T09:58:12.345Z')]
        public \DateTimeImmutable $occurredAt,

        #[Assert\Length(max: 200, maxMessage: 'telemetry.event.target.too_long')]
        #[OA\Property(description: 'Interaction target (data-track value); null when not applicable', example: 'save-button', nullable: true)]
        public ?string $target = null,

        // additionalProperties keeps the generated client types open: the frontends send
        // per-type payloads such as {from}, {ms}, {via, tag} or {from, to, via}.
        #[OA\Property(
            description: 'Free-form event details; keys depend on the event type.',
            type: 'object',
            example: ['via' => 'click', 'tag' => 'button'],
            nullable: true,
            additionalProperties: true,
        )]
        public ?array $meta = null,
    ) {
    }
}
