<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\HabitEntry;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Tool factory for the habit entry tickets (T-0402, reused by T-0403).
 *
 * The defaults describe a row that satisfies `chk_habit_entry_value` (exactly
 * one of the two values): a rating of '3.00' on a fresh 1-5 scale habit, no
 * note. `entryDate` is a running sequence starting at 2026-01-01, so the
 * unique index `uniq_habit_entry_habit_date` never breaks when several
 * entries share a habit that a test passes in without a date. `createdAt` is a
 * fixed instant, which keeps assertions on it stable.
 *
 * The named states fix the value columns together, so a test never builds a
 * row the database would refuse:
 *
 *   HabitEntryFactory::new()->boolean()->create(['habit' => $yesNoHabit])
 *   HabitEntryFactory::new()->withNumeric('0.00')->create(['habit' => $habit])
 *
 * The numeric value is always a DECIMAL string ('7.50'), never a float - that
 * is what Doctrine hands out and what `HabitEntry::__construct()` takes.
 * `boolean()` also swaps the default habit for a yes/no habit; an explicit
 * `habit` attribute always wins.
 *
 * Assumes `HabitEntry::__construct()` takes its columns as named parameters
 * (design.md §4.2): habit, entryDate, valueNumeric, valueBool, note,
 * createdAt.
 *
 * @extends PersistentObjectFactory<HabitEntry>
 */
final class HabitEntryFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return HabitEntry::class;
    }

    /**
     * A yes/no entry on a yes/no habit: `valueBool` set, `valueNumeric` null.
     */
    public function boolean(): static
    {
        return $this->with([
            'habit' => HabitFactory::new()->boolean(),
            'valueNumeric' => null,
            'valueBool' => true,
        ]);
    }

    /**
     * A numeric entry with the given DECIMAL string, e.g. '7.50' or '0.00'.
     */
    public function withNumeric(string $valueNumeric): static
    {
        return $this->with(['valueNumeric' => $valueNumeric, 'valueBool' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        // Function-local static, not a class property: Foundry's factory base
        // class is annotated @immutable/@readonly, so PHPStan rejects a static
        // property on it (same reasoning as HabitFactory).
        /** @var int $sequence */
        static $sequence = 0;
        ++$sequence;

        return [
            'habit' => HabitFactory::new(),
            'entryDate' => (new \DateTimeImmutable('2026-01-01'))->modify(\sprintf('+%d days', $sequence)),
            'valueNumeric' => '3.00',
            'valueBool' => null,
            'note' => null,
            'createdAt' => new \DateTimeImmutable('2026-01-01T12:00:00+00:00'),
        ];
    }
}
