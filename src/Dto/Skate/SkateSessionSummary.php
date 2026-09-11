<?php

declare(strict_types=1);

namespace App\Dto\Skate;

use App\Entity\SessionTrick;
use App\Entity\SkateSession;
use App\Service\Skate\SessionMetrics;
use OpenApi\Attributes as OA;

/**
 * One entry of GET /api/skate-sessions (SkateSessionListResponse.items).
 * Deliberately slim: no trick rows, no notes - the week overview only needs
 * the totals (design.md §3, "GET-Liste").
 */
final readonly class SkateSessionSummary
{
    public function __construct(
        #[OA\Property(description: 'Session ID', example: '01997d11-4c02-7a3e-8b55-2d9f10e4a7c1')]
        public string $id,
        #[OA\Property(description: 'Session date, day precision', format: 'date', example: '2026-09-06')]
        public string $sessionDate,
        #[OA\Property(description: 'Duration in minutes', example: 95)]
        public int $durationMinutes,
        #[OA\Property(description: 'Where the session took place', example: 'Skatepark Braunschweig')]
        public string $location,
        #[OA\Property(description: 'Number of practiced-trick rows', example: 2)]
        public int $trickCount,
        #[OA\Property(description: 'Derived: sum of all tricks[].attempts, 0 without tricks', example: 54)]
        public int $totalAttempts,
        #[OA\Property(description: 'Derived: sum of all tricks[].landed', example: 30)]
        public int $totalLanded,
        #[OA\Property(description: 'Derived: totalLanded / totalAttempts, rounded to three decimals; null when totalAttempts is 0', example: 0.556, nullable: true)]
        public ?float $successRate,
        #[OA\Property(description: 'Derived: weightBeforeKg - weightAfterKg, rounded to two decimals; null if either weight is missing', example: 1.3, nullable: true)]
        public ?float $fluidLossKg,
        #[OA\Property(description: 'Perceived exertion, 1 to 10', example: 7, nullable: true)]
        public ?int $perceivedExertion,
        #[OA\Property(description: 'Knee pain, 0 to 10', example: 3, nullable: true)]
        public ?int $kneePain,
    ) {
    }

    public static function fromEntity(SkateSession $session): self
    {
        $trickRows = $session->getTricks()->toArray();

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
            $session->getDurationMinutes(),
            $session->getLocation(),
            \count($trickRows),
            $totalAttempts,
            $totalLanded,
            SessionMetrics::successRate($totalAttempts, $totalLanded),
            SessionMetrics::fluidLossKg($session->getWeightBeforeKg(), $session->getWeightAfterKg()),
            $session->getPerceivedExertion(),
            $session->getKneePain(),
        );
    }
}
