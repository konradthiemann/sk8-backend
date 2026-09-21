<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Habit;

use App\Service\Habit\HabitDefinition;
use App\Service\Habit\HabitDefinitionProviderInterface;

/**
 * Feeds a test-owned definition list into the synchronizer, so its tests do
 * not depend on the real R-04 catalog (design.md §4.3).
 */
final readonly class InMemoryHabitDefinitionProvider implements HabitDefinitionProviderInterface
{
    /**
     * @param list<HabitDefinition> $definitions
     */
    public function __construct(private array $definitions)
    {
    }

    public function definitions(): array
    {
        return $this->definitions;
    }
}
