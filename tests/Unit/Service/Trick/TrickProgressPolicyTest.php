<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Trick;

use App\Service\Trick\TrickProgressPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Guards the four R-02 constants against a typo in the research parameters
 * (ticket "Tests": "hält einen Tippfehler in den Rechercheparametern auf").
 * Pure PHP object test, no kernel: reads `public const` values directly.
 *
 * Two layers of assertion per constant: a plausible-range check (would still
 * catch a typo even if R-02's numbers changed later) and an exact-value
 * check against the confirmed T-0201 values (MASTERY_RATE=0.75,
 * MASTERY_SESSIONS=3, MASTERY_MIN_ATTEMPTS=15, MASTERY_MODE=each_session -
 * `python3 .claude/specs/check-tickets.py --show T-0201`), which is the
 * concrete typo this criterion is meant to catch today.
 */
final class TrickProgressPolicyTest extends TestCase
{
    public function testMasteryRateIsAFractionGreaterThanZeroAndAtMostOne(): void
    {
        self::assertGreaterThan(0.0, TrickProgressPolicy::MASTERY_RATE);
        self::assertLessThanOrEqual(1.0, TrickProgressPolicy::MASTERY_RATE);
        self::assertSame(0.75, self::constant('MASTERY_RATE'));
    }

    public function testMasterySessionsIsAtLeastOne(): void
    {
        self::assertGreaterThanOrEqual(1, TrickProgressPolicy::MASTERY_SESSIONS);
        self::assertSame(3, self::constant('MASTERY_SESSIONS'));
    }

    public function testMasteryMinAttemptsIsAtLeastOne(): void
    {
        self::assertGreaterThanOrEqual(1, TrickProgressPolicy::MASTERY_MIN_ATTEMPTS);
        self::assertSame(15, self::constant('MASTERY_MIN_ATTEMPTS'));
    }

    public function testMasteryModeIsOneOfTheTwoDocumentedModes(): void
    {
        self::assertContains(TrickProgressPolicy::MASTERY_MODE, ['each_session', 'pooled']);
        self::assertSame('each_session', self::constant('MASTERY_MODE'));
    }

    /**
     * Reads the constant through reflection instead of a direct class-const
     * reference, so PHPStan cannot resolve the value at analysis time and
     * flag the exact-value assertSame() below as an always-true tautology.
     * The whole point of this test is a genuine runtime guard against a
     * future typo in these constants, not a statically provable fact.
     */
    private static function constant(string $name): mixed
    {
        return (new \ReflectionClassConstant(TrickProgressPolicy::class, $name))->getValue();
    }
}
