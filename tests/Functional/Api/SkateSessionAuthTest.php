<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Criterion 28: every one of the five routes rejects a missing or wrong
 * `X-Api-Key` with 401, before touching any resource. A syntactically valid
 * but non-existent UUID is used for the {id} routes so the assertion never
 * depends on a session actually existing - the security layer runs before
 * the controller (ADR-006), so this must be 401, never 404.
 */
final class SkateSessionAuthTest extends ApiTestCase
{
    public function testItRejectsEveryRouteWithoutAnApiKey(): void
    {
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

    public function testItRejectsEveryRouteWithAWrongApiKey(): void
    {
        foreach (self::routes() as [$method, $uri]) {
            self::ensureKernelShutdown();
            $client = static::createClient();

            self::apiRequest($client, $method, $uri, apiKey: 'definitely-wrong');

            self::assertResponseStatusCodeSame(401, \sprintf('%s %s with wrong key', $method, $uri));
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
            ['POST', '/api/skate-sessions'],
            ['GET', '/api/skate-sessions'],
            ['GET', \sprintf('/api/skate-sessions/%s', $unknownId)],
            ['PUT', \sprintf('/api/skate-sessions/%s', $unknownId)],
            ['DELETE', \sprintf('/api/skate-sessions/%s', $unknownId)],
        ];
    }
}
