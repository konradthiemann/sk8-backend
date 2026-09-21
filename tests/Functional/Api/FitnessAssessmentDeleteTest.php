<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\FitnessAssessment;
use App\Tests\Factory\FitnessAssessmentFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

final class FitnessAssessmentDeleteTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/fitness-assessments';

    public function testItDeletesAnAssessmentAndRemovesItsRow(): void
    {
        // Criterion 12, first half. Foundry needs the kernel booted before it
        // can persist, so createClient() (which owns the boot) always comes
        // first, factory calls after.
        $client = static::createClient();

        $assessment = FitnessAssessmentFactory::createOne();
        $id = $assessment->getId();

        self::apiRequest($client, 'DELETE', \sprintf('%s/%s', self::ENDPOINT, $id->toRfc4122()));

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $client->getResponse()->getContent());

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        self::assertNull($entityManager->find(FitnessAssessment::class, $id), 'the row must be gone');
    }

    public function testItAllowsCreatingAnAssessmentForTheSameDateAgainAfterDeletingIt(): void
    {
        // Criterion 12, second half: the unique date frees up again.
        $client = static::createClient();

        $assessment = FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-08')]);

        self::apiRequest($client, 'DELETE', \sprintf('%s/%s', self::ENDPOINT, $assessment->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(204);

        self::apiRequest($client, 'POST', self::ENDPOINT, ['assessedOn' => '2026-09-08', 'plankSeconds' => 95]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testItLeavesOtherAssessmentsUntouched(): void
    {
        $client = static::createClient();

        $doomed = FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-01')]);
        $survivor = FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-02')]);

        self::apiRequest($client, 'DELETE', \sprintf('%s/%s', self::ENDPOINT, $doomed->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(204);

        self::apiRequest($client, 'GET', self::ENDPOINT);
        $body = self::jsonResponse($client);
        self::assertSame(1, $body['total']);
        self::assertIsArray($body['items']);
        self::assertSame($survivor->getId()->toRfc4122(), array_column($body['items'], 'id')[0]);
    }

    public function testItReturnsNotFoundForAnUnknownId(): void
    {
        // Criterion 13.
        $client = static::createClient();
        $this->assertDeleteRouteExists();

        self::apiRequest($client, 'DELETE', self::ENDPOINT.'/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    #[DataProvider('malformedIds')]
    public function testItReturnsNotFoundForAnIdThatIsNotAUuid(string $id): void
    {
        // The route requirement keeps a malformed id from matching at all,
        // so it must end in 404 and never in a 500 from the UUID converter.
        $client = static::createClient();
        $this->assertDeleteRouteExists();

        self::apiRequest($client, 'DELETE', self::ENDPOINT.'/'.$id);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    public function testItOffersNoUpdateRoutes(): void
    {
        // Update means delete and re-enter (ticket: no PUT/PATCH).
        $client = static::createClient();
        $assessment = FitnessAssessmentFactory::createOne();

        foreach (['PUT', 'PATCH'] as $method) {
            self::apiRequest($client, $method, \sprintf('%s/%s', self::ENDPOINT, $assessment->getId()->toRfc4122()), ['plankSeconds' => 1]);

            self::assertResponseStatusCodeSame(405, $method);
        }
    }

    /**
     * A 404 from the router for a route that does not exist looks exactly
     * like a 404 for an unknown id, so without this guard the two not-found
     * tests would be green before the endpoint is even built.
     */
    private function assertDeleteRouteExists(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertNotNull(
            $router->getRouteCollection()->get('api_fitness_assessments_delete'),
            'the delete route must be registered as api_fitness_assessments_delete',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIds(): iterable
    {
        yield 'plain word' => ['latest'];
        yield 'number' => ['42'];
        yield 'truncated uuid' => ['0192f5c0-1d44-7a88-9b02'];
    }
}
