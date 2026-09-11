<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Repository\TrickSlugProviderInterface;
use App\Validator\ExistingTrickSlug;
use App\Validator\ExistingTrickSlugValidator;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * `App\Repository\TrickRepository` is `final` (T-0101), so PHPUnit cannot
 * build a mock or stub for it (`ClassIsFinalException`, confirmed while
 * writing this test) and PHP cannot subtype a final class for a hand-rolled
 * fake either. Booting a real kernel to get a real `TrickRepository` was
 * tried and rejected: `phpunit.dist.xml` hard-splits the suites ("Unit: no
 * kernel, no database") and a `KernelTestCase` inside `tests/Unit/` was
 * confirmed to corrupt kernel-boot state for the Functional suite that runs
 * right after it (`WebTestCase::createClient(): "the kernel should only be
 * booted once"` on the very next Functional test class).
 *
 * The fix is a narrow seam: `ExistingTrickSlugValidator` depends on
 * `TrickSlugProviderInterface` (one method, `findSlugs()`) instead of the
 * concrete `TrickRepository` directly. `TrickRepository` already has a
 * matching `findSlugs(): array` method (T-0101) and only needs to declare
 * `implements TrickSlugProviderInterface` - see tests.md "Bekannte Lücken"
 * for the full rationale. This keeps the validator's production dependency
 * effectively unchanged (still autowires to the same repository) while
 * making it fakeable here without a kernel.
 *
 * @extends ConstraintValidatorTestCase<ExistingTrickSlugValidator>
 */
final class ExistingTrickSlugValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        // Return type stays the parent's interface, not the concrete
        // ExistingTrickSlugValidator: PHP resolves a covariant return type
        // eagerly when the class is loaded, which would turn "class does not
        // exist yet" into an unrecoverable fatal error instead of a red test.
        return new ExistingTrickSlugValidator(new class implements TrickSlugProviderInterface {
            public function findSlugs(): array
            {
                return ['ollie', 'manual', 'kickflip'];
            }
        });
    }

    public function testItAcceptsASlugThatExistsInTheCatalog(): void
    {
        $this->validator->validate('ollie', new ExistingTrickSlug());

        $this->assertNoViolation();
    }

    public function testItRejectsASlugTheCatalogDoesNotKnow(): void
    {
        // Criterion 14.
        $constraint = new ExistingTrickSlug(message: 'unknown trick slug');

        $this->validator->validate('switch-heelflip-to-fakie-manual', $constraint);

        $this->buildViolation('unknown trick slug')
            ->assertRaised();
    }

    public function testItAcceptsNull(): void
    {
        $this->validator->validate(null, new ExistingTrickSlug());

        $this->assertNoViolation();
    }

    public function testItLoadsTheSlugListOnlyOnceAcrossMultipleValidateCallsOnTheSameInstance(): void
    {
        // A session may carry up to 50 trick rows (SkateSessionRequest.tricks
        // max); design.md is explicit that this must not cost 50 queries.
        $provider = new class implements TrickSlugProviderInterface {
            public int $calls = 0;

            public function findSlugs(): array
            {
                ++$this->calls;

                return ['ollie', 'manual', 'kickflip'];
            }
        };
        $validator = new ExistingTrickSlugValidator($provider);
        $validator->initialize($this->context);

        $validator->validate('ollie', new ExistingTrickSlug());
        $validator->validate('manual', new ExistingTrickSlug());
        $validator->validate('kickflip', new ExistingTrickSlug());

        self::assertSame(1, $provider->calls);
    }
}
