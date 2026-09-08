<?php

declare(strict_types=1);

namespace App\Dto\Telemetry;

use App\Enum\TelemetryApp;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload of POST /api/telemetry/events.
 */
#[OA\Schema(required: ['app', 'sessionId', 'events'])]
final readonly class TelemetryBatchRequest
{
    public const int MAX_EVENTS = 100;

    /**
     * @param list<TelemetryEventInput> $events
     */
    public function __construct(
        #[Assert\NotBlank(message: 'telemetry.app.blank')]
        #[Assert\Choice(callback: [TelemetryApp::class, 'values'], message: 'telemetry.app.invalid')]
        #[OA\Property(description: 'Frontend that emitted the events', enum: ['skate', 'nutrition', 'habits'], example: 'skate')]
        public string $app,

        #[Assert\NotBlank(message: 'telemetry.session_id.blank')]
        #[Assert\Uuid(message: 'telemetry.session_id.invalid')]
        #[OA\Property(description: 'Random UUID generated per browser tab', format: 'uuid', example: '6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f')]
        public string $sessionId,

        #[Assert\Count(min: 1, max: self::MAX_EVENTS, minMessage: 'telemetry.events.min', maxMessage: 'telemetry.events.max')]
        #[Assert\Valid]
        #[OA\Property(description: 'Between 1 and 100 events', minItems: 1, maxItems: 100)]
        public array $events,
    ) {
    }
}
