<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Habit;

use App\Dto\Habit\HabitDayResponse;
use App\Dto\Habit\HabitEntryResponse;
use App\Entity\Habit;
use App\Entity\HabitEntry;
use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Repository\HabitDayRow;
use PHPUnit\Framework\TestCase;

/**
 * Pure mapping test: no kernel, no database. Checks what
 * `HabitDayResponse::fromRows()` derives from the repository rows (T-0402
 * design.md §3.3): `totalCount` is the number of active habits, `completedCount`
 * the number with an entry - and an entry with the value `0` (or `false`) is
 * an entry, so it counts (criterion 3).
 */
final class HabitDayResponseTest extends TestCase
{
    public function testItCountsTotalAndCompletedHabits(): void
    {
        $rows = [
            $this->rowWithEntry($this->scale('knee-pain', 10), '3.00', null),
            $this->rowWithoutEntry($this->scale('mood', 20)),
            $this->rowWithEntry($this->scale('stress', 30), '2.00', null),
        ];

        $response = HabitDayResponse::fromRows('2026-09-08', $rows);

        self::assertSame(3, $response->totalCount);
        self::assertSame(2, $response->completedCount);
        self::assertCount(3, $response->items);
    }

    public function testItCountsAnEntryWithTheValueZeroAsCompleted(): void
    {
        // Criterion 3: zero is a value, "not entered" means there is no entry.
        $response = HabitDayResponse::fromRows('2026-09-08', [$this->rowWithEntry($this->scale('knee-pain', 10), '0.00', null)]);

        self::assertSame(1, $response->completedCount);
    }

    public function testItCountsAnEntryWithNoAsCompleted(): void
    {
        $response = HabitDayResponse::fromRows('2026-09-08', [$this->rowWithEntry($this->boolean('mobility-stretch', 10), null, false)]);

        self::assertSame(1, $response->completedCount);
    }

    public function testItCountsNothingAsCompletedWhenNoHabitHasAnEntry(): void
    {
        $response = HabitDayResponse::fromRows('2026-09-08', [
            $this->rowWithoutEntry($this->scale('knee-pain', 10)),
            $this->rowWithoutEntry($this->scale('mood', 20)),
        ]);

        self::assertSame(2, $response->totalCount);
        self::assertSame(0, $response->completedCount);
    }

    public function testItAnswersAnEmptyCatalogWithAnEmptyList(): void
    {
        // Criterion 16.
        $response = HabitDayResponse::fromRows('2026-09-08', []);

        self::assertSame('2026-09-08', $response->date);
        self::assertSame(0, $response->totalCount);
        self::assertSame(0, $response->completedCount);
        self::assertSame([], $response->items);
    }

    public function testItKeepsTheOrderOfTheRowsAndPairsEachHabitWithItsEntry(): void
    {
        $kneePain = $this->scale('knee-pain', 10);
        $mood = $this->scale('mood', 20);
        $rows = [$this->rowWithoutEntry($kneePain), $this->rowWithEntry($mood, '4.00', null)];

        $response = HabitDayResponse::fromRows('2026-09-08', $rows);

        [$first, $second] = $response->items;
        self::assertSame('knee-pain', $first->habit->slug);
        self::assertNull($first->entry);
        self::assertSame('mood', $second->habit->slug);
        self::assertInstanceOf(HabitEntryResponse::class, $second->entry);
        self::assertSame(4.0, $second->entry->valueNumeric);
        self::assertSame($mood->getId()->toRfc4122(), $second->entry->habitId);
    }

    public function testItDeclaresTheFieldsInContractOrder(): void
    {
        $response = HabitDayResponse::fromRows('2026-09-08', [$this->rowWithoutEntry($this->scale('mood', 20))]);

        self::assertSame(['date', 'totalCount', 'completedCount', 'items'], array_keys(get_object_vars($response)));
        self::assertSame(['habit', 'entry'], array_keys(get_object_vars($response->items[0])));
    }

    public function testItSerializesAMissingEntryAsNull(): void
    {
        $response = HabitDayResponse::fromRows('2026-09-08', [$this->rowWithoutEntry($this->scale('mood', 20))]);

        self::assertStringContainsString('"entry":null', json_encode($response, \JSON_THROW_ON_ERROR));
    }

    private function rowWithEntry(Habit $habit, ?string $valueNumeric, ?bool $valueBool): HabitDayRow
    {
        $entry = new HabitEntry($habit, new \DateTimeImmutable('2026-09-08'), $valueNumeric, $valueBool, null, new \DateTimeImmutable('2026-09-08T19:04:11+00:00'));

        return new HabitDayRow($habit, $entry);
    }

    private function rowWithoutEntry(Habit $habit): HabitDayRow
    {
        return new HabitDayRow($habit, null);
    }

    private function scale(string $slug, int $sortOrder): Habit
    {
        return new Habit($slug, ucfirst($slug), HabitValueType::Scale, null, 0, 10, HabitTargetDirection::Low, null, $sortOrder);
    }

    private function boolean(string $slug, int $sortOrder): Habit
    {
        return new Habit($slug, ucfirst($slug), HabitValueType::Boolean, null, null, null, HabitTargetDirection::High, null, $sortOrder);
    }
}
