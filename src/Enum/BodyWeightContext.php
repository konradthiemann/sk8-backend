<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Context a body-weight measurement was taken in (DATENMODELL.md, "Körper").
 *
 * The one deliberate exception to "identifiers are English" (ADR-008, ticket
 * T-0103): case *names* stay English code identifiers, but the backing
 * *values* are the German strings DATENMODELL.md and the database fix them
 * to - `chk_body_weight_context` on the `body_weight` table checks against
 * exactly these four values.
 */
enum BodyWeightContext: string
{
    case Morning = 'morgens';
    case BeforeSession = 'vor_session';
    case AfterSession = 'nach_session';
    case Other = 'sonstiges';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
