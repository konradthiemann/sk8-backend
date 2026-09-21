<?php

declare(strict_types=1);

namespace App\Service\Habit;

/**
 * One rejected field of a habit entry request, before translation.
 * App\Exception\FieldViolationsException turns a list of these into the shared
 * 422 body.
 */
final readonly class FieldViolation
{
    /**
     * @param string                    $field      property name in the request: `date`, `valueNumeric`, `valueBool`
     * @param string                    $messageKey full translation key in the `validators` domain, e.g. `habit_entry.value.exactly_one`
     * @param array<string, int|string> $parameters placeholder values without braces, e.g. ['min' => 0, 'max' => 10]
     */
    public function __construct(
        public string $field,
        public string $messageKey,
        public array $parameters = [],
    ) {
    }
}
