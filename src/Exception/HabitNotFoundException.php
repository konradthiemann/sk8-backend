<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A habit ID that does not exist (or, for writes, is deactivated): 404 with the
 * error code `habit_not_found`.
 */
final class HabitNotFoundException extends NotFoundHttpException implements ProvidesApiErrorCode
{
    public function __construct()
    {
        parent::__construct('Habit not found.');
    }

    public function getApiErrorCode(): string
    {
        return 'habit_not_found';
    }
}
