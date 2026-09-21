<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\HabitTargetDirection;
use App\Tests\Factory\HabitFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test). Exercises
 * `GET /api/habits` end to end (design.md §3/§4.5, App\Controller\Api\
 * HabitCatalogController). Read-only endpoint: the catalog is maintained by
 * `app:habits:sync`, never through the API, so there is no write-path test.
 *
 * Every test starts from an empty habit table (`startClient()`), because the
 * catalog rows are a deployment concern and each scenario creates exactly the
 * rows it needs.
 */
final class HabitCatalogTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/habits';

    public function testItReturnsOnlyActiveHabitsInAscendingSortOrder(): void
    {
        // Criterion 7. Created out of order, plus an inactive row that would
        // sort first, so the order can only come from the query.
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'third', 'sortOrder' => 30]);
        HabitFactory::createOne(['slug' => 'first', 'sortOrder' => 10]);
        HabitFactory::createOne(['slug' => 'second', 'sortOrder' => 20]);
        HabitFactory::createOne(['slug' => 'retired', 'sortOrder' => 5, 'isActive' => false]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['first', 'second', 'third'], $this->slugs($client));
    }

    public function testItBreaksASortOrderTieByNameAscending(): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'b-slug', 'name' => 'beta', 'sortOrder' => 10]);
        HabitFactory::createOne(['slug' => 'a-slug', 'name' => 'alpha', 'sortOrder' => 10]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertSame(['a-slug', 'b-slug'], $this->slugs($client));
    }

    public function testItBreaksASortOrderAndNameTieBySlugAscending(): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'slug-b', 'name' => 'same', 'sortOrder' => 10]);
        HabitFactory::createOne(['slug' => 'slug-a', 'name' => 'same', 'sortOrder' => 10]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertSame(['slug-a', 'slug-b'], $this->slugs($client));
    }

    public function testItPrefersSortOrderOverNameWhenBothDiffer(): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'late', 'name' => 'aaa', 'sortOrder' => 20]);
        HabitFactory::createOne(['slug' => 'early', 'name' => 'zzz', 'sortOrder' => 10]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertSame(['early', 'late'], $this->slugs($client));
    }

    public function testItIncludesInactiveHabitsWhenAskedAndSortsThemBySortOrder(): void
    {
        // Criterion 8: the inactive row (sort order 5) sits between the active ones by its own sortOrder.
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'first', 'sortOrder' => 10]);
        HabitFactory::createOne(['slug' => 'retired', 'sortOrder' => 5, 'isActive' => false]);
        HabitFactory::createOne(['slug' => 'second', 'sortOrder' => 20]);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?includeInactive=true');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['retired', 'first', 'second'], $this->slugs($client));
        $habits = $this->habits($client);
        self::assertFalse($habits[0]['isActive'] ?? null);
        self::assertTrue($habits[1]['isActive'] ?? null);
    }

    #[DataProvider('truthyValues')]
    public function testItTreatsTruthyIncludeInactiveValuesAsTrue(string $value): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'active-one', 'sortOrder' => 10]);
        HabitFactory::createOne(['slug' => 'retired', 'sortOrder' => 20, 'isActive' => false]);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?includeInactive='.$value);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['active-one', 'retired'], $this->slugs($client));
    }

    /**
     * @return array<int|string, array{string}>
     */
    public static function truthyValues(): array
    {
        return ['1' => ['1'], 'on' => ['on'], 'yes' => ['yes']];
    }

    #[DataProvider('falsyValues')]
    public function testItTreatsFalsyIncludeInactiveValuesLikeTheDefault(string $query): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'active-one', 'sortOrder' => 10]);
        HabitFactory::createOne(['slug' => 'retired', 'sortOrder' => 20, 'isActive' => false]);

        self::apiRequest($client, 'GET', self::ENDPOINT.$query);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['active-one'], $this->slugs($client));
    }

    /**
     * @return array<int|string, array{string}>
     */
    public static function falsyValues(): array
    {
        return [
            'not given' => [''],
            'false' => ['?includeInactive=false'],
            '0' => ['?includeInactive=0'],
            'off' => ['?includeInactive=off'],
            'no' => ['?includeInactive=no'],
            'empty value' => ['?includeInactive='],
        ];
    }

    #[DataProvider('nonBooleanValues')]
    public function testItRejectsANonBooleanIncludeInactiveWithValidationFailed(string $value): void
    {
        // Not 404: #[MapQueryString] answers a failed mapping with 404 unless the
        // status is set explicitly (design.md §3.3). First test in the repo for a
        // type error (as opposed to a range error) in a query string.
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'active-one']);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?includeInactive='.$value);

        self::assertResponseStatusCodeSame(422);
        $body = self::jsonResponse($client);
        self::assertSame('validation_failed', $body['error'] ?? null);
        self::assertContains('includeInactive', self::violationFields($body));
    }

    /**
     * @return array<int|string, array{string}>
     */
    public static function nonBooleanValues(): array
    {
        return ['abc' => ['abc'], 'two' => ['2'], 'maybe' => ['maybe']];
    }

    public function testItReturnsScaleBoundsAsIntegersAndANullUnitForAScaleHabit(): void
    {
        // Criterion 9.
        $client = $this->startClient();
        HabitFactory::new()->scale()->create(['slug' => 'knee-pain', 'scaleMin' => 0, 'scaleMax' => 10]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $habit = $this->onlyHabit($client);
        self::assertSame('scale', $habit['valueType'] ?? null);
        self::assertSame(0, $habit['scaleMin'] ?? null);
        self::assertSame(10, $habit['scaleMax'] ?? null);
        self::assertArrayHasKey('unit', $habit);
        self::assertNull($habit['unit']);
    }

    public function testItReturnsNullBoundsForAHabitThatIsNotAScale(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->boolean()->create(['slug' => 'mobility-stretch']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $habit = $this->onlyHabit($client);
        self::assertSame('boolean', $habit['valueType'] ?? null);
        self::assertArrayHasKey('scaleMin', $habit);
        self::assertArrayHasKey('scaleMax', $habit);
        self::assertNull($habit['scaleMin']);
        self::assertNull($habit['scaleMax']);
    }

    public function testItReturnsUnitDirectionAndValueTypeAsTheirStringValues(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->duration()->create(['slug' => 'sleep-duration']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $habit = $this->onlyHabit($client);
        self::assertSame('duration', $habit['valueType'] ?? null);
        self::assertSame('h', $habit['unit'] ?? null);
        self::assertSame('hoch', $habit['targetDirection'] ?? null);
    }

    public function testItReturnsTheLowDirectionAsNiedrig(): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'stress', 'targetDirection' => HabitTargetDirection::Low]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertSame('niedrig', $this->onlyHabit($client)['targetDirection'] ?? null);
    }

    public function testItReturnsAWholeTargetValueAsAJsonNumberNotAString(): void
    {
        // Criterion 10: a DECIMAL(8,2) column holding 8.00 leaves the API as
        // the number 8 (JsonResponse writes 8.0 as 8), never as "8.00".
        $client = $this->startClient();
        HabitFactory::new()->duration()->create(['slug' => 'sleep-duration', 'targetValue' => '8.00']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $raw = $client->getResponse()->getContent();
        self::assertIsString($raw);
        self::assertMatchesRegularExpression('/"targetValue":8[,}]/', $raw);
        self::assertStringNotContainsString('"8.00"', $raw);
        self::assertStringNotContainsString('"targetValue":"', $raw);
        self::assertSame(8, $this->onlyHabit($client)['targetValue'] ?? null);
    }

    public function testItReturnsAFractionalTargetValueWithoutTrailingZeros(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->duration()->create(['slug' => 'sleep-duration', 'targetValue' => '2.50']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $raw = $client->getResponse()->getContent();
        self::assertIsString($raw);
        self::assertStringContainsString('"targetValue":2.5', $raw);
        self::assertStringNotContainsString('2.50', $raw);
        self::assertSame(2.5, $this->onlyHabit($client)['targetValue'] ?? null);
    }

    public function testItReturnsNullAsTheTargetValueWhenTheHabitHasNone(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->scale()->create(['slug' => 'mood']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $habit = $this->onlyHabit($client);
        self::assertArrayHasKey('targetValue', $habit);
        self::assertNull($habit['targetValue']);
    }

    public function testItReturnsAllElevenHabitResponseFieldsInCamelCaseInContractOrder(): void
    {
        $client = $this->startClient();
        HabitFactory::new()->duration()->create(['slug' => 'sleep-duration']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertSame(
            ['id', 'slug', 'name', 'valueType', 'unit', 'scaleMin', 'scaleMax', 'targetDirection', 'targetValue', 'sortOrder', 'isActive'],
            array_keys($this->onlyHabit($client)),
        );
    }

    public function testItReturnsTheStoredIdAsAUuid(): void
    {
        $client = $this->startClient();
        $habit = HabitFactory::createOne(['slug' => 'mood']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $id = $this->onlyHabit($client)['id'] ?? null;
        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
        self::assertSame($habit->getId()->toRfc4122(), $id);
    }

    public function testItReturnsSortOrderAsAnIntegerAndIsActiveAsABoolean(): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'mood', 'sortOrder' => 50]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $habit = $this->onlyHabit($client);
        self::assertSame(50, $habit['sortOrder'] ?? null);
        self::assertTrue($habit['isActive'] ?? null);
    }

    public function testItWrapsTheListInAHabitsKeyAndNothingElse(): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'mood']);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertSame(['habits'], array_keys(self::jsonResponse($client)));
    }

    public function testItReturnsAnEmptyListForAnEmptyCatalog(): void
    {
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('{"habits":[]}', $client->getResponse()->getContent());
    }

    public function testItReturnsAnEmptyListWhenOnlyInactiveHabitsExistAndTheyAreNotRequested(): void
    {
        $client = $this->startClient();
        HabitFactory::createOne(['slug' => 'retired', 'isActive' => false]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertSame('{"habits":[]}', $client->getResponse()->getContent());
    }

    public function testItReturnsUnauthorizedWithoutAnApiKey(): void
    {
        // Criterion 11.
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItReturnsUnauthorizedWithAWrongApiKey(): void
    {
        $client = $this->startClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: 'not-the-key');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    #[DataProvider('unsupportedMethods')]
    public function testItRejectsEveryMethodOtherThanGet(string $method): void
    {
        $client = $this->startClient();

        self::apiRequest($client, $method, self::ENDPOINT);

        self::assertResponseStatusCodeSame(405);
        self::assertSame(['error' => 'method_not_allowed'], self::jsonResponse($client));
    }

    /**
     * @return array<int|string, array{string}>
     */
    public static function unsupportedMethods(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    public function testItHasNoSingleHabitEndpoint(): void
    {
        // Deliberate (ticket, "Routenreihenfolge"): no GET /api/habits/{habitId}.
        $client = $this->startClient();
        $habit = HabitFactory::createOne(['slug' => 'mood']);

        self::apiRequest($client, 'GET', self::ENDPOINT.'/'.$habit->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    /**
     * Boots the client and empties the habit table, so every test controls the complete catalog.
     */
    private function startClient(): KernelBrowser
    {
        $client = static::createClient();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->createQuery('DELETE FROM App\Entity\Habit h')->execute();

        return $client;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function habits(KernelBrowser $client): array
    {
        $body = self::jsonResponse($client);
        self::assertIsArray($body['habits'] ?? null);

        $habits = [];
        foreach ($body['habits'] as $habit) {
            self::assertIsArray($habit);
            $habits[] = $habit;
        }

        /** @var list<array<string, mixed>> $habits */
        return $habits;
    }

    /**
     * @return list<string>
     */
    private function slugs(KernelBrowser $client): array
    {
        return array_map(
            static function (array $habit): string {
                $slug = $habit['slug'] ?? null;

                return \is_string($slug) ? $slug : self::fail('Expected "slug" to be a string.');
            },
            $this->habits($client),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyHabit(KernelBrowser $client): array
    {
        $habits = $this->habits($client);
        self::assertCount(1, $habits);

        return $habits[0];
    }
}
