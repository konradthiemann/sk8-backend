<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Trick-row tests reference "ollie", part of the 16-trick catalog seeded by
 * T-0101's data migration - no need to create it via a factory here.
 */
final class SkateSessionValidationTest extends ApiTestCase
{
    private const string ENDPOINT = '/api/skate-sessions';

    public function testItAcceptsAWeightBeforeWithoutAWeightAfterAndFluidLossIsNull(): void
    {
        // Criterion 6.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['weightBeforeKg' => 78.4]));

        self::assertResponseStatusCodeSame(201);
        $body = self::jsonResponse($client);
        self::assertSame(78.4, $body['weightBeforeKg']);
        self::assertNull($body['fluidLossKg']);
    }

    public function testItRejectsAWeightAfterWithoutAWeightBefore(): void
    {
        // Criterion 7.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['weightAfterKg' => 77.1]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['weightAfterKg'], self::violationFields(self::jsonResponse($client)));
    }

    #[DataProvider('invalidDurations')]
    public function testItRejectsADurationOutsideOneToSixHundredMinutes(int $duration): void
    {
        // Criteria 10, 11.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload(['durationMinutes' => $duration]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['durationMinutes'], self::violationFields(self::jsonResponse($client)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'zero' => [0];
        yield 'above six hundred' => [601];
    }

    public function testItRejectsATrickRowWhereLandedExceedsAttempts(): void
    {
        // Criterion 12.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'tricks' => [['trickSlug' => 'ollie', 'attempts' => 5, 'landed' => 6, 'notes' => null]],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['tricks[0].landed'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsTheSameTrickSlugTwiceInOneRequest(): void
    {
        // Criterion 13.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'tricks' => [
                ['trickSlug' => 'ollie', 'attempts' => 10, 'landed' => 5, 'notes' => null],
                ['trickSlug' => 'ollie', 'attempts' => 8, 'landed' => 2, 'notes' => null],
            ],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['tricks'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsATrickSlugTheCatalogDoesNotKnow(): void
    {
        // Criterion 14.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'tricks' => [['trickSlug' => 'not-a-real-trick', 'attempts' => 5, 'landed' => 1, 'notes' => null]],
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['tricks[0].trickSlug'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsASessionDateInTheFuture(): void
    {
        // Criterion 15. NotInFutureValidator compares against "today" in
        // Europe/Berlin (%app.timezone%), so "+1 day" must be computed in the
        // same zone here - the system default zone can differ by a couple of
        // hours around midnight, which would occasionally make this date not
        // actually be tomorrow from the validator's point of view.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sessionDate' => (new \DateTimeImmutable('+1 day', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['sessionDate'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsAStartedAtOnADifferentDayThanSessionDate(): void
    {
        // Criterion 17.
        $client = static::createClient();
        $sessionDate = (new \DateTimeImmutable('-2 days'))->format('Y-m-d');
        $startedAtOtherDay = (new \DateTimeImmutable('-3 days'))->format('Y-m-d').'T16:00:00+02:00';

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sessionDate' => $sessionDate,
            'startedAt' => $startedAtOtherDay,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['startedAt'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsFromAfterToOnTheListEndpoint(): void
    {
        // Criterion 21 (contract-level check; SkateSessionReadTest exercises
        // the same rule from the list-behaviour angle).
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?from=2026-09-05&to=2026-09-01');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['from'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItRejectsALimitOutsideOneToTwoHundredOnTheListEndpoint(): void
    {
        // Criterion 22 (contract-level check; SkateSessionReadTest also
        // covers the upper bound).
        $client = static::createClient();

        self::apiRequest($client, 'GET', self::ENDPOINT.'?limit=0');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['limit'], self::violationFields(self::jsonResponse($client)));
    }

    public function testItTranslatesEveryViolationMessageToNonEmptyGermanText(): void
    {
        // Criterion 30: fire several rules at once; none of the resulting
        // messages may be empty or leak the raw, untranslated message key.
        $client = static::createClient();

        self::apiRequest($client, 'POST', self::ENDPOINT, $this->validPayload([
            'sessionDate' => (new \DateTimeImmutable('+1 day', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
            'durationMinutes' => 0,
            'weightAfterKg' => 77.1,
            'tricks' => [
                ['trickSlug' => 'ollie', 'attempts' => 10, 'landed' => 5, 'notes' => null],
                ['trickSlug' => 'ollie', 'attempts' => 8, 'landed' => 2, 'notes' => null],
            ],
        ]));

        self::assertResponseStatusCodeSame(422);
        $violations = self::violations(self::jsonResponse($client));
        self::assertNotEmpty($violations);

        foreach ($violations as $violation) {
            self::assertNotSame('', trim($violation['message']));
            self::assertStringStartsNotWith('skate_session.', $violation['message'], 'the raw translation key must not leak into the response');
        }
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
            'startedAt' => null,
            'durationMinutes' => 60,
            'location' => 'Skatepark Braunschweig',
            'weightBeforeKg' => null,
            'weightAfterKg' => null,
            'perceivedExertion' => null,
            'kneePain' => null,
            'notes' => null,
            'tricks' => [],
        ], $overrides);
    }
}
