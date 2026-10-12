<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 2 · concurrency — the acceptance test for race-free numbering
|--------------------------------------------------------------------------
|
|   php tests/concurrency_test.php                  # 50 workers, direct mode
|   php tests/concurrency_test.php 100              # 100 workers, direct mode
|   php tests/concurrency_test.php http http://127.0.0.1:8080 50
|
| Direct mode starts N PHP processes that all block on one wall-clock barrier
| and then call Token::issue() at the same instant. That races real InnoDB
| transactions against one row — which is the race we care about.
|
| HTTP mode fires N parallel POST /api/tokens through curl_multi. It only says
| anything if the web server is multi-worker: Apache/XAMPP yes, and the PHP
| built-in server on Windows is single-process (PHP_CLI_SERVER_WORKERS is
| documented as unsupported there), so HTTP mode measures the API path rather
| than the race unless Apache is serving.
|--------------------------------------------------------------------------
*/

use Tests\Support\Fixtures;
use Tests\Support\ProcessPool;
use Tests\Support\TestRunner;

require __DIR__ . '/bootstrap.php';

$arguments = array_slice($argv, 1);
$mode      = 'direct';
$baseUrl   = 'http://127.0.0.1:8080';
$count     = 50;

if (($arguments[0] ?? '') === 'http') {
    $mode    = 'http';
    $baseUrl = rtrim($arguments[1] ?? $baseUrl, '/');
    $count   = max(1, (int) ($arguments[2] ?? 50));
} else {
    $count = max(1, (int) ($arguments[0] ?? 50));
}

$t = new TestRunner(sprintf('Phase 2 · concurrency (%s mode, %d requests)', $mode, $count));

// ---------------------------------------------------------------------------
// Fire the requests
// ---------------------------------------------------------------------------

Fixtures::seed();

// In HTTP mode the workers cannot report their own timing, so concurrency is
// evidenced instead by comparing the burst against the latency of one request.
// A serialising (single-process) server needs count x latency; a real worker
// pool finishes far sooner.
$baselineLatency = 0.0;

if ($mode === 'http') {
    $baselineLatency = medianLatency($baseUrl, 7);
    Fixtures::seed();
}

$deadlocksBefore = Fixtures::innodbDeadlocks();
$wallStart       = microtime(true);
$outcomes        = $mode === 'http' ? fireHttp($baseUrl, $count) : fireProcesses($count);
$wallElapsed     = microtime(true) - $wallStart;
$deadlocksAfter  = Fixtures::innodbDeadlocks();

$numbers    = [];
$publicIds  = [];
$overlap    = 0;
$failures   = [];

foreach ($outcomes as $index => $outcome) {
    if ($outcome['exit'] !== 0 || !is_array($outcome['json']) || ($outcome['json']['ok'] ?? false) !== true) {
        $failures[] = sprintf(
            'request #%d: exit %d, stdout=%s, stderr=%s',
            $index + 1,
            $outcome['exit'],
            $outcome['stdout'] === '' ? '(empty)' : $outcome['stdout'],
            $outcome['stderr'] === '' ? '(empty)' : substr($outcome['stderr'], 0, 300),
        );

        continue;
    }

    $numbers[(int) $outcome['json']['number']]  = true;
    $publicIds[(string) $outcome['json']['public_id']] = true;
}

$overlap = maxConcurrent($outcomes);

// Implied concurrency for HTTP mode: how much faster the burst was than
// answering the same requests one after another.
$overlapFactor = $baselineLatency > 0.0
    ? ($count * $baselineLatency) / max($wallElapsed, 0.000001)
    : 0.0;

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

fwrite(STDOUT, sprintf("\n  fired %d requests in %.2fs wall clock\n", $count, $wallElapsed));

if ($mode === 'direct') {
    fwrite(STDOUT, sprintf(
        "  observed peak overlap: %d requests inside issue() at once\n",
        $overlap,
    ));
} else {
    fwrite(STDOUT, sprintf(
        "  median latency of one request: %.1f ms\n" .
        "  serial equivalent: %.2fs  ->  implied concurrency ~%.1fx\n",
        $baselineLatency * 1000,
        $count * $baselineLatency,
        $overlapFactor,
    ));
}

fwrite(STDOUT, sprintf("  innodb deadlocks: %d -> %d\n\n", $deadlocksBefore, $deadlocksAfter));

// ---------------------------------------------------------------------------
// Assertions — the plan's definition of done
// ---------------------------------------------------------------------------

$t->test('every request succeeded', function (TestRunner $t) use ($failures, $count): void {
    if ($failures !== []) {
        $t->assertSame(0, count($failures), "failures:\n          " . implode("\n          ", array_slice($failures, 0, 5)));
    }

    $t->assertSame(0, count($failures), 'all ' . $count . ' requests returned ok:true');
});

$t->test('the requests genuinely ran at the same time', function (TestRunner $t) use ($overlap, $overlapFactor, $mode): void {
    if ($mode === 'direct') {
        $t->assertTrue(
            $overlap >= 2,
            sprintf('peak overlap was %d — direct-mode workers must not be serialised', $overlap),
        );

        return;
    }

    if ($overlapFactor >= 2.0) {
        $t->assertTrue(true, sprintf('implied concurrency %.1fx', $overlapFactor));

        return;
    }

    // A single-process web server cannot overlap anything, so the timing
    // evidence simply is not available. Saying so beats pretending.
    $t->skip(sprintf(
        'The server answered requests one at a time (implied concurrency %.1fx, so it is treating ' .
        "the burst as a queue).\n" .
        '          That is expected for the PHP built-in server — PHP_CLI_SERVER_WORKERS is not ' .
        "supported on Windows.\n" .
        '          The data invariants below still hold; they are just weaker evidence on their own.',
        $overlapFactor,
    ));
});

$t->test('no two requests received the same number', function (TestRunner $t) use ($numbers, $count): void {
    $t->assertSame($count, count($numbers), 'distinct numbers issued');
});

$t->test('no two requests received the same public_id', function (TestRunner $t) use ($publicIds, $count): void {
    $t->assertSame($count, count($publicIds), 'distinct public_ids issued');
});

$t->test('numbers are gap-free 1..N', function (TestRunner $t) use ($numbers, $count): void {
    $issued = array_keys($numbers);
    sort($issued);

    $t->assertSame(range(1, $count), $issued, 'a gapless run is the point of the counter row');
});

$t->test('the database has zero duplicate (service, date, number) rows', function (TestRunner $t): void {
    $duplicates = Fixtures::duplicateNumbers();

    $t->assertSame(0, count($duplicates), 'duplicate number groups: ' . json_encode($duplicates));

    // The plan's literal query:
    //   SELECT service_id, queue_date, number, COUNT(*) c
    //   FROM tokens GROUP BY 1,2,3 HAVING c > 1;      -- expect 0 rows
    $planQuery = Fixtures::pdo()->query(
        'SELECT service_id, queue_date, number, COUNT(*) AS c
           FROM tokens GROUP BY 1, 2, 3 HAVING c > 1'
    )->fetchAll();

    $t->assertSame(0, count($planQuery), 'the plan\'s HAVING c > 1 query returns 0 rows');
});

$t->test('MAX(number) equals COUNT(*) — nothing was skipped or overwritten', function (TestRunner $t) use ($count): void {
    //   SELECT MAX(number), COUNT(*) FROM tokens WHERE service_id = 1;  -- expect equal
    $row = Fixtures::pdo()->query(
        'SELECT MAX(number) AS max_number, COUNT(*) AS total FROM tokens WHERE service_id = 1'
    )->fetch();

    $t->assertSame($count, (int) $row['max_number'], 'MAX(number)');
    $t->assertSame($count, (int) $row['total'], 'COUNT(*)');
    $t->assertSame((int) $row['max_number'], (int) $row['total'], 'MAX(number) == COUNT(*)');
});

$t->test('the counter row agrees with the tokens table', function (TestRunner $t) use ($count): void {
    $row = Fixtures::pdo()->query(
        'SELECT last_number FROM queue_counters WHERE service_id = 1'
    )->fetch();

    $t->assertSame($count, (int) $row['last_number'], 'queue_counters.last_number');
});

$t->test('the design avoided deadlocks rather than retrying through them', function (TestRunner $t) use ($deadlocksBefore, $deadlocksAfter): void {
    $t->assertSame(
        $deadlocksBefore,
        $deadlocksAfter,
        'Innodb_deadlocks must not move: a one-row counter cannot produce gap-lock deadlocks',
    );
});

exit($t->summary());

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Start $count PHP processes that race on the same service.
 *
 * @return list<array{stdout: string, stderr: string, exit: int, json: array<string, mixed>|null}>
 */
function fireProcesses(int $count): array
{
    $commands = [];

    for ($i = 0; $i < $count; $i++) {
        $commands[] = ProcessPool::worker(__DIR__ . '/support/issue_worker.php', [(string) Fixtures::GENERAL]);
    }

    return ProcessPool::runParallel($commands);
}

/**
 * Fire $count parallel POST /api/tokens through curl_multi.
 *
 * @return list<array{stdout: string, stderr: string, exit: int, json: array<string, mixed>|null}>
 */
function fireHttp(string $baseUrl, int $count): array
{
    $multi   = curl_multi_init();
    $handles = [];

    for ($i = 0; $i < $count; $i++) {
        $handle = curl_init($baseUrl . '/api/tokens');

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['service_id' => Fixtures::GENERAL]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        curl_multi_add_handle($multi, $handle);
        $handles[$i] = $handle;
    }

    $running = null;

    do {
        $status = curl_multi_exec($multi, $running);

        if ($running > 0) {
            curl_multi_select($multi, 0.5);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];

    foreach ($handles as $index => $handle) {
        $body   = (string) curl_multi_getcontent($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error  = curl_error($handle);

        $decoded  = json_decode($body, true);
        $envelope = is_array($decoded) ? $decoded : null;

        $results[$index] = [
            'stdout' => $body,
            'stderr' => $error,
            'exit'   => $status === 201 ? 0 : 1,
            'json'   => is_array($envelope) && ($envelope['ok'] ?? false) === true
                ? $envelope['data'] + ['ok' => true]
                : null,
        ];

        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }

    curl_multi_close($multi);

    return $results;
}

/**
 * Median latency (seconds) of a single POST /api/tokens, used as the yardstick
 * for HTTP-mode concurrency.
 */
function medianLatency(string $baseUrl, int $samples): float
{
    $latencies = [];

    for ($i = 0; $i < $samples; $i++) {
        $handle = curl_init($baseUrl . '/api/tokens');

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['service_id' => Fixtures::GENERAL]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $start = microtime(true);
        curl_exec($handle);
        $latencies[] = microtime(true) - $start;

        curl_close($handle);
    }

    sort($latencies);

    return $latencies[intdiv(count($latencies), 2)];
}

/**
 * Peak number of workers inside their database call at the same time, computed
 * from the t0/t1 brackets each worker reports.
 *
 * @param list<array{json: array<string, mixed>|null}> $outcomes
 */
function maxConcurrent(array $outcomes): int
{
    $events = [];

    foreach ($outcomes as $outcome) {
        $t0 = $outcome['json']['t0'] ?? null;
        $t1 = $outcome['json']['t1'] ?? null;

        if ($t0 === null || $t1 === null) {
            continue;
        }

        $events[] = [(float) $t0, 1];
        $events[] = [(float) $t1, -1];
    }

    if ($events === []) {
        return 0;
    }

    usort($events, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

    $current = 0;
    $peak    = 0;

    foreach ($events as [, $delta]) {
        $current += $delta;
        $peak     = max($peak, $current);
    }

    return $peak;
}
