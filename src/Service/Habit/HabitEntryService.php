<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Dto\Habit\HabitEntryRequest;
use App\Entity\Habit;
use App\Entity\HabitEntry;
use App\Exception\FieldViolationsException;
use App\Exception\HabitEntryNotFoundException;
use App\Exception\HabitNotFoundException;
use App\Repository\HabitEntryAlreadyExistsException;
use App\Repository\HabitEntryStoreInterface;
use App\Repository\HabitReaderInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Records, corrects and deletes the value of one habit on one day (T-0402
 * design.md §3 and §4.2).
 *
 * `put()` is an idempotent upsert: it loads the day first, and when two
 * requests insert at the same moment the unique index refuses the second one -
 * the service then loads the row the first one wrote and changes it, so the
 * user never sees an error for a double tap.
 */
final readonly class HabitEntryService
{
    public function __construct(
        private HabitReaderInterface $habits,
        private HabitEntryStoreInterface $entries,
        private HabitValueValidator $values,
        private EntryDateRules $dates,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Order of checks: habit (unknown or inactive), then date and value together, then the write.
     *
     * @throws HabitNotFoundException
     * @throws FieldViolationsException
     * @throws ConflictHttpException    when the row a concurrent request wrote is already gone again
     */
    public function put(string $habitId, string $rawDate, HabitEntryRequest $request): HabitEntryWriteResult
    {
        $habit = $this->habits->findHabitById($habitId);
        if (null === $habit || !$habit->isActive()) {
            throw new HabitNotFoundException();
        }

        $date = $this->dates->parseCalendarDate($rawDate);
        $violations = null === $date
            ? [new FieldViolation('date', 'habit_entry.date.invalid')]
            : array_filter([$this->dates->rangeViolation($date)]);
        $violations = [...$violations, ...$this->values->validate($habit, $request->valueNumeric, $request->valueBool)];

        if ([] !== $violations) {
            throw FieldViolationsException::create(array_values($violations), $this->translator);
        }

        // No violation means the date parsed.
        \assert(null !== $date);

        $valueNumeric = null === $request->valueNumeric ? null : number_format($request->valueNumeric, 2, '.', '');
        $note = '' === $request->note ? null : $request->note;

        $existing = $this->entries->findByHabitAndDate($habit, $date);
        if (null !== $existing) {
            return $this->correct($existing, $valueNumeric, $request->valueBool, $note);
        }

        $entry = new HabitEntry($habit, $date, $valueNumeric, $request->valueBool, $note, \DateTimeImmutable::createFromInterface($this->clock->now()));

        try {
            $this->entries->insert($entry);
        } catch (HabitEntryAlreadyExistsException) {
            return $this->correctTheRowOfTheWinner($habit, $date, $valueNumeric, $request->valueBool, $note);
        }

        return new HabitEntryWriteResult($entry, true);
    }

    /**
     * @throws HabitNotFoundException
     * @throws FieldViolationsException    when the date is not a calendar date
     * @throws HabitEntryNotFoundException
     */
    public function delete(string $habitId, string $rawDate): void
    {
        $habit = $this->habits->findHabitById($habitId);
        if (null === $habit) {
            throw new HabitNotFoundException();
        }

        $date = $this->dates->parseCalendarDate($rawDate);
        if (null === $date) {
            throw FieldViolationsException::create([new FieldViolation('date', 'habit_entry.date.invalid')], $this->translator);
        }

        $entry = $this->entries->findByHabitAndDate($habit, $date);
        if (null === $entry) {
            throw new HabitEntryNotFoundException();
        }

        $this->entries->remove($entry);
    }

    private function correctTheRowOfTheWinner(Habit $habit, \DateTimeImmutable $date, ?string $valueNumeric, ?bool $valueBool, ?string $note): HabitEntryWriteResult
    {
        // Only what the store hands out after the conflict is used from here on: the entities of
        // the closed entity manager are not touched again.
        $existing = $this->entries->findByHabitAndDate($habit, $date);
        if (null === $existing) {
            throw new ConflictHttpException('The entry was changed concurrently, please try again.');
        }

        return $this->correct($existing, $valueNumeric, $valueBool, $note);
    }

    private function correct(HabitEntry $entry, ?string $valueNumeric, ?bool $valueBool, ?string $note): HabitEntryWriteResult
    {
        $entry->change($valueNumeric, $valueBool, $note);
        $this->entries->flush();

        return new HabitEntryWriteResult($entry, false);
    }
}
