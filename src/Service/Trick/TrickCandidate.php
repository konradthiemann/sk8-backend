<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Enum\TrickStatus;

/**
 * Pure value object for one trick in the recommendation ranking (T-0202
 * design.md §4). Deliberately carries no Doctrine reference, so
 * TrickRecommender::selectSuggestions() is testable without a kernel
 * (tests/Unit/Service/Trick/TrickRecommenderTest.php).
 */
final readonly class TrickCandidate
{
    public function __construct(
        public string $slug,
        public string $name,
        public TrickStatus $status,
        public ?int $goalOrder,
        public int $difficulty,
        public ?float $recentSuccessRate,
    ) {
    }
}
