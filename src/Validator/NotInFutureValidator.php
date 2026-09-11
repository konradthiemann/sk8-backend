<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * First consumer of Symfony\Component\Clock\ClockInterface in this repo
 * (design.md §4.1): injecting the clock instead of calling `new
 * \DateTimeImmutable('now')` directly makes "now" swappable for tests via
 * `Symfony\Component\Clock\MockClock`, without waiting for real midnight to
 * exercise the Europe/Berlin edge case (criterion 16).
 */
final class NotInFutureValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotInFuture) {
            throw new UnexpectedTypeException($constraint, NotInFuture::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $timezone = new \DateTimeZone($this->timezone);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($timezone);

        try {
            if ('date' === $constraint->mode) {
                $subject = new \DateTimeImmutable($value, $timezone);
                $isFuture = $subject->format('Y-m-d') > $now->format('Y-m-d');
            } else {
                $subject = new \DateTimeImmutable($value);
                $isFuture = $subject > $now;
            }
        } catch (\Exception) {
            // Malformed value: a separate format constraint (Assert\Date /
            // Assert\DateTime) reports this, not this validator.
            return;
        }

        if ($isFuture) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
