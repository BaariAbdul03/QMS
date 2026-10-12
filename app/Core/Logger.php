<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Append-only daily file logger. No dependencies, no rotation beyond one file
 * per day (see the reset script for demo cleanup).
 */
final class Logger
{
    private static ?string $directory = null;

    public static function configure(?string $directory): void
    {
        self::$directory = $directory;
    }

    public static function directory(): ?string
    {
        return self::$directory;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function write(string $level, string $message, array $context = []): void
    {
        $directory = self::$directory;

        if ($directory === null) {
            return;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . (json_encode($context, JSON_UNESCAPED_SLASHES) ?: '{}'),
        );

        @file_put_contents($directory . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
