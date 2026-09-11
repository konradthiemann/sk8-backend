<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/trick-tree.
 */
final readonly class TrickTreeResponse
{
    /**
     * @param list<TrickTreeNode> $nodes
     * @param list<TrickTreeEdge> $edges
     */
    public function __construct(
        /**
         * @var list<TrickTreeNode>
         */
        #[OA\Property(description: 'All tricks, sorted by goalOrder ascending (null last), then difficulty, then slug', type: 'array', items: new OA\Items(ref: new Model(type: TrickTreeNode::class)))]
        public array $nodes,
        /**
         * @var list<TrickTreeEdge>
         */
        #[OA\Property(description: 'One edge per prerequisite, from the prerequisite to the dependent trick, sorted by from then to', type: 'array', items: new OA\Items(ref: new Model(type: TrickTreeEdge::class)))]
        public array $edges,
        #[OA\Property(description: 'The mastery thresholds behind status/successRate', ref: new Model(type: TrickPolicyView::class))]
        public TrickPolicyView $policy,
        #[OA\Property(description: 'Server time of this response, ISO 8601', example: '2026-09-08T17:41:02+00:00')]
        public string $generatedAt,
    ) {
    }
}
