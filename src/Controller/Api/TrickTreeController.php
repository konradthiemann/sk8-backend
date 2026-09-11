<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Trick\TrickTreeResponse;
use App\Service\Trick\TrickTreeService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the full trick tree: every trick's derived progress status plus its
 * prerequisite edges, in one call (T-0201). Reading this route also
 * refreshes trick_progress from the latest session data - see
 * App\Service\Trick\TrickProgressRefresher's doc comment for why a GET that
 * writes was chosen over a listener/hook into EPIC-01.
 */
#[OA\Tag(name: 'Trick')]
final class TrickTreeController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';

    public function __construct(
        private readonly TrickTreeService $trickTreeService,
    ) {
    }

    #[Route('/api/trick-tree', name: 'api_trick_tree', methods: ['GET'])]
    #[OA\Get(
        summary: 'Get the full trick tree',
        description: 'Every trick with its derived status, session-derived numbers and prerequisite edges, plus the mastery policy thresholds. Refreshes trick_progress from session_trick before responding.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The full trick tree',
        content: new OA\JsonContent(
            ref: new Model(type: TrickTreeResponse::class),
            example: [
                'nodes' => [
                    [
                        'slug' => 'ollie',
                        'name' => 'Ollie',
                        'category' => 'flat',
                        'difficulty' => 2,
                        'isGoal' => true,
                        'goalOrder' => 1,
                        'status' => 'sitzt',
                        'attemptsTotal' => 412,
                        'landedTotal' => 318,
                        'successRate' => 0.772,
                        'recentSuccessRate' => 0.81,
                        'sessionCount' => 14,
                        'firstLandedOn' => '2026-04-19',
                        'lastPracticedOn' => '2026-09-06',
                    ],
                    [
                        'slug' => 'boardslide',
                        'name' => 'Boardslide',
                        'category' => 'slide',
                        'difficulty' => 6,
                        'isGoal' => true,
                        'goalOrder' => 6,
                        'status' => 'gesperrt',
                        'attemptsTotal' => 0,
                        'landedTotal' => 0,
                        'successRate' => null,
                        'recentSuccessRate' => null,
                        'sessionCount' => 0,
                        'firstLandedOn' => null,
                        'lastPracticedOn' => null,
                    ],
                ],
                'edges' => [
                    ['from' => 'ollie', 'to' => 'pop-shove-it'],
                    ['from' => 'ollie', 'to' => 'boardslide'],
                ],
                'policy' => [
                    'masteryRate' => 0.75,
                    'masterySessions' => 3,
                    'masteryMinAttempts' => 15,
                    'masteryMode' => 'each_session',
                ],
                'generatedAt' => '2026-09-08T17:41:02+00:00',
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
        return new JsonResponse($this->trickTreeService->build());
    }
}
