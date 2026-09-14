<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Trick\TrickRecommendationResponse;
use App\Service\Trick\TrickRecommender;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Answers "which trick next, how much, and should today be a rest day" as a
 * deterministic, explainable-in-milliseconds rule instead of a model call
 * (T-0202, ADR-010). Reading this route also refreshes trick_progress from
 * the latest session data, same as GET /api/trick-tree - see
 * App\Service\Trick\TrickTreeService::currentSnapshot(), which this
 * controller's service reuses.
 */
#[OA\Tag(name: 'Trick')]
final class TrickRecommendationController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';

    public function __construct(
        private readonly TrickRecommender $trickRecommender,
    ) {
    }

    #[Route('/api/trick-recommendation', name: 'api_trick_recommendation', methods: ['GET'])]
    #[OA\Get(
        summary: 'Get the next trick(s) to focus on',
        description: 'Up to focusLimit suggestions ranked by status, goalOrder, difficulty and slug, each with a German reasonCode and a recommended attempts/minutes dosage, plus an optional recovery hint for today.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The current recommendation',
        content: new OA\JsonContent(
            ref: new Model(type: TrickRecommendationResponse::class),
            example: [
                'primary' => [
                    'slug' => 'pop-shove-it',
                    'name' => 'Pop Shove-it',
                    'status' => 'uebe',
                    'reasonCode' => 'almost_landed',
                    'reason' => 'Du landest diesen Trick schon öfter, aber die Erfolgsquote ist noch nicht über mehrere Einheiten stabil – bleib dran.',
                    'dosage' => ['attemptsMin' => 15, 'attemptsMax' => 30, 'minutesMin' => 10, 'minutesMax' => 20],
                ],
                'secondary' => [
                    [
                        'slug' => 'manual',
                        'name' => 'Manual',
                        'status' => 'uebe',
                        'reasonCode' => 'consolidate',
                        'reason' => 'Diesen Trick beherrschst du schon – übe ihn ab und zu weiter, damit er sitzen bleibt.',
                        'dosage' => ['attemptsMin' => 15, 'attemptsMax' => 30, 'minutesMin' => 10, 'minutesMax' => 20],
                    ],
                ],
                'focusLimit' => 2,
                'pauseHint' => [
                    'code' => 'knee_pain',
                    'message' => 'Dein Knie meldet sich stärker als sonst – heute lieber kürzer treten oder pausieren, bei anhaltenden Schmerzen ärztlich abklären lassen.',
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
    #[OA\Response(
        response: 500,
        description: 'Internal error',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'internal_error']),
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->trickRecommender->recommend());
    }
}
