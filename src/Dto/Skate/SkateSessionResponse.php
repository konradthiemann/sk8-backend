<?php

declare(strict_types=1);

namespace App\Dto\Skate;

use App\Entity\SessionTrick;
use App\Entity\SkateSession;
use App\Service\Skate\SessionMetrics;
use OpenApi\Attributes as OA;

/**
 * Response body of POST, GET (detail) and PUT /api/skate-sessions{/id}.
 */
final readonly class SkateSessionResponse
{
    /**
     * @param list<SessionTrickResponse> $tricks
     */
    public function __construct(
        #[OA\Property(description: 'Session ID', example: '01997d11-4c02-7a3e-8b55-2d9f10e4a7c1')]
        public string $id,
        #[OA\Property(description: 'Session date, day precision', format: 'date', example: '2026-09-06')]
        public string $sessionDate,
        #[OA\Property(description: 'When the session started (ISO 8601 with offset)', format: 'date-time', example: '2026-09-06T14:30:00+00:00', nullable: true)]
        public ?string $startedAt,
        #[OA\Property(description: 'Duration in minutes', example: 95)]
        public int $durationMinutes,
        #[OA\Property(description: 'Where the session took place', example: 'Skatepark Braunschweig')]
        public string $location,
        #[OA\Property(description: 'Body weight before the session, in kg', example: 78.4, nullable: true)]
        public ?float $weightBeforeKg,
        #[OA\Property(description: 'Body weight after the session, in kg', example: 77.1, nullable: true)]
        public ?float $weightAfterKg,
        #[OA\Property(description: 'Derived: weightBeforeKg - weightAfterKg, rounded to two decimals; null if either weight is missing; may be negative', example: 1.3, nullable: true)]
        public ?float $fluidLossKg,
        #[OA\Property(description: 'Perceived exertion, 1 to 10', example: 7, nullable: true)]
        public ?int $perceivedExertion,
        #[OA\Property(description: 'Knee pain, 0 to 10', example: 3, nullable: true)]
        public ?int $kneePain,
        #[OA\Property(description: 'Free-form notes', example: 'Manuals liefen gut, Knie ab 60 Minuten spuerbar.', nullable: true)]
        public ?string $notes,
        #[OA\Property(description: 'When this session was created', format: 'date-time', example: '2026-09-06T18:12:04+00:00')]
        public string $createdAt,
        /** @var list<SessionTrickResponse> */
        #[OA\Property(description: 'Practiced tricks, in the order they were added')]
        public array $tricks,
        #[OA\Property(description: 'Derived: sum of all tricks[].attempts, 0 without tricks', example: 54)]
        public int $totalAttempts,
        #[OA\Property(description: 'Derived: sum of all tricks[].landed', example: 30)]
        public int $totalLanded,
        #[OA\Property(description: 'Derived: totalLanded / totalAttempts, rounded to three decimals; null when totalAttempts is 0', example: 0.556, nullable: true)]
        public ?float $successRate,
    ) {
    }

    public static function fromEntity(SkateSession $session): self
    {
        $trickRows = array_values($session->getTricks()->toArray());
        $tricks = array_map(SessionTrickResponse::fromEntity(...), $trickRows);

        $totalAttempts = 0;
        $totalLanded = 0;
        foreach ($trickRows as $row) {
            \assert($row instanceof SessionTrick);
            $totalAttempts += $row->getAttempts();
            $totalLanded += $row->getLanded();
        }

        return new self(
            $session->getId()->toRfc4122(),
            $session->getSessionDate()->format('Y-m-d'),
            $session->getStartedAt()?->format(\DateTimeInterface::ATOM),
            $session->getDurationMinutes(),
            $session->getLocation(),
            SessionMetrics::weightKg($session->getWeightBeforeKg()),
            SessionMetrics::weightKg($session->getWeightAfterKg()),
            SessionMetrics::fluidLossKg($session->getWeightBeforeKg(), $session->getWeightAfterKg()),
            $session->getPerceivedExertion(),
            $session->getKneePain(),
            $session->getNotes(),
            $session->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $tricks,
            $totalAttempts,
            $totalLanded,
            SessionMetrics::successRate($totalAttempts, $totalLanded),
        );
    }
}
