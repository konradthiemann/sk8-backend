<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

final class ErrorFormatTest extends ApiTestCase
{
    public function testItReturnsNotFoundAsJsonForUnknownApiRoutes(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', '/api/nope');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    public function testItReturnsNotFoundAsJsonEvenWithoutApiKey(): void
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', '/api/nope', apiKey: null);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::jsonResponse($client));
    }

    public function testItReturnsBadRequestAsJsonForMalformedJson(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/telemetry/events',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_KEY' => TEST_API_KEY],
            '{"app": "skate", "events": [',
        );

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'bad_request'], self::jsonResponse($client));
    }
}
