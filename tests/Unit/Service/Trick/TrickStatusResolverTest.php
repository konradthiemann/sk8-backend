<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Trick;

use App\Enum\TrickStatus;
use App\Service\Trick\TrickAggregateStats;
use App\Service\Trick\TrickProgressPolicy;
use App\Service\Trick\TrickStatusResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP object test, no kernel, no database (ticket "Fachlogik":
 * "Reine Funktion, kein Kernel, kein Doctrine - deshalb unit-testbar").
 * Trick ids are arbitrary short strings ('a', 'b', ...) rather than real
 * UUIDs: `resolveAll()` treats them as opaque map keys, design.md §4/§6.
 *
 * `TrickProgressPolicy`'s real R-02 constants (MASTERY_RATE=0.75,
 * MASTERY_SESSIONS=3, MASTERY_MIN_ATTEMPTS=15, MASTERY_MODE=each_session,
 * confirmed via check-tickets.py --show T-0201) drive every fixture below -
 * `isMastered()` is expected to read them directly rather than take them as
 * parameters (design.md §6 pseudocode: "nutzt TrickProgressPolicy").
 */
final class TrickStatusResolverTest extends TestCase
{
    public function testItMarksARootTrickWithoutAttemptsReadyWhenThereAreNoSessionsAtAll(): void
    {
        // Criterion 1, "kein Voraussetzung" half.
        $resolver = new TrickStatusResolver();

        $result = $resolver->resolveAll(
            ['a' => self::emptyStats()],
            ['a' => []],
        );

        self::assertSame(TrickStatus::Ready, $result['a']);
    }

    public function testItLocksATrickWithAnUnsatisfiedPrerequisiteWhenThereAreNoSessionsAtAll(): void
    {
        // Criterion 1, "mit Voraussetzung" half: 'b' requires 'a', neither
        // has any data, so 'a' resolves to Ready (no prerequisites of its
        // own) and 'b' - whose only prerequisite is not Mastered - Locked.
        $resolver = new TrickStatusResolver();

        $result = $resolver->resolveAll(
            ['a' => self::emptyStats(), 'b' => self::emptyStats()],
            ['a' => [], 'b' => ['a']],
        );

        self::assertSame(TrickStatus::Ready, $result['a']);
        self::assertSame(TrickStatus::Locked, $result['b']);
    }

    public function testItMarksATrickPracticingWhenItHasAttemptsBelowMasteryThreshold(): void
    {
        // Criterion 2: attempts recorded, but not enough qualifying recent
        // sessions to ever reach isMastered() - see design.md §6,
        // "isMastered(): ... < MASTERY_SESSIONS: return false".
        $resolver = new TrickStatusResolver();
        $stats = new TrickAggregateStats(
            attemptsTotal: 30,
            landedTotal: 9,
            firstLandedOn: new \DateTimeImmutable('2026-08-20'),
            lastPracticedOn: new \DateTimeImmutable('2026-09-06'),
            sessionCount: 2,
            recentSessions: [
                ['sessionDate' => '2026-09-06', 'attempts' => 15, 'landed' => 4],
                ['sessionDate' => '2026-08-20', 'attempts' => 15, 'landed' => 5],
            ],
        );

        $result = $resolver->resolveAll(['a' => $stats], ['a' => []]);

        self::assertSame(TrickStatus::Practicing, $result['a']);
    }

    public function testItMastersATrickWhoseLastMasterySessionsAllQualifyAndClearTheRateThreshold(): void
    {
        // Criterion 3: MASTERY_SESSIONS = 3 qualifying sessions (each with
        // attempts >= MASTERY_MIN_ATTEMPTS = 15), each_session mode requires
        // every one of them individually >= MASTERY_RATE = 0.75.
        self::assertSame(3, self::policyConstant('MASTERY_SESSIONS'), 'fixture assumes the real R-02 value');
        self::assertSame(15, self::policyConstant('MASTERY_MIN_ATTEMPTS'), 'fixture assumes the real R-02 value');
        self::assertSame(0.75, self::policyConstant('MASTERY_RATE'), 'fixture assumes the real R-02 value');

        $resolver = new TrickStatusResolver();
        $stats = new TrickAggregateStats(
            attemptsTotal: 60,
            landedTotal: 48,
            firstLandedOn: new \DateTimeImmutable('2026-07-01'),
            lastPracticedOn: new \DateTimeImmutable('2026-09-01'),
            sessionCount: 3,
            recentSessions: [
                // Already DESC by sessionDate, as TrickTreeService/the
                // repository are expected to hand them over (design.md §6).
                ['sessionDate' => '2026-09-01', 'attempts' => 20, 'landed' => 16],
                ['sessionDate' => '2026-08-01', 'attempts' => 20, 'landed' => 16],
                ['sessionDate' => '2026-07-01', 'attempts' => 20, 'landed' => 16],
            ],
        );

        $result = $resolver->resolveAll(['a' => $stats], ['a' => []]);

        self::assertSame(TrickStatus::Mastered, $result['a']);
    }

    public function testItReadiesADependentTrickWithNoAttemptsWhenItsOnlyPrerequisiteIsMastered(): void
    {
        // Criterion 4.
        $resolver = new TrickStatusResolver();
        $masteredStats = new TrickAggregateStats(
            attemptsTotal: 60,
            landedTotal: 48,
            firstLandedOn: new \DateTimeImmutable('2026-07-01'),
            lastPracticedOn: new \DateTimeImmutable('2026-09-01'),
            sessionCount: 3,
            recentSessions: [
                ['sessionDate' => '2026-09-01', 'attempts' => 20, 'landed' => 16],
                ['sessionDate' => '2026-08-01', 'attempts' => 20, 'landed' => 16],
                ['sessionDate' => '2026-07-01', 'attempts' => 20, 'landed' => 16],
            ],
        );

        $result = $resolver->resolveAll(
            ['a' => $masteredStats, 'b' => self::emptyStats()],
            ['a' => [], 'b' => ['a']],
        );

        self::assertSame(TrickStatus::Mastered, $result['a']);
        self::assertSame(TrickStatus::Ready, $result['b']);
    }

    public function testItKeepsATrickWithAttemptsPracticingRatherThanLockedWhenItsPrerequisiteIsNotMastered(): void
    {
        // Criterion 5: 'b' has its own attempts, so durchlauf 1 decides its
        // status from its own data alone and never reaches the
        // prerequisite check that would otherwise lock it.
        $resolver = new TrickStatusResolver();
        $practicingStats = new TrickAggregateStats(
            attemptsTotal: 12,
            landedTotal: 3,
            firstLandedOn: new \DateTimeImmutable('2026-09-01'),
            lastPracticedOn: new \DateTimeImmutable('2026-09-01'),
            sessionCount: 1,
            recentSessions: [
                ['sessionDate' => '2026-09-01', 'attempts' => 12, 'landed' => 3],
            ],
        );

        $result = $resolver->resolveAll(
            ['a' => self::emptyStats(), 'b' => $practicingStats],
            ['a' => [], 'b' => ['a']],
        );

        // 'a' has no data of its own and no prerequisites -> Ready, not Mastered.
        self::assertSame(TrickStatus::Ready, $result['a']);
        self::assertSame(TrickStatus::Practicing, $result['b']);
    }

    public function testItDoesNotMasterATrickWithOnlyOneQualifyingSessionEvenAboveTheRateThreshold(): void
    {
        // Criterion 6: one recent session clears MASTERY_RATE but the trick
        // has fewer than MASTERY_SESSIONS qualifying sessions overall -
        // "zu wenig Daten ist nie beherrscht" (ticket, "TrickStatusResolver").
        // attemptsTotal > 0, so the trick still lands on Practicing, not Locked.
        $resolver = new TrickStatusResolver();
        $stats = new TrickAggregateStats(
            attemptsTotal: 20,
            landedTotal: 17,
            firstLandedOn: new \DateTimeImmutable('2026-09-01'),
            lastPracticedOn: new \DateTimeImmutable('2026-09-01'),
            sessionCount: 1,
            recentSessions: [
                ['sessionDate' => '2026-09-01', 'attempts' => 20, 'landed' => 17],
            ],
        );

        $result = $resolver->resolveAll(['a' => $stats], ['a' => []]);

        self::assertSame(TrickStatus::Practicing, $result['a']);
        self::assertNotSame(TrickStatus::Mastered, $result['a']);
    }

    public function testItTerminatesAndAssignsAStatusToBothTricksWhenTheirPrerequisitesFormACycle(): void
    {
        // Criterion 12: fachlich verboten (trick_prerequisite should never
        // contain a cycle - see TrickCatalogSeedTest::testThePrerequisiteGraphContainsNoCycle
        // for the real catalog's own guarantee), but the resolver itself must
        // not hang or recurse infinitely if one ever slipped in. Two flat
        // passes (design.md §6) can't loop: durchlauf 2 only ever reads
        // durchlauf 1's finished snapshot, never re-enters durchlauf 1.
        $resolver = new TrickStatusResolver();

        $result = $resolver->resolveAll(
            ['a' => self::emptyStats(), 'b' => self::emptyStats()],
            ['a' => ['b'], 'b' => ['a']],
        );

        self::assertCount(2, $result, 'the resolver must return promptly with a status for every trick');
        self::assertArrayHasKey('a', $result);
        self::assertArrayHasKey('b', $result);
        // Neither side of the cycle was ever marked Mastered in durchlauf 1
        // (both start with zero attempts), so durchlauf 2 can't find a
        // satisfied prerequisite for either - both end up Locked.
        self::assertSame(TrickStatus::Locked, $result['a']);
        self::assertSame(TrickStatus::Locked, $result['b']);
    }

    private static function emptyStats(): TrickAggregateStats
    {
        return new TrickAggregateStats(
            attemptsTotal: 0,
            landedTotal: 0,
            firstLandedOn: null,
            lastPracticedOn: null,
            sessionCount: 0,
            recentSessions: [],
        );
    }

    /**
     * Reads the constant through reflection instead of a direct class-const
     * reference, so PHPStan cannot resolve the value at analysis time and
     * flag the exact-value assertSame() above as an always-true tautology -
     * the point here is a genuine runtime guard that these fixtures still
     * match the real R-02 constants, not a statically provable fact.
     */
    private static function policyConstant(string $name): mixed
    {
        return (new \ReflectionClassConstant(TrickProgressPolicy::class, $name))->getValue();
    }
}
