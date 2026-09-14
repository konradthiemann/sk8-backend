<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use OpenApi\Attributes as OA;

/**
 * `pauseHint` of GET /api/trick-recommendation's response (T-0202 design.md
 * §3); the endpoint returns `null` when neither threshold is met.
 */
final readonly class PauseHintView
{
    public function __construct(
        #[OA\Property(description: 'Machine-readable pause reason', example: 'knee_pain')]
        public string $code,
        #[OA\Property(description: 'German explanation for the pause hint', example: 'Dein Knie meldet sich stärker als sonst – heute lieber kürzer treten oder pausieren, bei anhaltenden Schmerzen ärztlich abklären lassen.')]
        public string $message,
    ) {
    }
}
