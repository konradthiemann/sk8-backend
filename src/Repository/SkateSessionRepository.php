<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SkateSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SkateSession>
 */
final class SkateSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SkateSession::class);
    }

    /**
     * Filtered, sorted, limited list of sessions with their trick rows
     * eagerly loaded (GET /api/skate-sessions).
     *
     * Deliberately two queries, not one fetch-joined query with
     * setMaxResults() (T-0102 design.md §4.4): a LEFT JOIN to the to-many
     * `tricks` relation turns setMaxResults() into a limit on *join rows*,
     * not root sessions, and silently returns too few, incompletely
     * hydrated sessions. Step 1 picks the page of session IDs (no join
     * needed); step 2 loads those sessions with their tricks fetch-joined.
     * Doctrine does not preserve `IN (:ids)` row order, so the
     * `sessionDate DESC, createdAt DESC` ordering from step 1 is re-applied
     * here before returning.
     *
     * @return list<SkateSession>
     */
    public function findFiltered(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit): array
    {
        $idQuery = $this->createQueryBuilder('s')
            ->select('s.id')
            ->orderBy('s.sessionDate', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit);
        $this->applyDateFilter($idQuery, $from, $to);

        /** @var list<array{id: mixed}> $rows */
        $rows = $idQuery->getQuery()->getScalarResult();
        $ids = array_map(self::idToUuid(...), $rows);

        if ([] === $ids) {
            return [];
        }

        /** @var list<SkateSession> $sessions */
        $sessions = $this->createQueryBuilder('s')
            ->leftJoin('s.tricks', 't')
            ->addSelect('t')
            ->leftJoin('t.trick', 'tr')
            ->addSelect('tr')
            ->where('s.id IN (:ids)')
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

        /** @var list<SkateSession> */
        return array_values(array_filter($ordered));
    }

    /**
     * Total match count of the same filter, *before* `limit` is applied.
     */
    public function countFiltered(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        $query = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)');
        $this->applyDateFilter($query, $from, $to);

        /** @var int|numeric-string $count */
        $count = $query->getQuery()->getSingleScalarResult();

        return (int) $count;
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

    private function applyDateFilter(QueryBuilder $queryBuilder, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        if (null !== $from) {
            $queryBuilder->andWhere('s.sessionDate >= :from')->setParameter('from', $from);
        }

        if (null !== $to) {
            $queryBuilder->andWhere('s.sessionDate <= :to')->setParameter('to', $to);
        }
    }
}
