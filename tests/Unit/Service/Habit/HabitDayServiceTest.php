<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Dto\Habit\HabitDayItem;
use App\Entity\Habit;
use App\Entity\HabitEntry;
use App\Enum\HabitValueType;
use App\Exception\FieldViolationsException;
use App\Service\Habit\EntryDateRules;
use App\Service\Habit\FieldViolation;
use App\Service\Habit\HabitDayService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Pure object test, no kernel, no database (T-0402 design.md §4.2). The reader
 * is the in-memory double next to this file; the date rules and the clock are
 * real, the clock is a `MockClock`. Covers the service side of criteria 14-17:
 * which day is asked for, and that a rejected day never reaches the reader.
 *
 * The clock reads 2026-09-08 19:04:11 UTC (21:04 in Europe/Berlin), so
 * "today" is 2026-09-08.
 */
final class HabitDayServiceTest extends TestCase
{
    public function testItAnswersTodayWhenNoDateIsGiven(): void
    {
        $reader = new InMemoryHabitReader();

        $response = $this->service($reader)->forDate(null);

        self::assertSame('2026-09-08', $response->date);
        self::assertSame(1, $reader->dayReadCount);
    }

    public function testItAnswersTodayWhenTheDateIsAnEmptyString(): void
    {
        $response = $this->service(new InMemoryHabitReader())->forDate('');

        self::assertSame('2026-09-08', $response->date);
    }

    public function testItAnswersTheRequestedPastDay(): void
    {
        $response = $this->service(new InMemoryHabitReader())->forDate('2026-09-01');

        self::assertSame('2026-09-01', $response->date);
    }

    public function testItAcceptsTodayAndTheEarliestEntryDate(): void
    {
        self::assertSame('2026-09-08', $this->service(new InMemoryHabitReader())->forDate('2026-09-08')->date);
        self::assertSame('2026-01-01', $this->service(new InMemoryHabitReader())->forDate('2026-01-01')->date);
    }

    public function testItCountsActiveHabitsAndTheOnesWithAnEntryOfThatDay(): void
    {
        // Criterion 14, with the inactive habit and the entry of another day left out.
        $knee = $this->scale('knee-pain', 10);
        $mood = $this->scale('mood', 20);
        $stress = $this->scale('stress', 30);
        $retired = $this->scale('retired', 5, isActive: false);
        $reader = new InMemoryHabitReader(
            [$stress, $retired, $knee, $mood],
            [
                $this->entry($knee, '3.00', '2026-09-08'),
                $this->entry($retired, '2.00', '2026-09-08'),
                $this->entry($mood, '4.00', '2026-09-07'),
            ],
        );

        $response = $this->service($reader)->forDate('2026-09-08');

        self::assertSame(3, $response->totalCount);
        self::assertSame(1, $response->completedCount);
        self::assertSame(
            ['knee-pain', 'mood', 'stress'],
            array_map(static fn (HabitDayItem $item): string => $item->habit->slug, $response->items),
        );
    }

    public function testItShowsTheEntryOfTheRequestedDayOnly(): void
    {
        $mood = $this->scale('mood', 20);
        $reader = new InMemoryHabitReader([$mood], [$this->entry($mood, '4.00', '2026-09-07')]);
        $service = $this->service($reader);

        self::assertNotNull($service->forDate('2026-09-07')->items[0]->entry);
        self::assertNull($service->forDate('2026-09-08')->items[0]->entry);
    }

    public function testItAnswersAnEmptyCatalogWithAnEmptyList(): void
    {
        // Criterion 16.
        $response = $this->service(new InMemoryHabitReader())->forDate(null);

        self::assertSame(0, $response->totalCount);
        self::assertSame(0, $response->completedCount);
        self::assertSame([], $response->items);
    }

    /**
     * @param array{string, string} $expected [field, messageKey]
     */
    #[DataProvider('rejectedDates')]
    public function testItRejectsADateThatIsNotAllowedWithoutReadingAnything(string $rawDate, array $expected): void
    {
        $reader = new InMemoryHabitReader();

        try {
            $this->service($reader)->forDate($rawDate);
            self::fail('expected FieldViolationsException');
        } catch (FieldViolationsException $exception) {
            self::assertSame(
                [$expected],
                array_map(
                    static fn (FieldViolation $violation): array => [$violation->field, $violation->messageKey],
                    $exception->getFieldViolations(),
                ),
            );
            self::assertSame(0, $reader->dayReadCount);
        }
    }

    /**
     * @return array<string, array{string, array{string, string}}>
     */
    public static function rejectedDates(): array
    {
        return [
            // Criterion 17.
            'tomorrow' => ['2026-09-09', ['date', 'habit_entry.date.future']],
            'far future' => ['2027-01-01', ['date', 'habit_entry.date.future']],
            'before the project start' => ['2025-12-31', ['date', 'habit_entry.date.before_start']],
            'not a calendar date' => ['2026-02-30', ['date', 'habit_entry.date.invalid']],
            'unpadded' => ['2026-9-8', ['date', 'habit_entry.date.invalid']],
            'words' => ['abc', ['date', 'habit_entry.date.invalid']],
            'trailing space' => ['2026-09-08 ', ['date', 'habit_entry.date.invalid']],
        ];
    }

    private function service(InMemoryHabitReader $reader): HabitDayService
    {
        return new HabitDayService(
            $reader,
            new EntryDateRules(new MockClock('2026-09-08 19:04:11', 'UTC'), 'Europe/Berlin'),
            new IdentityTranslator(),
        );
    }

    private function scale(string $slug, int $sortOrder, bool $isActive = true): Habit
    {
        return new Habit($slug, ucfirst($slug), HabitValueType::Scale, null, 0, 10, null, null, $sortOrder, $isActive);
    }

    private function entry(Habit $habit, string $valueNumeric, string $date): HabitEntry
    {
        return new HabitEntry($habit, new \DateTimeImmutable($date), $valueNumeric, null, null, new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
    }
}
