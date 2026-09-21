<?php

declare(strict_types=1);

namespace App\Service\Training;

use App\Dto\Training\TrainingSessionRequest;
use App\Dto\Training\TrainingSetInput;
use App\Entity\Exercise;
use App\Entity\TrainingSession;
use App\Entity\TrainingSet;
use App\Enum\BodySide;
use App\Repository\ExerciseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Create and delete of training sessions (T-0302 design.md §4). Resolves
 * exercise slugs to catalog entities in one query - a second, independent
 * query from the one App\Validator\TrainingSetsMatchExercisesValidator ran
 * during validation, same deliberate duplication as
 * App\Service\Skate\SkateSessionService::resolveTricks().
 *
 * No update() - there is no PUT/PATCH (design.md §3: a wrongly logged
 * session is deleted and re-entered instead).
 */
final readonly class TrainingSessionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExerciseRepository $exerciseRepository,
    ) {
    }

    public function create(TrainingSessionRequest $request): TrainingSession
    {
        $exercisesBySlug = $this->resolveExercises($request->sets);

        $session = new TrainingSession(
            new \DateTimeImmutable($request->sessionDate),
            $request->durationMinutes,
            $request->perceivedExertion,
            $request->kneePain,
            $request->notes,
            new \DateTimeImmutable(),
        );

        foreach ($request->sets as $input) {
            $exercise = $this->exerciseFor($input->exerciseSlug, $exercisesBySlug);
            $side = null === $input->side ? null : BodySide::from($input->side);
            $session->addSet(new TrainingSet($session, $exercise, $input->setNumber, $input->reps, $input->seconds, $side));
        }

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * The DB's ON DELETE CASCADE on training_set.training_session_id removes
     * the set rows server-side; the sets never need to be loaded into the
     * Unit of Work first - same principle as
     * App\Service\Skate\SkateSessionService::delete().
     */
    public function delete(TrainingSession $trainingSession): void
    {
        $this->entityManager->remove($trainingSession);
        $this->entityManager->flush();
    }

    /**
     * Resolves every distinct exercise slug referenced by the request in one
     * query (design.md §3), instead of one query per set row.
     *
     * @param list<TrainingSetInput> $inputs
     *
     * @return array<string, Exercise>
     */
    private function resolveExercises(array $inputs): array
    {
        $slugs = array_values(array_unique(array_map(
            static fn (TrainingSetInput $input): string => $input->exerciseSlug,
            $inputs,
        )));

        $bySlug = [];
        foreach ($this->exerciseRepository->findBySlugs($slugs) as $exercise) {
            $bySlug[$exercise->getSlug()] = $exercise;
        }

        return $bySlug;
    }

    /**
     * @param array<string, Exercise> $exercisesBySlug
     */
    private function exerciseFor(string $slug, array $exercisesBySlug): Exercise
    {
        $exercise = $exercisesBySlug[$slug] ?? null;
        if (null === $exercise) {
            // App\Validator\TrainingSetsMatchExercisesValidator already
            // rejected any slug not in the catalog before the controller
            // ever calls this service; reaching this means the two
            // disagree, which should never happen in practice.
            throw new \LogicException(\sprintf('Exercise with slug "%s" was validated but could not be resolved.', $slug));
        }

        return $exercise;
    }
}
