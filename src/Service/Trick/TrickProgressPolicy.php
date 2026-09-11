<?php

declare(strict_types=1);

namespace App\Service\Trick;

/**
 * The four R-02 constants that drive mastery detection (T-0201 design.md
 * §4). `final class` with only `public const` - no instances needed, same
 * pattern as App\Service\Skate\SessionMetrics.
 *
 * Source: .claude/state/research/trick-progression.md, Abschnitt "Konsequenz
 * für das Produkt" (values confirmed via
 * `python3 .claude/specs/check-tickets.py --show T-0201`).
 */
final class TrickProgressPolicy
{
    /**
     * Success rate from which a trick counts as mastered. Evidence grade D:
     * no trick-specific threshold found in the literature; 0.75 is a
     * deliberately conservative middle ground between the ~0.5 optimal-*
     * learning*-progress point and the 0.8-0.9 range mastery-learning
     * approaches use, because a too-early "sitzt" raises fall risk in a
     * not-yet-automatized movement given the knee pre-injury.
     */
    public const float MASTERY_RATE = 0.75;

    /**
     * Number of most-recent qualifying sessions considered. Evidence grade C:
     * follows the "consistency across consecutive sessions" criterion and the
     * overlearning literature (extra reps beyond the first success reduce
     * decay); 3 is the smallest number that represents "repeatedly", not just
     * "once".
     */
    public const int MASTERY_SESSIONS = 3;

    /**
     * Minimum attempts a session needs before it counts toward mastery at
     * all. Evidence grade D: statistical plausibility only (a success rate
     * from very few attempts has a very wide confidence interval), no
     * sport-science source.
     */
    public const int MASTERY_MIN_ATTEMPTS = 15;

    /**
     * `each_session` (every qualifying session must individually clear
     * MASTERY_RATE) or `pooled` (summed across the window). Evidence grade C:
     * consistent with the "consistency across sessions" criterion - prevents
     * one outstanding session from pooled-averaging out several weak ones.
     */
    public const string MASTERY_MODE = 'each_session';
}
