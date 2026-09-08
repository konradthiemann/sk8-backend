<?php

declare(strict_types=1);

namespace App\Dto\Telemetry;

use OpenApi\Attributes as OA;

/**
 * Response body of POST /api/telemetry/events.
 */
final readonly class TelemetryAcceptedResponse
{
    public function __construct(
        #[OA\Property(description: 'Number of events that were stored', example: 2)]
        public int $accepted,
    ) {
    }
}
