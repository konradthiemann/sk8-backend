<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FitnessAssessment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * No joins are involved, so plain findBy()/count() are enough here - unlike
 * App\Repository\TrainingSessionRepository::findPage(), which needs two
 * queries because of its to-many `sets` relation.
 *
 * @extends ServiceEntityRepository<FitnessAssessment>
 */
final class FitnessAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FitnessAssessment::class);
    }

    /**
     * Newest first. `assessed_on` is unique, so the order is total and needs
     * no tie-breaker; the unique index serves the sort.
     *
     * @return list<FitnessAssessment>
     */
    public function findPage(int $limit, int $offset): array
    {
        return array_values($this->findBy([], ['assessedOn' => 'DESC'], $limit, $offset));
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    public function existsForDate(\DateTimeImmutable $date): bool
    {
        return $this->count(['assessedOn' => $date]) > 0;
    }
}
