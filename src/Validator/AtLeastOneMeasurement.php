<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint on App\Dto\Training\FitnessAssessmentRequest: at
 * least one of the eight measurements must be set, otherwise there is
 * nothing to compare later (T-0303 design.md §3.1). `0` counts as set;
 * `assessedOn` and `notes` are never measurements. The violation is
 * reported on the virtual path `values`, since no single input field is at
 * fault.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AtLeastOneMeasurement extends Constraint
{
    public string $message = 'fitness.values.none';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
