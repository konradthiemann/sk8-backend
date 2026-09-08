<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

final class HealthTest extends ApiTestCase
{
    public function testItReportsOkWithoutApiKey(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', '/api/health', apiKey: null);

        self::assertResponseStatusCodeSame(200);
        $body = self::jsonResponse($client);

        self::assertSame(['status', 'time'], array_keys($body));
        self::assertSame('ok', $body['status']);
    }

    public function testItReturnsTheCurrentTimeAsIso8601Utc(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', '/api/health', apiKey: null);

        $body = self::jsonResponse($client);
        self::assertIsString($body['time']);

        $time = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $body['time']);
        self::assertNotFalse($time, 'time must be a valid ISO 8601 / RFC 3339 timestamp');
        self::assertSame(0, $time->getOffset(), 'time must be expressed in UTC');
        self::assertEqualsWithDelta(time(), $time->getTimestamp(), 5.0);
    }

    /**
     * The frontends send X-Api-Key on every request, health included (see README).
     * A valid key must therefore not turn the public route into an error.
     */
    public function testItStillReportsOkWhenTheApiKeyIsSent(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', '/api/health');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('ok', self::jsonResponse($client)['status']);
    }
}
