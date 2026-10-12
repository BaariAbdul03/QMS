<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Flat config store with dot-notation access: Config::get('db.host').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Config file not found: %s', $path));
        }

        /** @var array<string, mixed> $items */
        $items = require $path;

        if (!is_array($items)) {
            throw new RuntimeException(sprintf('Config file must return an array: %s', $path));
        }

        self::$items = $items;
    }

    /**
     * @param array<string, mixed> $items
     */
    public static function set(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!str_contains($key, '.')) {
            return self::$items[$key] ?? $default;
        }

        $cursor = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$items;
    }
}
