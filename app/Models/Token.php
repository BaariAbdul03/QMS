<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\InvalidTransitionException;
use PDO;
use PDOException;

/**
 * The queue itself, and the two concurrency guarantees the project is built on.
 *
 * 1. issue() serialises number allocation on a single row per service per day
 *    (queue_counters). No gap locks, so no deadlocks; the UNIQUE key on
 *    (service_id, queue_date, number) stays as a safety net.
 *
 * 2. callNext() locks the counter row, then takes the oldest waiting token with
 *    FOR UPDATE SKIP LOCKED so two desks clicking at the same instant cannot
 *    grab the same visitor.
 *
 * Every state change is ONE guarded UPDATE ... WHERE status = <expected>. A
 * rowCount() of 0 means the guard did not match, which is an illegal
 * transition -> HTTP 409.
 */
final class Token
{
    public const WAITING   = 'waiting';
    public const CALLED    = 'called';
    public const SERVING   = 'serving';
    public const COMPLETED = 'completed';
    public const NO_SHOW   = 'no_show';
    public const CANCELLED = 'cancelled';

    /** Statuses that occupy a counter. */
    public const ACTIVE_AT_COUNTER = [self::CALLED, self::SERVING];

    private const MAX_ISSUE_ATTEMPTS = 3;

    /** MySQL: 1213 = deadlock, 1205 = lock wait timeout. */
    private const RETRYABLE_ERRORS = ['1213', '1205', '40001'];

    // -----------------------------------------------------------------------
    // Issuing tokens
    // -----------------------------------------------------------------------

    /**
     * Take a new token. Safe to call from N processes at once.
     *
     * The counter row is locked from the INSERT until COMMIT, so concurrent
     * issuers for the same service queue up behind one row lock. Numbers come
     * out gap-free, and the transaction is retried on deadlock/timeout.
     *
     * @return array<string, mixed> the freshly created token
     */
    public static function issue(int $serviceId): array
    {
        $service = Service::findOrFail($serviceId);
        $date    = Clock::today();

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return Database::transaction(static function (PDO $pdo) use ($service, $date, $serviceId): array {
                    $number = self::allocateNumber($pdo, $serviceId, $date);

                    $publicId = bin2hex(random_bytes(8));
                    $code     = self::formatCode((string) $service['prefix'], $number);

                    $insert = $pdo->prepare(
                        "INSERT INTO tokens (public_id, service_id, queue_date, number, display_code, status, taken_at)
                         VALUES (?, ?, ?, ?, ?, 'waiting', NOW())"
                    );
                    $insert->execute([$publicId, $serviceId, $date, $number, $code]);

                    $token = self::find((int) $pdo->lastInsertId());

                    if ($token === null) {
                        throw new HttpException('Token was created but could not be read back.', 500);
                    }

                    return $token;
                });
            } catch (PDOException $e) {
                if ($attempt >= self::MAX_ISSUE_ATTEMPTS || !self::isRetryable($e)) {
                    throw $e;
                }

                // Jittered backoff: competing writers desynchronise instead of
                // colliding again immediately.
                usleep(random_int(5_000, 25_000) * $attempt);
            }
        }
    }

    /**
     * Allocate the next number for a service/day pair.
     *
     *   INSERT ... VALUES (LAST_INSERT_ID(1))
     *   ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)
     *
     * One row lock, no gap lock. LAST_INSERT_ID(expr) is the only portable way
     * to read the new value back on the same connection (there is no
     * AUTO_INCREMENT column to use lastInsertId() on).
     */
    private static function allocateNumber(PDO $pdo, int $serviceId, string $date): int
    {
        $upsert = $pdo->prepare(
            'INSERT INTO queue_counters (service_id, queue_date, last_number)
             VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
        );
        $upsert->execute([$serviceId, $date]);

        return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    public static function formatCode(string $prefix, int $number): string
    {
        $padding = max(1, Config::int('queue.number_padding', 3));

        return $prefix . '-' . str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------------
    // Staff actions
    // -----------------------------------------------------------------------

    /**
     * Give the next waiting visitor to this counter.
     *
     * Returns null when the queue is empty for this counter's services — the
     * caller turns that into a friendly message, not an error.
     *
     * @return array<string, mixed>|null
     */
    public static function callNext(int $counterId): ?array
    {
        return Database::transaction(static function (PDO $pdo) use ($counterId): ?array {
            $date = Clock::today();

            // 1. Lock the counter row. This serialises two clicks on the same
            //    desk and stops one desk from holding two tokens.
            $lock = $pdo->prepare('SELECT * FROM counters WHERE id = ? FOR UPDATE');
            $lock->execute([$counterId]);
            $counter = $lock->fetch();

            if ($counter === false) {
                throw HttpException::notFound(sprintf('Counter %d does not exist.', $counterId));
            }

            if ((int) $counter['is_open'] !== 1) {
                throw HttpException::conflict('This counter is closed.');
            }

            $busy = $pdo->prepare(
                "SELECT id, display_code, status FROM tokens
                 WHERE counter_id = ? AND queue_date = ? AND status IN ('called', 'serving')
                 LIMIT 1"
            );
            $busy->execute([$counterId, $date]);
            $current = $busy->fetch();

            if ($current !== false) {
                throw HttpException::conflict(
                    sprintf('Finish %s before calling the next visitor.', (string) $current['display_code']),
                    ['token' => ['id' => (int) $current['id'], 'display_code' => (string) $current['display_code']]]
                );
            }

            // 2. Oldest waiting token, read in index order.
            //
            // ORDER BY number, not taken_at: number is the allocation order and
            // is strictly increasing per service/day, while taken_at only has
            // second granularity. More importantly, `number` is the last column
            // of idx_queue (service_id, queue_date, status, number), so InnoDB
            // can walk the index and stop at the first unlocked row.
            //
            // Sorting by a non-indexed column instead (taken_at) forces a
            // filesort, and a locking read must lock every row it reads — so
            // one counter would lock the whole queue and every other counter
            // would skip all of it and wrongly report "queue empty".
            $serviceId = $counter['service_id'] === null ? null : (int) $counter['service_id'];

            if ($serviceId === null) {
                // Floating counter: (service_id, number) matches the index order
                // as well, so this stays a single ordered walk.
                $sql    = "SELECT id FROM tokens
                            WHERE queue_date = ? AND status = 'waiting'
                            ORDER BY service_id, number
                            LIMIT 1
                            FOR UPDATE SKIP LOCKED";
                $params = [$date];
            } else {
                $sql    = "SELECT id FROM tokens
                            WHERE service_id = ? AND queue_date = ? AND status = 'waiting'
                            ORDER BY number
                            LIMIT 1
                            FOR UPDATE SKIP LOCKED";
                $params = [$serviceId, $date];
            }

            $pick = $pdo->prepare($sql);
            $pick->execute($params);
            $candidate = $pick->fetch();

            if ($candidate === false) {
                return null;
            }

            // 3. The guarded claim. `status = 'waiting'` keeps the update honest
            //    even if something else touched the row between pick and claim.
            $claim = $pdo->prepare(
                "UPDATE tokens
                    SET status = 'called', counter_id = ?, called_at = NOW()
                  WHERE id = ? AND status = 'waiting'"
            );
            $claim->execute([$counterId, (int) $candidate['id']]);

            if ($claim->rowCount() === 0) {
                throw HttpException::conflict('That token was claimed by another counter a moment ago.');
            }

            return self::find((int) $candidate['id']);
        });
    }

    /** called -> serving. Starts the clock the ETA actually measures. */
    public static function start(int $tokenId, int $counterId): array
    {
        $statement = Database::connection()->prepare(
            "UPDATE tokens
                SET status = 'serving', serving_at = NOW()
              WHERE id = ? AND status = 'called' AND counter_id = ?"
        );
        $statement->execute([$tokenId, $counterId]);

        return self::resolveGuard($statement->rowCount(), $tokenId, 'start serving', [self::CALLED]);
    }

    /** serving -> completed. */
    public static function complete(int $tokenId): array
    {
        $statement = Database::connection()->prepare(
            "UPDATE tokens
                SET status = 'completed', completed_at = NOW()
              WHERE id = ? AND status = 'serving'"
        );
        $statement->execute([$tokenId]);

        return self::resolveGuard($statement->rowCount(), $tokenId, 'complete', [self::SERVING]);
    }

    /** called -> no_show. The visitor never turned up. */
    public static function markNoShow(int $tokenId): array
    {
        $statement = Database::connection()->prepare(
            "UPDATE tokens
                SET status = 'no_show', completed_at = NOW()
              WHERE id = ? AND status = 'called'"
        );
        $statement->execute([$tokenId]);

        return self::resolveGuard($statement->rowCount(), $tokenId, 'mark as no-show', [self::CALLED]);
    }

    /**
     * Re-announce a called token (the visitor missed it the first time).
     *
     * The status stays 'called' — only called_at moves, which is what the
     * display board watches to replay the chime.
     */
    public static function recall(int $tokenId): array
    {
        $statement = Database::connection()->prepare(
            "UPDATE tokens
                SET called_at = NOW()
              WHERE id = ? AND status = 'called'"
        );
        $statement->execute([$tokenId]);

        return self::resolveGuard($statement->rowCount(), $tokenId, 'recall', [self::CALLED]);
    }

    /** waiting -> cancelled. A visitor who changed their mind. */
    public static function cancel(string $publicId): array
    {
        $statement = Database::connection()->prepare(
            "UPDATE tokens
                SET status = 'cancelled'
              WHERE public_id = ? AND status = 'waiting'"
        );
        $statement->execute([$publicId]);

        if ($statement->rowCount() === 0) {
            $token = self::findByPublicId($publicId);

            if ($token === null) {
                throw HttpException::notFound('That token does not exist.');
            }

            throw InvalidTransitionException::for('cancel', [self::WAITING], (string) $token['status']);
        }

        return self::findByPublicId($publicId) ?? throw new HttpException('Token disappeared after cancel.', 500);
    }

    /**
     * Shared tail for the guarded updates: turn "no row matched" into the right
     * exception and hand back the fresh row on success.
     *
     * @param string[] $expected
     * @return array<string, mixed>
     */
    private static function resolveGuard(int $affected, int $tokenId, string $action, array $expected): array
    {
        $token = self::find($tokenId);

        if ($token === null) {
            throw HttpException::notFound(sprintf('Token %d does not exist.', $tokenId));
        }

        if ($affected === 0) {
            throw InvalidTransitionException::for($action, $expected, (string) $token['status']);
        }

        return $token;
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $statement = Database::connection()->prepare(self::SELECT . ' WHERE t.id = ? LIMIT 1');
        $statement->execute([$id]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function findByPublicId(string $publicId): ?array
    {
        $statement = Database::connection()->prepare(self::SELECT . ' WHERE t.public_id = ? LIMIT 1');
        $statement->execute([$publicId]);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    public static function findByPublicIdOrFail(string $publicId): array
    {
        return self::findByPublicId($publicId)
            ?? throw HttpException::notFound('That token does not exist or has expired.');
    }

    /**
     * How many people are ahead of this token *right now* (1 = next up).
     * Only meaningful while the token is waiting.
     */
    public static function positionOf(array $token): int
    {
        if ((string) $token['status'] !== self::WAITING) {
            return 0;
        }

        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM tokens
              WHERE service_id = ? AND queue_date = ? AND status = 'waiting' AND number < ?"
        );
        $statement->execute([(int) $token['service_id'], (string) $token['queue_date'], (int) $token['number']]);

        return (int) $statement->fetchColumn() + 1;
    }

    public static function waitingCount(int $serviceId, ?string $date = null): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM tokens WHERE service_id = ? AND queue_date = ? AND status = 'waiting'"
        );
        $statement->execute([$serviceId, $date ?? Clock::today()]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function waitingFor(int $serviceId, ?string $date = null): array
    {
        $statement = Database::connection()->prepare(
            "SELECT t.*, s.name AS service_name, s.prefix AS service_prefix
               FROM tokens t
               JOIN services s ON s.id = t.service_id
              WHERE t.service_id = ? AND t.queue_date = ? AND t.status = 'waiting'
              ORDER BY t.number"
        );
        $statement->execute([$serviceId, $date ?? Clock::today()]);

        return $statement->fetchAll();
    }

    /**
     * Today's token counts by status, plus the totals the panels show.
     *
     * @return array<string, int>
     */
    public static function statusCounts(?string $date = null): array
    {
        $statement = Database::connection()->prepare(
            'SELECT status, COUNT(*) AS total FROM tokens WHERE queue_date = ? GROUP BY status'
        );
        $statement->execute([$date ?? Clock::today()]);

        $counts = array_fill_keys(
            [self::WAITING, self::CALLED, self::SERVING, self::COMPLETED, self::NO_SHOW, self::CANCELLED],
            0
        );

        foreach ($statement->fetchAll() as $row) {
            $status = (string) $row['status'];

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        $counts['in_queue'] = $counts[self::WAITING];
        $counts['active']   = $counts[self::WAITING] + $counts[self::CALLED] + $counts[self::SERVING];
        $counts['total']    = $counts[self::WAITING] + $counts[self::CALLED] + $counts[self::SERVING]
            + $counts[self::COMPLETED] + $counts[self::NO_SHOW] + $counts[self::CANCELLED];

        return $counts;
    }

    /**
     * Shape a token row for the API (plan section 6). Never leaks the internal
     * auto-increment id for visitor-facing payloads.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    public static function present(array $token, ?int $position = null): array
    {
        return [
            'public_id'    => (string) $token['public_id'],
            'display_code' => (string) $token['display_code'],
            'number'       => (int) $token['number'],
            'status'       => (string) $token['status'],
            'service'      => [
                'id'     => (int) $token['service_id'],
                'name'   => (string) ($token['service_name'] ?? ''),
                'prefix' => (string) ($token['service_prefix'] ?? ''),
            ],
            'counter'      => $token['counter_id'] === null ? null : [
                'id'   => (int) $token['counter_id'],
                'name' => (string) ($token['counter_name'] ?? ''),
            ],
            'queue_date'   => (string) $token['queue_date'],
            'position'     => $position ?? self::positionOf($token),
            'taken_at'     => $token['taken_at'],
            'called_at'    => $token['called_at'],
            'serving_at'   => $token['serving_at'],
            'completed_at' => $token['completed_at'],
        ];
    }

    /**
     * The same shape as present(), plus the internal id.
     *
     * Staff actions (start, complete, no-show, recall) address a token by its
     * id, so a staff response has to be self-sufficient — otherwise the client
     * would have to poll again just to act on the token it was just handed.
     * The id never appears in a visitor payload.
     *
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    public static function presentForStaff(array $token, ?int $position = null): array
    {
        return ['id' => (int) $token['id']] + self::present($token, $position);
    }

    private const SELECT = 'SELECT t.*, s.name AS service_name, s.prefix AS service_prefix,
                                   s.avg_service_minutes AS fallback_minutes,
                                   c.name AS counter_name, c.staff_name AS counter_staff
                            FROM tokens t
                            JOIN services s ON s.id = t.service_id
                            LEFT JOIN counters c ON c.id = t.counter_id';

    private static function isRetryable(PDOException $e): bool
    {
        $code = (string) $e->getCode();
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return in_array($code, self::RETRYABLE_ERRORS, true) || in_array($driverCode, self::RETRYABLE_ERRORS, true);
    }
}
