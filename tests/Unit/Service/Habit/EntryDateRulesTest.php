<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Service\Habit\EntryDateRules;
use App\Service\Habit\FieldViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * No kernel: the rules are built with a `MockClock`, so "today" never depends
 * on the real time or the test runner's system timezone (containers usually
 * run UTC; same idea as NotInFutureValidatorTest).
 *
 * The clock reads 2026-09-08 23:30 UTC, which is 2026-09-09 01:30 in
 * Europe/Berlin (CEST): "today" is 2026-09-09 for every test that does not
 * pick another clock. A rule that compared against the UTC date would call
 * 2026-09-09 a future day. Covers ticket criteria 4, 5 and 17 on the rule
 * level (design.md §3.1).
 */
final class EntryDateRulesTest extends TestCase
{
    private const string NOW_UTC = '2026-09-08 23:30:00';
    private const string TIMEZONE = 'Europe/Berlin';

    public function testItKnowsTheEarliestEntryDate(): void
    {
        self::assertSame('2026-01-01', \constant(EntryDateRules::class.'::EARLIEST_ENTRY_DATE'));
    }

    public function testItTakesTodayInTheAppTimezoneNotInUtc(): void
    {
        $today = $this->rules()->today();

        self::assertSame('2026-09-09', $today->format('Y-m-d'));
        self::assertSame('00:00:00', $today->format('H:i:s'));
    }

    public function testItSwitchesToTheNextDayAtMidnightInTheAppTimezone(): void
    {
        // 22:00 UTC is exactly 00:00 in Berlin in September.
        self::assertSame('2026-09-09', $this->rules('2026-09-08 22:00:00')->today()->format('Y-m-d'));
        self::assertSame('2026-09-08', $this->rules('2026-09-08 21:59:59')->today()->format('Y-m-d'));
    }

    public function testItHonoursTheConfiguredTimezoneInsteadOfAFixedOne(): void
    {
        // 03:00 UTC on the 9th is still the evening of the 8th in Los Angeles.
        $rules = new EntryDateRules(new MockClock('2026-09-09 03:00:00', 'UTC'), 'America/Los_Angeles');

        self::assertSame('2026-09-08', $rules->today()->format('Y-m-d'));
    }

    #[DataProvider('calendarDates')]
    public function testItParsesAValidCalendarDateToMidnight(string $raw): void
    {
        $date = $this->rules()->parseCalendarDate($raw);

        self::assertNotNull($date);
        self::assertSame($raw, $date->format('Y-m-d'));
        self::assertSame('00:00:00', $date->format('H:i:s'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function calendarDates(): array
    {
        return [
            'ordinary day' => ['2026-09-08'],
            'first of the year' => ['2026-01-01'],
            'last of the year' => ['2026-12-31'],
            'end of February in a common year' => ['2026-02-28'],
            'leap day in a leap year' => ['2028-02-29'],
            'thirty-first of a long month' => ['2026-07-31'],
        ];
    }

    #[DataProvider('notCalendarDates')]
    public function testItRejectsWhatIsNoCalendarDate(string $raw): void
    {
        self::assertNull($this->rules()->parseCalendarDate($raw));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notCalendarDates(): array
    {
        return [
            'thirtieth of February' => ['2026-02-30'],
            'leap day in a common year' => ['2026-02-29'],
            'thirty-first of a short month' => ['2026-04-31'],
            'month thirteen' => ['2026-13-01'],
            'all zeros' => ['0000-00-00'],
            'day zero' => ['2026-09-00'],
            'month zero' => ['2026-00-10'],
            'unpadded parts' => ['2026-2-3'],
            'unpadded month' => ['2026-9-08'],
            'no separators' => ['20260908'],
            'words' => ['abc'],
            'empty string' => [''],
            'trailing space' => ['2026-09-08 '],
            'with a time' => ['2026-09-08T00:00'],
            'slashes' => ['2026/09/08'],
        ];
    }

    public function testItSeesNoViolationForToday(): void
    {
        self::assertNull($this->rangeViolationFor('2026-09-09'));
    }

    public function testItSeesNoViolationForAPastDay(): void
    {
        // Criterion 5: a forgotten day can be entered afterwards.
        self::assertNull($this->rangeViolationFor('2026-09-06'));
    }

    public function testItRejectsTomorrowAsFuture(): void
    {
        // Criteria 4 and 17.
        $violation = $this->rangeViolationFor('2026-09-10');

        self::assertNotNull($violation);
        self::assertSame('date', $violation->field);
        self::assertSame('habit_entry.date.future', $violation->messageKey);
        self::assertSame([], $violation->parameters);
    }

    public function testItRejectsAFarFutureDayAsFuture(): void
    {
        self::assertSame('habit_entry.date.future', $this->rangeViolationFor('2027-01-01')?->messageKey);
    }

    public function testItAcceptsTheEarliestEntryDate(): void
    {
        self::assertNull($this->rangeViolationFor('2026-01-01'));
    }

    public function testItRejectsTheDayBeforeTheEarliestEntryDateAsBeforeStart(): void
    {
        $violation = $this->rangeViolationFor('2025-12-31');

        self::assertNotNull($violation);
        self::assertSame('date', $violation->field);
        self::assertSame('habit_entry.date.before_start', $violation->messageKey);
        self::assertSame([], $violation->parameters);
    }

    public function testItStillCallsTheNextDayFutureBeforeMidnightInTheAppTimezone(): void
    {
        // 21:30 UTC is 23:30 on the 8th in Berlin: the 8th is still today, the 9th is tomorrow.
        $rules = $this->rules('2026-09-08 21:30:00');

        self::assertNull($this->rangeViolationOf($rules, '2026-09-08'));
        self::assertSame('habit_entry.date.future', $this->rangeViolationOf($rules, '2026-09-09')?->messageKey);
    }

    private function rangeViolationFor(string $raw): ?FieldViolation
    {
        return $this->rangeViolationOf($this->rules(), $raw);
    }

    private function rangeViolationOf(EntryDateRules $rules, string $raw): ?FieldViolation
    {
        $date = $rules->parseCalendarDate($raw);
        self::assertNotNull($date, \sprintf('"%s" must be a calendar date', $raw));

        return $rules->rangeViolation($date);
    }

    private function rules(string $nowUtc = self::NOW_UTC): EntryDateRules
    {
        return new EntryDateRules(new MockClock($nowUtc, 'UTC'), self::TIMEZONE);
    }
}
