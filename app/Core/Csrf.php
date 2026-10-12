<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Per-session CSRF token, required on every staff POST (plan item 5).
 *
 * Sent either as the X-CSRF-Token header (the panel's fetch calls) or as a
 * `_token` form field (the login form), and compared with hash_equals().
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public const HEADER = 'X-CSRF-Token';

    public static function token(): string
    {
        Session::start();

        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Issue a new token. Called after login so a token minted before
     * authentication cannot be replayed with the new session.
     */
    public static function rotate(): void
    {
        Session::put(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }

    public static function validate(?string $candidate): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '' || $candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    public static function fromRequest(Request $request): ?string
    {
        $header = $request->header(self::HEADER);

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $field = $request->input('_token');

        return is_scalar($field) ? (string) $field : null;
    }

    /**
     * Middleware for state-changing routes. Unsafe methods only.
     */
    public static function middleware(): callable
    {
        return static function (Request $request): ?Response {
            if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                return null;
            }

            if (!self::validate(self::fromRequest($request))) {
                throw HttpException::forbidden('This request is missing a valid CSRF token. Reload the page and try again.');
            }

            return null;
        };
    }

    /**
     * Guard for HTML form submissions: a human should get the form back with an
     * explanation, not a JSON 403. The usual cause is an expired session — the
     * token lives in the session, so both disappear together.
     */
    public static function formMiddleware(string $failureRedirect = '/staff/login'): callable
    {
        return static function (Request $request) use ($failureRedirect): ?Response {
            if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                return null;
            }

            if (self::validate(self::fromRequest($request))) {
                return null;
            }

            Logger::info('CSRF rejected a form submission.', ['path' => $request->path()]);

            return Response::redirect($failureRedirect . '?expired=1');
        };
    }
}
