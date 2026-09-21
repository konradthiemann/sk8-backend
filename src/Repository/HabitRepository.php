<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Habit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Habit>
 */
final class HabitRepository extends ServiceEntityRepository implements HabitCatalogStoreInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Habit::class);
    }

    /**
     * The catalog for GET /api/habits: `sort_order` ascending, ties broken by
     * `name`, then `slug` (which makes the order total). Inactive rows only
     * when asked for.
     *
     * @return list<Habit>
     */
    public function findCatalog(bool $includeInactive): array
    {
        $builder = $this->createQueryBuilder('h')
            ->orderBy('h.sortOrder', 'ASC')
            ->addOrderBy('h.name', 'ASC')
            ->addOrderBy('h.slug', 'ASC');

        if (!$includeInactive) {
            $builder->where('h.isActive = true');
        }

        /** @var list<Habit> $result */
        $result = $builder->getQuery()->getResult();

        return $result;
    }

    public function findAllIndexedBySlug(): array
    {
        /** @var list<Habit> $rows */
        $rows = $this->createQueryBuilder('h')
            ->getQuery()
            ->getResult();

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row->getSlug()] = $row;
        }

        return $bySlug;
    }

    public function add(Habit $habit): void
    {
        $this->getEntityManager()->persist($habit);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
