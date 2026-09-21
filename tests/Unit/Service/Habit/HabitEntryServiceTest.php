<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Dto\Habit\HabitEntryRequest;
use App\Entity\Habit;
use App\Entity\HabitEntry;
use App\Enum\HabitValueType;
use App\Exception\FieldViolationsException;
use App\Exception\HabitEntryNotFoundException;
use App\Exception\HabitNotFoundException;
use App\Service\Habit\EntryDateRules;
use App\Service\Habit\FieldViolation;
use App\Service\Habit\HabitEntryService;
use App\Service\Habit\HabitValueValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Uid\Uuid;

/**
 * Pure object test, no kernel, no database (T-0402 design.md §4.2/§4.3). The
 * reader and the store are the in-memory doubles next to this file, the
 * validator, the date rules, the clock and the translator are real (the clock
 * is a `MockClock`, the translator returns the message keys).
 *
 * Covers ticket criteria 1-3, 5 and 11-13 on the service level, above all the
 * upsert: an existing row is changed in place (same object, `id` and
 * `createdAt` stay), and a unique conflict that a concurrent request causes
 * ends in "load and change" instead of an error. The concurrent request is
 * replayed with the store's `racingEntry`.
 *
 * The clock reads 2026-09-08 19:04:11 UTC (21:04 in Europe/Berlin), so
 * "today" is 2026-09-08.
 */
final class HabitEntryServiceTest extends TestCase
{
    private const string NOW_UTC = '2026-09-08 19:04:11';
    private const string TODAY = '2026-09-08';

    public function testItCreatesANewEntryAndReportsItAsCreated(): void
    {
        // Criterion 1.
        $habit = $this->duration();
        $store = new InMemoryHabitEntryStore();

        $result = $this->service([$habit], $store)->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 7.5, note: 'spät ins Bett'));

        self::assertTrue($result->created);
        self::assertSame($habit, $result->entry->getHabit());
        self::assertSame(self::TODAY, $result->entry->getEntryDate()->format('Y-m-d'));
        self::assertSame('7.50', $result->entry->getValueNumeric());
        self::assertNull($result->entry->getValueBool());
        self::assertSame('spät ins Bett', $result->entry->getNote());
        self::assertSame(1, $store->insertCount);
        self::assertSame([$result->entry], array_values($store->entries));
    }

    public function testItStampsTheCreationTimeFromTheClock(): void
    {
        $habit = $this->duration();

        $result = $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 7.5));

        self::assertSame((new \DateTimeImmutable(self::NOW_UTC.' UTC'))->getTimestamp(), $result->entry->getCreatedAt()->getTimestamp());
    }

    public function testItStoresTheNumberAsATwoDecimalString(): void
    {
        $habit = $this->duration();

        $result = $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 7.25));

        self::assertSame('7.25', $result->entry->getValueNumeric());
    }

    public function testItStoresZeroAsAValueAndNotAsNull(): void
    {
        // Criterion 3: zero is an entry.
        $habit = $this->number();

        $result = $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 0.0));

        self::assertTrue($result->created);
        self::assertSame('0.00', $result->entry->getValueNumeric());
        self::assertNull($result->entry->getValueBool());
    }

    public function testItStoresNoAsAValueForAYesNoHabit(): void
    {
        $habit = $this->boolean();

        $result = $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueBool: false));

        self::assertTrue($result->created);
        self::assertFalse($result->entry->getValueBool());
        self::assertNull($result->entry->getValueNumeric());
    }

    public function testItTurnsAnEmptyNoteIntoNull(): void
    {
        // Criterion 20.
        $habit = $this->duration();

        $result = $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 7.5, note: ''));

        self::assertNull($result->entry->getNote());
    }

    public function testItAcceptsAPastDay(): void
    {
        // Criterion 5.
        $habit = $this->duration();

        $result = $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), '2026-09-05', new HabitEntryRequest(valueNumeric: 7.5));

        self::assertTrue($result->created);
        self::assertSame('2026-09-05', $result->entry->getEntryDate()->format('Y-m-d'));
    }

    public function testItChangesAnExistingEntryInPlaceAndReportsItAsNotCreated(): void
    {
        // Criterion 2.
        $habit = $this->duration();
        $existing = $this->entry($habit, '7.50', 'alte Notiz');
        $store = new InMemoryHabitEntryStore([$existing]);

        $result = $this->service([$habit], $store)->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 8.0, note: 'neue Notiz'));

        self::assertFalse($result->created);
        self::assertSame($existing, $result->entry, 'the row is changed, not replaced');
        self::assertSame('8.00', $existing->getValueNumeric());
        self::assertSame('neue Notiz', $existing->getNote());
    }

    public function testItKeepsIdAndCreationTimeWhenItChangesAnEntry(): void
    {
        // Criterion 2.
        $habit = $this->duration();
        $existing = $this->entry($habit, '7.50', null);
        $idBefore = $existing->getId()->toRfc4122();
        $createdAtBefore = $existing->getCreatedAt();

        $result = $this->service([$habit], new InMemoryHabitEntryStore([$existing]))->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 8.0));

        self::assertSame($idBefore, $result->entry->getId()->toRfc4122());
        self::assertSame($createdAtBefore, $result->entry->getCreatedAt());
    }

    public function testItFlushesOnceAndInsertsNothingWhenItChangesAnEntry(): void
    {
        $habit = $this->duration();
        $store = new InMemoryHabitEntryStore([$this->entry($habit, '7.50', null)]);

        $this->service([$habit], $store)->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 8.0));

        self::assertSame(0, $store->insertCount);
        self::assertSame(1, $store->flushCount);
        self::assertCount(1, $store->entries);
    }

    public function testItClearsTheNoteWhenTheCorrectionSendsNone(): void
    {
        // The correction overwrites the whole entry, the note included.
        $habit = $this->duration();
        $existing = $this->entry($habit, '7.50', 'alte Notiz');

        $this->service([$habit], new InMemoryHabitEntryStore([$existing]))->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 8.0));

        self::assertNull($existing->getNote());
    }

    public function testItChangesZeroToASetValueAndBack(): void
    {
        $habit = $this->number();
        $existing = $this->entry($habit, '4.00', null);
        $service = $this->service([$habit], new InMemoryHabitEntryStore([$existing]));

        $service->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 0.0));
        self::assertSame('0.00', $existing->getValueNumeric());

        $service->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 3.0));
        self::assertSame('3.00', $existing->getValueNumeric());
    }

    public function testItDoesNotTouchTheEntryOfAnotherHabitOnTheSameDay(): void
    {
        $sleep = $this->duration();
        $water = $this->number();
        $otherEntry = $this->entry($water, '5.00', null);
        $store = new InMemoryHabitEntryStore([$otherEntry]);

        $result = $this->service([$sleep, $water], $store)->put($this->idOf($sleep), self::TODAY, new HabitEntryRequest(valueNumeric: 7.5));

        self::assertTrue($result->created);
        self::assertSame('5.00', $otherEntry->getValueNumeric());
        self::assertCount(2, $store->entries);
    }

    public function testItRejectsAnUnknownHabitWithoutTouchingTheStore(): void
    {
        $store = new InMemoryHabitEntryStore();

        try {
            $this->service([$this->duration()], $store)->put(Uuid::v7()->toRfc4122(), self::TODAY, new HabitEntryRequest(valueNumeric: 7.5));
            self::fail('expected HabitNotFoundException');
        } catch (HabitNotFoundException) {
            self::assertSame(0, $store->insertCount);
            self::assertSame(0, $store->flushCount);
            self::assertSame([], $store->entries);
        }
    }

    public function testItRejectsAnInactiveHabitWithoutTouchingTheStore(): void
    {
        // Criterion 11.
        $habit = $this->duration(isActive: false);
        $store = new InMemoryHabitEntryStore();

        try {
            $this->service([$habit], $store)->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 7.5));
            self::fail('expected HabitNotFoundException');
        } catch (HabitNotFoundException) {
            self::assertSame(0, $store->insertCount);
            self::assertSame(0, $store->flushCount);
            self::assertSame([], $store->entries);
        }
    }

    public function testItReportsAnUnknownHabitBeforeAnyDateOrValueViolation(): void
    {
        $this->expectException(HabitNotFoundException::class);

        $this->service([], new InMemoryHabitEntryStore())->put(Uuid::v7()->toRfc4122(), '2026-02-30', new HabitEntryRequest());
    }

    public function testItRejectsAFutureDayWithoutWriting(): void
    {
        // Criterion 4.
        $habit = $this->duration();
        $store = new InMemoryHabitEntryStore();

        $violations = $this->violationsOf(fn () => $this->service([$habit], $store)->put($this->idOf($habit), '2026-09-09', new HabitEntryRequest(valueNumeric: 7.5)));

        self::assertSame([['date', 'habit_entry.date.future']], $violations);
        self::assertSame(0, $store->insertCount);
        self::assertSame(0, $store->flushCount);
    }

    public function testItRejectsADayBeforeTheProjectStart(): void
    {
        $habit = $this->duration();

        $violations = $this->violationsOf(fn () => $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), '2025-12-31', new HabitEntryRequest(valueNumeric: 7.5)));

        self::assertSame([['date', 'habit_entry.date.before_start']], $violations);
    }

    public function testItRejectsADateThatIsNoCalendarDate(): void
    {
        $habit = $this->duration();

        $violations = $this->violationsOf(fn () => $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), '2026-02-30', new HabitEntryRequest(valueNumeric: 7.5)));

        self::assertSame([['date', 'habit_entry.date.invalid']], $violations);
    }

    public function testItRejectsAValueThatBreaksAWriteRuleWithoutWriting(): void
    {
        $habit = $this->duration();
        $store = new InMemoryHabitEntryStore();

        $violations = $this->violationsOf(fn () => $this->service([$habit], $store)->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 24.25)));

        self::assertSame([['valueNumeric', 'habit_entry.value.out_of_range']], $violations);
        self::assertSame(0, $store->insertCount);
        self::assertSame(0, $store->flushCount);
    }

    public function testItRejectsARequestWithoutAnyValue(): void
    {
        // Criterion 10.
        $habit = $this->duration();

        $violations = $this->violationsOf(fn () => $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), self::TODAY, new HabitEntryRequest()));

        self::assertSame([['valueNumeric', 'habit_entry.value.exactly_one']], $violations);
    }

    public function testItRejectsARequestWithBothValues(): void
    {
        // Criterion 9.
        $habit = $this->duration();

        $violations = $this->violationsOf(fn () => $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 7.0, valueBool: true)));

        self::assertSame([['valueNumeric', 'habit_entry.value.exactly_one']], $violations);
    }

    public function testItRejectsANumberForAYesNoHabit(): void
    {
        // Criterion 6.
        $habit = $this->boolean();

        $violations = $this->violationsOf(fn () => $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 1.0)));

        self::assertSame([['valueNumeric', 'habit_entry.value.bool_expected']], $violations);
    }

    public function testItReportsTheDateViolationBeforeTheValueViolationInOneException(): void
    {
        $habit = $this->duration();

        $violations = $this->violationsOf(fn () => $this->service([$habit], new InMemoryHabitEntryStore())->put($this->idOf($habit), '2026-09-09', new HabitEntryRequest(valueNumeric: 24.25)));

        self::assertSame(
            [['date', 'habit_entry.date.future'], ['valueNumeric', 'habit_entry.value.out_of_range']],
            $violations,
        );
    }

    public function testItChangesTheRowThatAConcurrentRequestInsertedInTheMeantime(): void
    {
        // The race behind the unique index `uniq_habit_entry_habit_date`: the
        // lookup finds nothing, then the other request wins the insert.
        $habit = $this->duration();
        $racing = $this->entry($habit, '1.00', 'von der anderen Anfrage');
        $idOfRacingRow = $racing->getId()->toRfc4122();
        $createdAtOfRacingRow = $racing->getCreatedAt();
        $store = new InMemoryHabitEntryStore();
        $store->racingEntry = $racing;

        $result = $this->service([$habit], $store)->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 6.5));

        self::assertFalse($result->created, 'the day already existed, so this is a correction (200), not a creation');
        self::assertSame($racing, $result->entry);
        self::assertSame($idOfRacingRow, $result->entry->getId()->toRfc4122());
        self::assertSame($createdAtOfRacingRow, $result->entry->getCreatedAt());
        self::assertSame('6.50', $racing->getValueNumeric());
        self::assertNull($racing->getNote(), 'the correction overwrites the note like any other correction');
        self::assertCount(1, $store->entries, 'still exactly one row for that day');
        self::assertSame(1, $store->insertCount, 'the insert is tried once, not retried');
        self::assertSame(1, $store->flushCount, 'the correction of the other request\'s row is flushed once');
    }

    public function testItAnswersWithAConflictWhenTheRacingRowIsGoneAgain(): void
    {
        $habit = $this->duration();
        $store = new InMemoryHabitEntryStore();
        $store->racingEntry = $this->entry($habit, '1.00', null);
        $store->racingEntryVanishes = true;

        $this->expectException(ConflictHttpException::class);

        $this->service([$habit], $store)->put($this->idOf($habit), self::TODAY, new HabitEntryRequest(valueNumeric: 6.5));
    }

    public function testItDeletesAnExistingEntry(): void
    {
        // Criterion 12.
        $habit = $this->duration();
        $store = new InMemoryHabitEntryStore([$this->entry($habit, '7.50', null)]);

        $this->service([$habit], $store)->delete($this->idOf($habit), self::TODAY);

        self::assertSame(1, $store->removeCount);
        self::assertSame([], $store->entries);
    }

    public function testItLeavesOtherEntriesUntouchedWhenItDeletesOne(): void
    {
        $sleep = $this->duration();
        $water = $this->number();
        $keptOtherHabit = $this->entry($water, '5.00', null);
        $keptOtherDay = $this->entry($sleep, '6.00', null, '2026-09-07');
        $store = new InMemoryHabitEntryStore([$this->entry($sleep, '7.50', null), $keptOtherHabit, $keptOtherDay]);

        $this->service([$sleep, $water], $store)->delete($this->idOf($sleep), self::TODAY);

        self::assertSame(1, $store->removeCount);
        self::assertCount(2, $store->entries);
        self::assertContains($keptOtherHabit, $store->entries);
        self::assertContains($keptOtherDay, $store->entries);
    }

    public function testItReportsAMissingEntryOnDelete(): void
    {
        // Criterion 13.
        $habit = $this->duration();
        $store = new InMemoryHabitEntryStore();

        $this->expectException(HabitEntryNotFoundException::class);

        $this->service([$habit], $store)->delete($this->idOf($habit), self::TODAY);
    }

    public function testItReportsAMissingEntryWhenOnlyAnotherDayHasOne(): void
    {
        $habit = $this->duration();
        $store = new InMemoryHabitEntryStore([$this->entry($habit, '7.50', null, '2026-09-07')]);

        $this->expectException(HabitEntryNotFoundException::class);

        $this->service([$habit], $store)->delete($this->idOf($habit), self::TODAY);
    }

    public function testItRejectsDeletingForAnUnknownHabit(): void
    {
        $this->expectException(HabitNotFoundException::class);

        $this->service([$this->duration()], new InMemoryHabitEntryStore())->delete(Uuid::v7()->toRfc4122(), self::TODAY);
    }

    public function testItDeletesAnEntryOfAnInactiveHabit(): void
    {
        // Decision of design.md §3.2: the entries of a switched-off habit stay, so a slip stays correctable.
        $habit = $this->duration(isActive: false);
        $store = new InMemoryHabitEntryStore([$this->entry($habit, '7.50', null)]);

        $this->service([$habit], $store)->delete($this->idOf($habit), self::TODAY);

        self::assertSame(1, $store->removeCount);
        self::assertSame([], $store->entries);
    }

    public function testItRejectsADeleteDateThatIsNoCalendarDate(): void
    {
        $habit = $this->duration();

        $violations = $this->violationsOf(fn () => $this->service([$habit], new InMemoryHabitEntryStore())->delete($this->idOf($habit), '2026-02-30'));

        self::assertSame([['date', 'habit_entry.date.invalid']], $violations);
    }

    public function testItAnswersAFutureDeleteDateWithAMissingEntryNotWithADateViolation(): void
    {
        // No entry can exist in the future, so "not found" is the factual answer (design.md §3.2).
        $habit = $this->duration();

        $this->expectException(HabitEntryNotFoundException::class);

        $this->service([$habit], new InMemoryHabitEntryStore())->delete($this->idOf($habit), '2026-09-09');
    }

    public function testItAnswersADeleteDateBeforeTheProjectStartWithAMissingEntry(): void
    {
        $habit = $this->duration();

        $this->expectException(HabitEntryNotFoundException::class);

        $this->service([$habit], new InMemoryHabitEntryStore())->delete($this->idOf($habit), '2025-12-31');
    }

    /**
     * @param list<Habit> $habits
     */
    private function service(array $habits, InMemoryHabitEntryStore $store): HabitEntryService
    {
        $clock = new MockClock(self::NOW_UTC, 'UTC');

        return new HabitEntryService(
            new InMemoryHabitReader($habits),
            $store,
            new HabitValueValidator(),
            new EntryDateRules($clock, 'Europe/Berlin'),
            $clock,
            new IdentityTranslator(),
        );
    }

    /**
     * Runs the call and returns its violations as [field, messageKey] pairs.
     *
     * @param callable(): mixed $call
     *
     * @return list<array{string, string}>
     */
    private function violationsOf(callable $call): array
    {
        try {
            $call();
        } catch (FieldViolationsException $exception) {
            return array_map(
                static fn (FieldViolation $violation): array => [$violation->field, $violation->messageKey],
                $exception->getFieldViolations(),
            );
        }

        self::fail('expected FieldViolationsException');
    }

    private function idOf(Habit $habit): string
    {
        return $habit->getId()->toRfc4122();
    }

    private function entry(Habit $habit, string $valueNumeric, ?string $note, string $date = self::TODAY): HabitEntry
    {
        return new HabitEntry($habit, new \DateTimeImmutable($date), $valueNumeric, null, $note, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
    }

    private function duration(bool $isActive = true): Habit
    {
        return new Habit('sleep-duration', 'Schlafdauer', HabitValueType::Duration, 'h', null, null, null, null, 20, $isActive);
    }

    private function number(): Habit
    {
        return new Habit('water', 'Wasser', HabitValueType::Number, 'Glas', null, null, null, null, 50);
    }

    private function boolean(): Habit
    {
        return new Habit('mobility-stretch', 'Beweglichkeit', HabitValueType::Boolean, null, null, null, null, null, 60);
    }
}
