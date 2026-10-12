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

        $sql = "SELECT c.id, c.name, c.staff_name, c.is_open, c.service_id,
                       s.name AS service_name, s.prefix AS service_prefix,
                       t.id AS token_id, t.public_id AS token_public_id,
                       t.display_code AS token_code, t.status AS token_status,
                       t.number AS token_number, t.called_at, t.serving_at
                FROM counters c
                LEFT JOIN services s ON s.id = c.service_id
                LEFT JOIN tokens t
                       ON t.counter_id = c.id
                      AND t.queue_date = ?
                      AND t.status IN ('called', 'serving')
                ORDER BY c.id";

        $statement = Database::connection()->prepare($sql);
        $statement->execute([$date]);

        return $statement->fetchAll();
    }
}
