<?php

declare(strict_types=1);

namespace App\Dto\Skate;

use App\Entity\SessionTrick;
use App\Service\Skate\SessionMetrics;
use OpenApi\Attributes as OA;

/**
 * One practiced-trick row inside SkateSessionResponse.tricks.
 */
final readonly class SessionTrickResponse
{
    public function __construct(
        #[OA\Property(description: 'Trick row ID', example: '01997d11-4c02-7a3e-8b55-2d9f10e4a7c2')]
        public string $id,
        #[OA\Property(description: 'Slug of the practiced catalog trick', example: 'ollie')]
        public string $trickSlug,
        #[OA\Property(description: 'Catalog display name, spares a second lookup', example: 'Ollie')]
        public string $trickName,
        #[OA\Property(description: 'Number of attempts', example: 30)]
        public int $attempts,
        #[OA\Property(description: 'Number of landed attempts', example: 21)]
        public int $landed,
        #[OA\Property(description: 'landed / attempts, rounded to three decimals; never null since attempts >= 1', example: 0.7)]
        public float $successRate,
        #[OA\Property(description: 'Optional note for this trick row', example: null, nullable: true)]
        public ?string $notes,
    ) {
    }

    public static function fromEntity(SessionTrick $sessionTrick): self
    {
        $attempts = $sessionTrick->getAttempts();
        $landed = $sessionTrick->getLanded();
        $successRate = SessionMetrics::successRate($attempts, $landed);

        // The chk_session_trick_attempts CHECK constraint guarantees attempts
        // >= 1, so successRate(...) never returns null for a persisted row.
        \assert(null !== $successRate);

        return new self(
            $sessionTrick->getId()->toRfc4122(),
            $sessionTrick->getTrick()->getSlug(),
            $sessionTrick->getTrick()->getName(),
            $attempts,
            $landed,
            $successRate,
            $sessionTrick->getNotes(),
        );
    }
}
