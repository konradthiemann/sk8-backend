<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Trick;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trick>
 */
final class TrickRepository extends ServiceEntityRepository implements TrickSlugProviderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trick::class);
    }

    /**
     * The full catalog, sorted by difficulty and then name (GET /api/tricks
     * contract, design.md §3). Fetch-joins prerequisites and their required
     * trick in one query to avoid N+1 lazy loads; unbounded result set is
     * fine at 16 rows.
     *
     * @return list<Trick>
     */
    public function findAllOrdered(): array
    {
        /** @var list<Trick> $result */
        $result = $this->createQueryBuilder('t')
            ->leftJoin('t.prerequisites', 'p')
            ->addSelect('p')
            ->leftJoin('p.requiresTrick', 'r')
            ->addSelect('r')
            ->orderBy('t.difficulty', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * All slugs of the catalog. Backs App\Validator\ExistingTrickSlugValidator
     * via the TrickSlugProviderInterface seam (T-0102).
     *
     * @return list<string>
     */
    public function findSlugs(): array
    {
        /** @var list<string> $slugs */
        $slugs = array_column(
            $this->createQueryBuilder('t')
                ->select('t.slug')
                ->getQuery()
                ->getScalarResult(),
            'slug',
        );

        return $slugs;
    }

    /**
     * Resolves the catalog tricks referenced by a skate session's request
     * payload in one query (T-0102 design.md §4.2). The caller decides what
     * to do with a slug that yields no row - by the time this runs, every
     * slug has already passed ExistingTrickSlugValidator.
     *
     * @param list<string> $slugs
     *
     * @return list<Trick>
     */
    public function findBySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        /** @var list<Trick> $result */
        $result = $this->createQueryBuilder('t')
            ->where('t.slug IN (:slugs)')
            ->setParameter('slugs', $slugs)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
