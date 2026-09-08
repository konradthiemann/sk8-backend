<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

/**
 * Guards the published OpenAPI document: the frontends generate their client
 * types from it, so a missing schema breaks consumers even when the API itself
 * behaves correctly.
 */
final class ApiDocTest extends ApiTestCase
{
    public function testItServesTheOpenApiDocumentWithoutApiKey(): void
    {
        $spec = $this->spec();

        self::assertIsString($spec['openapi'] ?? null);

        $paths = self::arrayAt($spec, 'paths');
        self::assertArrayHasKey('/api/health', $paths);
        self::assertArrayHasKey('/api/telemetry/events', $paths);
        self::assertArrayNotHasKey('/api/doc.json', $paths);

        $scheme = self::arrayAt($spec, 'components', 'securitySchemes', 'ApiKey');
        self::assertSame('apiKey', $scheme['type'] ?? null);
        self::assertSame('header', $scheme['in'] ?? null);
        self::assertSame('X-Api-Key', $scheme['name'] ?? null);
    }

    /**
     * Consumers generate their client types from this document, so the error
     * envelopes must be described - an empty schema {} generates as unusable.
     */
    public function testItDescribesTheSharedErrorSchemas(): void
    {
        $spec = $this->spec();

        $error = self::arrayAt($spec, 'components', 'schemas', 'ErrorResponse');
        self::assertSame('object', $error['type'] ?? null);
        self::assertSame(['error'], $error['required'] ?? null);
        self::assertSame('string', self::arrayAt($error, 'properties', 'error')['type'] ?? null);

        $violation = self::arrayAt($spec, 'components', 'schemas', 'Violation');
        self::assertSame('object', $violation['type'] ?? null);
        self::assertSame(['field', 'message'], $violation['required'] ?? null);
        self::assertSame('string', self::arrayAt($violation, 'properties', 'field')['type'] ?? null);
        $message = self::arrayAt($violation, 'properties', 'message');
        self::assertSame('string', $message['type'] ?? null);
        self::assertIsString($message['example'] ?? null, 'the German message needs a realistic example');

        $validation = self::arrayAt($spec, 'components', 'schemas', 'ValidationErrorResponse');
        self::assertSame('object', $validation['type'] ?? null);
        self::assertSame(['error', 'violations'], $validation['required'] ?? null);
        self::assertSame(['validation_failed'], self::arrayAt($validation, 'properties', 'error')['enum'] ?? null);

        $violations = self::arrayAt($validation, 'properties', 'violations');
        self::assertSame('array', $violations['type'] ?? null);
        self::assertSame('#/components/schemas/Violation', self::arrayAt($violations, 'items')['$ref'] ?? null);
    }

    public function testItReferencesTheErrorSchemasOnTheTelemetryOperation(): void
    {
        $spec = $this->spec();

        // Pairs, not a map: PHP would turn the numeric status keys into integers.
        $expected = [
            ['401', '#/components/schemas/ErrorResponse'],
            ['404', '#/components/schemas/ErrorResponse'],
            ['405', '#/components/schemas/ErrorResponse'],
            ['422', '#/components/schemas/ValidationErrorResponse'],
        ];

        foreach ($expected as [$status, $ref]) {
            $schema = self::arrayAt(
                $spec,
                'paths',
                '/api/telemetry/events',
                'post',
                'responses',
                $status,
                'content',
                'application/json',
                'schema',
            );

            self::assertSame($ref, $schema['$ref'] ?? null, "status {$status} must reference {$ref}");
        }
    }

    /**
     * Without additionalProperties, openapi-typescript renders meta as
     * Record<string, never> and the frontends cannot send their payloads.
     */
    public function testItLeavesTelemetryMetaOpenForArbitraryKeys(): void
    {
        $meta = self::arrayAt(
            $this->spec(),
            'components',
            'schemas',
            'TelemetryEventInput',
            'properties',
            'meta',
        );

        self::assertSame('object', $meta['type'] ?? null);
        self::assertTrue($meta['additionalProperties'] ?? false);
    }

    public function testItServesTheSwaggerUiWithoutApiKey(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $client = static::createClient();

        self::apiRequest($client, 'GET', '/api/doc.json', apiKey: null);

        self::assertResponseStatusCodeSame(200);

        return self::jsonResponse($client);
    }
}
