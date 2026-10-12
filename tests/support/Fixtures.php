<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Clock;
use App\Core\Database;
use App\Models\Token;
use PDO;

/**
 * Deterministic database fixtures. Tests wipe the queue tables and rebuild a
 * known two-service / three-counter world, so assertions can use literal ids.
 */
final class Fixtures
{
    public const GENERAL = 1;   // prefix A, 7 min fallback
    public const BILLING = 2;   // prefix B, 4 min fallback

    public const COUNTER_1 = 1; // General OPD
    public const COUNTER_2 = 2; // General OPD
    public const COUNTER_3 = 3; // floating (serves every service)

    /**
     * Empty every queue table. FOREIGN_KEY_CHECKS is disabled because TRUNCATE
     * is refused on a table that is referenced by another.
     */
    public static function reset(): void
    {
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['tokens', 'queue_counters', 'counters', 'services'] as $table) {
            $pdo->exec('TRUNCATE TABLE ' . $table);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Clear the queue but keep services/counters (used between scenarios). */
    public static function resetQueue(): void
    {
        $pdo = Database::connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE tokens');
        $pdo->exec('TRUNCATE TABLE queue_counters');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public static function seed(): void
    {
        self::reset();

        $pdo = Database::connection();

        $pdo->exec(
            "INSERT INTO services (id, name, prefix, avg_service_minutes, is_active) VALUES
                (1, 'General OPD', 'A', 7.00, 1),
                (2, 'Billing',     'B', 4.00, 1)"
        );

        $pdo->exec(
            "INSERT INTO counters (id, name, service_id, staff_name, is_open) VALUES
                (1, 'Counter 1', 1,    'Asha',  1),
                (2, 'Counter 2', 1,    'Ravi',  1),
                (3, 'Counter 3', NULL, 'Meera', 1)"
        );
    }

    /**
     * Drive a real token into the requested status using the production
     * transitions, so fixtures can never disagree with the state machine.
     *
     * @return array<string, mixed>
     */
    public static function tokenIn(string $status, int $serviceId = self::GENERAL, int $counterId = self::COUNTER_1): array
    {
        $token = Token::issue($serviceId);

        return match ($status) {
            Token::WAITING   => $token,
            Token::CANCELLED => Token::cancel((string) $token['public_id']),
            Token::CALLED    => self::asCalled($token, $counterId),
            Token::SERVING   => Token::start((int) self::asCalled($token, $counterId)['id'], $counterId),
            Token::COMPLETED => Token::complete((int) Token::start((int) self::asCalled($token, $counterId)['id'], $counterId)['id']),
            Token::NO_SHOW   => Token::markNoShow((int) self::asCalled($token, $counterId)['id']),
            default          => throw new \InvalidArgumentException('Unknown status: ' . $status),
        };
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    private static function asCalled(array $token, int $counterId): array
    {
        $claimed = Token::callNext($counterId);

        if ($claimed === null) {
            throw new \RuntimeException('Fixture could not call the token it just issued.');
        }

        return $claimed;
    }

    /**
     * Insert completed tokens with an explicit service duration, for ETA tests.
     *
     * @param list<int> $minutes
     */
    public static function completedTokens(array $minutes, int $serviceId = self::GENERAL, ?string $date = null): void
    {
        $pdo  = Database::connection();
        $date = $date ?? Clock::today();

        $insert = $pdo->prepare(
            "INSERT INTO tokens (public_id, service_id, counter_id, queue_date, number, display_code, status,
                                 taken_at, called_at, serving_at, completed_at)
             VALUES (?, ?, 1, ?, ?, ?, 'completed',
                     NOW() - INTERVAL ? MINUTE,
                     NOW() - INTERVAL ? MINUTE,
                     NOW() - INTERVAL ? MINUTE,
                     NOW())"
        );

        $number = (int) $pdo->query(
            'SELECT COALESCE(MAX(number), 0) FROM tokens WHERE service_id = ' . $serviceId . " AND queue_date = '" . $date . "'"
        )->fetchColumn();

        foreach ($minutes as $minute) {
            $number++;
            $insert->execute([
                bin2hex(random_bytes(8)),
                $serviceId,
                $date,
                $number,
                Token::formatCode($serviceId === self::GENERAL ? 'A' : 'B', $number),
                $minute + 1,
                $minute,
                $minute,
            ]);
        }

        // Keep the counter row in step so later issue() calls do not collide
        // with the numbers inserted above. (GREATEST with a bound parameter
        // rather than VALUES(), which MySQL 8.0.20 deprecated.)
        $pdo->prepare(
            'INSERT INTO queue_counters (service_id, queue_date, last_number) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, ?)'
        )->execute([$serviceId, $date, $number, $number]);
    }

    public static function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * Rows that violate the "one token per (service, day, number)" guarantee.
     *
     * @return list<array<string, mixed>>
     */
    public static function duplicateNumbers(): array
    {
        return self::pdo()->query(
            'SELECT service_id, queue_date, number, COUNT(*) AS c
               FROM tokens
              GROUP BY service_id, queue_date, number
             HAVING c > 1'
        )->fetchAll();
    }

    public static function innodbDeadlocks(): int
    {
        $row = self::pdo()->query("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->fetch();

        return (int) ($row['Value'] ?? 0);
    }
}
