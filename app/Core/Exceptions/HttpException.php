<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Generic "this request is wrong" exception with named constructors for the
 * status codes the API actually uses.
 */
final class HttpException extends AppException
{
    public static function badRequest(string $message = 'Bad request.', array $details = []): self
    {
        return new self($message, 400, $details);
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Not allowed.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self($message, 404);
    }

    /**
     * Illegal state transition: the row was not in the status we expected.
     */
    public static function conflict(string $message = 'That action is no longer possible.', array $details = []): self
    {
        return new self($message, 409, $details);
    }

    public static function unprocessable(string $message = 'Validation failed.', array $details = []): self
    {
        return new self($message, 422, $details);
    }

    public static function tooManyRequests(string $message = 'Too many requests, slow down.', array $details = []): self
    {
        return new self($message, 429, $details);
    }

    public static function methodNotAllowed(string $message = 'Method not allowed.'): self
    {
        return new self($message, 405);
    }
}
