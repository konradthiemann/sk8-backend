<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Trick;

use App\Entity\Trick;
use App\Entity\TrickProgress;
use App\Enum\TrickStatus;
use App\Repository\TrickProgressRepository;
use App\Service\Trick\TrickAggregateStats;
use App\Service\Trick\TrickProgressRefresher;
use App\Tests\Factory\TrickFactory;
use App\Tests\Factory\TrickProgressFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test, pattern of
 * tests/Functional/Catalog/TrickCatalogSeedTest.php): `TrickProgressRefresher`
 * writes real rows and its whole point (criteria 7, 8) is observable
 * `updated_at` behaviour across two real persist/flush cycles, which a
 * kernel-free unit test with a stubbed EntityManager cannot show - unlike
 * BodyWeightSynchronizerTest (tests/Unit/Service/Body/), the class under
 * test here is not itself pure (design.md §4: real TrickProgressRepository,
 * real EntityManagerInterface).
 *
 * `TrickFactory` extends `PersistentObjectFactory` (not the deprecated
 * `PersistentProxyObjectFactory`), so `createOne()` returns the real entity
 * directly - no `_real()` unwrapping needed, same as every other use of it
 * in this codebase (e.g. tests/Functional/Api/SkateSessionReadTest.php).
 *
 * `updated_at` is stored as `TIMESTAMP(0) WITH TIME ZONE` (design.md §2,
 * migration SQL) - second precision, not microsecond. The "changed" tests
 * below therefore sleep(1) between the two refresh() calls whose effect they
 * compare; without it, two `new DateTimeImmutable('now')` calls made
 * microseconds apart could round-trip through Postgres to the identical
 * second and make the assertion flaky. The "unchanged" test needs no such
 * wait: applyIfChanged() is expected to never touch updated_at at all when
 * nothing really changed, regardless of how much wall-clock time passed.
 */
final class TrickProgressRefresherTest extends KernelTestCase
{
    use Factories;

    public function testItCreatesANewProgressRowForATrickWithoutOne(): void
    {
        self::bootKernel();
        $trick = TrickFactory::createOne();
        $refresher = $this->refresher();
        $trickId = $trick->getId()->toRfc4122();

        $refresher->refresh(
            [$trick],
            [$trickId => TrickStatus::Practicing],
            [$trickId => self::stats(attemptsTotal: 12, landedTotal: 3)],
        );

        $row = $this->progressFor($trick);
        self::assertSame(TrickStatus::Practicing, $row->getStatus());
        self::assertSame(12, $row->getAttemptsTotal());
        self::assertSame(3, $row->getLandedTotal());
    }

    public function testItLeavesUpdatedAtUnchangedOnASecondRefreshWithNoNewData(): void
    {
        // Criterion 7.
        self::bootKernel();
        $trick = TrickFactory::createOne();
        $refresher = $this->refresher();
        $trickId = $trick->getId()->toRfc4122();
        $statuses = [$trickId => TrickStatus::Practicing];
        $stats = [$trickId => self::stats(attemptsTotal: 12, landedTotal: 3)];

        $refresher->refresh([$trick], $statuses, $stats);
        $updatedAtAfterFirstCall = $this->progressFor($trick)->getUpdatedAt();

        // Same trick, same status, same stats - nothing for a second
        // "GET /api/trick-tree" to have discovered.
        $refresher->refresh([$trick], $statuses, $stats);
        $updatedAtAfterSecondCall = $this->progressFor($trick)->getUpdatedAt();

        self::assertEquals($updatedAtAfterFirstCall, $updatedAtAfterSecondCall);
    }

    public function testItUpdatesTheStatusAndUpdatedAtWhenNewDataCrossesTheMasteryThreshold(): void
    {
        // Criterion 8.
        self::bootKernel();
        $trick = TrickFactory::createOne();
        $refresher = $this->refresher();
        $trickId = $trick->getId()->toRfc4122();

        $refresher->refresh(
            [$trick],
            [$trickId => TrickStatus::Practicing],
            [$trickId => self::stats(attemptsTotal: 12, landedTotal: 3)],
        );
        $updatedAtBefore = $this->progressFor($trick)->getUpdatedAt();
        self::assertSame(TrickStatus::Practicing, $this->progressFor($trick)->getStatus());

        // See class doc comment: updated_at has second precision in Postgres.
        sleep(1);

        $refresher->refresh(
            [$trick],
            [$trickId => TrickStatus::Mastered],
            [$trickId => self::stats(attemptsTotal: 60, landedTotal: 48, firstLandedOn: new \DateTimeImmutable('2026-07-01'))],
        );
        $row = $this->progressFor($trick);

        self::assertSame(TrickStatus::Mastered, $row->getStatus());
        self::assertSame(60, $row->getAttemptsTotal());
        self::assertSame(48, $row->getLandedTotal());
        self::assertGreaterThan($updatedAtBefore, $row->getUpdatedAt());
    }

    public function testItUpdatesAPreExistingProgressRowInPlaceRatherThanCreatingASecondOne(): void
    {
        // Exercises TrickProgressFactory (this ticket's "Neue Factory")
        // directly: seeds the trick_progress row through an independent code
        // path rather than relying on refresh() to have created it first, so
        // this proves refresh() finds and reuses an existing row -
        // `existing[trickId] ?? new TrickProgress(...)` (design.md §6
        // pseudocode) - instead of only ever taking the "new row" branch,
        // and that doing so does not violate uniq_trick_progress_trick.
        self::bootKernel();
        $trick = TrickFactory::createOne();
        $trickId = $trick->getId()->toRfc4122();
        TrickProgressFactory::createOne([
            'trick' => $trick,
            'status' => TrickStatus::Ready,
            'now' => new \DateTimeImmutable('2026-01-01'),
            'attemptsTotal' => 0,
            'landedTotal' => 0,
            'firstLandedOn' => null,
        ]);

        $this->refresher()->refresh(
            [$trick],
            [$trickId => TrickStatus::Practicing],
            [$trickId => self::stats(attemptsTotal: 9, landedTotal: 2)],
        );

        // Only one trick exists in this isolated transaction, so exactly one
        // row proves refresh() reused the seeded row instead of inserting a
        // second one (which uniq_trick_progress_trick would reject anyway).
        self::assertCount(1, $this->progressRepository()->findAllIndexedByTrickId());
        self::assertSame(TrickStatus::Practicing, $this->progressFor($trick)->getStatus());
        self::assertSame(9, $this->progressFor($trick)->getAttemptsTotal());
    }

    public function testItCascadeDeletesTheProgressRowWhenItsTrickIsDeleted(): void
    {
        // Criterion 11. Not assigned to any file in the ticket's own "Tests"
        // table - see tests.md, "Lücken" for why it is added here anyway:
        // TrickProgressRepository/EntityManager are already real and
        // kernel-booted in this file, the cheapest place to prove a
        // database-level FK constraint (design.md §2:
        // "FK -> trick(id) ON DELETE CASCADE").
        self::bootKernel();
        $trick = TrickFactory::createOne();
        $refresher = $this->refresher();
        $trickId = $trick->getId()->toRfc4122();

        $refresher->refresh([$trick], [$trickId => TrickStatus::Ready], [$trickId => self::stats()]);
        self::assertNotNull($this->progressRepository()->findAllIndexedByTrickId()[$trickId] ?? null);

        $entityManager = $this->entityManager();
        $entityManager->remove($trick);
        $entityManager->flush();
        $entityManager->clear();

        self::assertArrayNotHasKey(
            $trickId,
            $this->progressRepository()->findAllIndexedByTrickId(),
            'trick_progress row must be gone once its trick is deleted (ON DELETE CASCADE)',
        );
    }

    private function refresher(): TrickProgressRefresher
    {
        $refresher = static::getContainer()->get(TrickProgressRefresher::class);
        \assert($refresher instanceof TrickProgressRefresher);

        return $refresher;
    }

    private function progressRepository(): TrickProgressRepository
    {
        $repository = static::getContainer()->get(TrickProgressRepository::class);
        \assert($repository instanceof TrickProgressRepository);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    private function progressFor(Trick $trick): TrickProgress
    {
        $row = $this->progressRepository()->findAllIndexedByTrickId()[$trick->getId()->toRfc4122()] ?? null;
        self::assertNotNull($row, 'expected a trick_progress row for this trick');

        return $row;
    }

    private static function stats(int $attemptsTotal = 0, int $landedTotal = 0, ?\DateTimeImmutable $firstLandedOn = null): TrickAggregateStats
    {
        return new TrickAggregateStats(
            attemptsTotal: $attemptsTotal,
            landedTotal: $landedTotal,
            firstLandedOn: $firstLandedOn,
            lastPracticedOn: null,
            sessionCount: 0,
            recentSessions: [],
        );
    }
}
