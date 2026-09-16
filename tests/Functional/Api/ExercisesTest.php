<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\ExerciseMeasure;
use App\Tests\Factory\ExerciseFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Functional, against real Postgres (dama-rolled-back per test). Exercises
 * `GET /api/exercises` end to end (design.md §3, App\Controller\Api\
 * ExerciseController). Read-only endpoint, so unlike TrickListTest there is
 * no matching write-path test file - the ticket text is explicit that a
 * write endpoint is a deliberate non-goal for curated catalog data.
 *
 * Sorting/shape tests replace the seeded catalog with a small, controlled
 * set of rows first (`replaceCatalogWith()`): the real 16-row catalog's
 * exact names are R-03 content this ticket does not fix (design.md §2,
 * "Offene Punkte"), so a test that depends on their alphabetical order would
 * be testing R-03's data, not the sort implementation. Tests that only care
 * about response shape (camelCase fields, total === count(items)) run
 * against whatever the migration seeded, same as TrickListTest.
 */
final class ExercisesTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/exercises';

    public function testItSortsPreventionExercisesFirstThenAlphabeticallyByName(): void
    {
        // Criterion 3.
        $client = static::createClient();
        $this->replaceCatalogWith([
            ['slug' => 'kniebeuge', 'name' => 'Kniebeuge', 'isPrevention' => false],
            ['slug' => 'ausfallschritt', 'name' => 'Ausfallschritt', 'isPrevention' => false],
            ['slug' => 'wadenheben', 'name' => 'Wadenheben', 'isPrevention' => true],
            ['slug' => 'daumenkreisen', 'name' => 'Daumenkreisen', 'isPrevention' => true],
        ]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $slugsInOrder = array_map(
            static function (array $item): string {
                $slug = $item['slug'] ?? null;

                return \is_string($slug) ? $slug : self::fail('Expected "slug" to be a string.');
            },
            $this->items($client),
        );

        self::assertSame(['daumenkreisen', 'wadenheben', 'ausfallschritt', 'kniebeuge'], $slugsInOrder);
    }

    public function testItReturnsTheExactMeasureValueForAnExerciseTrackedSecondsPerSide(): void
    {
        // Criterion 4.
        $client = static::createClient();
        ExerciseFactory::createOne(['slug' => 'measure-contract-check', 'measure' => ExerciseMeasure::SecondsPerSide]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $item = $this->findBySlug('measure-contract-check', $this->items($client));
        self::assertSame('seconds_per_side', $item['measure'] ?? null);
    }

    public function testItReturnsAllExerciseViewFieldsInCamelCase(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        foreach ($this->items($client) as $item) {
            self::assertSame(
                ['slug', 'name', 'equipment', 'muscleGroups', 'kneeLoad', 'measure', 'description', 'isPrevention'],
                array_keys($item),
            );
        }
    }

    public function testItReturnsTotalMatchingTheNumberOfItems(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $body = self::jsonResponse($client);
        self::assertIsArray($body['items'] ?? null);
        self::assertSame(\count($body['items']), $body['total'] ?? null);
    }

    public function testItReturnsAnEmptyCatalogAsTotalZero(): void
    {
        // Criterion 5.
        $client = static::createClient();
        $this->clearCatalog();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['items' => [], 'total' => 0], self::jsonResponse($client));
    }

    public function testItReturnsUnauthorizedWithoutApiKey(): void
    {
        // Criterion 6.
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT, apiKey: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    public function testItRejectsPostRequests(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT);

        self::assertResponseStatusCodeSame(405);
        self::assertSame(['error' => 'method_not_allowed'], self::jsonResponse($client));
    }

    /**
     * Deletes every seeded/created exercise, then inserts exactly the given
     * rows through ExerciseFactory (defaults fill the remaining fields), so
     * a sort/shape assertion never depends on R-03's still-undecided catalog
     * content.
     *
     * @param list<array{slug: string, name: string, isPrevention: bool}> $rows
     */
    private function replaceCatalogWith(array $rows): void
    {
        $this->clearCatalog();

        foreach ($rows as $row) {
            ExerciseFactory::createOne($row);
        }
    }

    private function clearCatalog(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->createQuery('DELETE FROM App\Entity\Exercise e')->execute();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(KernelBrowser $client): array
    {
        $body = self::jsonResponse($client);
        self::assertIsArray($body['items'] ?? null);

        $items = [];
        foreach ($body['items'] as $item) {
            self::assertIsArray($item);
            $items[] = $item;
        }

        /** @var list<array<string, mixed>> $items */
        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function findBySlug(string $slug, array $items): array
    {
        foreach ($items as $item) {
            if (($item['slug'] ?? null) === $slug) {
                return $item;
            }
        }

        self::fail(\sprintf('exercise with slug "%s" not found in response', $slug));
    }
}
