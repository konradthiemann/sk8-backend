<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Habit;
use App\Entity\HabitEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Habit>
 */
final class HabitRepository extends ServiceEntityRepository implements HabitCatalogStoreInterface, HabitReaderInterface
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

    public function findSlugsWithEntries(): array
    {
        /** @var list<array{slug: string}> $rows */
        $rows = $this->getEntityManager()
            ->createQuery('SELECT h.slug FROM '.Habit::class.' h WHERE EXISTS (SELECT 1 FROM '.HabitEntry::class.' e WHERE e.habit = h) ORDER BY h.slug')
            ->getScalarResult();

        return array_map(static fn (array $row): string => $row['slug'], $rows);
    }

    public function findHabitById(string $habitId): ?Habit
    {
        if (!Uuid::isValid($habitId)) {
            return null;
        }

        return $this->find(Uuid::fromString($habitId));
    }

    /**
     * One query: `SELECT h, e ... LEFT JOIN e WITH e.habit = h AND e.entryDate = :date`. The day
     * condition sits in the join (in a WHERE it would drop habits without an entry). Doctrine
     * hydrates an entity join without an association as one flat list - habit, entry or null,
     * habit, entry or null, ... - not as pairs, so the entries are matched to their habit by the
     * habit ID instead of by position.
     *
     * @return list<HabitDayRow>
     */
    public function findActiveWithEntry(\DateTimeImmutable $date): array
    {
        /** @var list<Habit|HabitEntry|null> $hydrated */
        $hydrated = $this->createQueryBuilder('h')
            ->addSelect('e')
            ->leftJoin(HabitEntry::class, 'e', 'WITH', 'e.habit = h AND e.entryDate = :date')
            ->where('h.isActive = true')
            ->orderBy('h.sortOrder', 'ASC')
            ->addOrderBy('h.name', 'ASC')
            ->addOrderBy('h.slug', 'ASC')
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        $habits = [];
        $entriesByHabitId = [];
        foreach ($hydrated as $item) {
            if ($item instanceof Habit) {
                $habits[] = $item;
            } elseif ($item instanceof HabitEntry) {
                $entriesByHabitId[$item->getHabit()->getId()->toRfc4122()] = $item;
            }
        }

        return array_map(
            static fn (Habit $habit): HabitDayRow => new HabitDayRow($habit, $entriesByHabitId[$habit->getId()->toRfc4122()] ?? null),
            $habits,
        );
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
