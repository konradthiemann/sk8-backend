<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\FitnessAssessmentFactory;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Zenstruck\Foundry\Test\Factories;

final class FitnessAssessmentListTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/fitness-assessments';

    public function testItSortsThreeAssessmentsNewestFirstAndReportsTheTotal(): void
    {
        // Criterion 10. Inserted out of order on purpose, so the order can
        // only come from the query, not from insertion order. Foundry needs
        // the kernel booted before it can persist, so createClient() (which
        // owns the boot) always comes first, factory calls after.
        $client = static::createClient();

        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-01')]);
        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-03')]);
        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-09-02')]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame(3, $body['total']);
        self::assertSame(['2026-09-03', '2026-09-02', '2026-09-01'], array_column($this->items($body), 'assessedOn'));
    }

    public function testItReturnsAnEmptyPageWithTheDefaultPagingWhenThereAreNoAssessments(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['items' => [], 'total' => 0, 'limit' => 30, 'offset' => 0], self::jsonResponse($client));
    }

    public function testItReturnsExactlyTheSecondItemWithLimitOneAndOffsetOneWhileTotalStaysTheFullCount(): void
    {
        $client = static::createClient();

        for ($day = 1; $day <= 3; ++$day) {
            FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable(\sprintf('2026-08-%02d', $day))]);
        }

        self::apiRequest($client, 'GET', self::ENDPOINT.'?limit=1&offset=1');

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame(3, $body['total']);
        self::assertSame(1, $body['limit']);
        self::assertSame(1, $body['offset']);
        // Newest-first order is 08-03, 08-02, 08-01; offset=1 skips 08-03.
        self::assertSame(['2026-08-02'], array_column($this->items($body), 'assessedOn'));
    }

    public function testItReturnsAnEmptyPageWhenTheOffsetLiesBeyondTheLastItem(): void
    {
        $client = static::createClient();

        FitnessAssessmentFactory::createOne(['assessedOn' => new \DateTimeImmutable('2026-08-01')]);

        self::apiRequest($client, 'GET', self::ENDPOINT.'?offset=5');

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);
        self::assertSame([], $body['items']);
        self::assertSame(1, $body['total']);
    }

    #[DataProvider('validLimits')]
    public function testItAcceptsALimitOnTheInclusiveBoundaries(int $limit): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?limit='.$limit);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($limit, self::jsonResponse($client)['limit']);
    }

    #[DataProvider('outOfRangeLimits')]
    public function testItRejectsALimitOutsideOneToOneHundred(string $limit): void
    {
        // Criterion 11. MapQueryString answers 404 by default; the 422 has to
        // be requested explicitly, which is what this test pins down.
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?limit='.$limit);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['limit'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsANegativeOffset(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?offset=-1');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['offset'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItListsEveryFieldOfAnAssessmentWithAllEightMeasurements(): void
    {
        $client = static::createClient();

        $assessment = FitnessAssessmentFactory::createOne([
            'assessedOn' => new \DateTimeImmutable('2026-09-08'),
            'pushUpsMax' => 24,
            'squatsMax' => null,
            'ringPullUpsMax' => 5,
            'plankSeconds' => 95,
            'singleLegBalanceLeftSeconds' => 28,
            'singleLegBalanceRightSeconds' => 51,
            'wallSitSeconds' => 70,
            'standingBroadJumpCm' => 185,
            'notes' => 'Links deutlich wackliger, Bandage getragen',
        ]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(200);
        $expected = [
            'id' => $assessment->getId()->toRfc4122(),
            'assessedOn' => '2026-09-08',
            'pushUpsMax' => 24,
            'squatsMax' => null,
            'ringPullUpsMax' => 5,
            'plankSeconds' => 95,
            'singleLegBalanceLeftSeconds' => 28,
            'singleLegBalanceRightSeconds' => 51,
            'wallSitSeconds' => 70,
            'standingBroadJumpCm' => 185,
            'notes' => 'Links deutlich wackliger, Bandage getragen',
            'balanceDifferenceSeconds' => 23,
            'weakerBalanceSide' => 'links',
        ];
        $actual = $this->items(self::jsonResponse($client))[0];
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    public function testItDerivesTheDifferenceAndTheWeakerSideFromTheTwoBalanceValues(): void
    {
        // Criterion 7.
        $client = static::createClient();

        FitnessAssessmentFactory::createOne(['singleLegBalanceLeftSeconds' => 28, 'singleLegBalanceRightSeconds' => 51]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $item = $this->items(self::jsonResponse($client))[0];
        self::assertSame(23, $item['balanceDifferenceSeconds']);
        self::assertSame('links', $item['weakerBalanceSide']);
    }

    public function testItNamesTheRightSideAsWeakerWhenItHoldsShorter(): void
    {
        $client = static::createClient();

        FitnessAssessmentFactory::createOne(['singleLegBalanceLeftSeconds' => 51, 'singleLegBalanceRightSeconds' => 28]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $item = $this->items(self::jsonResponse($client))[0];
        self::assertSame(23, $item['balanceDifferenceSeconds']);
        self::assertSame('rechts', $item['weakerBalanceSide']);
    }

    #[DataProvider('incompleteBalancePairs')]
    public function testItReturnsNoDifferenceAndNoWeakerSideWhenABalanceSideIsMissing(?int $left, ?int $right): void
    {
        // Criterion 8.
        $client = static::createClient();

        FitnessAssessmentFactory::createOne(['singleLegBalanceLeftSeconds' => $left, 'singleLegBalanceRightSeconds' => $right]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $item = $this->items(self::jsonResponse($client))[0];
        self::assertNull($item['balanceDifferenceSeconds']);
        self::assertNull($item['weakerBalanceSide']);
    }

    public function testItReturnsADifferenceOfZeroAndNoWeakerSideForEqualBalanceValues(): void
    {
        // Criterion 9.
        $client = static::createClient();

        FitnessAssessmentFactory::createOne(['singleLegBalanceLeftSeconds' => 30, 'singleLegBalanceRightSeconds' => 30]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $item = $this->items(self::jsonResponse($client))[0];
        self::assertSame(0, $item['balanceDifferenceSeconds']);
        self::assertNull($item['weakerBalanceSide']);
    }

    public function testItTreatsAZeroBalanceValueAsMeasuredAndNamesThatSideAsWeaker(): void
    {
        $client = static::createClient();

        FitnessAssessmentFactory::createOne(['singleLegBalanceLeftSeconds' => 0, 'singleLegBalanceRightSeconds' => 40]);

        self::apiRequest($client, 'GET', self::ENDPOINT);

        $item = $this->items(self::jsonResponse($client))[0];
        self::assertSame(40, $item['balanceDifferenceSeconds']);
        self::assertSame('links', $item['weakerBalanceSide']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function validLimits(): iterable
    {
        yield 'lowest' => [1];
        yield 'highest' => [100];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outOfRangeLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'above one hundred' => ['101'];
        yield 'not a number' => ['many'];
    }

    /**
     * @return iterable<string, array{?int, ?int}>
     */
    public static function incompleteBalancePairs(): iterable
    {
        yield 'only left measured' => [28, null];
        yield 'only right measured' => [null, 51];
        yield 'neither measured' => [null, null];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $body): array
    {
        self::assertIsArray($body['items'] ?? null);

        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];

        return $items;
    }
}
