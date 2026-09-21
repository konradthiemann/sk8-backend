<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Lets an exception bring its own machine-readable `error` code. Without it,
 * App\EventListener\ApiExceptionListener derives the code from the HTTP status
 * alone, so every 404 would read `not_found`.
 */
interface ProvidesApiErrorCode
{
    /**
     * @return non-empty-string snake_case code, e.g. `habit_not_found`
     */
    public function getApiErrorCode(): string;
}
