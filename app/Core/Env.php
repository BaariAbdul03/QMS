<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Minimal .env loader. No Composer, no dependencies.
 *
 * Supported syntax: KEY=value, KEY="quoted value", KEY='single', blank lines,
 * # comments, and an optional `export ` prefix. A trailing " # comment" is
 * stripped only when the value is unquoted.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $vars = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            // In cloud container platforms (e.g. Render, Docker), environment
            // variables are injected directly via the process (getenv).
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException(sprintf('Could not read environment file: %s', $path));
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');

            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($key === '') {
                continue;
            }

            self::$vars[$key] = self::normaliseValue($value);
        }

        self::$loaded = true;
    }

    /**
     * Seed values directly. Used by CLI scripts and tests.
     *
     * @param array<string, string> $vars
     */
    public static function setMany(array $vars, bool $override = true): void
    {
        foreach ($vars as $key => $value) {
            if ($override || !array_key_exists($key, self::$vars)) {
                self::$vars[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function has(string $key): bool
    {
        return self::lookup($key) !== null;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::lookup($key) ?? $default;
    }

    public static function require(string $key): string
    {
        $value = self::lookup($key);

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Missing required environment variable: %s', $key));
        }

        return $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::lookup($key);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::lookup($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Real process environment variables win over the file, so CI/test runners
     * can override without editing .env.
     */
    private static function lookup(string $key): ?string
    {
        $fromProcess = getenv($key);

        if ($fromProcess !== false) {
            return $fromProcess;
        }

        return self::$vars[$key] ?? null;
    }

    private static function normaliseValue(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last  = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);

                return $first === '"' ? str_replace(['\n', '\r', '\t', '\"'], ["\n", "\r", "\t", '"'], $inner) : $inner;
            }
        }

        // Unquoted value: allow a trailing "# comment".
        $hash = strpos($value, ' #');

        if ($hash !== false) {
            $value = rtrim(substr($value, 0, $hash));
        }

        return $value;
    }
}
