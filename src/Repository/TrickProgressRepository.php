<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrickProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TrickProgress>
 */
final class TrickProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrickProgress::class);
    }

    /**
     * One row per trick that has at least one session_trick row: total
     * attempts/landed, the first session with a landed attempt and the most
     * recent practiced session, and how many sessions the trick appeared in
     * (design.md §2). A trick with no sessions at all simply has no entry -
     * the caller (TrickTreeService) fills in the zero/null defaults.
     *
     * COUNT(st.id) instead of COUNT(DISTINCT ...): uniq_session_trick already
     * guarantees at most one session_trick row per (skate_session_id,
     * trick_id), so one row *is* one session for this trick.
     *
     * Deviation from design.md §2: the design's single query computes
     * firstLandedOn via `MIN(CASE WHEN st.landed > 0 THEN ss.sessionDate
     * ELSE NULL END)`, but DQL's GeneralCaseExpression grammar requires its
     * "ELSE" branch to be a ScalarExpression and has no NULL literal (only
     * IS [NOT] NULL comparisons and NULLIF/COALESCE, neither of which fits
     * here without a type mismatch against a date column) - confirmed by a
     * "[Syntax Error] ... Unexpected 'NULL'" respectively "Expected T_ELSE,
     * got 'END'" from the actual parser. Split into two queries instead: the
     * general aggregates below, and firstLandedOnByTrickId() (a plain
     * MIN(...) with a WHERE filter, no CASE needed at all) merged into the
     * same result array.
     *
     * @return array<string, array{attemptsTotal: int, landedTotal: int, firstLandedOn: ?\DateTimeImmutable, lastPracticedOn: ?\DateTimeImmutable, sessionCount: int}>
     */
    public function aggregatesByTrickId(): array
    {
        $rows = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT
                IDENTITY(st.trick) AS trickId,
                SUM(st.attempts) AS attemptsTotal,
                SUM(st.landed) AS landedTotal,
                MAX(ss.sessionDate) AS lastPracticedOn,
                COUNT(st.id) AS sessionCount
            FROM App\Entity\SessionTrick st
            JOIN st.skateSession ss
            GROUP BY st.trick
            DQL)->getResult();

        $aggregates = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));

            $aggregates[self::trickIdKey($row['trickId'])] = [
                'attemptsTotal' => (int) $row['attemptsTotal'],
                'landedTotal' => (int) $row['landedTotal'],
                'firstLandedOn' => null,
                'lastPracticedOn' => self::toDateImmutable($row['lastPracticedOn']),
                'sessionCount' => (int) $row['sessionCount'],
            ];
        }

        foreach ($this->firstLandedOnByTrickId() as $trickId => $firstLandedOn) {
            if (isset($aggregates[$trickId])) {
                $aggregates[$trickId]['firstLandedOn'] = $firstLandedOn;
            }
        }

        return $aggregates;
    }

    /**
     * The earliest session date with at least one landed attempt, per trick
     * - see aggregatesByTrickId()'s doc comment for why this is its own
     * query rather than a CASE expression inside the general aggregation.
     *
     * @return array<string, \DateTimeImmutable>
     */
    private function firstLandedOnByTrickId(): array
    {
        $rows = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT
                IDENTITY(st.trick) AS trickId,
                MIN(ss.sessionDate) AS firstLandedOn
            FROM App\Entity\SessionTrick st
            JOIN st.skateSession ss
            WHERE st.landed > 0
            GROUP BY st.trick
            DQL)->getResult();

        $firstLandedOnByTrickId = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));

            $firstLandedOn = self::toDateImmutable($row['firstLandedOn']);
            \assert(null !== $firstLandedOn);
            $firstLandedOnByTrickId[self::trickIdKey($row['trickId'])] = $firstLandedOn;
        }

        return $firstLandedOnByTrickId;
    }

    /**
     * The most recent `$limit` sessions per trick, newest first - one query
     * sorted `trick_id, session_date DESC`, cut to size in PHP (design.md §2:
     * single-digit data volume per trick, a ROW_NUMBER() window function
     * would be YAGNI).
     *
     * @return array<string, list<array{sessionDate: string, attempts: int, landed: int}>>
     */
    public function recentSessionsByTrickId(int $limit): array
    {
        $rows = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT
                IDENTITY(st.trick) AS trickId,
                ss.sessionDate AS sessionDate,
                st.attempts AS attempts,
                st.landed AS landed
            FROM App\Entity\SessionTrick st
            JOIN st.skateSession ss
            ORDER BY trickId ASC, sessionDate DESC
            DQL)->getResult();

        $byTrick = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));

            $trickId = self::trickIdKey($row['trickId']);
            if (\count($byTrick[$trickId] ?? []) >= $limit) {
                continue; // already sorted trick_id, session_date DESC - later rows of the same group are older
            }

            $sessionDate = self::toDateImmutable($row['sessionDate']);
            \assert(null !== $sessionDate, 'session_date is NOT NULL in the database');

            $byTrick[$trickId][] = [
                'sessionDate' => $sessionDate->format('Y-m-d'),
                'attempts' => (int) $row['attempts'],
                'landed' => (int) $row['landed'],
            ];
        }

        return $byTrick;
    }

    /**
     * The most recent `$limit` sessions of a single trick, newest first
     * (T-0202 design.md §2). Unlike recentSessionsByTrickId() (which loads
     * every trick at once and cuts to size in PHP because it cannot filter
     * by trick in SQL), a real `LIMIT` clause is enough here: only one trick
     * is involved. `sessionId` is `IDENTITY(st.skateSession)` - the
     * skate_session's own id, not the session_trick row's id (the ticket's
     * example JSON keys the same session across multiple fields by this
     * UUID).
     *
     * @return list<array{sessionId: string, sessionDate: string, attempts: int, landed: int, notes: ?string}>
     */
    public function historyByTrickId(Uuid $trickId, int $limit): array
    {
        $rows = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT
                IDENTITY(st.skateSession) AS sessionId,
                ss.sessionDate AS sessionDate,
                st.attempts AS attempts,
                st.landed AS landed,
                st.notes AS notes
            FROM App\Entity\SessionTrick st
            JOIN st.skateSession ss
            WHERE st.trick = :trickId
            ORDER BY ss.sessionDate DESC, ss.id DESC
            DQL)
            ->setParameter('trickId', $trickId)
            ->setMaxResults($limit)
            ->getResult();

        $history = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));

            $sessionDate = self::toDateImmutable($row['sessionDate']);
            \assert(null !== $sessionDate, 'session_date is NOT NULL in the database');

            $notes = $row['notes'];
            \assert(null === $notes || \is_string($notes));

            $history[] = [
                'sessionId' => self::trickIdKey($row['sessionId']),
                'sessionDate' => $sessionDate->format('Y-m-d'),
                'attempts' => (int) $row['attempts'],
                'landed' => (int) $row['landed'],
                'notes' => $notes,
            ];
        }

        return $history;
    }

    /**
     * Existing trick_progress rows, indexed by their trick's id
     * (`trick.getId()->toRfc4122()`) - the lookup TrickProgressRefresher
     * needs to decide "update in place" vs. "create new" per trick.
     *
     * @return array<string, TrickProgress>
     */
    public function findAllIndexedByTrickId(): array
    {
        $rows = $this->createQueryBuilder('tp')
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            \assert($row instanceof TrickProgress);
            $indexed[$row->getTrick()->getId()->toRfc4122()] = $row;
        }

        return $indexed;
    }

    /**
     * `IDENTITY(st.trick)` is expected to already come back as a
     * Symfony\Component\Uid\Uuid (the join column's mapped type), but this
     * normalizes defensively in case a given Doctrine/DBAL version hands back
     * the raw string instead - either way the result is the same
     * RFC 4122 string that TrickProgress/Trick keys use everywhere else.
     */
    private static function trickIdKey(mixed $value): string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }

        \assert(\is_string($value), 'trickId must be a Uuid or a string');

        return $value;
    }

    /**
     * Normalizes a DQL scalar result cell that is expected to be a date:
     * defensively accepts null, a DateTimeInterface, or the raw 'Y-m-d'
     * string a CASE/aggregate expression's type inference might fall back to.
     */
    private static function toDateImmutable(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        \assert(\is_string($value));

        return new \DateTimeImmutable($value);
    }
}
