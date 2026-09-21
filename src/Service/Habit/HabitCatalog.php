<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;

/**
 * The habit catalog as typed code: the seven habits of R-04 (T-0401 design.md
 * §4.2). `sleep-regularity` is deliberately absent (T-0403 derives it from
 * `sleep-duration`), as are alcohol and cannabis (`substance_entry`).
 *
 * Changing a row here changes nothing in the database until
 * `app:habits:sync` runs. Changing a scale or value type of an existing habit
 * breaks its history - add a new habit instead (R-04 §5.2).
 */
final class HabitCatalog implements HabitDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            new HabitDefinition(
                slug: 'knee-pain',
                name: 'Knieschmerz',
                valueType: HabitValueType::Scale,
                sortOrder: 10,
                scaleMin: 0,
                scaleMax: 10,
                targetDirection: HabitTargetDirection::Low,
            ),
            new HabitDefinition(
                slug: 'sleep-duration',
                name: 'Schlafdauer',
                valueType: HabitValueType::Duration,
                sortOrder: 20,
                unit: 'h',
                targetDirection: HabitTargetDirection::High,
                targetValue: 8.0,
            ),
            new HabitDefinition(
                slug: 'sleep-quality',
                name: 'Schlafqualität',
                valueType: HabitValueType::Scale,
                sortOrder: 30,
                scaleMin: 1,
                scaleMax: 5,
                targetDirection: HabitTargetDirection::High,
            ),
            new HabitDefinition(
                slug: 'stress',
                name: 'Stress',
                valueType: HabitValueType::Scale,
                sortOrder: 40,
                scaleMin: 1,
                scaleMax: 5,
                targetDirection: HabitTargetDirection::Low,
            ),
            new HabitDefinition(
                slug: 'mood',
                name: 'Stimmung',
                valueType: HabitValueType::Scale,
                sortOrder: 50,
                scaleMin: 1,
                scaleMax: 5,
                targetDirection: HabitTargetDirection::High,
            ),
            new HabitDefinition(
                slug: 'recovery-readiness',
                name: 'Erholung',
                valueType: HabitValueType::Scale,
                sortOrder: 60,
                scaleMin: 1,
                scaleMax: 5,
                targetDirection: HabitTargetDirection::High,
            ),
            new HabitDefinition(
                slug: 'mobility-stretch',
                name: 'Beweglichkeit',
                valueType: HabitValueType::Boolean,
                sortOrder: 70,
                targetDirection: HabitTargetDirection::High,
            ),
        ];
    }
}
