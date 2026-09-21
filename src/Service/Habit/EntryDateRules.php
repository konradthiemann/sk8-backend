<?php

declare(strict_types=1);

namespace App\Service\Habit;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one place that decides which calendar days a habit entry may have
 * (T-0402 design.md §3.1): a real calendar date, not after today, not before
 * the project start. "Today" is the calendar day in the configured application
 * timezone, not in UTC.
 */
final readonly class EntryDateRules
{
    /**
     * First day for which entries may be recorded.
     */
    public const string EARLIEST_ENTRY_DATE = '2026-01-01';

    private \DateTimeZone $timezone;

    public function __construct(
        private ClockInterface $clock,
        #[Autowire(param: 'app.timezone')]
        string $timezone,
    ) {
        $this->timezone = new \DateTimeZone($timezone);
    }

    /**
     * Today's calendar day at 00:00 in the application timezone.
     */
    public function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone($this->timezone)
            ->setTime(0, 0);
    }

    /**
     * Strict `YYYY-MM-DD`: `null` unless the text is a real calendar date in exactly that form.
     * createFromFormat() alone rolls `2026-02-30` over to March, so the result must format back to the input.
     */
    public function parseCalendarDate(string $raw): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $this->timezone);
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        return $date;
    }

    /**
     * `date.future` after today, `date.before_start` before the earliest entry date, otherwise `null`.
     */
    public function rangeViolation(\DateTimeImmutable $date): ?FieldViolation
    {
        $day = $date->format('Y-m-d');

        if ($day > $this->today()->format('Y-m-d')) {
            return new FieldViolation('date', 'habit_entry.date.future');
        }

        if ($day < self::EARLIEST_ENTRY_DATE) {
            return new FieldViolation('date', 'habit_entry.date.before_start');
        }

        return null;
    }
}
