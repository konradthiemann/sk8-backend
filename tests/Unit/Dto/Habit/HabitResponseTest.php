<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Habit;

use App\Dto\Habit\HabitResponse;
use App\Entity\Habit;
use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure mapping test: no kernel, no database. Checks what
 * `HabitResponse::fromEntity()` produces, above all the DECIMAL string ->
 * number step of criterion 10 (design.md §3.2: `'8.00'` becomes `8.0`, which
 * `json_encode` writes as `8`).
 */
final class HabitResponseTest extends TestCase
{
    public function testItMapsAllElevenFieldsFromADurationHabit(): void
    {
        $habit = new Habit('sleep-duration', 'Schlafdauer', HabitValueType::Duration, 'h', null, null, HabitTargetDirection::High, '8.00', 20);

        $response = HabitResponse::fromEntity($habit);

        self::assertSame($habit->getId()->toRfc4122(), $response->id);
        self::assertSame('sleep-duration', $response->slug);
        self::assertSame('Schlafdauer', $response->name);
        self::assertSame('duration', $response->valueType);
        self::assertSame('h', $response->unit);
        self::assertNull($response->scaleMin);
        self::assertNull($response->scaleMax);
        self::assertSame('hoch', $response->targetDirection);
        self::assertSame(8.0, $response->targetValue);
        self::assertSame(20, $response->sortOrder);
        self::assertTrue($response->isActive);
    }

    public function testItMapsAScaleHabitWithBoundsAndNoUnitOrTarget(): void
    {
        // Criterion 9.
        $habit = new Habit('knee-pain', 'Knieschmerz', HabitValueType::Scale, null, 0, 10, HabitTargetDirection::Low, null, 10);

        $response = HabitResponse::fromEntity($habit);

        self::assertSame('scale', $response->valueType);
        self::assertSame(0, $response->scaleMin);
        self::assertSame(10, $response->scaleMax);
        self::assertNull($response->unit);
        self::assertSame('niedrig', $response->targetDirection);
        self::assertNull($response->targetValue);
    }

    public function testItLeavesDirectionAndTargetNullWhenTheHabitHasNone(): void
    {
        $habit = new Habit('journal', 'Tagebuch', HabitValueType::Boolean, null, null, null, null, null, 80);

        $response = HabitResponse::fromEntity($habit);

        self::assertSame('boolean', $response->valueType);
        self::assertNull($response->targetDirection);
        self::assertNull($response->targetValue);
    }

    public function testItMapsAnInactiveHabit(): void
    {
        $habit = new Habit('retired', 'Alt', HabitValueType::Boolean, null, null, null, null, null, 90, false);

        self::assertFalse(HabitResponse::fromEntity($habit)->isActive);
    }

    #[DataProvider('decimalStrings')]
    public function testItConvertsTheDecimalStringToAFloat(string $decimal, float $expected): void
    {
        // Criterion 10: the string never reaches the client.
        $habit = new Habit('water', 'Wasser', HabitValueType::Number, 'Glas', null, null, HabitTargetDirection::High, $decimal, 30);

        $targetValue = HabitResponse::fromEntity($habit)->targetValue;

        self::assertIsFloat($targetValue);
        self::assertSame($expected, $targetValue);
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function decimalStrings(): array
    {
        return [
            'whole number' => ['8.00', 8.0],
            'half' => ['2.50', 2.5],
            'two decimals' => ['7.25', 7.25],
            'zero' => ['0.00', 0.0],
        ];
    }

    public function testItEncodesAWholeTargetAsAJsonNumberWithoutQuotes(): void
    {
        // Criterion 10 at the JSON level: 8.0 is written as 8 (no
        // JSON_PRESERVE_ZERO_FRACTION), never as "8.00".
        $habit = new Habit('sleep-duration', 'Schlafdauer', HabitValueType::Duration, 'h', null, null, HabitTargetDirection::High, '8.00', 20);

        $json = json_encode(HabitResponse::fromEntity($habit), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"targetValue":8,', $json);
        self::assertStringNotContainsString('"8.00"', $json);
    }

    public function testItSerializesTheElevenFieldsInTheContractOrder(): void
    {
        $habit = new Habit('sleep-duration', 'Schlafdauer', HabitValueType::Duration, 'h', null, null, HabitTargetDirection::High, '8.00', 20);

        $decoded = json_decode(json_encode(HabitResponse::fromEntity($habit), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame(
            ['id', 'slug', 'name', 'valueType', 'unit', 'scaleMin', 'scaleMax', 'targetDirection', 'targetValue', 'sortOrder', 'isActive'],
            array_keys($decoded),
        );
    }
}
