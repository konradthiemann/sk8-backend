<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Trick;

use App\Enum\TrickStatus;
use App\Service\Skate\SessionMetrics;
use App\Service\Trick\RecommendationReason;
use App\Service\Trick\TrickProgressPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP object test, no kernel, no database - `RecommendationReason::
 * forCandidate(TrickStatus, ?float): self` (design.md §4). Assumed `self`
 * carries public readonly `code` and `message` properties, mirroring the
 * `{code, message}` shape of `App\Dto\Trick\TrickSuggestion`/`PauseHintView`
 * (design.md §3).
 *
 * Covers the four documented reasonCode branches plus the deliberate
 * deviation from the ticket's literal table (design.md §3, "Abweichung"):
 * the ticket's own `consolidate` row carries an extra "< MASTERY_SESSIONS
 * qualifizierend" clause that leaves a gap (three qualifying, individually
 * inconsistent, but pooled-strong sessions match none of the four ticket
 * rows). The implemented rule is simplified and gap-free:
 *
 *   bereit                                 -> ready_to_start
 *   uebe, recentSuccessRate = null         -> no_data
 *   uebe, recentSuccessRate >= MASTERY_RATE -> consolidate
 *   uebe, recentSuccessRate <  MASTERY_RATE -> almost_landed
 *
 * Every branch also asserts the exact German text against design.md §3's
 * table (verbatim from R-02), the same guard-against-typo intent as
 * TrickProgressPolicyTest for the numeric constants.
 */
final class RecommendationReasonTest extends TestCase
{
    private const string READY_TO_START_TEXT = 'Du hast alle Voraussetzungen für diesen Trick sicher drauf – Zeit für den nächsten Schritt.';
    private const string ALMOST_LANDED_TEXT = 'Du landest diesen Trick schon öfter, aber die Erfolgsquote ist noch nicht über mehrere Einheiten stabil – bleib dran.';
    private const string CONSOLIDATE_TEXT = 'Diesen Trick beherrschst du schon – übe ihn ab und zu weiter, damit er sitzen bleibt.';
    private const string NO_DATA_TEXT = 'Zu diesem Trick fehlen noch Übungsdaten – probier ihn in der nächsten Einheit ein paar Mal, damit wir ihn einschätzen können.';

    public function testItReturnsReadyToStartWhenTheCandidateIsReady(): void
    {
        $reason = RecommendationReason::forCandidate(TrickStatus::Ready, null);

        self::assertSame('ready_to_start', $reason->code);
        self::assertSame(self::READY_TO_START_TEXT, $reason->message);
    }

    public function testItReturnsReadyToStartForAReadyCandidateEvenWithARecentSuccessRatePresent(): void
    {
        // "bereit" is the first matching row regardless of recentSuccessRate
        // (design.md §6 flowchart: the bereit? check comes before any
        // recentSuccessRate comparison) - a Ready trick has no session data
        // to speak of in the real catalog, but the rule itself must not
        // depend on that being true.
        $reason = RecommendationReason::forCandidate(TrickStatus::Ready, 0.9);

        self::assertSame('ready_to_start', $reason->code);
    }

    public function testItReturnsNoDataWhenPracticingWithoutARecentSuccessRate(): void
    {
        $reason = RecommendationReason::forCandidate(TrickStatus::Practicing, null);

        self::assertSame('no_data', $reason->code);
        self::assertSame(self::NO_DATA_TEXT, $reason->message);
    }

    public function testItReturnsAlmostLandedWhenPracticingBelowMasteryRate(): void
    {
        $belowThreshold = TrickProgressPolicy::MASTERY_RATE - 0.01;

        $reason = RecommendationReason::forCandidate(TrickStatus::Practicing, $belowThreshold);

        self::assertSame('almost_landed', $reason->code);
        self::assertSame(self::ALMOST_LANDED_TEXT, $reason->message);
    }

    public function testItReturnsConsolidateWhenRecentSuccessRateIsExactlyAtMasteryRate(): void
    {
        // Explicit boundary case (ticket "Tests": "inklusive Grenzfall
        // recentSuccessRate genau gleich MASTERY_RATE") - ">=", not ">".
        $reason = RecommendationReason::forCandidate(TrickStatus::Practicing, TrickProgressPolicy::MASTERY_RATE);

        self::assertSame('consolidate', $reason->code);
        self::assertSame(self::CONSOLIDATE_TEXT, $reason->message);
    }

    public function testItReturnsConsolidateWellAboveMasteryRate(): void
    {
        $reason = RecommendationReason::forCandidate(TrickStatus::Practicing, 0.95);

        self::assertSame('consolidate', $reason->code);
    }

    public function testItReturnsConsolidateForThreeIndividuallyInconsistentButPooledStrongSessions(): void
    {
        // design.md §3, "Abweichung": three sessions of 20 attempts each,
        // landed 16/14/16 (rates 0.80/0.70/0.80). Individually, the middle
        // session (0.70) is below MASTERY_RATE, so TrickStatusResolver::
        // isMastered() (each_session mode) would say false and the trick
        // stays "uebe" - but the *pooled* rate across the window is
        // 46/60 = 0.7667 >= MASTERY_RATE = 0.75, with all three sessions
        // already qualifying (20 attempts >= MASTERY_MIN_ATTEMPTS = 15).
        // The ticket's own reasonCode table has no row for this combination;
        // the implemented, gap-free rule resolves it to "consolidate".
        $pooledRate = SessionMetrics::successRate(60, 16 + 14 + 16);
        self::assertSame(0.767, $pooledRate, 'fixture sanity check: matches design.md §3\'s own worked example');
        self::assertGreaterThanOrEqual(TrickProgressPolicy::MASTERY_RATE, $pooledRate, 'the whole point of this scenario is that the pooled rate clears the threshold');

        $reason = RecommendationReason::forCandidate(TrickStatus::Practicing, $pooledRate);

        self::assertSame('consolidate', $reason->code);
    }
}
