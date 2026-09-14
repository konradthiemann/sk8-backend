<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only DQL queries over App\Entity\SkateSession for the pause-hint
 * signal of GET /api/trick-recommendation (T-0202 design.md §2). A plain
 * `final readonly class` with EntityManagerInterface, deliberately not a
 * `ServiceEntityRepository<SkateSession>`: SkateSession already has its one
 * canonical repository (App\Repository\SkateSessionRepository, T-0102) - a
 * second ServiceEntityRepository for the same entity would leave it unclear
 * which one is "the" repository. See design.md §2 for the full reasoning.
 */
final readonly class SkateSessionLoadRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * knee_pain of the most recent session with a recorded value, sorted
     * session_date DESC, id DESC - a more recent session with no value at
     * all must not hide an older session's real number.
     */
    public function lastKneePain(): ?int
    {
        $rows = $this->entityManager->createQuery(<<<'DQL'
            SELECT ss.kneePain AS kneePain
            FROM App\Entity\SkateSession ss
            WHERE ss.kneePain IS NOT NULL
            ORDER BY ss.sessionDate DESC, ss.id DESC
            DQL)
            ->setMaxResults(1)
            ->getResult();

        if ([] === $rows) {
            return null;
        }

        \assert(\is_array($rows[0]));

        return (int) $rows[0]['kneePain'];
    }

    /**
     * Number of consecutive calendar days with at least one session,
     * counted backwards from $today (or from the day before, if $today
     * itself has no session yet) - design.md §6, "Algorithmus
     * consecutiveSessionDays". Two sessions on the same day count as one day
     * (DISTINCT); a one-day gap stops the count immediately. Loads every
     * distinct session_date unfiltered and walks backwards in PHP rather
     * than a SQL window function (design.md §7, "Offene Frage 3"): a
     * realistic row count here never gets large enough to matter.
     */
    public function consecutiveSessionDays(\DateTimeImmutable $today): int
    {
        $rows = $this->entityManager->createQuery(<<<'DQL'
            SELECT DISTINCT ss.sessionDate AS sessionDate
            FROM App\Entity\SkateSession ss
            DQL)->getResult();

        $sessionDays = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));
            $sessionDays[self::toDateKey($row['sessionDate'])] = true;
        }

        $cursor = $today;
        if (!isset($sessionDays[$cursor->format('Y-m-d')])) {
            $cursor = $cursor->modify('-1 day');
        }

        $count = 0;
        while (isset($sessionDays[$cursor->format('Y-m-d')])) {
            ++$count;
            $cursor = $cursor->modify('-1 day');
        }

        return $count;
    }

    /**
     * Normalizes a DQL scalar result cell that is expected to be a date -
     * same defensive pattern as App\Repository\TrickProgressRepository::toDateImmutable().
     */
    private static function toDateKey(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        \assert(\is_string($value));

        return (new \DateTimeImmutable($value))->format('Y-m-d');
    }
}
