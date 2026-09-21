<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\TrainingSet;
use App\Enum\ExerciseMeasure;
use App\Tests\Factory\ExerciseFactory;
use App\Tests\Factory\TrainingSessionFactory;
use App\Tests\Factory\TrainingSetFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

final class TrainingSessionDeleteTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/training-sessions';

    public function testItDeletesASessionAndCascadesItsSets(): void
    {
        // Criterion 18. Foundry needs the kernel booted before it can
        // persist, so createClient() (which owns the boot) always comes
        // first, factory calls after.
        $client = static::createClient();

        $exercise = ExerciseFactory::createOne(['measure' => ExerciseMeasure::Reps]);
        $session = TrainingSessionFactory::createOne();
        $set = TrainingSetFactory::createOne(['trainingSession' => $session, 'exercise' => $exercise, 'setNumber' => 1]);
        $setId = $set->getId();
        $sessionId = $session->getId()->toRfc4122();

        self::apiRequest($client, 'DELETE', \sprintf('%s/%s', self::ENDPOINT, $sessionId));

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $client->getResponse()->getContent());

        self::apiRequest($client, 'GET', \sprintf('%s/%s', self::ENDPOINT, $sessionId));
        self::assertResponseStatusCodeSame(404);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertNull($entityManager->find(TrainingSet::class, $setId), 'training_set row must be gone via ON DELETE CASCADE');
    }

    public function testItReturnsNotFoundForAnUnknownId(): void
    {
        // Criterion 16, delete route.
        $client = static::createClient();
        self::apiRequest($client, 'DELETE', self::ENDPOINT.'/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }
}
