<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Database;
use PDO;

/**
 * The self-learning part of the queue: how long a service actually takes, and
 * therefore what "about 9-15 minutes" should say for a given visitor.
 *
 * Design notes that matter:
 *
 *  - The duration measured is serving_at -> completed_at, NOT called_at ->
 *    completed_at. The called-to-serving gap is the visitor walking to the desk,
 *    which has nothing to do with service time.
 *  - Samples are capped at the last 10 usable ones and outliers are dropped, so
 *    one forgotten "complete" click cannot poison the estimate.
 *  - With fewer than three samples the historical value is blended with the
 *    configured fallback, so a brand new service is not confidently wrong.
 *  - The estimate is divided by the number of open counters: three desks drain a
 *    queue about three times as fast as one.
 *  - The answer is a range. Fake precision ("8 minutes 42 seconds") would be a
 *    lie about how well this is actually known.
 */
final class QueueStats
{
    /** How many usable durations the rolling average is taken from. */
    public const SAMPLE_SIZE = 10;

    /** Below this many samples the fallback is blended in. */
    public const MIN_SAMPLES = 3;

    /** Ignore these as mis-clicks or forgotten sessions. */
    public const MIN_DURATION_SECONDS = 10;
    public const MAX_DURATION_SECONDS = 3600;

    /** Candidates fetched before outlier filtering, so 10 usable ones remain. */
    private const CANDIDATE_LIMIT = 60;

    /**
     * Average service length, with its provenance, for one service.
     *
     * @return array{minutes: float, samples: int, source: string}
     */
    public static function avgServiceMinutes(int $serviceId): array
    {
        $fallback = self::fallbackMinutes($serviceId);
        $samples  = self::recentDurations($serviceId, self::SAMPLE_SIZE);

        if ($samples === []) {
            return ['minutes' => $fallback, 'samples' => 0, 'source' => 'fallback'];
        }

        $average = array_sum($samples) / count($samples) / 60;

        if (count($samples) >= self::MIN_SAMPLES) {
            return ['minutes' => $average, 'samples' => count($samples), 'source' => 'history'];
        }

        // Too little history to trust on its own: weight it against the
        // configured default until MIN_SAMPLES real observations exist.
        $blended = ($average * count($samples) + $fallback * (self::MIN_SAMPLES - count($samples))) / self::MIN_SAMPLES;

        return ['minutes' => $blended, 'samples' => count($samples), 'source' => 'blended'];
    }

    /**
     * How many open desks can serve this service right now. Never below 1 —
     * the ETA should not divide by zero just because everybody closed up.
     */
    public static function openCounters(int $serviceId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM counters
              WHERE is_open = 1 AND (service_id = ? OR service_id IS NULL)'
        );
        $statement->execute([$serviceId]);

        return max(1, (int) $statement->fetchColumn());
    }

    /**
     * Desks currently mid-service for this service, used for the in-flight
     * correction below.
     */
    public static function inServiceCount(int $serviceId, ?string $date = null): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM tokens
              WHERE service_id = ? AND queue_date = ? AND status = 'serving'"
        );
        $statement->execute([$serviceId, $date ?? Clock::today()]);

        return (int) $statement->fetchColumn();
    }

    /**
     * The estimate shown to one waiting visitor.
     *
     *   work   = people ahead x average service time
     *   served = the visitor who is halfway through right now, if any
     *   eta    = ceil((work + served) / open counters)
     *
     * Returned as a range because the inputs are estimates. Null when the token
     * is not waiting — a called or completed token has no wait left to predict.
     *
     * @param array<string, mixed> $token
     * @return array{
     *     minutes: int, low: int, high: int, ahead: int,
     *     average_minutes: float, open_counters: int,
     *     samples: int, source: string, label: string
     * }|null
     */
    public static function etaFor(array $token): ?array
    {
        if ((string) $token['status'] !== Token::WAITING) {
            return null;
        }

        $serviceId = (int) $token['service_id'];
        $ahead     = max(0, Token::positionOf($token) - 1);

        $average  = self::avgServiceMinutes($serviceId);
        $open     = self::openCounters($serviceId);
        $inFlight = self::inServiceCount($serviceId, (string) $token['queue_date']);

        $work = $ahead * $average['minutes'];

        // A counter that is mid-service is roughly half done with that visitor.
        $busy = $inFlight > 0 ? 0.5 * $average['minutes'] : 0.0;

        $minutes = (int) ceil(($work + $busy) / $open);

        $low  = (int) floor($minutes * 0.75);
        $high = (int) ceil($minutes * 1.25);

        return [
            'minutes'         => $minutes,
            'low'             => $low,
            'high'            => $high,
            'ahead'           => $ahead,
            'average_minutes' => round($average['minutes'], 2),
            'open_counters'   => $open,
            'samples'         => $average['samples'],
            'source'          => $average['source'],
            'label'           => self::label($ahead, $low, $high, $average['source']),
        ];
    }

    /**
     * Human phrasing for the range. Kept here rather than in JavaScript so the
     * API and the UI can never disagree about what the numbers mean.
     */
    private static function label(int $ahead, int $low, int $high, string $source): string
    {
        if ($ahead === 0) {
            return 'You are next';
        }

        $confidence = $source === 'history' ? '' : ' (rough estimate)';

        if ($high <= 1) {
            return 'About a minute' . $confidence;
        }

        if ($low === $high) {
            return sprintf('About %d min%s', $high, $confidence);
        }

        return sprintf('About %d–%d min%s', $low, $high, $confidence);
    }

    /**
     * Average wait per person, for the display board's per-service panel.
     */
    public static function serviceSnapshot(int $serviceId): float
    {
        return round(self::avgServiceMinutes($serviceId)['minutes'], 1);
    }

    /**
     * The last N usable service durations, newest first, in seconds.
     *
     * @return list<float>
     */
    private static function recentDurations(int $serviceId, int $limit): array
    {
        $statement = Database::connection()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, serving_at, completed_at) AS seconds
               FROM tokens
              WHERE service_id = ?
                AND status = ?
                AND serving_at IS NOT NULL
                AND completed_at IS NOT NULL
                AND completed_at >= serving_at
              ORDER BY completed_at DESC
              LIMIT ' . self::CANDIDATE_LIMIT
        );
        $statement->execute([$serviceId, Token::COMPLETED]);

        $durations = [];

        foreach ($statement->fetchAll() as $row) {
            $seconds = (float) $row['seconds'];

            if ($seconds < self::MIN_DURATION_SECONDS || $seconds > self::MAX_DURATION_SECONDS) {
                continue; // a mis-click or a session somebody forgot to close
            }

            $durations[] = $seconds;

            if (count($durations) === $limit) {
                break;
            }
        }

        return $durations;
    }

    private static function fallbackMinutes(int $serviceId): float
    {
        $statement = Database::connection()->prepare('SELECT avg_service_minutes FROM services WHERE id = ? LIMIT 1');
        $statement->execute([$serviceId]);

        $value = $statement->fetchColumn();

        return $value === false ? 7.0 : max(1.0, (float) $value);
    }

    /**
     * Durations actually used, for tests and for the "why is the ETA that big"
     * conversation.
     *
     * @return list<float>
     */
    public static function sampleDurations(int $serviceId): array
    {
        return self::recentDurations($serviceId, self::SAMPLE_SIZE);
    }
}
