<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Enum\ExerciseMeasure;
use App\Repository\TrainingSessionRepository;
use App\Tests\Factory\ExerciseFactory;
use App\Tests\Factory\TrainingSessionFactory;
use App\Tests\Factory\TrainingSetFactory;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test, pattern of
 * tests/Functional/Repository/SkateSessionLoadRepositoryTest.php):
 * findOneWithSets() is exactly one fetch-joined DQL query (design.md §4),
 * and only a real EntityManager against a real schema can prove that - a
 * kernel-free unit test cannot observe real SQL round trips.
 *
 * The query count is read off the profiler's own debug data holder
 * (`doctrine.debug_data_holder`, registered whenever kernel.debug is true -
 * confirmed present and populated in this repo's test environment via
 * `bin/console debug:container doctrine.debug_data_holder` and a throwaway
 * probe test before writing this file), the same mechanism the web
 * profiler's Doctrine panel uses to show query counts. No prior
 * query-counting helper existed in this repo to reuse.
 *
 * The exercise slug is prefixed "qa-" on purpose: the real catalog
 * (T-0301's SyncExercisesCommand) already seeds "ring-row" into every test
 * database, so reusing that exact slug via ExerciseFactory would collide
 * with uniq_exercise_slug (same convention as SkateSessionWriteTest's "qa-"
 * trick slugs, confirmed the hard way while writing this file too).
 */
final class TrainingSessionRepositoryTest extends KernelTestCase
{
    use Factories;

    public function testFindOneWithSetsLoadsSetsAndExercisesInASingleQuery(): void
    {
        $repository = $this->repository();

        $ringRow = ExerciseFactory::createOne(['slug' => 'qa-ring-row', 'measure' => ExerciseMeasure::Reps]);
        $session = TrainingSessionFactory::createOne();
        TrainingSetFactory::createOne(['trainingSession' => $session, 'exercise' => $ringRow, 'setNumber' => 1]);
        TrainingSetFactory::createOne(['trainingSession' => $session, 'exercise' => $ringRow, 'setNumber' => 2]);

        $debugDataHolder = $this->debugDataHolder();
        $debugDataHolder->reset();

        $loaded = $repository->findOneWithSets($session->getId());

        self::assertNotNull($loaded);
        $sets = $loaded->getSets();
        self::assertCount(2, $sets);

        // Touching each set's exercise would trigger a lazy-load query of
        // its own if the exercise were not already fetch-joined - proving
        // the *hydration*, not just the row count, needs only one query.
        foreach ($sets as $set) {
            self::assertNotSame('', $set->getExercise()->getName());
        }

        /** @var array<string, list<array<string, mixed>>> $data */
        $data = $debugDataHolder->getData();
        $queries = $data['default'] ?? [];
        self::assertCount(1, $queries, 'findOneWithSets() must load the session, its sets and their exercises in a single query');
    }

    public function testFindOneWithSetsReturnsNullForAnUnknownId(): void
    {
        $repository = $this->repository();

        self::assertNull($repository->findOneWithSets(Uuid::v7()));
    }

    private function repository(): TrainingSessionRepository
    {
        self::bootKernel();
        $repository = static::getContainer()->get(TrainingSessionRepository::class);
        \assert($repository instanceof TrainingSessionRepository);

        return $repository;
    }

    private function debugDataHolder(): BacktraceDebugDataHolder
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        \assert($holder instanceof BacktraceDebugDataHolder);

        return $holder;
    }
}
