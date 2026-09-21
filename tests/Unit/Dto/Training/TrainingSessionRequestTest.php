<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Training;

use App\Dto\Training\TrainingSessionRequest;
use App\Dto\Training\TrainingSetInput;
use App\Entity\Exercise;
use App\Enum\Equipment;
use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;
use App\Repository\ExerciseSlugProviderInterface;
use App\Validator\NotInFutureValidator;
use App\Validator\TrainingSetsMatchExercisesValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\ContainerConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Pure field validation, no kernel, no database (Kriterien 2, 9, 10):
 * builds the real Symfony Validator component directly via
 * Validation::createValidatorBuilder(), never the container's validator
 * service.
 *
 * TrainingSessionRequest carries two constraints that need dependencies of
 * their own: App\Validator\NotInFuture on sessionDate (needs a clock), and
 * the class-level App\Validator\TrainingSetsMatchExercises (needs an
 * exercise lookup). The default constraint-validator factory only knows
 * `new $class()`, which would fatal on both, so a tiny hand-rolled PSR-11
 * container feeds real instances of both here - the same role Symfony's own
 * container plays in production, just without booting one. See
 * TrainingSetsMatchExercisesValidatorTest for why the exercise lookup is
 * typed to App\Repository\ExerciseSlugProviderInterface, not the concrete
 * `final` App\Repository\ExerciseRepository (unmockable-final-class
 * problem, same fix, flagged in tests.md).
 *
 * The clock is fixed to 2026-09-08 (Europe/Berlin) so criteria 9/10 never
 * depend on the day this suite happens to run - same convention as
 * NotInFutureValidatorTest's own MockClock.
 */
final class TrainingSessionRequestTest extends TestCase
{
    private const string KNOWN_SLUG = 'ring-row';
    private const string TODAY = '2026-09-08';

    public function testItRejectsAnEmptySetsList(): void
    {
        // Criterion 2.
        $violations = $this->validate($this->buildRequest(['sets' => []]));

        self::assertSame(['sets'], self::paths($violations));
    }

    public function testItAcceptsAPayloadWithExactlyOneValidSet(): void
    {
        // Baseline: proves the fixture itself is clean, so a failure in the
        // other tests here can be attributed to the field under test.
        $violations = $this->validate($this->buildRequest());

        self::assertCount(0, $violations);
    }

    public function testItRejectsASessionDateInTheFuture(): void
    {
        // Criterion 9. "Today" is 2026-09-08 per the fixed clock.
        $violations = $this->validate($this->buildRequest(['sessionDate' => '2026-09-09']));

        self::assertSame(['sessionDate'], self::paths($violations));
    }

    #[DataProvider('invalidDurations')]
    public function testItRejectsADurationOutsideOneToSixHundredMinutes(int $duration): void
    {
        // Criterion 10.
        $violations = $this->validate($this->buildRequest(['durationMinutes' => $duration]));

        self::assertSame(['durationMinutes'], self::paths($violations));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'zero' => [0];
        yield 'above six hundred' => [601];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildRequest(array $overrides = []): TrainingSessionRequest
    {
        $defaults = [
            'sessionDate' => self::TODAY,
            'durationMinutes' => 40,
            'perceivedExertion' => null,
            'kneePain' => null,
            'notes' => null,
            'sets' => [
                new TrainingSetInput(exerciseSlug: self::KNOWN_SLUG, setNumber: 1, reps: 10, seconds: null, side: null),
            ],
        ];

        /** @var array{sessionDate: string, durationMinutes: int, perceivedExertion: ?int, kneePain: ?int, notes: ?string, sets: list<TrainingSetInput>} $args */
        $args = array_merge($defaults, $overrides);

        return new TrainingSessionRequest(
            sessionDate: $args['sessionDate'],
            durationMinutes: $args['durationMinutes'],
            perceivedExertion: $args['perceivedExertion'],
            kneePain: $args['kneePain'],
            notes: $args['notes'],
            sets: $args['sets'],
        );
    }

    /**
     * @return list<string>
     */
    private static function paths(ConstraintViolationListInterface $violations): array
    {
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }

    private function validate(TrainingSessionRequest $request): ConstraintViolationListInterface
    {
        return $this->validator()->validate($request);
    }

    private function validator(): ValidatorInterface
    {
        $clock = new MockClock(self::TODAY.'T10:00:00+00:00', 'UTC');
        $catalog = [
            new Exercise(self::KNOWN_SLUG, self::KNOWN_SLUG, Equipment::Bodyweight, ['test'], KneeLoad::None, ExerciseMeasure::Reps, null, false),
        ];

        $provider = new class($catalog) implements ExerciseSlugProviderInterface {
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

        $container = new class($clock, $provider) implements ContainerInterface {
            public function __construct(
                private readonly MockClock $clock,
                private readonly ExerciseSlugProviderInterface $provider,
            ) {
            }

            public function get(string $id): object
            {
                return match ($id) {
                    NotInFutureValidator::class => new NotInFutureValidator($this->clock, 'Europe/Berlin'),
                    TrainingSetsMatchExercisesValidator::class => new TrainingSetsMatchExercisesValidator($this->provider),
                    default => throw new \RuntimeException(\sprintf('Unexpected constraint validator id "%s" requested.', $id)),
                };
            }

            public function has(string $id): bool
            {
                return \in_array($id, [NotInFutureValidator::class, TrainingSetsMatchExercisesValidator::class], true);
            }
        };

        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ContainerConstraintValidatorFactory($container))
            ->getValidator();
    }
}
