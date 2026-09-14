<?php

declare(strict_types=1);

namespace App\Dto\Trick;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Response body of GET /api/trick-recommendation (T-0202 design.md §3).
 */
final readonly class TrickRecommendationResponse
{
    /**
     * @param list<TrickSuggestion> $secondary
     */
    public function __construct(
        #[OA\Property(description: 'The single best next trick to focus on; null when no trick is currently practicing or ready', ref: new Model(type: TrickSuggestion::class), nullable: true)]
        public ?TrickSuggestion $primary,
        /**
         * @var list<TrickSuggestion>
         */
        #[OA\Property(description: 'Further suggestions - primary plus secondary never exceed focusLimit', type: 'array', items: new OA\Items(ref: new Model(type: TrickSuggestion::class)))]
        public array $secondary,
        #[OA\Property(description: 'Maximum total number of suggestions (primary + secondary)', example: 2)]
        public int $focusLimit,
        #[OA\Property(description: 'Recovery hint for today; null when neither threshold is met', ref: new Model(type: PauseHintView::class), nullable: true)]
        public ?PauseHintView $pauseHint,
        #[OA\Property(description: 'Server time of this response, ISO 8601', example: '2026-09-08T17:41:02+00:00')]
        public string $generatedAt,
    ) {
    }
}
