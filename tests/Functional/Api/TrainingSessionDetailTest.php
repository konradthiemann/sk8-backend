<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;
use App\Tests\Factory\ExerciseFactory;
use App\Tests\Factory\TrainingSessionFactory;
use App\Tests\Factory\TrainingSetFactory;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

final class TrainingSessionDetailTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/training-sessions';

    public function testItReturnsMaxKneeLoadAsTheHighestOfItsExercises(): void
    {
        // Criterion 15. Foundry needs the kernel booted before it can
        // persist, so createClient() (which owns the boot) always comes
        // first, factory calls after.
        $client = static::createClient();

        $high = ExerciseFactory::createOne(['kneeLoad' => KneeLoad::High, 'measure' => ExerciseMeasure::Reps]);
        $none = ExerciseFactory::createOne(['kneeLoad' => KneeLoad::None, 'measure' => ExerciseMeasure::Reps]);
        $session = TrainingSessionFactory::createOne();
        TrainingSetFactory::createOne(['trainingSession' => $session, 'exercise' => $high, 'setNumber' => 1]);
        TrainingSetFactory::createOne(['trainingSession' => $session, 'exercise' => $none, 'setNumber' => 1]);

        self::apiRequest($client, 'GET', \sprintf('%s/%s', self::ENDPOINT, $session->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertSame('hoch', $body['maxKneeLoad']);
        self::assertSame(2, $body['setCount']);
        self::assertSame(2, $body['exerciseCount']);
    }

    public function testItSortsSetsByExerciseNameThenBySetNumber(): void
    {
        // design.md §3: TrainingSessionView.sets sortiert nach Übungsname,
        // dann setNumber, dann side.
        $client = static::createClient();

        $zebra = ExerciseFactory::createOne(['name' => 'Zehenheben am Ring', 'measure' => ExerciseMeasure::Reps]);
        $anchor = ExerciseFactory::createOne(['name' => 'Ausfallschritt', 'measure' => ExerciseMeasure::Reps]);
        $session = TrainingSessionFactory::createOne();

        // Inserted deliberately out of both alphabetical and set-number order.
        TrainingSetFactory::createOne(['trainingSession' => $session, 'exercise' => $zebra, 'setNumber' => 1]);
        TrainingSetFactory::createOne(['trainingSession' => $session, 'exercise' => $anchor, 'setNumber' => 2]);
        TrainingSetFactory::createOne(['trainingSession' => $session, 'exercise' => $anchor, 'setNumber' => 1]);

        self::apiRequest($client, 'GET', \sprintf('%s/%s', self::ENDPOINT, $session->getId()->toRfc4122()));

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        $sets = $body['sets'];
        self::assertIsArray($sets);
        $orderings = [];
        foreach ($sets as $set) {
            self::assertIsArray($set);
            $orderings[] = [$set['exerciseName'], $set['setNumber']];
        }

        self::assertSame(
            [['Ausfallschritt', 1], ['Ausfallschritt', 2], ['Zehenheben am Ring', 1]],
            $orderings,
        );
    }

    #[DataProvider('invalidIds')]
    public function testItReturnsNotFoundForAnUnknownOrFormallyInvalidId(string $id): void
    {
        // Criteria 16, 17: a well-formed but unknown UUID 404s via the
        // controller (findOneWithSets() returns null); a formally invalid
        // id never matches the route at all (requirements: ['id' =>
        // Requirement::UUID], design.md §3) - both must produce the same
        // {"error":"not_found"} shape, never a 500.
        $client = static::createClient();
        self::apiRequest($client, 'GET', self::ENDPOINT.'/'.$id);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'well-formed but unknown uuid' => [Uuid::v7()->toRfc4122()];
        yield 'not a uuid at all' => ['not-a-uuid'];
    }
}
