<?php

declare(strict_types=1);

namespace App\Service\Habit;

use App\Enum\HabitValueType;

/**
 * Checks a habit definition list for consistency before anything is read from
 * or written to the database. Mirrors `chk_habit_scale`, `chk_habit_target`
 * and `chk_habit_sort_order` and is stricter than `chk_habit_duration_unit`
 * (a blank unit is rejected too), so a violation never first shows up as a SQL
 * error. Content rules of the R-04 catalog itself (seven rows, unique sort
 * order) are not checked here, they live in the catalog test.
 */
final class HabitDefinitionValidator
{
    private const string SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @param list<HabitDefinition> $definitions
     *
     * @throws InvalidHabitCatalogException on the first violation; the message names the slug
     */
    public function validate(array $definitions): void
    {
        if ([] === $definitions) {
            throw new InvalidHabitCatalogException('Der Gewohnheiten-Katalog ist leer; ein Abgleich würde alle Gewohnheiten deaktivieren.');
        }

        $seen = [];
        foreach ($definitions as $definition) {
            $slug = $definition->slug;

            if (1 !== preg_match(self::SLUG_PATTERN, $slug)) {
                throw new InvalidHabitCatalogException(\sprintf('Ungültiger Slug "%s": nur Kleinbuchstaben, Ziffern und einzelne Bindestriche erlaubt.', $slug));
            }

            if (isset($seen[$slug])) {
                throw new InvalidHabitCatalogException(\sprintf('Doppelter Slug "%s" im Gewohnheiten-Katalog.', $slug));
            }
            $seen[$slug] = true;

            $this->validateDefinition($definition);
        }
    }

    private function validateDefinition(HabitDefinition $definition): void
    {
        $slug = $definition->slug;

        if ('' === trim($definition->name)) {
            throw new InvalidHabitCatalogException(\sprintf('Gewohnheit "%s" hat keinen Namen.', $slug));
        }

        if (HabitValueType::Scale === $definition->valueType) {
            if (null === $definition->scaleMin || null === $definition->scaleMax) {
                throw new InvalidHabitCatalogException(\sprintf('Gewohnheit "%s" ist eine Skala und braucht scaleMin und scaleMax.', $slug));
            }

            if ($definition->scaleMin >= $definition->scaleMax) {
                throw new InvalidHabitCatalogException(\sprintf('Gewohnheit "%s": scaleMin muss kleiner als scaleMax sein.', $slug));
            }
        } elseif (null !== $definition->scaleMin || null !== $definition->scaleMax) {
            throw new InvalidHabitCatalogException(\sprintf('Gewohnheit "%s" ist keine Skala und darf keine Skalengrenzen haben.', $slug));
        }

        if (HabitValueType::Duration === $definition->valueType && '' === trim($definition->unit ?? '')) {
            throw new InvalidHabitCatalogException(\sprintf('Gewohnheit "%s" ist eine Dauer und braucht eine Einheit (unit).', $slug));
        }

        if (null !== $definition->targetValue && null === $definition->targetDirection) {
            throw new InvalidHabitCatalogException(\sprintf('Gewohnheit "%s" hat einen Zielwert, aber keine Zielrichtung.', $slug));
        }

        if ($definition->sortOrder < 0) {
            throw new InvalidHabitCatalogException(\sprintf('Gewohnheit "%s" hat eine negative Sortierung.', $slug));
        }
    }
}
