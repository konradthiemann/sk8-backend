<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/tricks/{slug} (T-0202 design.md §3): everything
 * the trick tree (GET /api/trick-tree) does not already show - description,
 * the full session history and the direct prerequisites'/unlocks' own
 * status.
 */
final readonly class TrickDetailResponse
{
    /**
     * @param list<TrickRefView>      $requires
     * @param list<TrickRefView>      $unlocks
     * @param list<TrickHistoryEntry> $history
     */
    public function __construct(
        #[OA\Property(description: 'Public trick key, not the UUID', example: 'pop-shove-it')]
        public string $slug,
        #[OA\Property(description: 'Display name (German)', example: 'Pop Shove-it')]
        public string $name,
        #[OA\Property(description: 'Movement category', example: 'rotation')]
        public string $category,
        #[OA\Property(description: 'Position in the tree, 1 to 10', example: 3)]
        public int $difficulty,
        #[OA\Property(description: 'Whether this trick is one of the seven contest goals', example: true)]
        public bool $isGoal,
        #[OA\Property(description: 'Position among the seven goals, 1 to 7', example: 2, nullable: true)]
        public ?int $goalOrder,
        #[OA\Property(description: 'Optional description', example: 'Board dreht 180 Grad unter dir, Fuesse bleiben ueber dem Board.', nullable: true)]
        public ?string $description,
        #[OA\Property(description: 'Derived progress numbers', ref: new Model(type: TrickDetailProgress::class))]
        public TrickDetailProgress $progress,
        /**
         * @var list<TrickRefView>
         */
        #[OA\Property(description: 'Direct prerequisites with their own status, sorted by difficulty then slug', type: 'array', items: new OA\Items(ref: new Model(type: TrickRefView::class)))]
        public array $requires,
        /**
         * @var list<TrickRefView>
         */
        #[OA\Property(description: 'Tricks this one directly unlocks, with their own status, sorted by difficulty then slug', type: 'array', items: new OA\Items(ref: new Model(type: TrickRefView::class)))]
        public array $unlocks,
        /**
         * @var list<TrickHistoryEntry>
         */
        #[OA\Property(description: 'Full session history, newest first, up to 100 entries', type: 'array', items: new OA\Items(ref: new Model(type: TrickHistoryEntry::class)))]
        public array $history,
        #[OA\Property(description: 'The mastery thresholds behind status/successRate', ref: new Model(type: TrickPolicyView::class))]
        public TrickPolicyView $policy,
    ) {
    }
}
