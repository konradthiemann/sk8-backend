<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Skate\SkateSessionListQuery;
use App\Dto\Skate\SkateSessionListResponse;
use App\Dto\Skate\SkateSessionRequest;
use App\Dto\Skate\SkateSessionResponse;
use App\Dto\Skate\SkateSessionSummary;
use App\Entity\SkateSession;
use App\Repository\SkateSessionRepository;
use App\Service\Skate\SkateSessionService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Full CRUD for skate sessions and their practiced tricks (T-0102). Every
 * action stays thin: request/query DTO in, App\Service\Skate\SkateSessionService
 * or App\Repository\SkateSessionRepository does the work, response DTO out.
 */
#[OA\Tag(name: 'SkateSession')]
final class SkateSessionController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';
    private const string VALIDATION_ERROR_RESPONSE = '#/components/schemas/ValidationErrorResponse';
    private const string ENDPOINT = '/api/skate-sessions';

    public function __construct(
        private readonly SkateSessionRepository $skateSessionRepository,
        private readonly SkateSessionService $skateSessionService,
    ) {
    }

    #[Route(self::ENDPOINT, name: 'api_skate_sessions_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Create a skate session',
        description: 'Creates a session together with its practiced-trick rows.',
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            ref: new Model(type: SkateSessionRequest::class),
            example: [
                'sessionDate' => '2026-09-06',
                'startedAt' => '2026-09-06T16:30:00+02:00',
                'durationMinutes' => 95,
                'location' => 'Skatepark Braunschweig',
                'weightBeforeKg' => 78.4,
                'weightAfterKg' => 77.1,
                'perceivedExertion' => 7,
                'kneePain' => 3,
                'notes' => 'Manuals liefen gut, Knie ab 60 Minuten spuerbar.',
                'tricks' => [
                    ['trickSlug' => 'ollie', 'attempts' => 30, 'landed' => 21, 'notes' => null],
                    ['trickSlug' => 'manual', 'attempts' => 24, 'landed' => 9, 'notes' => 'Zu frueh aufgesetzt.'],
                ],
            ],
        ),
    )]
    #[OA\Response(
        response: 201,
        description: 'Session created',
        content: new OA\JsonContent(ref: new Model(type: SkateSessionResponse::class)),
        headers: [new OA\Header(header: 'Location', description: 'URL of the new session', schema: new OA\Schema(type: 'string'))],
    )]
    #[OA\Response(response: 400, description: 'Malformed JSON body', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'bad_request']))]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(
        response: 422,
        description: 'Payload rejected by validation',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [
                ['field' => 'sessionDate', 'message' => 'Das Datum darf nicht in der Zukunft liegen.'],
                ['field' => 'tricks[0].landed', 'message' => 'Es koennen nicht mehr Treffer als Versuche sein.'],
            ],
        ]),
    )]
    public function create(#[MapRequestPayload] SkateSessionRequest $request): JsonResponse
    {
        $session = $this->skateSessionService->create($request);

        return self::jsonResponse(
            SkateSessionResponse::fromEntity($session),
            Response::HTTP_CREATED,
            ['Location' => \sprintf('%s/%s', self::ENDPOINT, $session->getId()->toRfc4122())],
        );
    }

    #[Route(self::ENDPOINT, name: 'api_skate_sessions_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'List skate sessions',
        description: 'Sessions newest first (sessionDate descending, then createdAt descending). Optionally filtered by date range.',
    )]
    #[OA\Response(
        response: 200,
        description: 'A page of sessions',
        content: new OA\JsonContent(
            ref: new Model(type: SkateSessionListResponse::class),
            example: [
                'items' => [
                    [
                        'id' => '01997d11-4c02-7a3e-8b55-2d9f10e4a7c1',
                        'sessionDate' => '2026-09-06',
                        'durationMinutes' => 95,
                        'location' => 'Skatepark Braunschweig',
                        'trickCount' => 2,
                        'totalAttempts' => 54,
                        'totalLanded' => 30,
                        'successRate' => 0.556,
                        'fluidLossKg' => 1.3,
                        'perceivedExertion' => 7,
                        'kneePain' => 3,
                    ],
                ],
                'total' => 1,
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(
        response: 422,
        description: 'limit outside 1-200, or from after to',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [['field' => 'from', 'message' => 'Das Start-Datum darf nicht nach dem End-Datum liegen.']],
        ]),
    )]
    public function list(
        // MapQueryString defaults validationFailedStatusCode to 404, not 422
        // (Symfony's own default, presumably to hide a filter from probing -
        // wrong here: criteria 21/22 require 422 for an invalid limit/range).
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ?SkateSessionListQuery $query,
    ): JsonResponse {
        $query ??= new SkateSessionListQuery();
        $from = null !== $query->from ? new \DateTimeImmutable($query->from) : null;
        $to = null !== $query->to ? new \DateTimeImmutable($query->to) : null;

        $sessions = $this->skateSessionRepository->findFiltered($from, $to, $query->limit);
        $total = $this->skateSessionRepository->countFiltered($from, $to);

        $items = array_map(SkateSessionSummary::fromEntity(...), $sessions);

        return self::jsonResponse(new SkateSessionListResponse($items, $total));
    }

    #[Route(self::ENDPOINT.'/{id}', name: 'api_skate_sessions_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[OA\Get(summary: 'Read one skate session', description: 'Returns the session together with all of its practiced-trick rows.')]
    #[OA\Response(response: 200, description: 'The session', content: new OA\JsonContent(ref: new Model(type: SkateSessionResponse::class)))]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 404, description: 'Unknown or formally invalid ID', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'not_found']))]
    public function get(#[MapEntity(id: 'id')] SkateSession $skateSession): JsonResponse
    {
        return self::jsonResponse(SkateSessionResponse::fromEntity($skateSession));
    }

    #[Route(self::ENDPOINT.'/{id}', name: 'api_skate_sessions_update', requirements: ['id' => Requirement::UUID], methods: ['PUT'])]
    #[OA\Put(
        summary: 'Replace a skate session',
        description: 'Replaces the session fields and reconciles the trick rows: a slug that stays keeps its row id, a new slug becomes a new row, a slug no longer present is deleted.',
    )]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: SkateSessionRequest::class)))]
    #[OA\Response(response: 200, description: 'Session updated', content: new OA\JsonContent(ref: new Model(type: SkateSessionResponse::class)))]
    #[OA\Response(response: 400, description: 'Malformed JSON body', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'bad_request']))]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 404, description: 'Unknown ID', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'not_found']))]
    #[OA\Response(response: 422, description: 'Payload rejected by validation', content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE))]
    public function update(#[MapEntity(id: 'id')] SkateSession $skateSession, #[MapRequestPayload] SkateSessionRequest $request): JsonResponse
    {
        $session = $this->skateSessionService->update($skateSession, $request);

        return self::jsonResponse(SkateSessionResponse::fromEntity($session));
    }

    #[Route(self::ENDPOINT.'/{id}', name: 'api_skate_sessions_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[OA\Delete(summary: 'Delete a skate session', description: 'Deletes the session and all of its practiced-trick rows (ON DELETE CASCADE).')]
    #[OA\Response(response: 204, description: 'Session deleted')]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 404, description: 'Unknown ID', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'not_found']))]
    public function delete(#[MapEntity(id: 'id')] SkateSession $skateSession): JsonResponse
    {
        $this->skateSessionService->delete($skateSession);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * JsonResponse's default json_encode() flags do not set
     * JSON_PRESERVE_ZERO_FRACTION: a derived float that happens to land on a
     * whole number (successRate 0.0 or 1.0, fluidLossKg 0.0, a whole-kg
     * weight) serializes as a JSON integer and round-trips as a PHP int on
     * the client, silently losing its float-ness. Every response here
     * carries such derived values, so every response goes through this
     * helper instead of calling `new JsonResponse()` directly.
     *
     * Order matters: JsonResponse::setEncodingOptions() re-encodes by
     * json_decode()-ing the string that the constructor already produced
     * with the *old* options - by then the zero fraction is already gone
     * and no later flag can bring it back. So the options are applied to an
     * empty payload first, and only then is the real data encoded.
     *
     * @param array<string, string> $headers
     */
    private static function jsonResponse(mixed $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        $response = new JsonResponse(null, $status, $headers);
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_PRESERVE_ZERO_FRACTION);
        $response->setData($data);

        return $response;
    }
}
