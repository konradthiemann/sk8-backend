<?php

declare(strict_types=1);

namespace App\Validator;

use App\Repository\TrickSlugProviderInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Loads the catalog's slug list at most once per request (design.md §4.1): a
 * session may carry up to 50 trick rows, and Symfony builds a fresh
 * validator instance per request, so a private lazily-initialized property
 * is enough to load once per request - no separate cache layer needed.
 *
 * Depends on TrickSlugProviderInterface, not the concrete TrickRepository
 * directly: TrickRepository is `final`, which made it unmockable in the
 * unit test for this validator (T-0102 tests.md). Production wiring is
 * unaffected - TrickRepository is still the only implementation and
 * autowires the same way.
 */
final class ExistingTrickSlugValidator extends ConstraintValidator
{
    /**
     * @var list<string>|null
     */
    private ?array $slugs = null;

    public function __construct(
        private readonly TrickSlugProviderInterface $trickSlugProvider,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ExistingTrickSlug) {
            throw new UnexpectedTypeException($constraint, ExistingTrickSlug::class);
        }

        if (null === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (null === $this->slugs) {
            $this->slugs = $this->trickSlugProvider->findSlugs();
        }

        if (!\in_array($value, $this->slugs, true)) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
