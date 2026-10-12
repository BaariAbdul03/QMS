<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use Throwable;

/**
 * Infrastructure failure. Mapped to 503 and never leaks credentials or the
 * driver message to the client (the real error is logged instead).
 */
final class DatabaseException extends AppException
{
    public function __construct(string $message = 'Database is unavailable.', ?Throwable $previous = null)
    {
        parent::__construct($message, 503, [], $previous);
    }
}
