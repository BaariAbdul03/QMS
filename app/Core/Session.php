<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened PHP session for the staff area (plan item 5).
 *
 * Cookie flags and strict mode are set BEFORE session_start(); the cookie can
 * only be changed while the session is not active.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (self::$started) {
            return;
        }

        // Reject a session id the server never issued (session fixation).
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');

        session_name(Config::string('security.session_name', 'qms_staff'));

        session_set_cookie_params([
            'lifetime' => 0,                    // browser session only
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isSecureRequest(),
            'httponly' => true,                 // no document.cookie access
            'samesite' => 'Lax',                // no cross-site POST
        ]);

        session_start();
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::start();

        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();

        unset($_SESSION[$key]);
    }

    /**
     * New id, new session, old data dropped. Called on privilege change (login).
     */
    public static function regenerate(): void
    {
        self::start();

        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        self::$started = false;
    }

    /** Used by tests to force a clean in-process session. */
    public static function reset(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        self::$started = false;
    }

    private static function isSecureRequest(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return true;
        }

        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
