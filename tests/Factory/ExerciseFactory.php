<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Exercise;
use App\Enum\Equipment;
use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for later tickets (T-0302, T-0303) that need arbitrary
 * exercises, mirroring TrickFactory. `slug` is a running sequence so that
 * `uniq_exercise_slug` never breaks across repeated `create*()` calls;
 * `equipment`/`kneeLoad`/`measure` default to a random case each so a test
 * that needs a specific one (e.g. T-0302's per-measure set validation)
 * passes it explicitly, as in `ExerciseFactory::createOne(['measure' =>
 * ExerciseMeasure::Reps])`.
 *
 * @extends PersistentObjectFactory<Exercise>
 */
final class ExerciseFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Exercise::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // Method-local static counter, not a class property - Foundry's
        // Factory base class is annotated @immutable, and PHPStan propagates
        // that readonly-ness to every property declared by subclasses too
        // (same reasoning as TrickFactory).
        /** @var int $sequence */
        static $sequence = 0;
        ++$sequence;

        return [
            'slug' => \sprintf('exercise-%d', $sequence),
            'name' => self::faker()->words(2, true),
            'equipment' => self::faker()->randomElement(Equipment::cases()),
            'muscleGroups' => self::faker()->words(self::faker()->numberBetween(1, 3)),
            'kneeLoad' => self::faker()->randomElement(KneeLoad::cases()),
            'measure' => self::faker()->randomElement(ExerciseMeasure::cases()),
            'description' => self::faker()->optional()->sentence(),
            'isPrevention' => false,
        ];
    }
}
