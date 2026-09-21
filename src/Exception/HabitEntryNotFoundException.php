<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * There is no entry for this habit on this day: 404 with the error code
 * `habit_entry_not_found`.
 */
final class HabitEntryNotFoundException extends NotFoundHttpException implements ProvidesApiErrorCode
{
    public function __construct()
    {
        parent::__construct('Habit entry not found.');
    }

    public function getApiErrorCode(): string
    {
        return 'habit_entry_not_found';
    }
}
