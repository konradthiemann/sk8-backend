<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\Training\FitnessAssessmentRequest;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Checks with `null !==`, never with a truthiness test: a measurement of 0
 * (e.g. 0 seconds of single-leg balance on the injured side) is a real
 * value. No dependencies, so it is testable without a kernel.
 */
final class AtLeastOneMeasurementValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AtLeastOneMeasurement) {
            throw new UnexpectedTypeException($constraint, AtLeastOneMeasurement::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof FitnessAssessmentRequest) {
            throw new UnexpectedValueException($value, FitnessAssessmentRequest::class);
        }

        $measurements = [
            $value->pushUpsMax,
            $value->squatsMax,
            $value->ringPullUpsMax,
            $value->plankSeconds,
            $value->singleLegBalanceLeftSeconds,
            $value->singleLegBalanceRightSeconds,
            $value->wallSitSeconds,
            $value->standingBroadJumpCm,
        ];

        foreach ($measurements as $measurement) {
            if (null !== $measurement) {
                return;
            }
        }

        $this->context->buildViolation($constraint->message)
            ->atPath('values')
            ->addViolation();
    }
}
