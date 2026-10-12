<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Staff authentication. One shared password, verified against a password_hash()
 * stored in .env — never a plain-text password in config (plan item 5).
 *
 * Fails closed: with no hash configured, nobody can log in.
 */
final class Auth
{
    private const SESSION_KEY = 'staff';

    public static function isConfigured(): bool
    {
        return trim(Config::string('security.staff_password_hash', '')) !== '';
    }

    /**
     * Verify a password and, on success, start a fresh authenticated session.
     */
    public static function attempt(string $password): bool
    {
        $hash = trim(Config::string('security.staff_password_hash', ''));

        if ($hash === '') {
            // No credential configured: refuse rather than allow.
            Logger::warning('Staff login attempted but STAFF_PASSWORD_HASH is not set.');

            return false;
        }

        if (!password_verify($password, $hash)) {
            // Constant-ish work either way: password_verify already does that.
            Logger::info('Failed staff login attempt.', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli']);

            return false;
        }

        // Privilege changed: give the client a brand new session id so a
        // pre-login id (fixation) is worthless afterwards.
        Session::regenerate();
        Csrf::rotate();

        Session::put(self::SESSION_KEY, [
            'authenticated_at' => time(),
            'ip'               => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);

        return true;
    }

    public static function check(): bool
    {
        $staff = Session::get(self::SESSION_KEY);

        return is_array($staff) && isset($staff['authenticated_at']);
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function authenticatedAt(): ?int
    {
        $staff = Session::get(self::SESSION_KEY);

        return is_array($staff) ? (int) ($staff['authenticated_at'] ?? 0) ?: null : null;
    }

    /**
     * Guard for the staff JSON API. Returns 401 instead of running the action.
     */
    public static function middleware(): callable
    {
        return static function (): ?Response {
            if (!self::check()) {
                throw HttpException::unauthorized('Staff login required.');
            }

            return null;
        };
    }

    /**
     * Guard for staff pages: bounce to the login form, remembering where the
     * user was trying to go.
     */
    public static function requirePage(Request $request): ?Response
    {
        if (!self::check()) {
            return Response::redirect('/staff/login?next=' . rawurlencode($request->path()));
        }

        return null;
    }
}
