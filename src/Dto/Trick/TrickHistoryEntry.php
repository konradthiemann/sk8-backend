<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use OpenApi\Attributes as OA;

/**
 * One entry of `history` in GET /api/tricks/{slug}'s response (T-0202
 * design.md §3) - one row per session the trick was practiced in.
 */
final readonly class TrickHistoryEntry
{
    public function __construct(
        #[OA\Property(description: 'ID of the skate session this entry belongs to', example: '0192f3a1-7c4e-7b21-9f0a-6d2c1b8e4a55')]
        public string $sessionId,
        #[OA\Property(description: 'Date of the session', format: 'date', example: '2026-09-06')]
        public string $sessionDate,
        #[OA\Property(description: 'Attempts in this session', example: 24)]
        public int $attempts,
        #[OA\Property(description: 'Landed attempts in this session', example: 7)]
        public int $landed,
        #[OA\Property(description: 'landed / attempts, three decimals; null when attempts is 0', example: 0.292, nullable: true)]
        public ?float $successRate,
        #[OA\Property(description: 'Optional free-text note for this trick within this session', example: 'Rotation zu flach', nullable: true)]
        public ?string $notes,
    ) {
    }
}
