<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\DatabaseException;
use PDO;
use PDOException;
use Throwable;

/**
 * PDO singleton.
 *
 * Connection contract (from the plan):
 *   - ERRMODE_EXCEPTION
 *   - EMULATE_PREPARES = false   (real server-side prepared statements)
 *   - charset utf8mb4
 *   - session time_zone pinned to the app timezone offset, so NOW() agrees
 *     with the queue_date the app computes in Asia/Kolkata.
 */
final class Database
{
    private static ?PDO $pdo = null;

    private static ?string $dsn = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = Config::get('db');

        if (!is_array($config)) {
            throw new DatabaseException('Database configuration is missing.');
        }

        $host    = (string) ($config['host'] ?? '127.0.0.1');
        $port    = (int) ($config['port'] ?? 3306);
        $name    = (string) ($config['name'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        self::$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO(self::$dsn, (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_PERSISTENT         => false,
                // Report MATCHED rows instead of CHANGED rows. Guarded state
                // transitions rely on rowCount() == 0 meaning "the guard did not
                // match"; without this, re-running an idempotent UPDATE (e.g.
                // recall twice inside the same second) would look like an
                // illegal transition even though the row was found.
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ]);
        } catch (PDOException $e) {
            // Deliberately vague: the DSN may contain the username. The real
            // driver error travels as $previous and is logged by ErrorHandler.
            throw new DatabaseException(
                'Could not connect to the database. Check the DB_* values in .env.',
                $e
            );
        }

        self::applySessionTimezone(self::$pdo, (string) ($config['timezone'] ?? '+05:30'));

        return self::$pdo;
    }

    /**
     * Run $work inside a transaction and return its value.
     *
     * A nested call joins the outer transaction instead of opening a second
     * one, so model methods can be composed safely.
     *
     * @template T
     * @param  callable(PDO): T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $work($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Drop the cached handle. Used by tests that swap credentials to prove the
     * "bad credentials" path, and by long-running CLI workers.
     */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$dsn = null;
    }

    public static function dsn(): ?string
    {
        return self::$dsn;
    }

    /**
     * Pin the session timezone. Accepts a UTC offset ('+05:30') or a named zone
     * (only if the server has the timezone tables loaded).
     */
    private static function applySessionTimezone(PDO $pdo, string $timezone): void
    {
        $timezone = trim($timezone);

        if ($timezone === '') {
            return;
        }

        $isOffset = preg_match('/^[+-]\d{2}:\d{2}$/', $timezone) === 1;
        $isNamed  = preg_match('/^[A-Za-z_\/]+$/', $timezone) === 1;

        if (!$isOffset && !$isNamed) {
            throw new DatabaseException(sprintf('DB_TIMEZONE "%s" is not a valid offset or zone name.', $timezone));
        }

        try {
            $statement = $pdo->prepare('SET time_zone = ?');
            $statement->execute([$timezone]);
        } catch (PDOException $e) {
            throw new DatabaseException(sprintf('Could not set the database session time zone to "%s".', $timezone), $e);
        }
    }
}
