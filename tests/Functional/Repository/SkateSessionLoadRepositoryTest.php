<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Repository\SkateSessionLoadRepository;
use App\Tests\Factory\SkateSessionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test, pattern of
 * tests/Functional/Service/Trick/TrickProgressRefresherTest.php):
 * App\Repository\SkateSessionLoadRepository is a schlichte DQL class over
 * SkateSession (design.md §2), not itself pure - a kernel-free unit test
 * cannot observe real GROUP BY/DISTINCT behaviour against Postgres.
 *
 * `lastKneePain()` and `consecutiveSessionDays()` only ever read
 * `skate_session` - no SessionTrick/Trick rows are needed at all, so every
 * fixture here uses SkateSessionFactory alone. Every test method boots the
 * kernel first (via repository(), called before any factory use), matching
 * TrickProgressRefresherTest's own convention.
 */
final class SkateSessionLoadRepositoryTest extends KernelTestCase
{
    use Factories;

    public function testLastKneePainReturnsNullWhenNoSessionHasARecordedValue(): void
    {
        $repository = $this->repository();
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-01'), 'kneePain' => null]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-02'), 'kneePain' => null]);

        self::assertNull($repository->lastKneePain());
    }

    public function testLastKneePainReturnsNullWhenThereAreNoSessionsAtAll(): void
    {
        self::assertNull($this->repository()->lastKneePain());
    }

    public function testLastKneePainReturnsTheValueOfTheMostRecentSessionByDate(): void
    {
        $repository = $this->repository();
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-08-01'), 'kneePain' => 6]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-05'), 'kneePain' => 2]);

        self::assertSame(2, $repository->lastKneePain());
    }

    public function testLastKneePainSkipsAMoreRecentSessionThatHasNoValueRecorded(): void
    {
        // "jüngste Einheit mit gesetztem Wert" - a newer session with
        // kneePain = null must not hide an older session's real value.
        $repository = $this->repository();
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-08-01'), 'kneePain' => 7]);
        SkateSessionFactory::createOne(['sessionDate' => new \DateTimeImmutable('2026-09-05'), 'kneePain' => null]);

        self::assertSame(7, $repository->lastKneePain());
    }

    public function testConsecutiveSessionDaysReturnsZeroWhenThereAreNoSessionsAtAll(): void
    {
        self::assertSame(0, $this->repository()->consecutiveSessionDays(new \DateTimeImmutable('2026-09-08')));
    }

    public function testConsecutiveSessionDaysCountsTwoSessionsOnTheSameDayAsOneDay(): void
    {
        // The explicitly required edge case (ticket "Tests", "Grenzfall,
        // der ausdrücklich getestet wird"): two units on one day must not
        // double-count as two consecutive days.
        $repository = $this->repository();
        $today = new \DateTimeImmutable('2026-09-08');
        SkateSessionFactory::createOne(['sessionDate' => $today]);
        SkateSessionFactory::createOne(['sessionDate' => $today]);

        self::assertSame(1, $repository->consecutiveSessionDays($today));
    }

    public function testConsecutiveSessionDaysCountsBackwardsFromTodayWhenTodayHasASession(): void
    {
        // Criterion 11 fixture: three calendar days in a row, including today.
        $repository = $this->repository();
        $today = new \DateTimeImmutable('2026-09-08');
        SkateSessionFactory::createOne(['sessionDate' => $today]);
        SkateSessionFactory::createOne(['sessionDate' => $today->modify('-1 day')]);
        SkateSessionFactory::createOne(['sessionDate' => $today->modify('-2 days')]);

        self::assertSame(3, $repository->consecutiveSessionDays($today));
    }

    public function testConsecutiveSessionDaysStartsFromYesterdayWhenTodayHasNoSessionYet(): void
    {
        // design.md §6 algorithm: "if today not in sessionDays: cursor =
        // today - 1 day" - the streak up to and including yesterday still
        // counts even though nothing was logged for today yet.
        $repository = $this->repository();
        $today = new \DateTimeImmutable('2026-09-08');
        SkateSessionFactory::createOne(['sessionDate' => $today->modify('-1 day')]);
        SkateSessionFactory::createOne(['sessionDate' => $today->modify('-2 days')]);

        self::assertSame(2, $repository->consecutiveSessionDays($today));
    }

    public function testConsecutiveSessionDaysStopsAtAOneDayGap(): void
    {
        // Criterion 12: today and the day before yesterday have sessions,
        // but yesterday is a gap - the streak must not jump over it.
        $repository = $this->repository();
        $today = new \DateTimeImmutable('2026-09-08');
        SkateSessionFactory::createOne(['sessionDate' => $today]);
        SkateSessionFactory::createOne(['sessionDate' => $today->modify('-2 days')]);

        self::assertSame(1, $repository->consecutiveSessionDays($today));
    }

    public function testConsecutiveSessionDaysIgnoresSessionsAfterTheGivenDate(): void
    {
        // A session dated after $today must not extend a streak counted
        // backwards from $today - the method answers "as of $today", not
        // "as of the latest session on record".
        $repository = $this->repository();
        $today = new \DateTimeImmutable('2026-09-08');
        SkateSessionFactory::createOne(['sessionDate' => $today]);
        SkateSessionFactory::createOne(['sessionDate' => $today->modify('+1 day')]);

        self::assertSame(1, $repository->consecutiveSessionDays($today));
    }

    private function repository(): SkateSessionLoadRepository
    {
        self::bootKernel();
        $repository = static::getContainer()->get(SkateSessionLoadRepository::class);
        \assert($repository instanceof SkateSessionLoadRepository);

        return $repository;
    }
}
