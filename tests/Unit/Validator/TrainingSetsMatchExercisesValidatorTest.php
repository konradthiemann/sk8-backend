<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Dto\Training\TrainingSessionRequest;
use App\Dto\Training\TrainingSetInput;
use App\Entity\Exercise;
use App\Enum\Equipment;
use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;
use App\Repository\ExerciseSlugProviderInterface;
use App\Validator\TrainingSetsMatchExercises;
use App\Validator\TrainingSetsMatchExercisesValidator;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * design.md §4 types the validator's constructor against the concrete,
 * `final class ExerciseRepository` (App\Repository\ExerciseRepository) and
 * claims ConstraintValidatorTestCase can build it "real or stubbed" without
 * a kernel, "kein Interface-Umweg erforderlich". Neither option actually
 * works:
 *
 * - "Gestubbt": PHPUnit's mock generator throws ClassIsFinalException
 *   unconditionally for any `$class->isFinal()` (vendor/phpunit/phpunit/src/
 *   Framework/MockObject/Generator/Generator.php:393-394) - there is no flag
 *   to opt out, because PHP itself forbids subclassing a final class, which
 *   is how every PHPUnit test double is built. This is the exact problem
 *   tests/Unit/Validator/ExistingTrickSlugValidatorTest.php already
 *   documents for the structurally identical `final class TrickRepository`
 *   ("ClassIsFinalException, confirmed while writing this test").
 * - "Echt": a real ExerciseRepository needs a booted kernel to obtain a
 *   working EntityManager. ExistingTrickSlugValidatorTest's docblock records
 *   that doing this from inside `tests/Unit/` was tried and rejected: a
 *   KernelTestCase there "corrupts kernel-boot state for the Functional
 *   suite that runs right after it" in the same PHPUnit process
 *   (`WebTestCase::createClient(): "the kernel should only be booted once"`
 *   on the very next Functional test class) - phpunit.dist.xml's own suite
 *   split assumes "Unit: no kernel, no database".
 *
 * Same fix as that file, same precedent - see
 * App\Repository\TrickSlugProviderInterface's own docblock, which calls
 * this exact pattern out as "T-0102 tests.md, Abweichung von design.md §4":
 * a narrow interface seam, App\Repository\ExerciseSlugProviderInterface (one
 * method, matching the findBySlugs() design.md already assigns to
 * ExerciseRepository verbatim), which TrainingSetsMatchExercisesValidator
 * should depend on instead of the concrete repository. Production wiring is
 * unaffected once ExerciseRepository additionally declares `implements
 * ExerciseSlugProviderInterface` - autowiring still resolves to the same
 * concrete repository, it just satisfies a narrower type. Flagged in
 * tests.md "Bekannte Lücken" for architect/implementer review; this is a
 * deviation from design.md §4, not from the ticket's own acceptance
 * criteria or field paths/messages, which are followed verbatim below.
 *
 * @extends ConstraintValidatorTestCase<TrainingSetsMatchExercisesValidator>
 */
final class TrainingSetsMatchExercisesValidatorTest extends ConstraintValidatorTestCase
{
    private const string RING_ROW = 'ring-row';
    private const string SINGLE_LEG_BALANCE = 'single-leg-balance';

    /**
     * @var list<Exercise>
     */
    private array $catalog;

    protected function createValidator(): ConstraintValidatorInterface
    {
        $this->catalog = [
            self::buildExercise(self::RING_ROW, ExerciseMeasure::Reps),
            self::buildExercise(self::SINGLE_LEG_BALANCE, ExerciseMeasure::SecondsPerSide),
        ];

        // Return type stays the parent's interface, not the concrete
        // TrainingSetsMatchExercisesValidator: PHP resolves a covariant
        // return type eagerly when the class is loaded, which would turn
        // "class does not exist yet" into an unrecoverable fatal error
        // instead of a red test.
        return new TrainingSetsMatchExercisesValidator(self::buildProvider($this->catalog));
    }

    public function testItAcceptsAValidSetOfEachMeasure(): void
    {
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet(self::RING_ROW, 1, reps: 10, seconds: null, side: null),
            $this->buildSet(self::SINGLE_LEG_BALANCE, 1, reps: null, seconds: 45, side: 'links'),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->assertNoViolation();
    }

    public function testItAcceptsTheSameExerciseWithDifferentSetNumbersOnBothSides(): void
    {
        // Grenzfall aus design.md §3: seitengetrennte Übungen zaehlen
        // set_number durchgehend je Übung, nicht je Seite - zwei Reihen
        // derselben Übung mit setNumber 1 und 2 (unterschiedliche Seiten)
        // duerfen nicht als Duplikat gemeldet werden.
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet(self::SINGLE_LEG_BALANCE, 1, reps: null, seconds: 45, side: 'links'),
            $this->buildSet(self::SINGLE_LEG_BALANCE, 2, reps: null, seconds: 60, side: 'rechts'),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->assertNoViolation();
    }

    public function testItRejectsAnUnknownExerciseSlug(): void
    {
        // Criterion 7. Only this one violation fires - design.md §3: an
        // unknown slug skips the remaining per-set checks.
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet('not-a-real-exercise', 1, reps: 10, seconds: null, side: null),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->buildViolation('training.set.exercise.unknown')
            ->atPath('sets[0].exerciseSlug')
            ->assertRaised();
    }

    public function testItRejectsARepsExerciseWhenRepsIsMissing(): void
    {
        // Criterion 4.
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet(self::RING_ROW, 1, reps: null, seconds: 10, side: null),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->buildViolation('training.set.reps.measure_mismatch')
            ->atPath('sets[0].reps')
            ->assertRaised();
    }

    public function testItRejectsARepsExerciseWhenSecondsIsAlsoSetAlongsideReps(): void
    {
        // Criterion 3's underlying mechanism (design.md §3, "Kein separates
        // Assert\Callback ... für genau eines von reps/seconds"): both being
        // set is caught here, via the measure check, not a standalone XOR.
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet(self::RING_ROW, 1, reps: 10, seconds: 30, side: null),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->buildViolation('training.set.reps.measure_mismatch')
            ->atPath('sets[0].reps')
            ->assertRaised();
    }

    public function testItRejectsASecondsExerciseWhenSecondsIsMissing(): void
    {
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet(self::SINGLE_LEG_BALANCE, 1, reps: 5, seconds: null, side: 'links'),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->buildViolation('training.set.seconds.measure_mismatch')
            ->atPath('sets[0].seconds')
            ->assertRaised();
    }

    public function testItRejectsASideRequiredExerciseWithoutASide(): void
    {
        // Criterion 5.
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet(self::SINGLE_LEG_BALANCE, 1, reps: null, seconds: 45, side: null),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->buildViolation('training.set.side.required')
            ->atPath('sets[0].side')
            ->assertRaised();
    }

    public function testItRejectsASideGivenForAnExerciseWithoutASideBreakdown(): void
    {
        // Criterion 6.
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet(self::RING_ROW, 1, reps: 10, seconds: null, side: 'links'),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->buildViolation('training.set.side.not_allowed')
            ->atPath('sets[0].side')
            ->assertRaised();
    }

    public function testItRejectsTheSameExerciseAndSetNumberTwice(): void
    {
        // Criterion 8. The violation lands on the second occurrence
        // (index 1) - design.md §3's algorithm flags a set only once its
        // (slug, setNumber) key has already been seen.
        $this->setPropertyPath('');
        $request = $this->buildRequest([
            $this->buildSet(self::RING_ROW, 1, reps: 10, seconds: null, side: null),
            $this->buildSet(self::RING_ROW, 1, reps: 8, seconds: null, side: null),
        ]);

        $this->validator->validate($request, new TrainingSetsMatchExercises());

        $this->buildViolation('training.set.number.duplicate')
            ->atPath('sets[1].setNumber')
            ->assertRaised();
    }

    public function testItLoadsTheCatalogOnlyOnceForAllSetsOfOneRequest(): void
    {
        // design.md §3: "lädt alle Übungen der Anfrage in einer Abfrage" -
        // must hold regardless of how many sets (up to 60) a request
        // carries, and regardless of how many distinct slugs appear.
        $provider = self::buildCountingProvider($this->catalog);
        $validator = new TrainingSetsMatchExercisesValidator($provider);
        $validator->initialize($this->context);
        $this->setPropertyPath('');

        $request = $this->buildRequest([
            $this->buildSet(self::RING_ROW, 1, reps: 10, seconds: null, side: null),
            $this->buildSet(self::RING_ROW, 2, reps: 8, seconds: null, side: null),
            $this->buildSet(self::SINGLE_LEG_BALANCE, 1, reps: null, seconds: 45, side: 'links'),
        ]);

        $validator->validate($request, new TrainingSetsMatchExercises());

        self::assertSame(1, $provider->calls);
    }

    /**
     * @param list<TrainingSetInput> $sets
     */
    private function buildRequest(array $sets): TrainingSessionRequest
    {
        return new TrainingSessionRequest(
            sessionDate: '2026-09-08',
            durationMinutes: 40,
            perceivedExertion: null,
            kneePain: null,
            notes: null,
            sets: $sets,
        );
    }

    private function buildSet(string $exerciseSlug, int $setNumber, ?int $reps, ?int $seconds, ?string $side): TrainingSetInput
    {
        return new TrainingSetInput(
            exerciseSlug: $exerciseSlug,
            setNumber: $setNumber,
            reps: $reps,
            seconds: $seconds,
            side: $side,
        );
    }

    private static function buildExercise(string $slug, ExerciseMeasure $measure): Exercise
    {
        return new Exercise($slug, $slug, Equipment::Bodyweight, ['test'], KneeLoad::None, $measure, null, false);
    }

    /**
     * @param list<Exercise> $exercises
     */
    private static function buildProvider(array $exercises): ExerciseSlugProviderInterface
    {
        return new class($exercises) implements ExerciseSlugProviderInterface {
            /**
             * @param list<Exercise> $exercises
             */
            public function __construct(private readonly array $exercises)
            {
            }

            public function findBySlugs(array $slugs): array
            {
                return array_values(array_filter(
                    $this->exercises,
                    static fn (Exercise $exercise): bool => \in_array($exercise->getSlug(), $slugs, true),
                ));
            }
        };
    }

    /**
     * The `object{calls: int}` shape documents the counter this anonymous class adds on
     * top of the interface, so `$provider->calls` at the call site type-checks without
     * widening the declared return type away from `ExerciseSlugProviderInterface`.
     *
     * @param list<Exercise> $exercises
     *
     * @return ExerciseSlugProviderInterface&object{calls: int}
     */
    private static function buildCountingProvider(array $exercises): ExerciseSlugProviderInterface
    {
        return new class($exercises) implements ExerciseSlugProviderInterface {
            public int $calls = 0;

            /**
             * @param list<Exercise> $exercises
             */
            public function __construct(private readonly array $exercises)
            {
            }

            public function findBySlugs(array $slugs): array
            {
                ++$this->calls;

                return array_values(array_filter(
                    $this->exercises,
                    static fn (Exercise $exercise): bool => \in_array($exercise->getSlug(), $slugs, true),
                ));
            }
        };
    }
}
