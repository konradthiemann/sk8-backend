<?php

declare(strict_types=1);

namespace App\Service\Training;

use App\Enum\Equipment;
use App\Enum\ExerciseMeasure;
use App\Enum\KneeLoad;

/**
 * One validated row of config/data/exercises.json, typed and mapped from raw
 * JSON by App\Service\Training\ExerciseCatalogFile (T-0301 design.md §4).
 * `kneeLoadReason` documents the knee-load classification for the catalog
 * file itself - it has no column on App\Entity\Exercise.
 */
final readonly class ExerciseCatalogEntry
{
    /**
     * @param list<string> $muscleGroups
     */
    public function __construct(
        public string $slug,
        public string $name,
        public Equipment $equipment,
        public array $muscleGroups,
        public KneeLoad $kneeLoad,
        public ExerciseMeasure $measure,
        public ?string $description,
        public bool $isPrevention,
        public string $kneeLoadReason,
    ) {
    }
}
