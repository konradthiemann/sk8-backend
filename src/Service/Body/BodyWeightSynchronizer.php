<?php

declare(strict_types=1);

namespace App\Service\Body;

use App\Entity\BodyWeight;
use App\Entity\SkateSession;
use App\Enum\BodyWeightContext;
use App\Repository\BodyWeightRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps the up-to-two `body_weight` rows of a `vor_session`/`nach_session`
 * pair in sync with a `SkateSession`'s weights (T-0103 design.md §4).
 *
 * A dedicated class rather than three lines inside SkateSessionService
 * (design.md §4, "Warum eine eigene Klasse"): that service already resolves
 * trick slugs and reconciles trick rows, and the base-timestamp derivation
 * needs to stay unit-testable without a kernel.
 *
 * Depends on BodyWeightRepositoryInterface, not the concrete (final)
 * BodyWeightRepository - see App\Repository\BodyWeightRepositoryInterface's
 * doc comment and T-0103 tests.md, "Abweichung von design.md §4".
 */
final readonly class BodyWeightSynchronizer
{
    public function __construct(
        private BodyWeightRepositoryInterface $bodyWeightRepository,
        private EntityManagerInterface $entityManager,
        #[Autowire(param: 'app.timezone')]
        private string $timezone,
    ) {
    }

    public function sync(SkateSession $session): void
    {
        $baseAt = $this->baseMeasuredAt($session);
        $afterAt = $baseAt->modify(\sprintf('+%d minutes', $session->getDurationMinutes()));

        $this->syncRow($session, BodyWeightContext::BeforeSession, $session->getWeightBeforeKg(), $baseAt);
        $this->syncRow($session, BodyWeightContext::AfterSession, $session->getWeightAfterKg(), $afterAt);
    }

    public function removeForSession(SkateSession $session): void
    {
        foreach ($this->bodyWeightRepository->findBySession($session->getId()) as $row) {
            $this->entityManager->remove($row);
        }
    }

    private function syncRow(SkateSession $session, BodyWeightContext $context, ?string $weightKg, \DateTimeImmutable $measuredAt): void
    {
        $existing = $this->bodyWeightRepository->findOneBySessionAndContext($session->getId(), $context);

        if (null === $weightKg) {
            if (null !== $existing) {
                $this->entityManager->remove($existing);
            }

            return;
        }

        if (null !== $existing) {
            $existing->setMeasuredAt($measuredAt);
            $existing->setMeasuredOn($session->getSessionDate());
            $existing->setWeightKg($weightKg);

            return;
        }

        $this->entityManager->persist(new BodyWeight($session->getSessionDate(), $measuredAt, $weightKg, $context, $session));
    }

    /**
     * `startedAt` if set; otherwise `sessionDate` at noon in `%app.timezone%`
     * (design.md §2). Noon keeps the substitute timestamp on the intended
     * calendar day in every timezone - midnight, computed in UTC, would
     * already be the previous day.
     *
     * Deliberately built from a date string plus the target timezone, not
     * `setTime()->setTimezone()`: the latter would reinterpret the existing
     * (UTC) wall-clock instant in the new timezone instead of placing 12:00
     * wall-clock noon directly in `%app.timezone%`.
     */
    private function baseMeasuredAt(SkateSession $session): \DateTimeImmutable
    {
        if (null !== $session->getStartedAt()) {
            return $session->getStartedAt();
        }

        return new \DateTimeImmutable(
            $session->getSessionDate()->format('Y-m-d').' 12:00:00',
            new \DateTimeZone($this->timezone),
        );
    }
}
