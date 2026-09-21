<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Exercise;

/**
 * Narrow seam so App\Validator\TrainingSetsMatchExercisesValidator can be
 * built and unit-tested without a kernel. App\Repository\ExerciseRepository
 * is `final` (T-0301), so PHPUnit cannot mock it and PHP cannot subclass it
 * for a hand-rolled fake either (T-0302 tests.md, "Abweichung von design.md
 * §4" - same problem, same fix as App\Repository\TrickSlugProviderInterface
 * for the structurally identical `final class TrickRepository`, T-0102).
 * ExerciseRepository already has a matching findBySlugs() method and only
 * needs to declare `implements ExerciseSlugProviderInterface` - production
 * wiring is unaffected, autowiring still resolves to the same concrete
 * repository.
 */
interface ExerciseSlugProviderInterface
{
    /**
     * @param list<string> $slugs
     *
     * @return list<Exercise>
     */
    public function findBySlugs(array $slugs): array;
}
