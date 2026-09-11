<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A date or instant may not lie in the future, compared against "now" in the
 * app timezone (`%app.timezone%`, default Europe/Berlin) - not the server's
 * UTC clock, so a session logged at 00:30 local time is not "tomorrow"
 * (T-0102 design.md §4.1/§4.3, criterion 16).
 *
 * `mode: 'date'` compares day precision only (e.g. `sessionDate`, a plain
 * `Y-m-d` string). `mode: 'instant'` compares full timestamps (e.g.
 * `startedAt`, an ISO 8601 string).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class NotInFuture extends Constraint
{
    public string $message = 'This value must not lie in the future.';

    public function __construct(
        public readonly string $mode = 'date',
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(options: null, groups: $groups, payload: $payload);

        if (null !== $message) {
            $this->message = $message;
        }
    }
}
