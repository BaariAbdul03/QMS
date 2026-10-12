<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Exceptions\HttpException;

/**
 * A service a visitor can take a token for (\"General OPD\", \"Billing\", ...).
 */
final class Service
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM services';

        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }

        $sql .= ' ORDER BY id';

        return Database::connection()->query($sql)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM services WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The service a visitor is about to join, or a clean 404/409.
     *
     * @return array<string, mixed>
     */
    public static function findOrFail(int $id): array
    {
        $service = self::find($id);

        if ($service === null) {
            throw HttpException::notFound(sprintf('Service %d does not exist.', $id));
        }

        if ((int) $service['is_active'] !== 1) {
            throw HttpException::conflict(sprintf('%s is not accepting tokens right now.', (string) $service['name']));
        }

        return $service;
    }

    /**
     * Active services plus how many people are waiting for each — the payload
     * behind GET /api/services.
     *
     * @return list<array<string, mixed>>
     */
    public static function listWithWaiting(?string $date = null): array
    {
        $date = $date ?? Clock::today();

        $sql = "SELECT s.id, s.name, s.prefix, s.avg_service_minutes,
                       COALESCE(q.waiting, 0) AS waiting,
                       COALESCE(c.open_counters, 0) AS open_counters
                FROM services s
                LEFT JOIN (
                    SELECT service_id, COUNT(*) AS waiting
                    FROM tokens
                    WHERE queue_date = ? AND status = 'waiting'
                    GROUP BY service_id
                ) q ON q.service_id = s.id
                LEFT JOIN (
                    SELECT service_id, COUNT(*) AS open_counters
                    FROM counters
                    WHERE is_open = 1 AND service_id IS NOT NULL
                    GROUP BY service_id
                ) c ON c.service_id = s.id
                WHERE s.is_active = 1
                ORDER BY s.id";

        $statement = Database::connection()->prepare($sql);
        $statement->execute([$date]);

        return array_map(static fn (array $row): array => [
            'id'                 => (int) $row['id'],
            'name'               => (string) $row['name'],
            'prefix'             => (string) $row['prefix'],
            'waiting'            => (int) $row['waiting'],
            'open_counters'      => (int) $row['open_counters'],
            'fallback_minutes'   => (float) $row['avg_service_minutes'],
        ], $statement->fetchAll());
    }
}
