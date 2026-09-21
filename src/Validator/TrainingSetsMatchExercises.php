<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on App\Dto\Training\TrainingSessionRequest: every
 * set must reference an exercise that exists in the T-0301 catalog, use the
 * measure (reps vs. seconds) and side that exercise's `measure` requires,
 * and not repeat a (exerciseSlug, setNumber) pair already present in the
 * same request (T-0302 design.md §3).
 *
 * A class constraint rather than a per-field property constraint or
 * Assert\Callback: App\Validator\TrainingSetsMatchExercisesValidator needs a
 * database lookup (App\Repository\ExerciseSlugProviderInterface, via
 * dependency injection) across the whole `sets` array in one call.
 * Assert\Callback methods get no dependency injection - see design.md §7,
 * Alternative 1.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TrainingSetsMatchExercises extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
