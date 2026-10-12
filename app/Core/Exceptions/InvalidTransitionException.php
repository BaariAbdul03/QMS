<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * A guarded UPDATE matched no row: the token was not in the status the action
 * required. Maps to HTTP 409 (plan section 4, \"State machine\").
 */
final class InvalidTransitionException extends AppException
{
    /**
     * @param string[] $expected
     */
    public static function for(string $action, array $expected, string $actual): self
    {
        return new self(
            sprintf(
                'Cannot %s: this token is "%s", the action needs "%s".',
                $action,
                $actual,
                implode('" or "', $expected),
            ),
            409,
            [
                'action'   => $action,
                'expected' => array_values($expected),
                'actual'   => $actual,
            ],
        );
    }
}
