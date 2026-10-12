<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP middleware around RateLimiter (plan section 6, "Abuse limit").
 *
 * Returns a 429 with Retry-After instead of throwing, so the response can carry
 * the header the client should honour.
 */
final class Throttle
{
    public static function middleware(string $bucket, ?int $limit = null, int $windowSeconds = 60): callable
    {
        return static function (Request $request) use ($bucket, $limit, $windowSeconds): ?Response {
            $effective = $limit ?? Config::int('limits.tokens_per_minute', 10);

            if ($effective <= 0) {
                return null; // limiter disabled
            }

            $key = $bucket . ':' . $request->ip();

            if (RateLimiter::allows($key, $effective, $windowSeconds)) {
                return null;
            }

            $retryAfter = RateLimiter::retryAfter($key);

            Logger::warning('Rate limit hit.', ['bucket' => $bucket, 'ip' => $request->ip(), 'limit' => $effective]);

            return Response::error(
                'Too many requests from this connection. Please wait a moment.',
                429,
                ['retry_after' => $retryAfter, 'limit' => $effective]
            )->withHeader('Retry-After', (string) $retryAfter);
        };
    }
}
