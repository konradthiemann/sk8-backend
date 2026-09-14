<?php

declare(strict_types=1);

namespace App\Service\Trick;

/**
 * The two German pause-hint texts of GET /api/trick-recommendation, and the
 * rule that picks one (T-0202 design.md §3/§4). Symmetric to
 * App\Service\Trick\RecommendationReason for the same "text belongs with its
 * selection rule" reasoning (design.md §7, "Offene Frage 2").
 *
 * Knee pain wins over a consecutive-days streak when both thresholds are met
 * (ticket: "erste zutreffende Zeile gewinnt", knee pain listed first) -
 * PAUSE_KNEE_PAIN is checked before PAUSE_CONSECUTIVE_DAYS.
 */
final class PauseHint
{
    private const string KNEE_PAIN_TEXT = 'Dein Knie meldet sich stärker als sonst – heute lieber kürzer treten oder pausieren, bei anhaltenden Schmerzen ärztlich abklären lassen.';
    private const string CONSECUTIVE_DAYS_TEXT = 'Du bist schon mehrere Tage in Folge gefahren – ein Ruhetag hilft Knie und Beinen, sich zu erholen.';

    private function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public static function resolve(?int $lastKneePain, int $consecutiveSessionDays): ?self
    {
        if (null !== $lastKneePain && $lastKneePain >= TrickRecommendationPolicy::PAUSE_KNEE_PAIN) {
            return new self('knee_pain', self::KNEE_PAIN_TEXT);
        }

        if ($consecutiveSessionDays >= TrickRecommendationPolicy::PAUSE_CONSECUTIVE_DAYS) {
            return new self('consecutive_days', self::CONSECUTIVE_DAYS_TEXT);
        }

        return null;
    }
}
