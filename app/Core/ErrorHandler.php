<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\AppException;
use ErrorException;
use Throwable;

/**
 * Turns every uncaught error into a JSON response.
 *
 * The promise the plan makes: a stack trace never reaches the browser. In debug
 * mode the extra *details* are attached as structured JSON — never PHP's HTML
 * error output — and zend.exception_ignore_args is switched on so a stack trace
 * can never carry the database password in the first place.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug = false): void
    {
        self::$debug = $debug;

        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        // Never let exception traces capture argument values.
        ini_set('zend.exception_ignore_args', '1');

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::report($e);
            self::response($e)->send();
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            self::report($exception);
            self::response($exception)->send();
        });
    }

    /**
     * Build the client-facing response for a throwable.
     */
    public static function response(Throwable $e): Response
    {
        $isApp   = $e instanceof AppException;
        $status  = $isApp ? $e->statusCode() : 500;
        $details = $isApp ? $e->details() : [];

        // AppException messages are written for humans and safe to show.
        // Anything else is an unexpected bug: generic message unless debugging.
        $message = $isApp
            ? $e->getMessage()
            : (self::$debug ? $e->getMessage() : 'Something went wrong on our side.');

        if (self::$debug) {
            $details['debug'] = [
                'exception' => $e::class,
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ];
        }

        return Response::error($message, $status, $details)->withoutCache();
    }

    /**
     * Write the real cause (including the driver message) to the log file, which
     * is the only place it is ever allowed to appear.
     */
    public static function report(Throwable $e): void
    {
        $chain = [];
        $cause = $e;

        while ($cause !== null) {
            $chain[] = $cause::class . ': ' . $cause->getMessage();
            $cause   = $cause->getPrevious();
        }

        Logger::error($e->getMessage(), [
            'chain'  => $chain,
            'file'   => $e->getFile() . ':' . $e->getLine(),
            'method' => PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REQUEST_METHOD'] ?? '?'),
            'uri'    => PHP_SAPI === 'cli' ? '' : ($_SERVER['REQUEST_URI'] ?? ''),
            'trace'  => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }
}
