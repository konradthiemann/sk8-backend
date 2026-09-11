<?php

declare(strict_types=1);

namespace App\Service\Skate;

use App\Dto\Skate\SessionTrickInput;
use App\Dto\Skate\SkateSessionRequest;
use App\Entity\SessionTrick;
use App\Entity\SkateSession;
use App\Entity\Trick;
use App\Repository\TrickRepository;
use App\Service\Body\BodyWeightSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Create, update and delete of skate sessions (T-0102 design.md §4).
 * Resolves trick slugs to catalog entities in one query, reconciles the
 * trick-row collection on update, and rounds weights to two decimals before
 * they ever reach the entity - the only place in the write path that
 * touches the numeric-string representation.
 *
 * Also keeps the session's body-weight history in sync (T-0103 design.md
 * §4): BodyWeightSynchronizer is called from create(), update() and
 * delete(), each time before this service's own flush().
 */
final readonly class SkateSessionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TrickRepository $trickRepository,
        private BodyWeightSynchronizer $bodyWeightSynchronizer,
    ) {
    }

    public function create(SkateSessionRequest $request): SkateSession
    {
        $tricksBySlug = $this->resolveTricks($request->tricks);

        $session = new SkateSession(
            new \DateTimeImmutable($request->sessionDate),
            $this->parseStartedAt($request->startedAt),
            $request->durationMinutes,
            trim($request->location),
            self::roundWeight($request->weightBeforeKg),
            self::roundWeight($request->weightAfterKg),
            $request->perceivedExertion,
            $request->kneePain,
            $request->notes,
            new \DateTimeImmutable(),
        );

        foreach ($request->tricks as $input) {
            $trick = $this->trickFor($input->trickSlug, $tricksBySlug);
            $session->addTrick(new SessionTrick($session, $trick, $input->attempts, $input->landed, $input->notes));
        }

        $this->entityManager->persist($session);
        $this->bodyWeightSynchronizer->sync($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Reconciles the trick-row collection against the submitted list (PUT-Trick-Abgleich, design.md §3):
     * a slug that stays keeps its row id and gets its numbers updated, a new slug becomes a new row, and
     * a slug that is no longer submitted is removed via orphanRemoval.
     */
    public function update(SkateSession $session, SkateSessionRequest $request): SkateSession
    {
        $tricksBySlug = $this->resolveTricks($request->tricks);

        $session->setSessionDate(new \DateTimeImmutable($request->sessionDate));
        $session->setStartedAt($this->parseStartedAt($request->startedAt));
        $session->setDurationMinutes($request->durationMinutes);
        $session->setLocation(trim($request->location));
        $session->setWeightBeforeKg(self::roundWeight($request->weightBeforeKg));
        $session->setWeightAfterKg(self::roundWeight($request->weightAfterKg));
        $session->setPerceivedExertion($request->perceivedExertion);
        $session->setKneePain($request->kneePain);
        $session->setNotes($request->notes);

        /** @var array<string, SessionTrick> $existingBySlug */
        $existingBySlug = [];
        foreach ($session->getTricks() as $row) {
            $existingBySlug[$row->getTrick()->getSlug()] = $row;
        }

        /** @var array<string, true> $submittedSlugs */
        $submittedSlugs = [];
        foreach ($request->tricks as $input) {
            $submittedSlugs[$input->trickSlug] = true;
            $existingRow = $existingBySlug[$input->trickSlug] ?? null;

            if (null !== $existingRow) {
                $existingRow->setAttempts($input->attempts);
                $existingRow->setLanded($input->landed);
                $existingRow->setNotes($input->notes);

                continue;
            }

            $trick = $this->trickFor($input->trickSlug, $tricksBySlug);
            $session->addTrick(new SessionTrick($session, $trick, $input->attempts, $input->landed, $input->notes));
        }

        foreach ($existingBySlug as $slug => $row) {
            if (!isset($submittedSlugs[$slug])) {
                $session->removeTrick($row);
            }
        }

        $this->bodyWeightSynchronizer->sync($session);
        $this->entityManager->flush();

        return $session;
    }

    public function delete(SkateSession $session): void
    {
        $this->bodyWeightSynchronizer->removeForSession($session);
        $this->entityManager->remove($session);
        $this->entityManager->flush();
    }

    /**
     * Resolves every distinct trick slug referenced by the request in one
     * query (design.md §4.2), instead of one query per trick row.
     *
     * @param list<SessionTrickInput> $inputs
     *
     * @return array<string, Trick>
     */
    private function resolveTricks(array $inputs): array
    {
        $slugs = array_values(array_unique(array_map(
            static fn (SessionTrickInput $input): string => $input->trickSlug,
            $inputs,
        )));

        $bySlug = [];
        foreach ($this->trickRepository->findBySlugs($slugs) as $trick) {
            $bySlug[$trick->getSlug()] = $trick;
        }

        return $bySlug;
    }

    /**
     * @param array<string, Trick> $tricksBySlug
     */
    private function trickFor(string $slug, array $tricksBySlug): Trick
    {
        $trick = $tricksBySlug[$slug] ?? null;
        if (null === $trick) {
            // App\Validator\ExistingTrickSlugValidator already rejected any
            // slug not in the catalog before the controller ever calls this
            // service; reaching this means the two disagree, which should
            // never happen in practice.
            throw new \LogicException(\sprintf('Trick with slug "%s" was validated but could not be resolved.', $slug));
        }

        return $trick;
    }

    private function parseStartedAt(?string $startedAt): ?\DateTimeImmutable
    {
        return null === $startedAt ? null : new \DateTimeImmutable($startedAt);
    }

    private static function roundWeight(?float $weightKg): ?string
    {
        return null === $weightKg ? null : number_format($weightKg, 2, '.', '');
    }
}
