<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Entity\Trick;
use App\Entity\TrickProgress;
use App\Enum\TrickStatus;
use App\Repository\TrickProgressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes the trick_progress projection forward from freshly computed
 * statuses and aggregate stats (T-0201 design.md §4/§6). Runs on every read
 * of the trick tree (App\Service\Trick\TrickTreeService), not on session
 * write - see the ticket's "Auslösung" section for why a reading endpoint
 * with a write side effect was chosen over a listener/hook into EPIC-01.
 *
 * One flush() for all rows (ticket: "Ein flush() für alle Änderungen").
 * TrickProgress::applyIfChanged() is what makes this idempotent and
 * observable: updatedAt only moves on a real change (AK 7, 8).
 *
 * Muster App\Service\Body\BodyWeightSynchronizer (T-0103): constructor takes
 * `%app.timezone%` via #[Autowire] for the same "now" it needs to stamp rows
 * with.
 */
final readonly class TrickProgressRefresher
{
    public function __construct(
        private TrickProgressRepository $repository,
        private EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.timezone')]
        private string $timezone,
    ) {
    }

    /**
     * @param list<Trick>                        $tricks
     * @param array<string, TrickStatus>         $statuses
     * @param array<string, TrickAggregateStats> $stats
     *
     * @return array<string, TrickProgress> the now-persisted row per trick id, including freshly created ones (T-0202 design.md §4)
     */
    public function refresh(array $tricks, array $statuses, array $stats): array
    {
        $existing = $this->repository->findAllIndexedByTrickId();
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));

        foreach ($tricks as $trick) {
            $trickId = $trick->getId()->toRfc4122();
            $status = $statuses[$trickId] ?? TrickStatus::Locked;
            $trickStats = $stats[$trickId] ?? self::emptyStats();

            $row = $existing[$trickId] ?? null;
            $isNew = null === $row;
            $row ??= new TrickProgress($trick, $status, $now);

            if ($isNew) {
                $this->entityManager->persist($row);
            }

            $row->applyIfChanged($status, $trickStats->attemptsTotal, $trickStats->landedTotal, $trickStats->firstLandedOn, $now);
            $existing[$trickId] = $row;
        }

        $this->entityManager->flush();

        return $existing;
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
}
