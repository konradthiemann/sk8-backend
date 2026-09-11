<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BodyWeight;
use App\Enum\BodyWeightContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BodyWeight>
 */
final class BodyWeightRepository extends ServiceEntityRepository implements BodyWeightRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BodyWeight::class);
    }

    /**
     * Every row belonging to one skate session, oldest first (T-0103
     * design.md §4).
     *
     * @return list<BodyWeight>
     */
    public function findBySession(Uuid $sessionId): array
    {
        /** @var list<BodyWeight> $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.skateSession = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->orderBy('b.measuredAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The at-most-one row a session has for a given context - what
     * App\Service\Body\BodyWeightSynchronizer::syncRow() reads before
     * deciding whether to create, update or remove.
     */
    public function findOneBySessionAndContext(Uuid $sessionId, BodyWeightContext $context): ?BodyWeight
    {
        /** @var BodyWeight|null $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.skateSession = :sessionId')
            ->andWhere('b.context = :context')
            ->setParameter('sessionId', $sessionId)
            ->setParameter('context', $context)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }
}
