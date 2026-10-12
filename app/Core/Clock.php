<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The single source of \"now\" for the queue.
 *
 * Why not CURDATE(): the database session time zone and the application time
 * zone can disagree, which resets the queue at the wrong hour (plan item 7).
 * Every queue_date in this app is computed here, in APP_TIMEZONE, and passed
 * to MySQL as a bound parameter.
 *
 * Tests freeze the clock with Clock::freeze('2026-10-12 09:00:00').
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    private static ?DateTimeZone $zone = null;

    public static function timezone(): DateTimeZone
    {
        return self::$zone ??= new DateTimeZone(Config::string('app.timezone', 'Asia/Kolkata'));
    }

    public static function reset(): void
    {
        self::$frozen = null;
        self::$zone   = null;
    }

    public static function now(): DateTimeImmutable
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }

        return new DateTimeImmutable('now', self::timezone());
    }

    /**
     * The queue day, e.g. "2026-10-12". Every query that groups tokens by day
     * uses this value rather than CURDATE().
     */
    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function freeze(string $moment): void
    {
        self::$frozen = new DateTimeImmutable($moment, self::timezone());
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }

    public static function isFrozen(): bool
    {
        return self::$frozen !== null;
    }
}
