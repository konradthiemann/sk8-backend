<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Habit\HabitDayQuery;
use App\Dto\Habit\HabitDayResponse;
use App\Service\Habit\HabitDayService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The day view of the habits app (T-0402): all active habits with their entry
 * of one day.
 */
#[OA\Tag(name: 'Habit')]
final class HabitDayController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';
    private const string VALIDATION_ERROR_RESPONSE = '#/components/schemas/ValidationErrorResponse';

    public function __construct(
        private readonly HabitDayService $service,
    ) {
    }

    #[Route('/api/habits/day', name: 'api_habits_day', methods: ['GET'])]
    #[OA\Get(
        summary: 'Read the habits of one day',
        description: 'Returns every active habit ascending by sortOrder (ties: name, then slug) with its entry of the day, or null when nothing is recorded. An entry with the value 0 or false counts as recorded. Without date, today (in the application time zone) is used.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The day view',
        content: new OA\JsonContent(
            ref: new Model(type: HabitDayResponse::class),
            example: [
                'date' => '2026-09-08',
                'totalCount' => 2,
                'completedCount' => 1,
                'items' => [
                    [
                        'habit' => [
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
                        'entry' => null,
                    ],
                    [
                        'habit' => [
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
                        'entry' => [
                            'id' => '0199a112-8b3d-7c4e-a1f2-3d4e5f6a7b8c',
                            'habitId' => '0199a0f1-4c7e-7a3b-9d21-5f6c7a8b9c0d',
                            'entryDate' => '2026-09-08',
                            'valueNumeric' => 7.5,
                            'valueBool' => null,
                            'note' => null,
                            'createdAt' => '2026-09-08T19:04:11+00:00',
                        ],
                    ],
                ],
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Missing or invalid X-Api-Key header', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']))]
    #[OA\Response(response: 405, description: 'Wrong HTTP method for this route', content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'method_not_allowed']))]
    #[OA\Response(
        response: 422,
        description: 'date is not a calendar date, lies in the future or before 2026-01-01',
        content: new OA\JsonContent(ref: self::VALIDATION_ERROR_RESPONSE, example: [
            'error' => 'validation_failed',
            'violations' => [['field' => 'date', 'message' => 'Du kannst keinen Wert für die Zukunft eintragen.']],
        ]),
    )]
    public function __invoke(
        // MapQueryString defaults validationFailedStatusCode to 404, not 422
        // (same documented pitfall as HabitCatalogController).
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        ?HabitDayQuery $query,
    ): JsonResponse {
        return new JsonResponse($this->service->forDate($query?->date));
    }
}
