<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Validator\NotInFuture;
use App\Validator\NotInFutureValidator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * No kernel: the validator is built directly with a `MockClock`, so
 * criterion 16 (00:30 local time already being "tomorrow" in UTC) never has
 * to wait for real midnight and never depends on the test runner's system
 * timezone (Railway/Docker containers typically run UTC).
 *
 * The clock is fixed to 2026-09-06 22:30:00 UTC, which is 2026-09-07 00:30
 * in Europe/Berlin (CEST, UTC+2) - "today" is 2026-09-07 for every test here.
 *
 * @extends ConstraintValidatorTestCase<NotInFutureValidator>
 */
final class NotInFutureValidatorTest extends ConstraintValidatorTestCase
{
    private const string NOW_UTC = '2026-09-06 22:30:00';
    private const string TIMEZONE = 'Europe/Berlin';

    protected function createValidator(): ConstraintValidatorInterface
    {
        // Return type stays the parent's interface, not the concrete
        // NotInFutureValidator: PHP resolves a covariant return type eagerly
        // when the class is loaded, which would turn "class does not exist
        // yet" into an unrecoverable fatal error instead of a red test.
        return new NotInFutureValidator(new MockClock(self::NOW_UTC, 'UTC'), self::TIMEZONE);
    }

    public function testItAcceptsTodaysDateInDateModeEvenAtHalfPastMidnightBerlinTime(): void
    {
        // Criterion 16: UTC clock still reads 2026-09-06, but Europe/Berlin
        // is already 2026-09-07 - "today" in the app timezone must pass.
        $this->validator->validate('2026-09-07', new NotInFuture(mode: 'date'));

        $this->assertNoViolation();
    }

    public function testItAcceptsAPastDateInDateMode(): void
    {
        $this->validator->validate('2026-01-15', new NotInFuture(mode: 'date'));

        $this->assertNoViolation();
    }

    public function testItRejectsATomorrowDateInDateMode(): void
    {
        // Criterion 15.
        $constraint = new NotInFuture(mode: 'date', message: 'the date may not lie in the future');

        $this->validator->validate('2026-09-08', $constraint);

        $this->buildViolation('the date may not lie in the future')
            ->assertRaised();
    }

    public function testItAcceptsTheCurrentInstantInInstantMode(): void
    {
        // Criterion 17's `mode: 'instant'` use for `startedAt`: an instant
        // exactly matching "now" must be accepted, not just strictly-past ones.
        $this->validator->validate('2026-09-06T22:30:00+00:00', new NotInFuture(mode: 'instant'));

        $this->assertNoViolation();
    }

    public function testItAcceptsAPastInstantInInstantMode(): void
    {
        $this->validator->validate('2026-09-06T20:00:00+02:00', new NotInFuture(mode: 'instant'));

        $this->assertNoViolation();
    }

    public function testItRejectsAFutureInstantInInstantMode(): void
    {
        $constraint = new NotInFuture(mode: 'instant', message: 'the instant may not lie in the future');

        $this->validator->validate('2026-09-06T23:00:00+00:00', $constraint);

        $this->buildViolation('the instant may not lie in the future')
            ->assertRaised();
    }

    public function testItAcceptsNull(): void
    {
        // Both sessionDate and startedAt use this constraint; startedAt is
        // optional, so null must never raise a violation here (blank/format
        // checks are separate constraints in SkateSessionRequest).
        $this->validator->validate(null, new NotInFuture(mode: 'date'));

        $this->assertNoViolation();
    }
}
