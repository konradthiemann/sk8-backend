<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\FitnessAssessment;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for T-0303 functional tests. Every instance gets its own
 * calendar day in the past (yesterday, the day before, ...), because
 * `uniq_fitness_assessment_assessed_on` allows only one assessment per day:
 * a random date would collide occasionally, and a fixed one would collide
 * every time a test creates two rows. Tests that care about a specific day
 * (ordering, duplicate detection) pass `assessedOn` explicitly.
 *
 * All eight measurements are set to plausible values by default and `notes`
 * is `null`; a test that needs a missing measurement overrides it with
 * `null` explicitly.
 *
 * Assumes `FitnessAssessment::__construct()` takes its columns as named
 * constructor parameters (design.md §4.1): assessedOn, pushUpsMax,
 * squatsMax, ringPullUpsMax, plankSeconds, singleLegBalanceLeftSeconds,
 * singleLegBalanceRightSeconds, wallSitSeconds, standingBroadJumpCm, notes.
 *
 * @extends PersistentObjectFactory<FitnessAssessment>
 */
final class FitnessAssessmentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return FitnessAssessment::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // A function-local static, not a class property: Foundry's factory
        // base class is annotated @readonly, so PHPStan rejects a static
        // property with a default value on it.
        /** @var int $daysAgo */
        static $daysAgo = 0;
        ++$daysAgo;

        return [
            'assessedOn' => (new \DateTimeImmutable(\sprintf('-%d days', $daysAgo)))->setTime(0, 0),
            'pushUpsMax' => self::faker()->numberBetween(10, 40),
            'squatsMax' => self::faker()->numberBetween(20, 60),
            'ringPullUpsMax' => self::faker()->numberBetween(1, 10),
            'plankSeconds' => self::faker()->numberBetween(30, 150),
            'singleLegBalanceLeftSeconds' => self::faker()->numberBetween(10, 60),
            'singleLegBalanceRightSeconds' => self::faker()->numberBetween(10, 60),
            'wallSitSeconds' => self::faker()->numberBetween(30, 120),
            'standingBroadJumpCm' => self::faker()->numberBetween(120, 220),
            'notes' => null,
        ];
    }
}
