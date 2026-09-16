<?php

declare(strict_types=1);

namespace App\Service\Training;

/**
 * Thrown by App\Service\Training\ExerciseCatalogFile when
 * config/data/exercises.json (or any other catalog file) fails validation:
 * a duplicate slug, an unknown equipment/kneeLoad/measure value, or an empty
 * name (T-0301 design.md §4, acceptance criteria 10/11). Always carries a
 * message naming the offending slug or value so `app:exercise:sync` can
 * print a message Konrad can act on.
 */
final class InvalidExerciseCatalogException extends \RuntimeException
{
}
