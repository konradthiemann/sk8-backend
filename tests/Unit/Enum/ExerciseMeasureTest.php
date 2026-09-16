<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ExerciseMeasure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure enum test, no kernel, no database: `usesReps()`/`requiresSide()` are
 * the two flags T-0302's set-entry validation reads to decide which fields
 * a `training_set` row requires for a given exercise (design.md §2,
 * "App\Enum\ExerciseMeasure ... dazu usesReps(): bool und requiresSide():
 * bool - T-0302 prueft damit die Saetze").
 */
final class ExerciseMeasureTest extends TestCase
{
    #[DataProvider('usesRepsCases')]
    public function testItReportsWhetherTheMeasureCountsRepsRatherThanTime(ExerciseMeasure $measure, bool $expected): void
    {
        self::assertSame($expected, $measure->usesReps());
    }

    /**
     * @return iterable<string, array{ExerciseMeasure, bool}>
     */
    public static function usesRepsCases(): iterable
    {
        yield 'reps counts reps' => [ExerciseMeasure::Reps, true];
        yield 'reps per side counts reps' => [ExerciseMeasure::RepsPerSide, true];
        yield 'seconds counts time, not reps' => [ExerciseMeasure::Seconds, false];
        yield 'seconds per side counts time, not reps' => [ExerciseMeasure::SecondsPerSide, false];
    }

    #[DataProvider('requiresSideCases')]
    public function testItReportsWhetherTheMeasureIsTrackedPerSide(ExerciseMeasure $measure, bool $expected): void
    {
        self::assertSame($expected, $measure->requiresSide());
    }

    /**
     * @return iterable<string, array{ExerciseMeasure, bool}>
     */
    public static function requiresSideCases(): iterable
    {
        yield 'reps per side requires a side' => [ExerciseMeasure::RepsPerSide, true];
        yield 'seconds per side requires a side' => [ExerciseMeasure::SecondsPerSide, true];
        yield 'plain reps has no side' => [ExerciseMeasure::Reps, false];
        yield 'plain seconds has no side' => [ExerciseMeasure::Seconds, false];
    }

    public function testItBacksEachCaseWithItsSnakeCaseDatabaseValue(): void
    {
        // chk_exercise_measure checks against exactly these four values.
        self::assertSame('reps', ExerciseMeasure::Reps->value);
        self::assertSame('seconds', ExerciseMeasure::Seconds->value);
        self::assertSame('reps_per_side', ExerciseMeasure::RepsPerSide->value);
        self::assertSame('seconds_per_side', ExerciseMeasure::SecondsPerSide->value);
    }
}
