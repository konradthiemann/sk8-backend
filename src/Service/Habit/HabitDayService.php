<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Dto\Habit\HabitDayResponse;
use App\Exception\FieldViolationsException;
use App\Repository\HabitReaderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the day view: every active habit with its entry of the day or `null`
 * (T-0402 design.md §3.3). Uses the same date rules as the write endpoints.
 */
final readonly class HabitDayService
{
    public function __construct(
        private HabitReaderInterface $habits,
        private EntryDateRules $dates,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string|null $rawDate `YYYY-MM-DD`; `null` or an empty string means today
     *
     * @throws FieldViolationsException when the date is invalid, in the future or before the project start
     */
    public function forDate(?string $rawDate): HabitDayResponse
    {
        if (null === $rawDate || '' === $rawDate) {
            $date = $this->dates->today();
        } else {
            $date = $this->dates->parseCalendarDate($rawDate);
            $violation = null === $date
                ? new FieldViolation('date', 'habit_entry.date.invalid')
                : $this->dates->rangeViolation($date);

            if (null !== $violation) {
                throw FieldViolationsException::create([$violation], $this->translator);
            }

            // No violation means the date parsed.
            \assert(null !== $date);
        }

        return HabitDayResponse::fromRows($date->format('Y-m-d'), $this->habits->findActiveWithEntry($date));
    }
}
