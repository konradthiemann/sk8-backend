<?php

declare(strict_types=1);

namespace App\Service\Training;

use App\Enum\Equipment;
use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;

/**
 * Reads and validates a training exercise catalog file (in production,
 * config/data/exercises.json), independently of Doctrine or the kernel
 * (T-0301 design.md §4). Used by both App\Command\SyncExercisesCommand and
 * this class's own unit tests.
 *
 * The file path is a method argument rather than a constructor dependency
 * (tests.md, "Dokumentierte Annahmen"): the sequence diagram already spells
 * `load(config/data/exercises.json)` this way, and it keeps the service
 * dependency-free so a throwaway fixture file can stand in for the real
 * catalog in every validation test.
 */
final class ExerciseCatalogFile
{
    /**
     * @return list<ExerciseCatalogEntry>
     */
    public function load(string $path): array
    {
        $rows = $this->decode($path);

        $entries = [];
        $seenSlugs = [];

        foreach ($rows as $row) {
            $slug = $this->requireString($row, 'slug', 'unknown');

            if (isset($seenSlugs[$slug])) {
                throw new InvalidExerciseCatalogException(\sprintf('Doppelter Slug "%s" in der Katalogdatei.', $slug));
            }
            $seenSlugs[$slug] = true;

            $entries[] = $this->mapEntry($slug, $row);
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(string $path): array
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new InvalidExerciseCatalogException(\sprintf('Katalogdatei "%s" konnte nicht gelesen werden.', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidExerciseCatalogException(\sprintf('Katalogdatei "%s" enthaelt kein gueltiges JSON: %s', $path, $exception->getMessage()), previous: $exception);
        }

        if (!\is_array($decoded)) {
            throw new InvalidExerciseCatalogException(\sprintf('Katalogdatei "%s" muss eine JSON-Liste von Uebungen enthalten.', $path));
        }

        $rows = [];
        foreach ($decoded as $item) {
            if (!\is_array($item)) {
                throw new InvalidExerciseCatalogException(\sprintf('Katalogdatei "%s" enthaelt einen Eintrag, der kein Objekt ist.', $path));
            }

            /** @var array<string, mixed> $item */
            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapEntry(string $slug, array $row): ExerciseCatalogEntry
    {
        $name = $this->requireString($row, 'name', $slug);
        if ('' === trim($name)) {
            throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat einen leeren Namen.', $slug));
        }

        $equipmentValue = $this->requireString($row, 'equipment', $slug);
        $equipment = Equipment::tryFrom($equipmentValue);
        if (null === $equipment) {
            throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat einen unbekannten equipment-Wert "%s".', $slug, $equipmentValue));
        }

        $kneeLoadValue = $this->requireString($row, 'kneeLoad', $slug);
        $kneeLoad = KneeLoad::tryFrom($kneeLoadValue);
        if (null === $kneeLoad) {
            throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat einen unbekannten kneeLoad-Wert "%s".', $slug, $kneeLoadValue));
        }

        $measureValue = $this->requireString($row, 'measure', $slug);
        $measure = ExerciseMeasure::tryFrom($measureValue);
        if (null === $measure) {
            throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat einen unbekannten measure-Wert "%s".', $slug, $measureValue));
        }

        return new ExerciseCatalogEntry(
            slug: $slug,
            name: $name,
            equipment: $equipment,
            muscleGroups: $this->requireStringList($row, 'muscleGroups', $slug),
            kneeLoad: $kneeLoad,
            measure: $measure,
            description: $this->optionalString($row, 'description', $slug),
            isPrevention: $this->requireBool($row, 'isPrevention', $slug),
            kneeLoadReason: $this->requireString($row, 'kneeLoadReason', $slug),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireString(array $row, string $key, string $slug): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat kein gueltiges Feld "%s".', $slug, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function optionalString(array $row, string $key, string $slug): ?string
    {
        $value = $row[$key] ?? null;
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat kein gueltiges Feld "%s".', $slug, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireBool(array $row, string $key, string $slug): bool
    {
        $value = $row[$key] ?? null;
        if (!\is_bool($value)) {
            throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat kein gueltiges Feld "%s".', $slug, $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function requireStringList(array $row, string $key, string $slug): array
    {
        $value = $row[$key] ?? null;
        if (!\is_array($value)) {
            throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat kein gueltiges Feld "%s".', $slug, $key));
        }

        $list = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new InvalidExerciseCatalogException(\sprintf('Uebung "%s" hat kein gueltiges Feld "%s".', $slug, $key));
            }

            $list[] = $item;
        }

        return $list;
    }
}
