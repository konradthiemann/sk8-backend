<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Habit;
use App\Entity\HabitEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HabitEntry>
 */
final class HabitEntryRepository extends ServiceEntityRepository implements HabitEntryStoreInterface
{
    public function __construct(private readonly ManagerRegistry $registry)
    {
        parent::__construct($registry, HabitEntry::class);
    }

    public function findByHabitAndDate(Habit $habit, \DateTimeImmutable $date): ?HabitEntry
    {
        return $this->findOneBy(['habit' => $habit, 'entryDate' => $date]);
    }

    public function insert(HabitEntry $entry): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entry);

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // A failed flush closes the entity manager. resetManager() reopens it (the injected
            // entity manager is a lazy proxy that re-initializes itself), so the caller can load
            // and change the row the concurrent request wrote.
            $this->registry->resetManager();

            throw new HabitEntryAlreadyExistsException('A concurrent request already recorded this day.', 0, $exception);
        }
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function remove(HabitEntry $entry): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->remove($entry);
        $entityManager->flush();
    }
}
