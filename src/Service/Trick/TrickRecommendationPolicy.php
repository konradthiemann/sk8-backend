<?php

declare(strict_types=1);

namespace App\Service\Trick;

/**
 * The six R-02 constants driving GET /api/trick-recommendation (T-0202
 * design.md §1/§4). `final class` with only `public const` - same pattern as
 * App\Service\Trick\TrickProgressPolicy.
 *
 * Source: .claude/state/research/trick-progression.md, Abschnitt "Konsequenz
 * für das Produkt" (values confirmed via
 * `python3 .claude/specs/check-tickets.py --show T-0202`).
 */
final class TrickRecommendationPolicy
{
    /**
     * Maximum total number of suggestions (primary + secondary) one call
     * returns. Evidence grade D: no sport-science source behind the exact
     * number - a product decision to keep the suggestion short and
     * actionable rather than a long list to pick from.
     */
    public const int FOCUS_LIMIT = 2;

    /**
     * Minimum recommended attempts for one practice session on a suggested
     * trick.
     */
    public const int PRACTICE_ATTEMPTS_MIN = 15;

    /**
     * Maximum recommended attempts for one practice session on a suggested
     * trick.
     */
    public const int PRACTICE_ATTEMPTS_MAX = 30;

    /**
     * Minimum recommended minutes for one practice session on a suggested
     * trick.
     */
    public const int PRACTICE_MINUTES_MIN = 10;

    /**
     * Maximum recommended minutes for one practice session on a suggested
     * trick.
     */
    public const int PRACTICE_MINUTES_MAX = 20;

    /**
     * Number of consecutive calendar days with at least one session from
     * which a rest-day hint is shown, unless the more urgent knee-pain hint
     * already applies (PAUSE_KNEE_PAIN is checked first).
     */
    public const int PAUSE_CONSECUTIVE_DAYS = 2;

    /**
     * knee_pain value (0-10 scale) of the most recent session from which a
     * pause hint is shown. Evidence grade C, a transfer of tendon-pain
     * research onto the knee (design.md §8, "Risiken") - not a medical
     * diagnosis and does not replace consulting a doctor about persistent
     * pain; the pause text says so explicitly (design.md §3).
     */
    public const int PAUSE_KNEE_PAIN = 5;
}
