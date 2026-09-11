<?php

declare(strict_types=1);

namespace App\Service\Skate;

/**
 * Pure, stateless derivations for skate sessions (T-0102 design.md §1/§4):
 * success rate, fluid loss and the plain `numeric` string -> float
 * conversion happen exactly here, nowhere else. Response DTOs only ever
 * receive the already-converted float/int values from this class.
 *
 * Weight parameters are `string`, because Doctrine's DBAL `decimal`/`numeric`
 * type always hands PHP a string, never a float (avoids silent
 * floating-point rounding on money-like values).
 */
final class SessionMetrics
{
    /**
     * `landed / attempts`, rounded to three decimal places. `null` when
     * there were no attempts at all (a session without trick rows).
     */
    public static function successRate(int $attempts, int $landed): ?float
    {
        if (0 === $attempts) {
            return null;
        }

        return round($landed / $attempts, 3);
    }

    /**
     * `weightBeforeKg - weightAfterKg`, rounded to two decimal places.
     * `null` when either weight is missing. May be negative (criterion 9):
     * a session where the athlete gained weight is not rejected.
     */
    public static function fluidLossKg(?string $weightBeforeKg, ?string $weightAfterKg): ?float
    {
        if (null === $weightBeforeKg || null === $weightAfterKg) {
            return null;
        }

        return round((float) $weightBeforeKg - (float) $weightAfterKg, 2);
    }

    /**
     * A single stored weight as a float, for response DTOs that echo it back
     * as-is (rounding already happened once, on write - see
     * App\Service\Skate\SkateSessionService).
     */
    public static function weightKg(?string $weightKg): ?float
    {
        return null === $weightKg ? null : (float) $weightKg;
    }
}
