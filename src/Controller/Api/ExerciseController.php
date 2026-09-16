<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Training\ExerciseListResponse;
use App\Dto\Training\ExerciseView;
use App\Repository\ExerciseRepository;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the curated training exercise catalog (T-0301). Read-only: the
 * catalog is curated master data seeded and kept in sync by
 * App\Command\SyncExercisesCommand, there is deliberately no write path -
 * an exercise created through the app would have no evidenced knee load.
 */
#[OA\Tag(name: 'Exercise')]
final class ExerciseController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';

    public function __construct(
        private readonly ExerciseRepository $exerciseRepository,
    ) {
    }

    #[Route('/api/exercises', name: 'api_exercises_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'List the exercise catalog',
        description: 'Returns the full curated exercise catalog, prevention exercises first and then alphabetically by name.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The full exercise catalog',
        content: new OA\JsonContent(
            ref: new Model(type: ExerciseListResponse::class),
            example: [
                'items' => [
                    [
                        'slug' => 'single-leg-balance',
                        'name' => 'Einbeinstand',
                        'equipment' => 'bodyweight',
                        'muscleGroups' => ['rumpf', 'wade', 'huefte'],
                        'kneeLoad' => 'niedrig',
                        'measure' => 'seconds_per_side',
                        'description' => 'Auf einem Bein stehen, Knie leicht gebeugt, Blick geradeaus.',
                        'isPrevention' => true,
                    ],
                    [
                        'slug' => 'ring-row',
                        'name' => 'Ruderzug an den Ringen',
                        'equipment' => 'rings',
                        'muscleGroups' => ['ruecken', 'bizeps', 'rumpf'],
                        'kneeLoad' => 'keine',
                        'measure' => 'reps',
                        'description' => 'Koerper gestreckt, Schulterblaetter zuerst, Ringe zum Brustkorb ziehen.',
                        'isPrevention' => false,
                    ],
                ],
                'total' => 2,
            ],
        ),
    )]
    #[OA\Response(
        response: 401,
        description: 'Missing or invalid X-Api-Key header',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']),
    )]
    #[OA\Response(
        response: 405,
        description: 'Wrong HTTP method for this route',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'method_not_allowed']),
    )]
    public function __invoke(): JsonResponse
    {
        $items = array_map(
            ExerciseView::fromEntity(...),
            $this->exerciseRepository->findAllOrdered(),
        );

        return new JsonResponse(new ExerciseListResponse($items, \count($items)));
    }
}
