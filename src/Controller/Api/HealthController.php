<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Health\HealthResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness probe used by Railway and Docker healthchecks. Public, no database access.
 */
#[OA\Tag(name: 'Health')]
final class HealthController
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    #[OA\Get(
        summary: 'Health check',
        description: 'Returns 200 as long as the application can serve requests. No API key required.',
        security: [],
    )]
    #[OA\Response(
        response: 200,
        description: 'Application is up',
        content: new OA\JsonContent(
            ref: new Model(type: HealthResponse::class),
            example: ['status' => 'ok', 'time' => '2026-09-07T10:00:00+00:00'],
        ),
    )]
    public function __invoke(): JsonResponse
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));

        return new JsonResponse(new HealthResponse('ok', $now->format(\DateTimeInterface::ATOM)));
    }
}
