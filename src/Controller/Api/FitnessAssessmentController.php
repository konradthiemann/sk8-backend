<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Training\FitnessAssessmentListQuery;
use App\Dto\Training\FitnessAssessmentListResponse;
use App\Dto\Training\FitnessAssessmentRequest;
use App\Dto\Training\FitnessAssessmentView;
use App\Entity\FitnessAssessment;
use App\Repository\FitnessAssessmentRepository;
use App\Service\Training\FitnessAssessmentService;
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
 * Create, list and delete of fitness assessments (T-0303). Every action
 * stays thin: request/query DTO in, App\Service\Training\FitnessAssessmentService
 * or App\Repository\FitnessAssessmentRepository does the work, response DTO
 * out. No PUT/PATCH and no GET by ID by design (design.md §3).
 */
#[OA\Tag(name: 'FitnessAssessment')]
final class FitnessAssessmentController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';
    private const string VALIDATION_ERROR_RESPONSE = '#/components/schemas/ValidationErrorResponse';
    private const string ENDPOINT = '/api/fitness-assessments';

    public function __construct(
        private readonly FitnessAssessmentRepository $repository,
        private readonly FitnessAssessmentService $service,
    ) {
    }

    #[Route(self::ENDPOINT, name: 'api_fitness_assessments_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Record a fitness assessment',
        description: 'Records one test with up to eight optional measurements; at least one measurement is required. At most one test per date - a second one for the same date is answered with 409.',
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            ref: new Model(type: FitnessAssessmentRequest::class),
            example: [
                'assessedOn' => '2026-09-08',
                'pushUpsMax' => 24,
                'squatsMax' => null,
                'ringPullUpsMax' => 5,
                'plankSeconds' => 95,
                'singleLegBalanceLeftSeconds' => 28,
                'singleLegBalanceRightSeconds' => 51,
                'wallSitSeconds' => 70,
                'standingBroadJumpCm' => 185,
                'notes' => 'Links deutlich wackliger, Bandage getragen, Kniebeugen wegen Knie ausgelassen',
            ],
        ),
    )]
    #[OA\Response(
        response: 201,
        description: 'Assessment recorded',
        content: new OA\JsonContent(
            ref: new Model(type: FitnessAssessmentView::class),
            example: [
                'id' => '0192f5c0-1d44-7a88-9b02-77ce1a4b0e93',
                'assessedOn' => '2026-09-08',
                'pushUpsMax' => 24,
                'squatsMax' => null,
                'ringPullUpsMax' => 5,
                'plankSeconds' => 95,
                'singleLegBalanceLeftSeconds' => 28,
                'singleLegBalanceRightSeconds' => 51,
                'wallSitSeconds' => 70,
                'standingBroadJumpCm' => 185,
                'notes' => 'Links deutlich wackliger, Bandage getragen, Kniebeugen wegen Knie ausgelassen',
                'balanceDifferenceSeconds' => 23,
                'weakerBalanceSide' => 'links',
            ],
        ),
        headers: [new OA\Header(header: 'Location', description: 'URL of the new assessment (only DELETE is offered there, no GET by ID)', schema: new OA\Schema(type: 'string'))],
    )]
    #[OA\Response(response: 400, description: 'Malformed JSON body', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'bad_request']))]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 409, description: 'An assessment for this date already exists', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'conflict']))]
    #[OA\Response(
        response: 422,
        description: 'Payload rejected by validation, including a missing or unusable date, a measurement outside its range and a request without any measurement',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [
                ['field' => 'values', 'message' => 'Trag mindestens einen Wert ein, sonst gibt es nichts zu vergleichen.'],
                ['field' => 'wallSitSeconds', 'message' => 'Die Wandsitz-Zeit muss zwischen 0 und 3600 Sekunden liegen.'],
            ],
        ]),
    )]
    public function create(#[MapRequestPayload] FitnessAssessmentRequest $request): JsonResponse
    {
        $assessment = $this->service->create($request);

        return new JsonResponse(
            FitnessAssessmentView::fromEntity($assessment),
            Response::HTTP_CREATED,
            ['Location' => \sprintf('%s/%s', self::ENDPOINT, $assessment->getId()->toRfc4122())],
        );
    }

    #[Route(self::ENDPOINT, name: 'api_fitness_assessments_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'List fitness assessments',
        description: 'Assessments newest first (assessedOn descending; the date is unique, so the order is total). Each entry carries the derived single-leg balance difference.',
    )]
    #[OA\Response(
        response: 200,
        description: 'A page of assessments',
        content: new OA\JsonContent(
            ref: new Model(type: FitnessAssessmentListResponse::class),
            example: [
                'items' => [
                    [
                        'id' => '0192f5c0-1d44-7a88-9b02-77ce1a4b0e93',
                        'assessedOn' => '2026-09-08',
                        'pushUpsMax' => 24,
                        'squatsMax' => null,
                        'ringPullUpsMax' => 5,
                        'plankSeconds' => 95,
                        'singleLegBalanceLeftSeconds' => 28,
                        'singleLegBalanceRightSeconds' => 51,
                        'wallSitSeconds' => 70,
                        'standingBroadJumpCm' => 185,
                        'notes' => 'Links deutlich wackliger, Bandage getragen',
                        'balanceDifferenceSeconds' => 23,
                        'weakerBalanceSide' => 'links',
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
        description: 'limit outside 1-100 or a negative offset',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [['field' => 'limit', 'message' => 'Das Limit muss zwischen 1 und 100 liegen.']],
        ]),
    )]
    public function list(
        // MapQueryString defaults validationFailedStatusCode to 404, not 422
        // (same documented pitfall as App\Controller\Api\TrainingSessionController::list()
        // - wrong here, criterion 11 requires 422 for an invalid limit).
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ?FitnessAssessmentListQuery $query,
    ): JsonResponse {
        $query ??= new FitnessAssessmentListQuery();

        $assessments = $this->repository->findPage($query->limit, $query->offset);
        $total = $this->repository->countAll();

        $items = array_map(FitnessAssessmentView::fromEntity(...), $assessments);

        return new JsonResponse(new FitnessAssessmentListResponse($items, $total, $query->limit, $query->offset));
    }

    #[Route(self::ENDPOINT.'/{id}', name: 'api_fitness_assessments_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[OA\Delete(summary: 'Delete a fitness assessment', description: 'Deletes the assessment; a new one may then be recorded for the same date.')]
    #[OA\Response(response: 204, description: 'Assessment deleted')]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 404, description: 'Unknown or formally invalid ID', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'not_found']))]
    public function delete(#[MapEntity(id: 'id')] FitnessAssessment $assessment): JsonResponse
    {
        $this->service->delete($assessment);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
