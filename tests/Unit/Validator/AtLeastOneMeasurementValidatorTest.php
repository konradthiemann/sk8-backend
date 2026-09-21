<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Dto\Training\FitnessAssessmentRequest;
use App\Validator\AtLeastOneMeasurement;
use App\Validator\AtLeastOneMeasurementValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * No kernel: the validator has no dependencies, so it is built directly and
 * fed hand-made request objects (design.md 4.1). The rule under test is
 * "at least one of the eight measurements is not null" - zero counts as
 * set (a left-leg balance of 0 seconds is a real and, for the knee, the most
 * telling measurement), while `assessedOn` and `notes` are never
 * measurements.
 *
 * @extends ConstraintValidatorTestCase<AtLeastOneMeasurementValidator>
 */
final class AtLeastOneMeasurementValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        // Return type stays the parent's interface, not the concrete
        // AtLeastOneMeasurementValidator: PHP resolves a covariant return
        // type eagerly when the class is loaded, which would turn "class
        // does not exist yet" into an unrecoverable fatal error instead of
        // a red test.
        return new AtLeastOneMeasurementValidator();
    }

    public function testItRejectsARequestWithoutAnyMeasurementOnThePathValues(): void
    {
        // Criterion 2.
        $this->validator->validate($this->buildRequest(), new AtLeastOneMeasurement());

        $this->buildViolation('fitness.values.none')
            ->atPath('property.path.values')
            ->assertRaised();
    }

    public function testItRejectsARequestThatOnlyCarriesNotes(): void
    {
        $this->validator->validate($this->buildRequest(['notes' => 'felt shaky today']), new AtLeastOneMeasurement());

        $this->buildViolation('fitness.values.none')
            ->atPath('property.path.values')
            ->assertRaised();
    }

    #[DataProvider('measurementFields')]
    public function testItAcceptsARequestWithExactlyOneMeasurementSet(string $field): void
    {
        // Criterion 3 - one case per field, so a field forgotten in the
        // validator's list cannot hide behind the other seven.
        $this->validator->validate($this->buildRequest([$field => 7]), new AtLeastOneMeasurement());

        $this->assertNoViolation();
    }

    #[DataProvider('measurementFields')]
    public function testItCountsAZeroMeasurementAsSet(string $field): void
    {
        // 0 is a real value, not "missing": null !== 0.
        $this->validator->validate($this->buildRequest([$field => 0]), new AtLeastOneMeasurement());

        $this->assertNoViolation();
    }

    public function testItAcceptsARequestWithAllMeasurementsSet(): void
    {
        $request = $this->buildRequest([
            'pushUpsMax' => 24,
            'squatsMax' => 41,
            'ringPullUpsMax' => 5,
            'plankSeconds' => 95,
            'singleLegBalanceLeftSeconds' => 28,
            'singleLegBalanceRightSeconds' => 51,
            'wallSitSeconds' => 70,
            'standingBroadJumpCm' => 185,
        ]);

        $this->validator->validate($request, new AtLeastOneMeasurement());

        $this->assertNoViolation();
    }

    public function testItIgnoresANullValue(): void
    {
        // Other constraints (NotNull, ...) own the "value is missing" case.
        $this->validator->validate(null, new AtLeastOneMeasurement());

        $this->assertNoViolation();
    }

    public function testItRejectsAConstraintOfAnotherType(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate($this->buildRequest(), new NotBlank());
    }

    public function testItRejectsAValueThatIsNotAFitnessAssessmentRequest(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate('not a request', new AtLeastOneMeasurement());
    }

    public function testItIsAClassConstraint(): void
    {
        self::assertSame(Constraint::CLASS_CONSTRAINT, (new AtLeastOneMeasurement())->getTargets());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function measurementFields(): iterable
    {
        yield 'push-ups' => ['pushUpsMax'];
        yield 'squats' => ['squatsMax'];
        yield 'ring pull-ups' => ['ringPullUpsMax'];
        yield 'plank' => ['plankSeconds'];
        yield 'single-leg balance left' => ['singleLegBalanceLeftSeconds'];
        yield 'single-leg balance right' => ['singleLegBalanceRightSeconds'];
        yield 'wall sit' => ['wallSitSeconds'];
        yield 'standing broad jump' => ['standingBroadJumpCm'];
    }

    /**
     * @param array<string, int|string|null> $overrides
     */
    private function buildRequest(array $overrides = []): FitnessAssessmentRequest
    {
        return (new \ReflectionClass(FitnessAssessmentRequest::class))->newInstanceArgs(['assessedOn' => '2026-09-08', ...$overrides]);
    }
}
