<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Training\TrainingSessionListQuery;
use App\Dto\Training\TrainingSessionListResponse;
use App\Dto\Training\TrainingSessionRequest;
use App\Dto\Training\TrainingSessionSummary;
use App\Dto\Training\TrainingSessionView;
use App\Entity\TrainingSession;
use App\Repository\TrainingSessionRepository;
use App\Service\Training\TrainingSessionService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Create, list, read and delete of training sessions and their sets
 * (T-0302). Every action stays thin: request/query DTO in,
 * App\Service\Training\TrainingSessionService or
 * App\Repository\TrainingSessionRepository does the work, response DTO out.
 * No PUT/PATCH by design (design.md §3).
 */
#[OA\Tag(name: 'TrainingSession')]
final class TrainingSessionController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';
    private const string VALIDATION_ERROR_RESPONSE = '#/components/schemas/ValidationErrorResponse';
    private const string ENDPOINT = '/api/training-sessions';

    public function __construct(
        private readonly TrainingSessionRepository $trainingSessionRepository,
        private readonly TrainingSessionService $trainingSessionService,
    ) {
    }

    #[Route(self::ENDPOINT, name: 'api_training_sessions_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Create a training session',
        description: 'Creates a session together with all of its sets, in one transaction - either every row is written, or none is (design.md §1).',
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            ref: new Model(type: TrainingSessionRequest::class),
            example: [
                'sessionDate' => '2026-09-08',
                'durationMinutes' => 38,
                'perceivedExertion' => 7,
                'kneePain' => 2,
                'notes' => 'Ringe am Tuerrahmen, Knie ruhig',
                'sets' => [
                    ['exerciseSlug' => 'ring-row', 'setNumber' => 1, 'reps' => 10, 'seconds' => null, 'side' => null],
                    ['exerciseSlug' => 'ring-row', 'setNumber' => 2, 'reps' => 8, 'seconds' => null, 'side' => null],
                    ['exerciseSlug' => 'single-leg-balance', 'setNumber' => 1, 'reps' => null, 'seconds' => 45, 'side' => 'links'],
                    ['exerciseSlug' => 'single-leg-balance', 'setNumber' => 2, 'reps' => null, 'seconds' => 60, 'side' => 'rechts'],
                ],
            ],
        ),
    )]
    #[OA\Response(
        response: 201,
        description: 'Session created',
        content: new OA\JsonContent(ref: new Model(type: TrainingSessionView::class)),
        headers: [new OA\Header(header: 'Location', description: 'URL of the new session', schema: new OA\Schema(type: 'string'))],
    )]
    #[OA\Response(response: 400, description: 'Malformed JSON body', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'bad_request']))]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(
        response: 422,
        description: 'Payload rejected by validation, including a set whose exercise is unknown or whose measure/side does not match the exercise',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [
                ['field' => 'sets[0].seconds', 'message' => 'Diese Übung wird in Sekunden gemessen.'],
                ['field' => 'sets[2].setNumber', 'message' => 'Diese Satznummer gibt es für die Übung schon.'],
            ],
        ]),
    )]
    public function create(#[MapRequestPayload] TrainingSessionRequest $request): JsonResponse
    {
        $session = $this->trainingSessionService->create($request);

        return new JsonResponse(
            TrainingSessionView::fromEntity($session),
            Response::HTTP_CREATED,
            ['Location' => \sprintf('%s/%s', self::ENDPOINT, $session->getId()->toRfc4122())],
        );
    }

    #[Route(self::ENDPOINT, name: 'api_training_sessions_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'List training sessions',
        description: 'Sessions newest first (sessionDate descending, then id descending - UUID v7 is time-ordered).',
    )]
    #[OA\Response(
        response: 200,
        description: 'A page of sessions',
        content: new OA\JsonContent(
            ref: new Model(type: TrainingSessionListResponse::class),
            example: [
                'items' => [
                    [
                        'id' => '0192f4b8-9a31-7c02-8e14-5b7d3f9a1c40',
                        'sessionDate' => '2026-09-08',
                        'durationMinutes' => 38,
                        'perceivedExertion' => 7,
                        'kneePain' => 2,
                        'notes' => 'Ringe am Tuerrahmen, Knie ruhig',
                        'setCount' => 4,
                        'exerciseCount' => 2,
                        'maxKneeLoad' => 'niedrig',
                        'createdAt' => '2026-09-08T19:04:11+00:00',
                    ],
                ],
                'total' => 1,
                'limit' => 30,
                'offset' => 0,
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(
        response: 422,
        description: 'limit outside 1-100',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [['field' => 'limit', 'message' => 'Das Limit muss zwischen 1 und 100 liegen.']],
        ]),
    )]
    public function list(
        // MapQueryString defaults validationFailedStatusCode to 404, not 422
        // (same documented pitfall as App\Controller\Api\SkateSessionController::list()
        // - wrong here, criterion 14 requires 422 for an invalid limit).
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ?TrainingSessionListQuery $query,
    ): JsonResponse {
        $query ??= new TrainingSessionListQuery();

        $sessions = $this->trainingSessionRepository->findPage($query->limit, $query->offset);
        $total = $this->trainingSessionRepository->countAll();

        $items = array_map(TrainingSessionSummary::fromEntity(...), $sessions);

        return new JsonResponse(new TrainingSessionListResponse($items, $total, $query->limit, $query->offset));
    }

    #[Route(self::ENDPOINT.'/{id}', name: 'api_training_sessions_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[OA\Get(summary: 'Read one training session', description: 'Returns the session together with all of its sets, sorted by exercise name, then set number, then side.')]
    #[OA\Response(response: 200, description: 'The session', content: new OA\JsonContent(ref: new Model(type: TrainingSessionView::class)))]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 404, description: 'Unknown or formally invalid ID', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'not_found']))]
    public function get(string $id): JsonResponse
    {
        // No #[MapEntity]: the standard EntityValueResolver would load the
        // session via find() and lazy-load sets/exercises on first access -
        // N+1, and without the exercise rows fetch-joined that maxKneeLoad
        // needs. TrainingSessionRepository::findOneWithSets() fetch-joins
        // everything in one query instead (design.md §3).
        $session = $this->trainingSessionRepository->findOneWithSets(Uuid::fromString($id));
        if (null === $session) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse(TrainingSessionView::fromEntity($session));
    }

    #[Route(self::ENDPOINT.'/{id}', name: 'api_training_sessions_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[OA\Delete(summary: 'Delete a training session', description: 'Deletes the session and all of its sets (ON DELETE CASCADE).')]
    #[OA\Response(response: 204, description: 'Session deleted')]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 404, description: 'Unknown ID', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'not_found']))]
    public function delete(#[MapEntity(id: 'id')] TrainingSession $trainingSession): JsonResponse
    {
        $this->trainingSessionService->delete($trainingSession);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
