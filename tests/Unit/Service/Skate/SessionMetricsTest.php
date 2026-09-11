<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Skate;

use App\Service\Skate\SessionMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure functions, no kernel, no database: `SessionMetrics` is the single
 * place where derived values (success rate, fluid loss) are computed and
 * where Doctrine's `numeric` strings turn into floats (design.md §"Nachkommastellen").
 */
final class SessionMetricsTest extends TestCase
{
    #[DataProvider('successRateCases')]
    public function testItComputesSuccessRateRoundedToThreeDecimals(int $attempts, int $landed, ?float $expected): void
    {
        self::assertSame($expected, SessionMetrics::successRate($attempts, $landed));
    }

    /**
     * @return iterable<string, array{int, int, ?float}>
     */
    public static function successRateCases(): iterable
    {
        // Single trick row: 30 attempts, 21 landed (criterion 2).
        yield 'exact one third rounds cleanly' => [30, 21, 0.7];
        // Session total across two trick rows: 54 attempts, 30 landed (criterion 3).
        yield 'repeating decimal rounds to three places' => [54, 30, 0.556];
        // Second trick row of the same example session.
        yield 'three eighths' => [24, 9, 0.375];
    }

    public function testItReturnsNullSuccessRateWhenThereAreNoAttempts(): void
    {
        // Criterion 4: a session without trick rows has totalAttempts = 0.
        self::assertNull(SessionMetrics::successRate(0, 0));
    }

    public function testItComputesFluidLossFromTwoNumericStrings(): void
    {
        // Criterion 5: 78.4 - 77.1 = 1.3, rounded to two decimals.
        self::assertSame(1.3, SessionMetrics::fluidLossKg('78.40', '77.10'));
    }

    public function testItRoundsFluidLossToTwoDecimalPlaces(): void
    {
        self::assertSame(1.23, SessionMetrics::fluidLossKg('80.005', '78.775'));
    }

    #[DataProvider('missingWeightCases')]
    public function testItReturnsNullFluidLossWhenEitherWeightIsMissing(?string $before, ?string $after): void
    {
        self::assertNull(SessionMetrics::fluidLossKg($before, $after));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function missingWeightCases(): iterable
    {
        yield 'before missing' => [null, '77.10'];
        yield 'after missing' => ['78.40', null];
        yield 'both missing' => [null, null];
    }

    public function testItAllowsANegativeFluidLossWhenWeightIncreased(): void
    {
        // Criterion 9: weightAfterKg bigger than weightBeforeKg is not rejected.
        self::assertSame(-5.0, SessionMetrics::fluidLossKg('70.00', '75.00'));
    }
}
