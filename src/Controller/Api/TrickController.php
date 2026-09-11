<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Trick\TrickListResponse;
use App\Dto\Trick\TrickResponse;
use App\Repository\TrickRepository;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the fixed trick catalog (T-0101). Read-only: the catalog is seeded
 * by a data migration, there is no write path in this epic.
 */
#[OA\Tag(name: 'Trick')]
final class TrickController
{
    private const string ERROR_RESPONSE = '#/components/schemas/ErrorResponse';

    public function __construct(
        private readonly TrickRepository $trickRepository,
    ) {
    }

    #[Route('/api/tricks', name: 'api_tricks_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'List the trick catalog',
        description: 'Returns all 16 tricks with their direct prerequisites, sorted by difficulty ascending and, on a tie, by name ascending.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The full trick catalog',
        content: new OA\JsonContent(
            ref: new Model(type: TrickListResponse::class),
            example: [
                'items' => [
                    [
                        'id' => '01997c6a-3b21-7c4e-9a10-4f2b6d8e1c30',
                        'slug' => 'rolling',
                        'name' => 'Sicher rollen',
                        'category' => 'flat',
                        'difficulty' => 1,
                        'description' => 'Pushen, Richtung halten, bremsen und in der Fahrt stehen.',
                        'isGoal' => false,
                        'goalOrder' => null,
                        'prerequisiteSlugs' => [],
                    ],
                    [
                        'id' => '01997c6a-3b21-7c4e-9a10-4f2b6d8e1c35',
                        'slug' => 'ollie',
                        'name' => 'Ollie',
                        'category' => 'flat',
                        'difficulty' => 3,
                        'description' => 'Ollie in der Fahrt. Ausgangsniveau etwa 25 cm, Zielhöhe darüber.',
                        'isGoal' => true,
                        'goalOrder' => 1,
                        'prerequisiteSlugs' => ['ollie-stand'],
                    ],
                    [
                        'id' => '01997c6a-3b21-7c4e-9a10-4f2b6d8e1c39',
                        'slug' => 'ollie-to-manual',
                        'name' => 'Ollie in den Manual',
                        'category' => 'balance',
                        'difficulty' => 6,
                        'description' => 'Ollie auf das Manual-Pad und direkt in den Manual abrollen.',
                        'isGoal' => true,
                        'goalOrder' => 4,
                        'prerequisiteSlugs' => ['manual', 'ollie'],
                    ],
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
        response: 405,
        description: 'Wrong HTTP method for this route',
        content: new OA\JsonContent(ref: self::ERROR_RESPONSE, example: ['error' => 'method_not_allowed']),
    )]
    public function __invoke(): JsonResponse
    {
        $items = array_map(
            TrickResponse::fromEntity(...),
            $this->trickRepository->findAllOrdered(),
        );

        return new JsonResponse(new TrickListResponse($items));
    }
}
