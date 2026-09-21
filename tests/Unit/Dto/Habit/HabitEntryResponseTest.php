<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Habit;

use App\Dto\Habit\HabitEntryResponse;
use App\Entity\Habit;
use App\Entity\HabitEntry;
use App\Enum\HabitValueType;
use PHPUnit\Framework\TestCase;

/**
 * Pure mapping test: no kernel, no database. Checks what
 * `HabitEntryResponse::fromEntity()` produces (T-0402 design.md §3.1): the
 * DECIMAL string becomes a number where `'0.00'` stays `0.0` and never turns
 * into `null` (ticket: null is "not entered", zero is a value), and
 * `createdAt` is always normalized to UTC, because the same instant comes out
 * of the database with the session's UTC offset and would otherwise differ
 * from the string of the freshly created entity (criterion 2).
 */
final class HabitEntryResponseTest extends TestCase
{
    public function testItMapsAllSevenFieldsFromANumericEntry(): void
    {
        $habit = $this->habit();
        $entry = new HabitEntry($habit, new \DateTimeImmutable('2026-09-08'), '7.50', null, 'spät ins Bett, früh raus', new \DateTimeImmutable('2026-09-08T19:04:11+00:00'));

        $response = HabitEntryResponse::fromEntity($entry);

        self::assertSame($entry->getId()->toRfc4122(), $response->id);
        self::assertSame($habit->getId()->toRfc4122(), $response->habitId);
        self::assertSame('2026-09-08', $response->entryDate);
        self::assertSame(7.5, $response->valueNumeric);
        self::assertNull($response->valueBool);
        self::assertSame('spät ins Bett, früh raus', $response->note);
        self::assertSame('2026-09-08T19:04:11+00:00', $response->createdAt);
    }

    public function testItKeepsZeroAsZeroAndNeverAsNull(): void
    {
        // Criterion 3.
        $response = HabitEntryResponse::fromEntity($this->entry('0.00', null));

        self::assertSame(0.0, $response->valueNumeric);
    }

    public function testItMapsAWholeDecimalToAFloat(): void
    {
        self::assertSame(8.0, HabitEntryResponse::fromEntity($this->entry('8.00', null))->valueNumeric);
    }

    public function testItLeavesTheNumberNullForAYesNoEntry(): void
    {
        $response = HabitEntryResponse::fromEntity($this->entry(null, true));

        self::assertNull($response->valueNumeric);
        self::assertTrue($response->valueBool);
    }

    public function testItKeepsNoAsFalseAndNeverAsNull(): void
    {
        self::assertFalse(HabitEntryResponse::fromEntity($this->entry(null, false))->valueBool);
    }

    public function testItLeavesTheNoteNullWhenThereIsNone(): void
    {
        self::assertNull(HabitEntryResponse::fromEntity($this->entry('3.00', null))->note);
    }

    public function testItConvertsAnEntryCreatedWithAnOffsetToUtc(): void
    {
        $entry = new HabitEntry($this->habit(), new \DateTimeImmutable('2026-09-08'), '7.50', null, null, new \DateTimeImmutable('2026-09-08T21:04:11+02:00'));

        self::assertSame('2026-09-08T19:04:11+00:00', HabitEntryResponse::fromEntity($entry)->createdAt);
    }

    public function testItWritesTheSameInstantIdenticallyWhateverItsOffset(): void
    {
        $habit = $this->habit();
        $date = new \DateTimeImmutable('2026-09-08');
        $inUtc = new HabitEntry($habit, $date, '7.50', null, null, new \DateTimeImmutable('2026-09-08T19:04:11+00:00'));
        $inBerlin = new HabitEntry($habit, $date, '7.50', null, null, new \DateTimeImmutable('2026-09-08T21:04:11+02:00', new \DateTimeZone('Europe/Berlin')));

        self::assertSame(
            HabitEntryResponse::fromEntity($inUtc)->createdAt,
            HabitEntryResponse::fromEntity($inBerlin)->createdAt,
        );
    }

    public function testItDeclaresTheFieldsInContractOrder(): void
    {
        $response = HabitEntryResponse::fromEntity($this->entry('7.50', null));

        self::assertSame(
            ['id', 'habitId', 'entryDate', 'valueNumeric', 'valueBool', 'note', 'createdAt'],
            array_keys(get_object_vars($response)),
        );
    }

    public function testItSerializesZeroAndWholeNumbersWithoutADecimalPoint(): void
    {
        // The API answers with JsonResponse, which encodes 0.0 as 0 and 8.0 as 8.
        $zero = json_encode(HabitEntryResponse::fromEntity($this->entry('0.00', null)), \JSON_THROW_ON_ERROR);
        $eight = json_encode(HabitEntryResponse::fromEntity($this->entry('8.00', null)), \JSON_THROW_ON_ERROR);
        $fraction = json_encode(HabitEntryResponse::fromEntity($this->entry('7.50', null)), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"valueNumeric":0,', $zero);
        self::assertStringContainsString('"valueNumeric":8,', $eight);
        self::assertStringContainsString('"valueNumeric":7.5,', $fraction);
    }

    private function entry(?string $valueNumeric, ?bool $valueBool): HabitEntry
    {
        return new HabitEntry($this->habit(), new \DateTimeImmutable('2026-09-08'), $valueNumeric, $valueBool, null, new \DateTimeImmutable('2026-09-08T19:04:11+00:00'));
    }

    private function habit(): Habit
    {
        return new Habit('sleep-duration', 'Schlafdauer', HabitValueType::Duration, 'h', null, null, null, null, 20);
    }
}
