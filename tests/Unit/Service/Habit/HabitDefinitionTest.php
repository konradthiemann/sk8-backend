<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;
use App\Service\Habit\HabitDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure value object, no kernel. Covers the one piece of behaviour
 * HabitDefinition has (design.md §4.2): rendering the float target as the
 * two-decimal string the DECIMAL(8,2) column stores and the synchronizer
 * compares against.
 */
final class HabitDefinitionTest extends TestCase
{
    public function testItReturnsNullAsTheDecimalWhenThereIsNoTargetValue(): void
    {
        $definition = new HabitDefinition(slug: 'mood', name: 'Stimmung', valueType: HabitValueType::Scale, sortOrder: 50, scaleMin: 1, scaleMax: 5);

        self::assertNull($definition->targetValueAsDecimal());
    }

    #[DataProvider('decimalRepresentations')]
    public function testItRendersTheTargetValueWithExactlyTwoDecimals(float $targetValue, string $expected): void
    {
        $definition = new HabitDefinition(
            slug: 'sleep-duration',
            name: 'Schlafdauer',
            valueType: HabitValueType::Duration,
            sortOrder: 20,
            unit: 'h',
            targetDirection: HabitTargetDirection::High,
            targetValue: $targetValue,
        );

        self::assertSame($expected, $definition->targetValueAsDecimal());
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function decimalRepresentations(): array
    {
        return [
            'whole number' => [8.0, '8.00'],
            'half' => [2.5, '2.50'],
            'two decimals' => [7.25, '7.25'],
            'zero' => [0.0, '0.00'],
            'thousands stay unseparated' => [1234.5, '1234.50'],
        ];
    }
}
