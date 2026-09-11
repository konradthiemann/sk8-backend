<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status of one trick's derived progress (DATENMODELL.md, "trick_progress",
 * T-0201).
 *
 * Orchestrator correction of the raw ticket text (design.md §4): the ticket
 * spells out German case names (`Gesperrt`, `Bereit`, `Uebe`, `Sitzt`), which
 * would violate ADR-008 ("Bezeichner englisch"). Treated as a transcription
 * slip rather than a binding instruction, same as App\Enum\BodyWeightContext
 * (T-0103): case *names* stay English code identifiers, backing *values*
 * stay the German strings the database and DATENMODELL.md fix them to -
 * `chk_trick_progress_status` checks against exactly these four values.
 */
enum TrickStatus: string
{
    case Locked = 'gesperrt';
    case Ready = 'bereit';
    case Practicing = 'uebe';
    case Mastered = 'sitzt';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
