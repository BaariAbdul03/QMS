<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\HttpException;

/**
 * A physical service desk. service_id NULL means \"handles every service\".
 */
final class Counter
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $statement = Database::connection()->query(
            'SELECT c.*, s.name AS service_name, s.prefix AS service_prefix
             FROM counters c
             LEFT JOIN services s ON s.id = c.service_id
             ORDER BY c.id'
        );

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT c.*, s.name AS service_name, s.prefix AS service_prefix
             FROM counters c
             LEFT JOIN services s ON s.id = c.service_id
             WHERE c.id = ? LIMIT 1'
        );
        $statement->execute([$id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    public static function findOrFail(int $id): array
    {
        $counter = self::find($id);

        if ($counter === null) {
            throw HttpException::notFound(sprintf('Counter %d does not exist.', $id));
        }

        return $counter;
    }

    /**
     * Counters with whatever token each one is holding right now — the payload
     * behind the display board and the staff overview.
     *
     * @return list<array<string, mixed>>
     */
    public static function listWithCurrentToken(?string $date = null): array
    {
        $date = $date ?? Clock::today();

        // The join is written against a single token id rather than a status
        // predicate: a predicate could match two rows (the model forbids a
        // counter holding two active tokens, but a stale row would silently
        // duplicate the counter in every panel). This guarantees one row per
        // counter, and picks the most recently called token if data ever does
        // end up inconsistent.
        $sql = "SELECT c.id, c.name, c.staff_name, c.is_open, c.service_id,
                       s.name AS service_name, s.prefix AS service_prefix,
                       t.id AS token_id, t.public_id AS token_public_id,
                       t.display_code AS token_code, t.status AS token_status,
                       t.number AS token_number, t.called_at, t.serving_at
                FROM counters c
                LEFT JOIN services s ON s.id = c.service_id
                LEFT JOIN tokens t ON t.id = (
                        SELECT t2.id
                          FROM tokens t2
                         WHERE t2.counter_id = c.id
                           AND t2.queue_date = ?
                           AND t2.status IN ('called', 'serving')
                         ORDER BY t2.id DESC
                         LIMIT 1
                )
                ORDER BY c.id";

        $statement = Database::connection()->prepare($sql);
        $statement->execute([$date]);

        return $statement->fetchAll();
    }
}
