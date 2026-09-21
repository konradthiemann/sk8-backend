<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrainingSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TrainingSession>
 */
final class TrainingSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSession::class);
    }

    /**
     * Sorted, limited page of sessions with their sets and exercises eagerly
     * loaded (GET /api/training-sessions, design.md §4). Deliberately two
     * queries, not one fetch-joined query with setMaxResults() - a LEFT JOIN
     * to the to-many `sets` relation turns setMaxResults() into a limit on
     * *join rows*, not root sessions, and silently returns too few,
     * incompletely hydrated sessions (documented bug, same pattern as
     * App\Repository\SkateSessionRepository::findFiltered()). Step 1 picks
     * the page of session IDs (no join needed); step 2 loads those sessions
     * with their sets and exercises fetch-joined. Doctrine does not preserve
     * `IN (:ids)` row order, so the `sessionDate DESC, id DESC` ordering
     * from step 1 is re-applied here before returning.
     *
     * @return list<TrainingSession>
     */
    public function findPage(int $limit, int $offset): array
    {
        $idQuery = $this->createQueryBuilder('ts')
            ->select('ts.id')
            ->orderBy('ts.sessionDate', 'DESC')
            ->addOrderBy('ts.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /** @var list<array{id: mixed}> $rows */
        $rows = $idQuery->getQuery()->getScalarResult();
        $ids = array_map(self::idToUuid(...), $rows);

        if ([] === $ids) {
            return [];
        }

        /** @var list<TrainingSession> $sessions */
        $sessions = $this->createQueryBuilder('ts')
            ->leftJoin('ts.sets', 's')
            ->addSelect('s')
            ->leftJoin('s.exercise', 'e')
            ->addSelect('e')
            ->where('ts.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($sessions as $session) {
            $byId[$session->getId()->toRfc4122()] = $session;
        }

        $ordered = [];
        foreach ($ids as $id) {
            $ordered[] = $byId[$id->toRfc4122()] ?? null;
        }

        /** @var list<TrainingSession> */
        return array_values(array_filter($ordered));
    }

    public function countAll(): int
    {
        /** @var int|numeric-string $count */
        $count = $this->createQueryBuilder('ts')
            ->select('COUNT(ts.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * One session with its sets and their exercises fetch-joined in a single
     * query (GET /api/training-sessions/{id}, design.md §3/§4) - no N+1 when
     * the response walks every set's exercise for maxKneeLoad/exerciseCount.
     */
    public function findOneWithSets(Uuid $id): ?TrainingSession
    {
        /** @var TrainingSession|null $result */
        $result = $this->createQueryBuilder('ts')
            ->leftJoin('ts.sets', 's')
            ->addSelect('s')
            ->leftJoin('s.exercise', 'e')
            ->addSelect('e')
            ->where('ts.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * @param array{id: mixed} $row
     */
    private static function idToUuid(array $row): Uuid
    {
        $id = $row['id'];
        if ($id instanceof Uuid) {
            return $id;
        }

        if (\is_string($id)) {
            return Uuid::fromString($id);
        }

        throw new \UnexpectedValueException('Expected the "id" scalar result to be a string or Uuid.');
    }
}
