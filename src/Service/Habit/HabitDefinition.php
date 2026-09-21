<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Enum\HabitTargetDirection;
use App\Enum\HabitValueType;

/**
 * One row of the typed habit catalog: the desired state that
 * App\Service\Habit\HabitCatalogSynchronizer mirrors into the `habit` table.
 * There is deliberately no `isActive` flag - being in the list means active,
 * a slug missing from the list is deactivated by the sync (T-0401 design.md
 * §10/A3).
 */
final readonly class HabitDefinition
{
    public function __construct(
        public string $slug,
        public string $name,
        public HabitValueType $valueType,
        public int $sortOrder,
        public ?string $unit = null,
        public ?int $scaleMin = null,
        public ?int $scaleMax = null,
        public ?HabitTargetDirection $targetDirection = null,
        public ?float $targetValue = null,
    ) {
    }

    /**
     * The target as the two-decimal string the DECIMAL(8,2) column stores
     * (8.0 becomes '8.00'), so definition and row compare without float noise.
     */
    public function targetValueAsDecimal(): ?string
    {
        if (null === $this->targetValue) {
            return null;
        }

        return number_format($this->targetValue, 2, '.', '');
    }
}
