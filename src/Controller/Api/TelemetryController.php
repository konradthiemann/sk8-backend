<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Telemetry\TelemetryAcceptedResponse;
use App\Dto\Telemetry\TelemetryBatchRequest;
use App\Service\Telemetry\TelemetryIngestService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives UX telemetry batches from the frontends (ADR-009).
 */
#[OA\Tag(name: 'Telemetry')]
final class TelemetryController
{
    /**
     * Error envelopes are declared once in config/packages/nelmio_api_doc.yaml,
     * because App\EventListener\ApiExceptionListener answers for every /api route.
     */
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';
    private const string VALIDATION_ERROR_RESPONSE = '#/components/schemas/ValidationErrorResponse';

    public function __construct(
        private readonly TelemetryIngestService $ingestService,
    ) {
    }

    #[Route('/api/telemetry/events', name: 'api_telemetry_events', methods: ['POST'])]
    #[OA\Post(
        summary: 'Store a batch of UX events',
        description: 'Accepts 1 to 100 events of one frontend session. Events are stored as-is; aggregation happens later in SQL.',
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            ref: new Model(type: TelemetryBatchRequest::class),
            example: [
                'app' => 'skate',
                'sessionId' => '6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f',
                'events' => [
                    ['type' => 'screen_view', 'screen' => 'home', 'occurredAt' => '2026-09-07T09:58:12.345Z'],
                    ['type' => 'interaction', 'screen' => 'session-new', 'target' => 'save-button', 'meta' => ['trick' => 'kickflip'], 'occurredAt' => '2026-09-07T09:59:01.000Z'],
                ],
            ],
        ),
    )]
    #[OA\Response(
        response: 202,
        description: 'Events stored',
        content: new OA\JsonContent(ref: new Model(type: TelemetryAcceptedResponse::class), example: ['accepted' => 2]),
    )]
    #[OA\Response(
        response: 401,
        description: 'Missing or invalid X-Api-Key header',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']),
    )]
    #[OA\Response(
        response: 404,
        description: 'Unknown route below /api',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'not_found']),
    )]
    #[OA\Response(
        response: 405,
        description: 'Wrong HTTP method for this route',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'method_not_allowed']),
    )]
    #[OA\Response(
        response: 422,
        description: 'Payload rejected by validation',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [['field' => 'events[0].type', 'message' => 'Unbekannter Event-Typ. Erlaubt sind: screen_view, time_on_screen, interaction, navigation.']],
        ]),
    )]
    public function __invoke(#[MapRequestPayload] TelemetryBatchRequest $batch): JsonResponse
    {
        $accepted = $this->ingestService->ingest($batch);

        return new JsonResponse(new TelemetryAcceptedResponse($accepted), Response::HTTP_ACCEPTED);
    }
}
