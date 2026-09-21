<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Habit\HabitListQuery;
use App\Dto\Habit\HabitListResponse;
use App\Dto\Habit\HabitResponse;
use App\Repository\HabitRepository;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the curated habit catalog (T-0401). Read-only: rows are created and
 * kept in sync by App\Command\HabitsSyncCommand, there is deliberately no
 * write path and no GET by ID.
 */
#[OA\Tag(name: 'Habit')]
final class HabitCatalogController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';
    private const string VALIDATION_ERROR_RESPONSE = '#/components/schemas/ValidationErrorResponse';

    public function __construct(
        private readonly HabitRepository $repository,
    ) {
    }

    #[Route('/api/habits', name: 'api_habits_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'List the habit catalog',
        description: 'Returns the habit catalog ascending by sortOrder (ties: name, then slug). Only active habits unless includeInactive is true. targetValue is a JSON number (8.00 is written as 8).',
    )]
    #[OA\Response(
        response: 200,
        description: 'The habit catalog',
        content: new OA\JsonContent(
            ref: new Model(type: HabitListResponse::class),
            example: [
                'habits' => [
                    [
                        'id' => '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c1e',
                        'slug' => 'knee-pain',
                        'name' => 'Knieschmerz',
                        'valueType' => 'scale',
                        'unit' => null,
                        'scaleMin' => 0,
                        'scaleMax' => 10,
                        'targetDirection' => 'niedrig',
                        'targetValue' => null,
                        'sortOrder' => 10,
                        'isActive' => true,
                    ],
                    [
                        'id' => '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d',
                        'slug' => 'sleep-duration',
                        'name' => 'Schlafdauer',
                        'valueType' => 'duration',
                        'unit' => 'h',
                        'scaleMin' => null,
                        'scaleMax' => null,
                        'targetDirection' => 'hoch',
                        'targetValue' => 8,
                        'sortOrder' => 20,
                        'isActive' => true,
                    ],
                ],
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 405, description: 'Wrong HTTP method for this route', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'method_not_allowed']))]
    #[OA\Response(
        response: 422,
        description: 'includeInactive is not a boolean',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [['field' => 'includeInactive', 'message' => 'Dieser Wert sollte vom Typ bool sein.']],
        ]),
    )]
    public function __invoke(
        // MapQueryString defaults validationFailedStatusCode to 404, not 422
        // (same documented pitfall as App\Controller\Api\FitnessAssessmentController::list()).
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ?HabitListQuery $query,
    ): JsonResponse {
        $query ??= new HabitListQuery();

        $habits = array_map(
            HabitResponse::fromEntity(...),
            $this->repository->findCatalog($query->includeInactive),
        );

        return new JsonResponse(new HabitListResponse($habits));
    }
}
