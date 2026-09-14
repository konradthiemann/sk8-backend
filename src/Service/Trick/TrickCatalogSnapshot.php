<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Entity\Trick;
use App\Entity\TrickProgress;
use App\Enum\TrickStatus;

/**
 * Pure value object bridging App\Service\Trick\TrickTreeService::currentSnapshot()
 * and the three endpoints that read it (T-0202 design.md §4): GET
 * /api/trick-tree, GET /api/tricks/{slug} and GET /api/trick-recommendation.
 * Not a Doctrine entity, not an API-facing DTO - same role as
 * TrickAggregateStats one layer up.
 */
final readonly class TrickCatalogSnapshot
{
    /**
     * @param list<Trick>                        $tricks       the full catalog, unsorted (TrickRepository::findAllOrdered() order)
     * @param array<string, TrickStatus>         $statuses     keyed by trick id
     * @param array<string, TrickAggregateStats> $stats        keyed by trick id
     * @param array<string, TrickProgress>       $progressRows keyed by trick id - the just-persisted trick_progress row for every trick
     */
    public function __construct(
        public array $tricks,
        public array $statuses,
        public array $stats,
        public array $progressRows,
    ) {
    }
}
