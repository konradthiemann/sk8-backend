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

    /**
     * T-0201: GET /api/trick-tree must not break `nelmio:apidoc:dump`
     * (ticket "Tests", ApiDocTest row: "Die neuen Routen brechen
     * nelmio:apidoc:dump nicht"). This file lists specific paths rather than
     * asserting the full route list generically, so the new route gets its
     * own explicit check, same as the existing assertions for /api/health
     * and /api/telemetry/events above.
     */
    public function testItRegistersTheTrickTreeRoute(): void
    {
        $paths = self::arrayAt($this->spec(), 'paths');

        self::assertArrayHasKey('/api/trick-tree', $paths);
        self::assertArrayHasKey('get', self::arrayAt($paths, '/api/trick-tree'));
    }

    /**
     * T-0303: the three fitness-assessment operations must appear in the
     * published document (ticket "Fertig, wenn": "in /api/doc mit Beispielen
     * dokumentiert"), and only those - the ticket defines no PUT/PATCH and no
     * single-item GET.
     */
    public function testItRegistersTheFitnessAssessmentRoutes(): void
    {
        $paths = self::arrayAt($this->spec(), 'paths');

        $collection = self::arrayAt($paths, '/api/fitness-assessments');
        self::assertArrayHasKey('post', $collection);
        self::assertArrayHasKey('get', $collection);

        $item = self::arrayAt($paths, '/api/fitness-assessments/{id}');
        self::assertArrayHasKey('delete', $item);
        self::assertArrayNotHasKey('put', $item);
        self::assertArrayNotHasKey('patch', $item);
    }

    /**
     * T-0303 is the first endpoint that answers 409. Consumers generate
     * their client types from ErrorResponse, so without `conflict` in the
     * enum the generated type would not know the code the API sends.
     */
    public function testItListsConflictAmongTheKnownErrorCodes(): void
    {
        $errorCodes = self::arrayAt($this->spec(), 'components', 'schemas', 'ErrorResponse', 'properties', 'error');

        self::assertIsArray($errorCodes['enum'] ?? null);
        self::assertContains('conflict', $errorCodes['enum']);
    }

    public function testItReferencesTheErrorSchemasOnTheFitnessAssessmentCreateOperation(): void
    {
        $spec = $this->spec();

        // Pairs, not a map: PHP would turn the numeric status keys into integers.
        $expected = [
            ['401', '#/components/schemas/ErrorResponse'],
            ['409', '#/components/schemas/ErrorResponse'],
            ['422', '#/components/schemas/ValidationErrorResponse'],
        ];

        foreach ($expected as [$status, $ref]) {
            $schema = self::arrayAt(
                $spec,
                'paths',
                '/api/fitness-assessments',
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
     * The frontends read the eight measurements from these two schemas; a
     * field missing here (the two later additions are the likely ones) would
     * silently be absent from every generated client type.
     */
    public function testItDescribesAllEightMeasurementsInTheFitnessAssessmentSchemas(): void
    {
        $schemas = self::arrayAt($this->spec(), 'components', 'schemas');
        $measurements = [
            'pushUpsMax',
            'squatsMax',
            'ringPullUpsMax',
            'plankSeconds',
            'singleLegBalanceLeftSeconds',
            'singleLegBalanceRightSeconds',
            'wallSitSeconds',
            'standingBroadJumpCm',
        ];

        foreach (['FitnessAssessmentRequest', 'FitnessAssessmentView'] as $schema) {
            $properties = self::arrayAt($schemas, $schema, 'properties');

            foreach ($measurements as $measurement) {
                self::assertArrayHasKey($measurement, $properties, "{$schema} must describe {$measurement}");
            }
        }

        $view = self::arrayAt($schemas, 'FitnessAssessmentView', 'properties');
        self::assertArrayHasKey('balanceDifferenceSeconds', $view);
        self::assertArrayHasKey('weakerBalanceSide', $view);
    }

    /**
     * T-0401: GET /api/habits must appear in the published document, and only
     * as a read operation - the catalog is maintained by `app:habits:sync`,
     * never through the API.
     */
    public function testItRegistersTheHabitCatalogRouteAsAReadOnlyOperation(): void
    {
        $paths = self::arrayAt($this->spec(), 'paths');

        $habits = self::arrayAt($paths, '/api/habits');
        self::assertArrayHasKey('get', $habits);
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            self::assertArrayNotHasKey($method, $habits, "/api/habits must not offer {$method}");
        }
    }

    public function testItReferencesTheResponseAndErrorSchemasOnTheHabitCatalogOperation(): void
    {
        $spec = $this->spec();

        // Pairs, not a map: PHP would turn the numeric status keys into integers.
        $expected = [
            ['200', '#/components/schemas/HabitListResponse'],
            ['401', '#/components/schemas/ErrorResponse'],
            ['405', '#/components/schemas/ErrorResponse'],
            ['422', '#/components/schemas/ValidationErrorResponse'],
        ];

        foreach ($expected as [$status, $ref]) {
            $schema = self::arrayAt(
                $spec,
                'paths',
                '/api/habits',
                'get',
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
     * The habits UI builds its input fields from this schema alone, so a
     * field missing here is missing from every generated client type.
     */
    public function testItDescribesAllElevenFieldsInTheHabitResponseSchema(): void
    {
        $schemas = self::arrayAt($this->spec(), 'components', 'schemas');

        $list = self::arrayAt($schemas, 'HabitListResponse', 'properties', 'habits');
        self::assertSame('array', $list['type'] ?? null);
        self::assertSame('#/components/schemas/HabitResponse', self::arrayAt($list, 'items')['$ref'] ?? null);

        $properties = self::arrayAt($schemas, 'HabitResponse', 'properties');
        foreach (['id', 'slug', 'name', 'valueType', 'unit', 'scaleMin', 'scaleMax', 'targetDirection', 'targetValue', 'sortOrder', 'isActive'] as $field) {
            self::assertArrayHasKey($field, $properties, "HabitResponse must describe {$field}");
        }
    }

    /**
     * Criterion 10 in the published contract: the DECIMAL column is a number,
     * not a string, for every generated client.
     */
    public function testItDescribesTheHabitTargetValueAsANumber(): void
    {
        $targetValue = self::arrayAt($this->spec(), 'components', 'schemas', 'HabitResponse', 'properties', 'targetValue');

        self::assertSame('number', $targetValue['type'] ?? null);
    }

    public function testItDescribesTheHabitValueTypeAndDirectionAsEnums(): void
    {
        $properties = self::arrayAt($this->spec(), 'components', 'schemas', 'HabitResponse', 'properties');

        self::assertEqualsCanonicalizing(['boolean', 'scale', 'number', 'duration'], self::arrayAt($properties, 'valueType')['enum'] ?? null);
        self::assertEqualsCanonicalizing(['hoch', 'niedrig'], self::arrayAt($properties, 'targetDirection')['enum'] ?? null);
    }

    public function testItDocumentsIncludeInactiveAsABooleanQueryParameterOnTheHabitCatalog(): void
    {
        $parameters = self::arrayAt($this->spec(), 'paths', '/api/habits', 'get')['parameters'] ?? null;
        self::assertIsArray($parameters, 'GET /api/habits must document its query parameters');

        $includeInactive = null;
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && 'includeInactive' === ($parameter['name'] ?? null)) {
                $includeInactive = $parameter;
            }
        }

        self::assertIsArray($includeInactive, 'includeInactive must be documented as a parameter');
        self::assertSame('query', $includeInactive['in'] ?? null);
        $schema = $includeInactive['schema'] ?? null;
        self::assertIsArray($schema);
        self::assertSame('boolean', $schema['type'] ?? null);
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
