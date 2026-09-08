<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    /**
     * Sends a JSON request to the API, by default authenticated with the test API key.
     *
     * @param array<string, mixed>|null $payload
     */
    protected static function apiRequest(
        KernelBrowser $client,
        string $method,
        string $uri,
        ?array $payload = null,
        ?string $apiKey = TEST_API_KEY,
    ): void {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $apiKey) {
            $server['HTTP_X_API_KEY'] = $apiKey;
        }

        $content = null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR);

        $client->request($method, $uri, [], [], $server, $content);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function jsonResponse(KernelBrowser $client): array
    {
        $response = $client->getResponse();

        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $content = $response->getContent();
        self::assertNotFalse($content);

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Walks a nested structure, asserting an array at every step.
     *
     * Keeps deep assertions readable and type-safe: chained offsets on mixed
     * are rejected by PHPStan at level max.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    protected static function arrayAt(array $source, string ...$path): array
    {
        $current = $source;
        $walked = [];

        foreach ($path as $key) {
            $walked[] = $key;
            self::assertArrayHasKey($key, $current, 'missing key: '.implode(' > ', $walked));

            $next = $current[$key];
            self::assertIsArray($next, 'not an array: '.implode(' > ', $walked));

            /** @var array<string, mixed> $next */
            $current = $next;
        }

        return $current;
    }

    /**
     * Asserts the shared validation-error envelope and returns its violations.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array{field: string, message: string}>
     */
    protected static function violations(array $body): array
    {
        self::assertSame('validation_failed', $body['error'] ?? null);
        self::assertIsArray($body['violations'] ?? null);

        $violations = [];
        foreach ($body['violations'] as $violation) {
            self::assertIsArray($violation);

            $field = $violation['field'] ?? null;
            $message = $violation['message'] ?? null;
            self::assertIsString($field);
            self::assertIsString($message);
            self::assertNotSame('', $message, 'every violation needs a German message');

            $violations[] = ['field' => $field, 'message' => $message];
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    protected static function violationFields(array $body): array
    {
        return array_map(
            static fn (array $violation): string => $violation['field'],
            self::violations($body),
        );
    }
}
