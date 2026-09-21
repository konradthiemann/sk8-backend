<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\Training\TrainingSessionRequest;
use App\Dto\Training\TrainingSetInput;
use App\Repository\ExerciseSlugProviderInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Loads every exercise referenced by one request's sets in a single query
 * (design.md §3: "lädt alle Übungen der Anfrage in einer Abfrage"), then
 * checks each set in memory:
 *
 *   - the exerciseSlug exists in the catalog (otherwise the remaining checks
 *     for that set are skipped - its measure cannot be known);
 *   - reps/seconds matches the exercise's measure (usesReps());
 *   - side is present exactly when the measure requires it (requiresSide());
 *   - (exerciseSlug, setNumber) has not already appeared earlier in the same
 *     request - side does not factor into this key, since set_number counts
 *     up per exercise, not per side (design.md, "Grenzfall Seiten und
 *     Satznummern").
 *
 * Depends on App\Repository\ExerciseSlugProviderInterface, not the concrete
 * App\Repository\ExerciseRepository directly: ExerciseRepository is `final`,
 * which PHPUnit cannot mock (T-0302 tests.md - same fix as
 * App\Validator\ExistingTrickSlugValidator for TrickRepository, T-0102).
 */
final class TrainingSetsMatchExercisesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ExerciseSlugProviderInterface $exerciseSlugProvider,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TrainingSetsMatchExercises) {
            throw new UnexpectedTypeException($constraint, TrainingSetsMatchExercises::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof TrainingSessionRequest) {
            throw new UnexpectedValueException($value, TrainingSessionRequest::class);
        }

        $slugs = array_values(array_unique(array_map(
            static fn (TrainingSetInput $set): string => $set->exerciseSlug,
            $value->sets,
        )));

        $exercisesBySlug = [];
        foreach ($this->exerciseSlugProvider->findBySlugs($slugs) as $exercise) {
            $exercisesBySlug[$exercise->getSlug()] = $exercise;
        }

        /** @var array<string, true> $seen */
        $seen = [];
        foreach ($value->sets as $i => $set) {
            if (!$set instanceof TrainingSetInput) {
                continue;
            }

            $exercise = $exercisesBySlug[$set->exerciseSlug] ?? null;

            if (null === $exercise) {
                $this->context->buildViolation('training.set.exercise.unknown')
                    ->atPath(\sprintf('sets[%d].exerciseSlug', $i))
                    ->addViolation();

                continue;
            }

            $measure = $exercise->getMeasure();

            if ($measure->usesReps()) {
                if (null === $set->reps || null !== $set->seconds) {
                    $this->context->buildViolation('training.set.reps.measure_mismatch')
                        ->atPath(\sprintf('sets[%d].reps', $i))
                        ->addViolation();
                }
            } elseif (null === $set->seconds || null !== $set->reps) {
                $this->context->buildViolation('training.set.seconds.measure_mismatch')
                    ->atPath(\sprintf('sets[%d].seconds', $i))
                    ->addViolation();
            }

            if ($measure->requiresSide()) {
                if (null === $set->side) {
                    $this->context->buildViolation('training.set.side.required')
                        ->atPath(\sprintf('sets[%d].side', $i))
                        ->addViolation();
                }
            } elseif (null !== $set->side) {
                $this->context->buildViolation('training.set.side.not_allowed')
                    ->atPath(\sprintf('sets[%d].side', $i))
                    ->addViolation();
            }

            $key = $set->exerciseSlug.'|'.$set->setNumber;
            if (isset($seen[$key])) {
                $this->context->buildViolation('training.set.number.duplicate')
                    ->atPath(\sprintf('sets[%d].setNumber', $i))
                    ->addViolation();
            } else {
                $seen[$key] = true;
            }
        }
    }
}
