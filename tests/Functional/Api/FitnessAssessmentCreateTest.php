<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\FitnessAssessmentFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

final class FitnessAssessmentCreateTest extends ApiTestCase
{
    use Factories;

    private const string ENDPOINT = '/api/fitness-assessments';

    /**
     * Every measurement field with its inclusive upper bound (design.md 3.1);
     * the lower bound is 0 for all of them.
     */
    private const array UPPER_BOUNDS = [
        'pushUpsMax' => 500,
        'squatsMax' => 1000,
        'ringPullUpsMax' => 200,
        'plankSeconds' => 3600,
        'singleLegBalanceLeftSeconds' => 3600,
        'singleLegBalanceRightSeconds' => 3600,
        'wallSitSeconds' => 3600,
        'standingBroadJumpCm' => 400,
    ];

    public function testItCreatesAnAssessmentWithAllEightMeasurementsAndReturnsALocationHeader(): void
    {
        // Criterion 1, and the end-to-end guard for the two new fields:
        // every value has to survive request -> entity -> row -> response.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, [
            'assessedOn' => '2026-09-08',
            'pushUpsMax' => 24,
            'squatsMax' => 41,
            'ringPullUpsMax' => 5,
            'plankSeconds' => 95,
            'singleLegBalanceLeftSeconds' => 28,
            'singleLegBalanceRightSeconds' => 51,
            'wallSitSeconds' => 70,
            'standingBroadJumpCm' => 185,
            'notes' => 'Links deutlich wackliger, Bandage getragen',
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);
        self::assertIsString($body['id']);
        self::assertTrue(Uuid::isValid($body['id']));

        $expected = [
            'id' => $body['id'],
            'assessedOn' => '2026-09-08',
            'pushUpsMax' => 24,
            'squatsMax' => 41,
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
        ksort($expected);
        ksort($body);
        self::assertSame($expected, $body);

        self::assertSame(
            \sprintf('/api/fitness-assessments/%s', $expected['id']),
            $client->getResponse()->headers->get('Location'),
        );
    }

    public function testItPersistsAllEightMeasurementsInTheirColumns(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, [
            'assessedOn' => '2026-09-08',
            'pushUpsMax' => 24,
            'squatsMax' => 41,
            'ringPullUpsMax' => 5,
            'plankSeconds' => 95,
            'singleLegBalanceLeftSeconds' => 28,
            'singleLegBalanceRightSeconds' => 51,
            'wallSitSeconds' => 70,
            'standingBroadJumpCm' => 185,
            'notes' => 'Links deutlich wackliger, Bandage getragen',
        ]);

        self::assertResponseStatusCodeSame(201);
        $id = self::jsonResponse($client)['id'];
        self::assertIsString($id);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertSame(1, $this->rowCount($entityManager));

        // assertEquals, not assertSame: the driver may hand back smallints as
        // int or as numeric strings; the values are what counts here.
        self::assertEquals([
            'id' => $id,
            'assessed_on' => '2026-09-08',
            'push_ups_max' => 24,
            'squats_max' => 41,
            'ring_pull_ups_max' => 5,
            'plank_seconds' => 95,
            'single_leg_balance_left_seconds' => 28,
            'single_leg_balance_right_seconds' => 51,
            'wall_sit_seconds' => 70,
            'standing_broad_jump_cm' => 185,
            'notes' => 'Links deutlich wackliger, Bandage getragen',
        ], $entityManager->getConnection()->fetchAssociative('SELECT * FROM fitness_assessment WHERE id = ?', [$id]));
    }

    #[DataProvider('measurementFields')]
    public function testItCreatesAnAssessmentWithExactlyOneMeasurementAndReturnsTheRestAsNull(string $field): void
    {
        // Criterion 3, plus: the view always carries all eight measurement
        // keys, missing ones as null.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, ['assessedOn' => '2026-09-08', $field => 7]);

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);

        $expected = array_fill_keys(array_keys(self::UPPER_BOUNDS), null);
        $expected[$field] = 7;
        $actual = array_intersect_key($body, $expected);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    public function testItCountsAZeroMeasurementAsSetAndReturnsItAsZero(): void
    {
        // A left-leg balance of 0 seconds must neither trip the "at least one
        // value" rule nor come back as null.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, [
            'assessedOn' => '2026-09-08',
            'singleLegBalanceLeftSeconds' => 0,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, self::jsonResponse($client)['singleLegBalanceLeftSeconds']);
    }

    public function testItCreatesAnAssessmentWhoseDateIsTodayInBerlin(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['assessedOn' => $this->berlinDay('now')]));

        self::assertResponseStatusCodeSame(201);
    }

    public function testItRejectsAnAssessmentDatedTomorrow(): void
    {
        // Criterion 4. "Tomorrow" is computed in Europe/Berlin
        // (%app.timezone%), the zone NotInFuture compares in.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['assessedOn' => $this->berlinDay('+1 day')]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['assessedOn'], self::violationFields(self::jsonResponse($client)));
    }

    #[DataProvider('unusableDates')]
    public function testItRejectsAnUnusableDate(string $assessedOn): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['assessedOn' => $assessedOn]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['assessedOn'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsARequestWithoutADate(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, ['pushUpsMax' => 24]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['assessedOn'], self::violationFields(self::jsonResponse($client)));
    }

    #[DataProvider('validBoundaryValues')]
    public function testItAcceptsAMeasurementOnItsInclusiveBoundary(string $field, int $value): void
    {
        // The database CHECK constraints mirror these ranges, so a boundary
        // that passes validation but trips a CHECK would surface as a 500.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([$field => $value]));

        self::assertResponseStatusCodeSame(201);
        self::assertSame($value, self::jsonResponse($client)[$field]);
    }

    #[DataProvider('outOfRangeValues')]
    public function testItRejectsAMeasurementOutsideItsRangeWithTheViolationOnThatField(string $field, int $value): void
    {
        // Criterion 5.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([$field => $value]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$field], self::violationFields(self::jsonResponse($client)));
    }

    public function testItReportsEveryOutOfRangeMeasurementInOneResponse(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'pushUpsMax' => 501,
            'wallSitSeconds' => -1,
            'standingBroadJumpCm' => 401,
        ]));

        self::assertResponseStatusCodeSame(422);
        $fields = self::violationFields(self::jsonResponse($client));
        sort($fields);
        self::assertSame(['pushUpsMax', 'standingBroadJumpCm', 'wallSitSeconds'], $fields);
    }

    public function testItExplainsAWallSitViolationInGerman(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['wallSitSeconds' => 3601]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'wallSitSeconds', 'message' => 'Die Wandsitz-Zeit muss zwischen 0 und 3600 Sekunden liegen.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItExplainsAStandingBroadJumpViolationInGerman(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['standingBroadJumpCm' => 401]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'standingBroadJumpCm', 'message' => 'Die Sprungweite muss zwischen 0 und 400 Zentimetern liegen.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItRejectsAMeasurementOfTheWrongJsonTypeWithAViolationOnThatField(): void
    {
        // A wrongly typed field is a validation failure (422), only
        // syntactically broken JSON is a 400.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['pushUpsMax' => 'many']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['pushUpsMax'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsARequestWithoutAnyMeasurementOnThePathValues(): void
    {
        // Criterion 2, all eight fields omitted.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, ['assessedOn' => '2026-09-08', 'notes' => 'nothing measured']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['values'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsARequestWhoseMeasurementsAreAllExplicitlyNull(): void
    {
        // Criterion 2, all eight fields sent as null.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(array_fill_keys(array_keys(self::UPPER_BOUNDS), null)));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['values'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItExplainsAMissingMeasurementInGerman(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, ['assessedOn' => '2026-09-08']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'values', 'message' => 'Trag mindestens einen Wert ein, sonst gibt es nichts zu vergleichen.']],
            self::violations(self::jsonResponse($client)),
        );
    }

    public function testItLeavesNoRowBehindWhenValidationFails(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['pushUpsMax' => 501]));

        self::assertResponseStatusCodeSame(422);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertSame(0, $this->rowCount($entityManager));
    }

    public function testItAcceptsNotesOfExactlyTwoThousandCharacters(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['notes' => str_repeat('a', 2000)]));

        self::assertResponseStatusCodeSame(201);
    }

    public function testItRejectsNotesOfMoreThanTwoThousandCharacters(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['notes' => str_repeat('a', 2001)]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['notes'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsAPayloadThatIsNotValidJsonWithBadRequest(): void
    {
        $client = static::createClient();

        $client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => TEST_API_KEY,
        ], '{"assessedOn": ');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'bad_request'], self::jsonResponse($client));
    }

    public function testItRejectsASecondAssessmentForTheSameDateWithConflictAndKeepsTheFirstRowUntouched(): void
    {
        // Criterion 6. Foundry needs the kernel booted before it can persist,
        // so createClient() (which owns the boot) always comes first.
        $client = static::createClient();

        $existing = FitnessAssessmentFactory::createOne([
            'assessedOn' => new \DateTimeImmutable('2026-09-08'),
            'pushUpsMax' => 11,
            'notes' => 'first entry',
        ]);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $rowBefore = $this->rowById($entityManager, $existing->getId()->toRfc4122());

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'assessedOn' => '2026-09-08',
            'pushUpsMax' => 99,
            'notes' => 'second entry',
        ]));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'conflict'], self::jsonResponse($client));
        self::assertSame(1, $this->rowCount($entityManager));
        self::assertSame($rowBefore, $this->rowById($entityManager, $existing->getId()->toRfc4122()));
    }

    public function testItRejectsEveryRouteWithoutAnApiKey(): void
    {
        // Criterion 14.
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

    public function testItRejectsAWrongApiKey(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(), apiKey: 'not-the-key');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'unauthorized'], self::jsonResponse($client));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function measurementFields(): iterable
    {
        foreach (array_keys(self::UPPER_BOUNDS) as $field) {
            yield $field => [$field];
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validBoundaryValues(): iterable
    {
        foreach (self::UPPER_BOUNDS as $field => $max) {
            yield $field.' at 0' => [$field, 0];
            yield $field.' at '.$max => [$field, $max];
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function outOfRangeValues(): iterable
    {
        foreach (self::UPPER_BOUNDS as $field => $max) {
            yield $field.' at -1' => [$field, -1];
            yield $field.' at '.($max + 1) => [$field, $max + 1];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableDates(): iterable
    {
        yield 'impossible calendar date' => ['2026-13-45'];
        yield 'free text' => ['morgen'];
        yield 'empty string' => [''];
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
            ['DELETE', \sprintf('%s/%s', self::ENDPOINT, $unknownId)],
        ];
    }

    private function berlinDay(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier, new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }

    private function rowCount(EntityManagerInterface $entityManager): int
    {
        /** @var numeric-string|int $raw */
        $raw = $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM fitness_assessment');

        return (int) $raw;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function rowById(EntityManagerInterface $entityManager, string $id): array|false
    {
        return $entityManager->getConnection()->fetchAssociative('SELECT * FROM fitness_assessment WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'assessedOn' => $this->berlinDay('-2 days'),
            'pushUpsMax' => 24,
        ], $overrides);
    }
}
