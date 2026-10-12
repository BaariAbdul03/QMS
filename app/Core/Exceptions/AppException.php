<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every exception that maps to an HTTP response.
 *
 * $message is safe to show to a client; wrap a $previous exception when the
 * underlying cause must stay out of the response body.
 */
class AppException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        /** @var array<string, mixed> */
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
