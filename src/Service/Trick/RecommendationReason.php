<?php

declare(strict_types=1);

namespace App\Service\Trick;

use App\Enum\TrickStatus;

/**
 * The four German reasonCode texts of GET /api/trick-recommendation, and the
 * rule that picks one for a given candidate (T-0202 design.md §3/§4). Holds
 * both on purpose (design.md §7, "Offene Frage 2"): the texts "gehören mit
 * der Regel zusammen, die sie auswählt" (ticket).
 *
 * Deliberate simplification of the ticket's literal reasonCode table
 * (design.md §3, "Abweichung"): the ticket's own `consolidate` row carries an
 * extra "< MASTERY_SESSIONS qualifizierend" clause that leaves a gap (three
 * qualifying, individually inconsistent, but pooled-strong sessions match
 * none of its four rows - see design.md §3 for the worked example, and
 * RecommendationReasonTest::testItReturnsConsolidateForThreeIndividuallyInconsistentButPooledStrongSessions()).
 * The rule implemented here is gap-free and agrees with the ticket table on
 * every case it actually covers:
 *
 *   bereit                                  -> ready_to_start
 *   uebe, recentSuccessRate = null          -> no_data
 *   uebe, recentSuccessRate >= MASTERY_RATE -> consolidate
 *   uebe, recentSuccessRate <  MASTERY_RATE -> almost_landed
 */
final class RecommendationReason
{
    private const string READY_TO_START_TEXT = 'Du hast alle Voraussetzungen für diesen Trick sicher drauf – Zeit für den nächsten Schritt.';
    private const string ALMOST_LANDED_TEXT = 'Du landest diesen Trick schon öfter, aber die Erfolgsquote ist noch nicht über mehrere Einheiten stabil – bleib dran.';
    private const string CONSOLIDATE_TEXT = 'Diesen Trick beherrschst du schon – übe ihn ab und zu weiter, damit er sitzen bleibt.';
    private const string NO_DATA_TEXT = 'Zu diesem Trick fehlen noch Übungsdaten – probier ihn in der nächsten Einheit ein paar Mal, damit wir ihn einschätzen können.';

    private function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public static function forCandidate(TrickStatus $status, ?float $recentSuccessRate): self
    {
        if (TrickStatus::Ready === $status) {
            return new self('ready_to_start', self::READY_TO_START_TEXT);
        }

        if (null === $recentSuccessRate) {
            return new self('no_data', self::NO_DATA_TEXT);
        }

        if ($recentSuccessRate >= TrickProgressPolicy::MASTERY_RATE) {
            return new self('consolidate', self::CONSOLIDATE_TEXT);
        }

        return new self('almost_landed', self::ALMOST_LANDED_TEXT);
    }
}
