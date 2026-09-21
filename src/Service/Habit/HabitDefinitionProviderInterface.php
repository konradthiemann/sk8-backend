<?php

declare(strict_types=1);

namespace App\Service\Habit;

/**
 * Seam between App\Service\Habit\HabitCatalogSynchronizer and the catalog, so
 * tests can feed their own definitions instead of the real R-04 rows.
 */
interface HabitDefinitionProviderInterface
{
    /**
     * @return list<HabitDefinition>
     */
    public function definitions(): array;
}
