<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\ExerciseMeasure;
use App\Tests\Factory\ExerciseFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Exercise slugs used here are prefixed "qa-" on purpose: the real catalog
 * (T-0301's SyncExercisesCommand, run against every test database from
 * config/data/exercises.json) already seeds "ring-row", "single-leg-balance"
 * and friends, so reusing those exact slugs via ExerciseFactory would
 * collide with uniq_exercise_slug (confirmed the hard way while writing this
 * test - same convention as SkateSessionWriteTest's "qa-" trick slugs).
 */
final class TrainingSessionCreateTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/training-sessions';

    public function testItCreatesASessionWithFourSetsAndReturnsALocationHeader(): void
    {
        // Criterion 1. Foundry needs the kernel booted before it can
        // persist, so createClient() (which owns the boot) always comes
        // first, factory calls after.
        $client = static::createClient();

        ExerciseFactory::createOne(['slug' => 'qa-ring-row', 'measure' => ExerciseMeasure::Reps]);
        ExerciseFactory::createOne(['slug' => 'qa-single-leg-balance', 'measure' => ExerciseMeasure::SecondsPerSide]);

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sets' => [
                ['exerciseSlug' => 'qa-ring-row', 'setNumber' => 1, 'reps' => 10, 'seconds' => null, 'side' => null],
                ['exerciseSlug' => 'qa-ring-row', 'setNumber' => 2, 'reps' => 8, 'seconds' => null, 'side' => null],
                ['exerciseSlug' => 'qa-single-leg-balance', 'setNumber' => 1, 'reps' => null, 'seconds' => 45, 'side' => 'links'],
                ['exerciseSlug' => 'qa-single-leg-balance', 'setNumber' => 2, 'reps' => null, 'seconds' => 60, 'side' => 'rechts'],
            ],
        ]));

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);
        self::assertIsString($body['id']);
        self::assertSame(4, $body['setCount']);

        $location = $client->getResponse()->headers->get('Location');
        self::assertSame(\sprintf('/api/training-sessions/%s', $body['id']), $location);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $sessionCount = $this->rowCount($entityManager, 'training_session');
        $setCount = $this->rowCount($entityManager, 'training_set');
        self::assertSame(1, $sessionCount, 'exactly one training_session row must exist');
        self::assertSame(4, $setCount, 'exactly four training_set rows must exist');
    }

    public function testItRejectsASetWithBothRepsAndSecondsAndLeavesNoPartialRow(): void
    {
        // Criterion 3 - the most important single test in this ticket:
        // #[MapRequestPayload] validates (including the class-level
        // TrainingSetsMatchExercises check) before
        // TrainingSessionController::create() is ever entered (design.md
        // §3), so a rejected request must leave the training_session row
        // count exactly where it started - not just return the right status
        // code.
        $client = static::createClient();

        ExerciseFactory::createOne(['slug' => 'qa-ring-row', 'measure' => ExerciseMeasure::Reps]);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $countBefore = $this->rowCount($entityManager, 'training_session');

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sets' => [
                ['exerciseSlug' => 'qa-ring-row', 'setNumber' => 1, 'reps' => 10, 'seconds' => 30, 'side' => null],
            ],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sets[0].reps'], self::violationFields(self::jsonResponse($client)));

        $countAfter = $this->rowCount($entityManager, 'training_session');
        self::assertSame($countBefore, $countAfter, 'a rejected request must not leave a training_session row behind');
    }

    public function testItRejectsAnUnknownExerciseSlugWithValidationFailedNotAnyOtherStatus(): void
    {
        // Criterion 7: 422, never 404 or 500.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sets' => [
                ['exerciseSlug' => 'not-a-real-exercise', 'setNumber' => 1, 'reps' => 10, 'seconds' => null, 'side' => null],
            ],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sets[0].exerciseSlug'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsEveryRouteWithoutAnApiKey(): void
    {
        // Criterion 19.
        foreach (self::routes() as [$method, $uri]) {
            // WebTestCase::createClient() forbids booting the kernel a second
            // time within one test method; shutting it down first (a no-op
            // before the very first iteration) is what makes looping over
            // several requests in one test method possible at all.
            self::ensureKernelShutdown();
            $client = static::createClient();

            self::apiRequest($client, $method, $uri, apiKey: null);

            self::assertResponseStatusCodeSame(401, \sprintf('%s %s without key', $method, $uri));
            self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
        }
    }

    /**
     * @return list<array{string, string}>
     */
    private static function routes(): array
    {
        $unknownId = Uuid::v7()->toRfc4122();

        return [
            ['POST', self::ENDPOINT],
            ['GET', self::ENDPOINT],
            ['GET', \sprintf('%s/%s', self::ENDPOINT, $unknownId)],
            ['DELETE', \sprintf('%s/%s', self::ENDPOINT, $unknownId)],
        ];
    }

    private function rowCount(EntityManagerInterface $entityManager, string $table): int
    {
        /** @var numeric-string|int $raw */
        $raw = $entityManager->getConnection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));

        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'sessionDate' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'durationMinutes' => 40,
            'perceivedExertion' => null,
            'kneePain' => null,
            'notes' => null,
            'sets' => [],
        ], $overrides);
    }
}
