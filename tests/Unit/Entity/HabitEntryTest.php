<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Habit;
use App\Entity\HabitEntry;
use App\Enum\HabitValueType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * Pure object test, no kernel, no database (T-0402 design.md §2/§4.2). The
 * database refuses a row with none or both values through
 * `chk_habit_entry_value`; the entity refuses it earlier, so the rule can be
 * tested without Postgres. `change()` is the correction path of the idempotent
 * PUT: values and note are overwritten, everything that identifies the row
 * (`id`, `habit`, `entryDate`, `createdAt`) stays.
 */
final class HabitEntryTest extends TestCase
{
    public function testItGetsAUuidV7IdInItsConstructor(): void
    {
        self::assertInstanceOf(UuidV7::class, $this->numericEntry()->getId());
    }

    public function testItGivesEveryEntryItsOwnId(): void
    {
        self::assertFalse($this->numericEntry()->getId()->equals($this->numericEntry()->getId()));
    }

    public function testItExposesAllConstructorValues(): void
    {
        $habit = $this->habit();
        $date = new \DateTimeImmutable('2026-09-08');
        $createdAt = new \DateTimeImmutable('2026-09-08T19:04:11+00:00');

        $entry = new HabitEntry($habit, $date, '7.50', null, 'spät ins Bett', $createdAt);

        self::assertSame($habit, $entry->getHabit());
        self::assertSame('2026-09-08', $entry->getEntryDate()->format('Y-m-d'));
        self::assertSame('7.50', $entry->getValueNumeric());
        self::assertNull($entry->getValueBool());
        self::assertSame('spät ins Bett', $entry->getNote());
        self::assertEquals($createdAt, $entry->getCreatedAt());
    }

    public function testItAcceptsAYesNoValueWithoutANumber(): void
    {
        $entry = new HabitEntry($this->habit(), new \DateTimeImmutable('2026-09-08'), null, false, null, new \DateTimeImmutable());

        self::assertNull($entry->getValueNumeric());
        self::assertFalse($entry->getValueBool());
    }

    public function testItAcceptsAZeroValueAsAValue(): void
    {
        $entry = new HabitEntry($this->habit(), new \DateTimeImmutable('2026-09-08'), '0.00', null, null, new \DateTimeImmutable());

        self::assertSame('0.00', $entry->getValueNumeric());
    }

    #[DataProvider('invalidValueCombinations')]
    public function testItRejectsNoneOrBothValuesInTheConstructor(?string $valueNumeric, ?bool $valueBool): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HabitEntry($this->habit(), new \DateTimeImmutable('2026-09-08'), $valueNumeric, $valueBool, null, new \DateTimeImmutable());
    }

    #[DataProvider('invalidValueCombinations')]
    public function testItRejectsNoneOrBothValuesInChange(?string $valueNumeric, ?bool $valueBool): void
    {
        $entry = $this->numericEntry();

        $this->expectException(\InvalidArgumentException::class);

        $entry->change($valueNumeric, $valueBool, null);
    }

    /**
     * @return array<string, array{?string, ?bool}>
     */
    public static function invalidValueCombinations(): array
    {
        return [
            'neither value' => [null, null],
            'both values' => ['1.00', true],
            'zero and false' => ['0.00', false],
        ];
    }

    public function testItOverwritesValueAndNoteInChange(): void
    {
        $entry = $this->numericEntry();

        $entry->change('8.00', null, 'ausgeschlafen');

        self::assertSame('8.00', $entry->getValueNumeric());
        self::assertSame('ausgeschlafen', $entry->getNote());
    }

    public function testItClearsTheNoteWhenChangeGetsNone(): void
    {
        $entry = new HabitEntry($this->habit(), new \DateTimeImmutable('2026-09-08'), '7.50', null, 'alte Notiz', new \DateTimeImmutable());

        $entry->change('7.50', null, null);

        self::assertNull($entry->getNote());
    }

    public function testItKeepsIdHabitDateAndCreationTimeInChange(): void
    {
        $habit = $this->habit();
        $date = new \DateTimeImmutable('2026-09-08');
        $createdAt = new \DateTimeImmutable('2026-09-08T19:04:11+00:00');
        $entry = new HabitEntry($habit, $date, '7.50', null, null, $createdAt);
        $idBefore = $entry->getId()->toRfc4122();

        $entry->change('8.00', null, 'geändert');

        self::assertSame($idBefore, $entry->getId()->toRfc4122());
        self::assertSame($habit, $entry->getHabit());
        self::assertSame($date, $entry->getEntryDate());
        self::assertSame($createdAt, $entry->getCreatedAt());
    }

    public function testItSwitchesBetweenNumberAndYesNoInChange(): void
    {
        $entry = $this->numericEntry();

        $entry->change(null, true, null);

        self::assertNull($entry->getValueNumeric());
        self::assertTrue($entry->getValueBool());
    }

    private function numericEntry(): HabitEntry
    {
        return new HabitEntry($this->habit(), new \DateTimeImmutable('2026-09-08'), '7.50', null, null, new \DateTimeImmutable('2026-09-08T19:04:11+00:00'));
    }

    private function habit(): Habit
    {
        return new Habit('sleep-duration', 'Schlafdauer', HabitValueType::Duration, 'h', null, null, null, null, 20);
    }
}
