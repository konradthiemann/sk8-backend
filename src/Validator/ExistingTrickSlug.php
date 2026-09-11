<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The value must be the slug of a trick that exists in the catalog (T-0101).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class ExistingTrickSlug extends Constraint
{
    public string $message = 'Unknown trick slug.';

    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(options: null, groups: $groups, payload: $payload);

        if (null !== $message) {
            $this->message = $message;
        }
    }
}
