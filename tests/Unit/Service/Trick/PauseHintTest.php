<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Trick;

use App\Service\Trick\PauseHint;
use App\Service\Trick\TrickRecommendationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP object test, no kernel, no database - `PauseHint::resolve(?int
 * $lastKneePain, int $consecutiveSessionDays): ?self` (design.md §4).
 * Assumed `self` carries public readonly `code` and `message` properties,
 * mirroring `App\Dto\Trick\PauseHintView`'s `{code, message}` shape
 * (design.md §3).
 *
 * The "Lücke bricht die Folge" half of criterion 12 is about
 * `SkateSessionLoadRepository::consecutiveSessionDays()` itself breaking the
 * count at a gap - proven at the database level in
 * tests/Functional/Repository/SkateSessionLoadRepositoryTest.php. This file
 * only proves that PauseHint::resolve() correctly treats a
 * below-threshold count (as a broken streak would produce) as "no hint".
 */
final class PauseHintTest extends TestCase
{
    private const string KNEE_PAIN_TEXT = 'Dein Knie meldet sich stärker als sonst – heute lieber kürzer treten oder pausieren, bei anhaltenden Schmerzen ärztlich abklären lassen.';
    private const string CONSECUTIVE_DAYS_TEXT = 'Du bist schon mehrere Tage in Folge gefahren – ein Ruhetag hilft Knie und Beinen, sich zu erholen.';

    public function testPauseKneePainConstantIsTheConfirmedRZeroTwoValue(): void
    {
        self::assertSame(5, self::policyConstant('PAUSE_KNEE_PAIN'));
    }

    public function testPauseConsecutiveDaysConstantIsTheConfirmedRZeroTwoValue(): void
    {
        self::assertSame(2, self::policyConstant('PAUSE_CONSECUTIVE_DAYS'));
    }

    public function testItReturnsKneePainHintWhenTheLastKneePainMeetsTheThreshold(): void
    {
        // Criterion 10, boundary: "=" the threshold, not only above it.
        $hint = PauseHint::resolve(TrickRecommendationPolicy::PAUSE_KNEE_PAIN, 0);

        self::assertNotNull($hint);
        self::assertSame('knee_pain', $hint->code);
        self::assertSame(self::KNEE_PAIN_TEXT, $hint->message);
    }

    public function testItReturnsConsecutiveDaysHintWhenTheStreakMeetsTheThresholdAndKneeIsUnremarkable(): void
    {
        // Criterion 11, boundary: "=" the threshold.
        $hint = PauseHint::resolve(null, TrickRecommendationPolicy::PAUSE_CONSECUTIVE_DAYS);

        self::assertNotNull($hint);
        self::assertSame('consecutive_days', $hint->code);
        self::assertSame(self::CONSECUTIVE_DAYS_TEXT, $hint->message);
    }

    public function testItPrefersKneePainOverConsecutiveDaysWhenBothThresholdsAreMet(): void
    {
        // Criterion 10, priority half (ticket: "erste zutreffende Zeile
        // gewinnt", knee pain listed first).
        $hint = PauseHint::resolve(TrickRecommendationPolicy::PAUSE_KNEE_PAIN, TrickRecommendationPolicy::PAUSE_CONSECUTIVE_DAYS + 3);

        self::assertNotNull($hint);
        self::assertSame('knee_pain', $hint->code);
    }

    public function testItReturnsNullWhenNeitherThresholdIsMet(): void
    {
        $hint = PauseHint::resolve(TrickRecommendationPolicy::PAUSE_KNEE_PAIN - 1, TrickRecommendationPolicy::PAUSE_CONSECUTIVE_DAYS - 1);

        self::assertNull($hint);
    }

    public function testItReturnsNullWhenThereIsNoKneePainDataAtAllAndTheStreakIsBelowThreshold(): void
    {
        $hint = PauseHint::resolve(null, 0);

        self::assertNull($hint);
    }

    public function testItReturnsNullWhenTheConsecutiveDaysCountIsBelowThresholdAsAGapWouldProduce(): void
    {
        // Criterion 12 (PauseHint's own half - see class doc comment): a
        // one-day gap yields a broken streak count of 1, which is below
        // PAUSE_CONSECUTIVE_DAYS = 2 and therefore must not trigger a hint.
        $brokenStreakCount = TrickRecommendationPolicy::PAUSE_CONSECUTIVE_DAYS - 1;

        $hint = PauseHint::resolve(null, $brokenStreakCount);

        self::assertNull($hint);
    }

    private static function policyConstant(string $name): mixed
    {
        return (new \ReflectionClassConstant(TrickRecommendationPolicy::class, $name))->getValue();
    }
}
