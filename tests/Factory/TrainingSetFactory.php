<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\TrainingSet;
use App\Enum\ExerciseMeasure;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for T-0302 functional tests. Attaches one training-set row to
 * a session, mirroring SessionTrickFactory. `exercise` defaults to a fresh
 * ExerciseFactory instance pinned to `measure: ExerciseMeasure::Reps` (not
 * ExerciseFactory's own random measure) so the *other* defaults here -
 * `reps` set, `seconds`/`side` both null - stay measure-consistent out of
 * the box (tests.md "measure-konsistent"): a test that needs a different
 * measure overrides both `exercise` and the reps/seconds/side fields
 * together, as in
 * `TrainingSetFactory::createOne(['exercise' => ExerciseFactory::createOne(['measure' => ExerciseMeasure::SecondsPerSide]), 'reps' => null, 'seconds' => 45, 'side' => BodySide::Links])`.
 *
 * `trainingSession` and `exercise` default to a fresh
 * `TrainingSessionFactory`/`ExerciseFactory` instance each, but every test
 * that cares about the relationship (which is most of them) overrides both
 * explicitly - see tests.md for the assumed `TrainingSet::__construct()`
 * contract.
 *
 * @extends PersistentObjectFactory<TrainingSet>
 */
final class TrainingSetFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return TrainingSet::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'trainingSession' => TrainingSessionFactory::new(),
            'exercise' => ExerciseFactory::new(['measure' => ExerciseMeasure::Reps]),
            'setNumber' => 1,
            'reps' => self::faker()->numberBetween(5, 20),
            'seconds' => null,
            'side' => null,
        ];
    }
}
