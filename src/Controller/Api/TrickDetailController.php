<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Trick\TrickDetailResponse;
use App\Entity\Trick;
use App\Service\Trick\TrickDetailService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves everything about one trick that GET /api/trick-tree does not show:
 * description, full session history, and its direct prerequisites'/unlocks'
 * own status (T-0202). Reading this route also refreshes trick_progress from
 * the latest session data, same as GET /api/trick-tree - see
 * App\Service\Trick\TrickTreeService::currentSnapshot(), which this
 * controller's service reuses.
 *
 * `#[MapEntity(mapping: ['slug' => 'slug'])] Trick $trick` lets Symfony's
 * EntityValueResolver resolve the trick and throw NotFoundHttpException (404)
 * for an unknown slug automatically, same pattern as
 * App\Controller\Api\SkateSessionController::get() for `id`. An invalid slug
 * never reaches the controller at all: the route's own `requirements` regex
 * already rejects it with 404 before routing matches (criterion 5).
 */
#[OA\Tag(name: 'Trick')]
final class TrickDetailController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';

    public function __construct(
        private readonly TrickDetailService $trickDetailService,
    ) {
    }

    #[Route('/api/tricks/{slug}', name: 'api_trick_detail', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    #[OA\Get(
        summary: 'Get one trick in detail',
        description: 'Description, derived progress, direct prerequisites and unlocked tricks with their own status, and the full session history (newest first, up to 100 entries). Refreshes trick_progress from session_trick before responding, same as GET /api/trick-tree.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The trick detail',
        content: new OA\JsonContent(
            ref: new Model(type: TrickDetailResponse::class),
            example: [
                'slug' => 'pop-shove-it',
                'name' => 'Pop Shove-it',
                'category' => 'rotation',
                'difficulty' => 3,
                'isGoal' => true,
                'goalOrder' => 2,
                'description' => 'Board dreht 180 Grad unter dir, Fuesse bleiben ueber dem Board.',
                'progress' => [
                    'status' => 'uebe',
                    'attemptsTotal' => 96,
                    'landedTotal' => 21,
                    'successRate' => 0.219,
                    'recentSuccessRate' => 0.267,
                    'sessionCount' => 5,
                    'firstLandedOn' => '2026-08-11',
                    'lastPracticedOn' => '2026-09-06',
                    'updatedAt' => '2026-09-06T18:12:44+00:00',
                ],
                'requires' => [
                    ['slug' => 'ollie', 'name' => 'Ollie', 'status' => 'sitzt'],
                ],
                'unlocks' => [
                    ['slug' => 'pop-shove-it-to-manual', 'name' => 'Pop Shove-it in den Manual', 'status' => 'gesperrt'],
                ],
                'history' => [
                    [
                        'sessionId' => '0192f3a1-7c4e-7b21-9f0a-6d2c1b8e4a55',
                        'sessionDate' => '2026-09-06',
                        'attempts' => 24,
                        'landed' => 7,
                        'successRate' => 0.292,
                        'notes' => 'Rotation zu flach',
                    ],
                    [
                        'sessionId' => '0192e88c-2b10-7a49-8f31-40c9b7e2d611',
                        'sessionDate' => '2026-09-02',
                        'attempts' => 20,
                        'landed' => 4,
                        'successRate' => 0.2,
                        'notes' => null,
                    ],
                ],
                'policy' => [
                    'masteryRate' => 0.75,
                    'masterySessions' => 3,
                    'masteryMinAttempts' => 15,
                    'masteryMode' => 'each_session',
                ],
            ],
        ),
    )]
    #[OA\Response(
        response: 401,
        description: 'Missing or invalid X-Api-Key header',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'unauthorized']),
    )]
    #[OA\Response(
        response: 404,
        description: 'Unknown or formally invalid slug',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'not_found']),
    )]
    #[OA\Response(
        response: 405,
        description: 'Wrong HTTP method for this route',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'method_not_allowed']),
    )]
    public function __invoke(#[MapEntity(mapping: ['slug' => 'slug'])] Trick $trick): JsonResponse
    {
        return new JsonResponse($this->trickDetailService->detail($trick));
    }
}
