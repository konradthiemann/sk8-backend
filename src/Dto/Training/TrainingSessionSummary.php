<?php

declare(strict_types=1);

namespace App\Dto\Training;

use App\Entity\TrainingSession;
use App\Entity\TrainingSet;
use App\Enum\KneeLoad;
use OpenApi\Attributes as OA;

/**
 * One entry of GET /api/training-sessions (TrainingSessionListResponse.items).
 * setCount, exerciseCount and maxKneeLoad are derived here, never stored
 * (DATENMODELL.md: "abgeleitete Werte gehören nicht in die Tabelle") - same
 * convention as App\Dto\Skate\SkateSessionSummary.
 */
final readonly class TrainingSessionSummary
{
    public function __construct(
        #[OA\Property(description: 'Session ID', example: '0192f4b8-9a31-7c02-8e14-5b7d3f9a1c40')]
        public string $id,
        #[OA\Property(description: 'Session date, day precision', format: 'date', example: '2026-09-08')]
        public string $sessionDate,
        #[OA\Property(description: 'Duration in minutes', example: 38)]
        public int $durationMinutes,
        #[OA\Property(description: 'Perceived exertion, 1 to 10', example: 7, nullable: true)]
        public ?int $perceivedExertion,
        #[OA\Property(description: 'Knee pain, 0 to 10', example: 2, nullable: true)]
        public ?int $kneePain,
        #[OA\Property(description: 'Free-form notes', example: 'Ringe am Tuerrahmen, Knie ruhig', nullable: true)]
        public ?string $notes,
        #[OA\Property(description: 'Number of set rows', example: 4)]
        public int $setCount,
        #[OA\Property(description: 'Number of distinct exercises among the sets', example: 2)]
        public int $exerciseCount,
        #[OA\Property(description: 'Derived: highest knee load among the session\'s exercises (KneeLoad::rank())', example: 'niedrig')]
        public string $maxKneeLoad,
        #[OA\Property(description: 'When this session was created', format: 'date-time', example: '2026-09-08T19:04:11+00:00')]
        public string $createdAt,
    ) {
    }

    public static function fromEntity(TrainingSession $session): self
    {
        $sets = array_values($session->getSets()->toArray());

        return new self(
            $session->getId()->toRfc4122(),
            $session->getSessionDate()->format('Y-m-d'),
            $session->getDurationMinutes(),
            $session->getPerceivedExertion(),
            $session->getKneePain(),
            $session->getNotes(),
            \count($sets),
            self::exerciseCount($sets),
            self::maxKneeLoad($sets)->value,
            $session->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<TrainingSet> $sets
     */
    private static function exerciseCount(array $sets): int
    {
        $ids = array_map(
            static fn (TrainingSet $set): string => $set->getExercise()->getId()->toRfc4122(),
            $sets,
        );

        return \count(array_unique($ids));
    }

    /**
     * @param list<TrainingSet> $sets
     */
    private static function maxKneeLoad(array $sets): KneeLoad
    {
        $max = KneeLoad::None;
        foreach ($sets as $set) {
            $load = $set->getExercise()->getKneeLoad();
            if ($load->rank() > $max->rank()) {
                $max = $load;
            }
        }

        return $max;
    }
}
