<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiter, one small JSON file per key.
 *
 * Deliberately simple: a shared-hosting-friendly abuse guard for token
 * issuance, not a distributed limiter. The counter is updated under an
 * exclusive lock so parallel requests cannot all read the same value; the
 * window boundary is the only place a burst can leak through.
 *
 * A limit of 0 or less disables the limiter (used by tests and by the demo
 * environment, where a concurrency test legitimately fires 50 requests from
 * one address).
 */
final class RateLimiter
{
    public static function allows(string $key, int $limit, int $windowSeconds = 60, ?string $directory = null): bool
    {
        if ($limit <= 0) {
            return true;
        }

        $directory = $directory ?? (STORAGE_PATH . '/rate-limit');

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            // Failing open is the wrong choice for an abuse guard, but failing
            // closed on a missing temp directory would take the queue offline.
            // Log loudly and let the request through.
            Logger::warning('Rate limiter directory is not writable; allowing request.', ['dir' => $directory]);

            return true;
        }

        $file = $directory . '/' . sha1($key) . '.json';
        $now  = time();

        $handle = @fopen($file, 'c+');

        if ($handle === false) {
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            $raw  = stream_get_contents($handle);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $data = is_array($data) ? $data : ['count' => 0, 'reset_at' => 0];

            if ((int) ($data['reset_at'] ?? 0) <= $now) {
                $data = ['count' => 0, 'reset_at' => $now + $windowSeconds];
            }

            $data['count'] = (int) $data['count'] + 1;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $data['count'] <= $limit;
        } finally {
            fclose($handle);
        }
    }

    /**
     * How long until the current window ends, for the Retry-After header.
     */
    public static function retryAfter(string $key, ?string $directory = null): int
    {
        $file = ($directory ?? (STORAGE_PATH . '/rate-limit')) . '/' . sha1($key) . '.json';

        if (!is_file($file)) {
            return 60;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        if (!is_array($data)) {
            return 60;
        }

        return max(1, (int) ($data['reset_at'] ?? 0) - time());
    }

    /** Clear one key, or the whole store. Used by tests and the demo reset. */
    public static function clear(?string $key = null, ?string $directory = null): void
    {
        $directory = $directory ?? (STORAGE_PATH . '/rate-limit');

        if (!is_dir($directory)) {
            return;
        }

        if ($key !== null) {
            @unlink($directory . '/' . sha1($key) . '.json');

            return;
        }

        foreach (glob($directory . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
}
