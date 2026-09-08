<?php

declare(strict_types=1);

namespace App\Dto\Health;

use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/health.
 */
final readonly class HealthResponse
{
    public function __construct(
        #[OA\Property(example: 'ok')]
        public string $status,

        #[OA\Property(description: 'Server time in UTC (ISO 8601)', format: 'date-time', example: '2026-09-07T10:00:00+00:00')]
        public string $time,
    ) {
    }
}
