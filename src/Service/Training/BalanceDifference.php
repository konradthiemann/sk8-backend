<?php

declare(strict_types=1);

namespace App\Service\Training;

use App\Enum\BodySide;

/**
 * Pure derivation of the single-leg balance asymmetry (T-0303 design.md
 * §4.1). Descriptive only - it names the shorter side, it does not judge
 * the size of the gap. Stateless and kernel-free, same idea as
 * App\Service\Skate\SessionMetrics.
 */
final readonly class BalanceDifference
{
    private function __construct(
        public ?int $seconds,
        public ?BodySide $weakerSide,
    ) {
    }

    public static function between(?int $leftSeconds, ?int $rightSeconds): self
    {
        if (null === $leftSeconds || null === $rightSeconds) {
            return new self(null, null);
        }

        $weakerSide = match (true) {
            $leftSeconds < $rightSeconds => BodySide::Links,
            $rightSeconds < $leftSeconds => BodySide::Rechts,
            default => null,
        };

        return new self(abs($leftSeconds - $rightSeconds), $weakerSide);
    }
}
