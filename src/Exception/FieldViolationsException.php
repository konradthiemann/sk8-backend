<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\Habit\FieldViolation;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A 422 raised outside a DTO, by a service rule. App\EventListener\ApiExceptionListener
 * only builds the `violations` list when the previous exception is a
 * ValidationFailedException, so this class carries one, built from the
 * translated violations.
 */
final class FieldViolationsException extends UnprocessableEntityHttpException
{
    /**
     * @param list<FieldViolation> $violations
     */
    private function __construct(private readonly array $violations, ValidationFailedException $previous)
    {
        parent::__construct('Validation failed.', $previous);
    }

    /**
     * @param non-empty-list<FieldViolation> $violations
     */
    public static function create(array $violations, TranslatorInterface $translator): self
    {
        $list = new ConstraintViolationList();
        foreach ($violations as $violation) {
            $parameters = [];
            foreach ($violation->parameters as $name => $value) {
                $parameters['{{ '.$name.' }}'] = (string) $value;
            }

            $message = $translator->trans($violation->messageKey, $parameters, 'validators');
            $list->add(new ConstraintViolation($message, $violation->messageKey, $parameters, null, $violation->field, null));
        }

        return new self($violations, new ValidationFailedException(null, $list));
    }

    /**
     * @return list<FieldViolation>
     */
    public function getFieldViolations(): array
    {
        return $this->violations;
    }
}
