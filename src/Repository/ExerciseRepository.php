<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Exercise>
 */
final class ExerciseRepository extends ServiceEntityRepository implements ExerciseSlugProviderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exercise::class);
    }

    /**
     * The full catalog, prevention exercises first (GET /api/exercises
     * contract, T-0301 design.md §3): `is_prevention` descending, then
     * `name` ascending.
     *
     * @return list<Exercise>
     */
    public function findAllOrdered(): array
    {
        /** @var list<Exercise> $result */
        $result = $this->createQueryBuilder('e')
            ->orderBy('e.isPrevention', 'DESC')
            ->addOrderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Every row keyed by its slug, for App\Command\SyncExercisesCommand to
     * diff the catalog file against without one query per entry.
     *
     * @return array<string, Exercise>
     */
    public function findAllIndexedBySlug(): array
    {
        /** @var list<Exercise> $rows */
        $rows = $this->createQueryBuilder('e')
            ->getQuery()
            ->getResult();

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row->getSlug()] = $row;
        }

        return $bySlug;
    }

    /**
     * Resolves the catalog exercises referenced by a training session's
     * request payload in one query (T-0302 design.md §3/§4). Called twice,
     * independently: once by App\Validator\TrainingSetsMatchExercisesValidator
     * (via ExerciseSlugProviderInterface) to check the request, once by
     * App\Service\Training\TrainingSessionService to build the entities - the
     * same deliberate duplication as App\Repository\TrickRepository::findBySlugs().
     *
     * @param list<string> $slugs
     *
     * @return list<Exercise>
     */
    public function findBySlugs(array $slugs): array
    {
        if ([] === $slugs) {
            return [];
        }

        /** @var list<Exercise> $result */
        $result = $this->createQueryBuilder('e')
            ->where('e.slug IN (:slugs)')
            ->setParameter('slugs', $slugs)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
