<?php

declare(strict_types=1);

namespace App\Service\Habit;

/**
 * Thrown by App\Service\Habit\HabitDefinitionValidator when the habit catalog
 * is inconsistent. The message is German and names the offending slug, so
 * `app:habits:sync` can print something Konrad can act on.
 */
final class InvalidHabitCatalogException extends \RuntimeException
{
}
