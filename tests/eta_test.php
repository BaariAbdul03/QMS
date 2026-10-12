<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 5 · the self-learning ETA
|--------------------------------------------------------------------------
|   php tests/eta_test.php
|
| The plan's two behavioural requirements are asserted exactly:
|   - closing a counter raises the estimate
|   - finishing several fast services lowers it
|
| Everything is driven from inserted data with exact durations, so the numbers
| asserted here are computed from the formula rather than copied from a run.
|--------------------------------------------------------------------------
*/

use App\Core\Clock;
use App\Models\QueueStats;
use App\Models\Token;
use Tests\Support\Fixtures;
use Tests\Support\TestRunner;

require __DIR__ . '/bootstrap.php';

$t = new TestRunner('Phase 5 · dynamic ETA');

// ---------------------------------------------------------------------------
// Measuring the average
// ---------------------------------------------------------------------------

$t->test('with no history the average is the configured fallback', function (TestRunner $t): void {
    Fixtures::seed();

    $average = QueueStats::avgServiceMinutes(Fixtures::GENERAL);

    $t->assertSame(7.0, round($average['minutes'], 2), 'General OPD falls back to 7 minutes');
    $t->assertSame(0, $average['samples'], 'no samples yet');
    $t->assertSame('fallback', $average['source'], 'and it says so');
});

$t->test('the average is the mean of the last ten usable durations', function (TestRunner $t): void {
    Fixtures::seed();

    // 12 durations; only the 10 newest count, and the mean is exact.
    Fixtures::completedWithDurations([300, 300, 300, 300, 300, 300, 300, 300, 300, 300, 3000, 3000]);

    $average = QueueStats::avgServiceMinutes(Fixtures::GENERAL);

    $t->assertSame(10, $average['samples'], 'capped at ten samples, not twelve');
    $t->assertSame(5.0, round($average['minutes'], 2), 'mean of ten 5-minute services is 5 minutes');
    $t->assertSame('history', $average['source'], 'real data is trusted');

    // The two 50-minute services are older than the window, so they must not
    // appear at all.
    $durations = array_map('intval', QueueStats::sampleDurations(Fixtures::GENERAL));
    $t->assertSame([300, 300, 300, 300, 300, 300, 300, 300, 300, 300], $durations, 'newest ten only');
});

$t->test('outliers are dropped: mis-clicks and forgotten sessions', function (TestRunner $t): void {
    Fixtures::seed();

    // 5s = accidental double click. 4000s = somebody forgot to press Complete.
    Fixtures::completedWithDurations([5, 1200, 900, 300, 360, 4000]);

    $average = QueueStats::avgServiceMinutes(Fixtures::GENERAL);

    $t->assertSame(4, $average['samples'], 'four of six survive the filter');
    $t->assertSame(11.5, round($average['minutes'], 2), 'mean of 20, 15, 5 and 6 minutes');

    $durations = array_map('intval', QueueStats::sampleDurations(Fixtures::GENERAL));
    $t->assertSame([1200, 900, 300, 360], $durations, 'the 5s and 4000s samples are gone');
});

$t->test('below three samples the fallback is blended in', function (TestRunner $t): void {
    Fixtures::seed();

    Fixtures::completedWithDurations([600, 600]); // two 10-minute services

    $average = QueueStats::avgServiceMinutes(Fixtures::GENERAL);

    // (10 + 10 + fallback 7) / 3
    $t->assertSame(2, $average['samples'], 'two samples');
    $t->assertSame(9.0, round($average['minutes'], 2), 'blended towards the 7-minute default');
    $t->assertSame('blended', $average['source'], 'provenance is reported');
});

$t->test('durations are measured from serving_at, not from called_at', function (TestRunner $t): void {
    Fixtures::seed();

    // The fixture leaves a 30s gap between called_at and serving_at (the walk to
    // the desk) and a 300s service. Measuring called_at -> completed_at would
    // read 330s and quietly overestimate every service by the walk time.
    Fixtures::completedWithDurations([300]);

    $durations = array_map('intval', QueueStats::sampleDurations(Fixtures::GENERAL));

    $t->assertSame([300], $durations, 'the called-to-serving gap is not service time');
});

// ---------------------------------------------------------------------------
// Capacity
// ---------------------------------------------------------------------------

$t->test('open counters includes the floating desk', function (TestRunner $t): void {
    Fixtures::seed();

    $t->assertSame(3, QueueStats::openCounters(Fixtures::GENERAL), 'two dedicated + one shared');
    $t->assertSame(1, QueueStats::openCounters(Fixtures::BILLING), 'Billing has only the shared desk');

    Fixtures::pdo()->exec('UPDATE counters SET is_open = 0 WHERE id = 2');
    $t->assertSame(2, QueueStats::openCounters(Fixtures::GENERAL), 'closing one desk drops the count');

    Fixtures::pdo()->exec('UPDATE counters SET is_open = 0');
    $t->assertSame(1, QueueStats::openCounters(Fixtures::GENERAL), 'never divides by zero');
});

// ---------------------------------------------------------------------------
// The estimate itself
// ---------------------------------------------------------------------------

$t->test('the estimate is ahead x average / open counters, as a range', function (TestRunner $t): void {
    Fixtures::seed();
    Fixtures::completedWithDurations(array_fill(0, 10, 300)); // exactly 5 minutes each

    $token = Fixtures::tokenWithAhead(5); // five people in front
    $eta   = QueueStats::etaFor($token);

    $t->assertTrue($eta !== null, 'a waiting token has an estimate');
    $t->assertSame(5, $eta['ahead'], 'five people ahead');
    $t->assertSame(5.0, $eta['average_minutes'], 'five minutes each');
    $t->assertSame(3, $eta['open_counters'], 'three desks');

    // ceil(5 people x 5 min / 3 desks) = ceil(8.33) = 9, shown as 75%-125%.
    $t->assertSame(9, $eta['minutes'], '9 minutes of work per desk');
    $t->assertSame(6, $eta['low'], 'low bound is 75% of the estimate');
    $t->assertSame(12, $eta['high'], 'high bound is 125% of the estimate');
    $t->assertSame('About 6–12 min', $eta['label'], 'phrased as a range, not fake precision');
});

$t->test('the person at the front is told they are next', function (TestRunner $t): void {
    Fixtures::seed();
    Fixtures::completedWithDurations(array_fill(0, 10, 300));

    $token = Fixtures::tokenWithAhead(0);
    $eta   = QueueStats::etaFor($token);

    $t->assertSame(0, $eta['ahead'], 'nobody ahead');
    $t->assertSame('You are next', $eta['label'], 'clearer than "about 0-0 min"');
});

$t->test('a desk that is mid-service adds half a service to the wait', function (TestRunner $t): void {
    Fixtures::seed();
    Fixtures::completedWithDurations(array_fill(0, 10, 300));

    $token = Fixtures::tokenWithAhead(5);
    $quiet = QueueStats::etaFor($token);

    // A desk is busy with somebody who is already being served. They are not
    // "ahead" of our visitor (they are not waiting), but their desk is not free
    // either, so half a service is charged for them. The number is deliberately
    // higher than our visitor's so the queue ahead of them does not change and
    // this test measures the in-flight term alone.
    $pdo = Fixtures::pdo();
    $pdo->prepare(
        "INSERT INTO tokens (public_id, service_id, counter_id, queue_date, number, display_code, status,
                             taken_at, called_at, serving_at)
         VALUES (?, ?, 1, ?, 900, 'A-900', 'serving', NOW(), NOW(), NOW())"
    )->execute([bin2hex(random_bytes(8)), Fixtures::GENERAL, Clock::today()]);

    $busy = QueueStats::etaFor($token);

    $t->assertSame(1, QueueStats::inServiceCount(Fixtures::GENERAL), 'one desk is mid-service');
    $t->assertSame($quiet['ahead'], $busy['ahead'], 'the queue ahead is unchanged');

    // ceil((5 x 5 + 2.5) / 3) = ceil(9.17) = 10, up from ceil(25/3) = 9.
    $t->assertSame(9, $quiet['minutes'], 'no desk busy');
    $t->assertSame(10, $busy['minutes'], 'a half-finished service is charged to a desk');
});

$t->test('there is no estimate once the token is not waiting', function (TestRunner $t): void {
    Fixtures::seed();

    foreach ([Token::CALLED, Token::SERVING, Token::COMPLETED, Token::CANCELLED, Token::NO_SHOW] as $status) {
        // Each status starts from an empty queue: a counter may only hold one
        // visitor at a time, so the fixtures cannot be chained on one desk.
        Fixtures::resetQueue();

        $token = Fixtures::tokenIn($status);

        $t->assertSame(null, QueueStats::etaFor($token), 'no wait to predict while ' . $status);
    }
});

// ---------------------------------------------------------------------------
// The plan's two behavioural requirements
// ---------------------------------------------------------------------------

$t->test('closing a counter raises the estimate', function (TestRunner $t): void {
    Fixtures::seed();
    Fixtures::completedWithDurations(array_fill(0, 10, 300));

    $token = Fixtures::tokenWithAhead(5);

    $before = QueueStats::etaFor($token);

    // Counter 2 closes: two desks can no longer be relied on to drain the queue.
    Fixtures::pdo()->exec('UPDATE counters SET is_open = 0 WHERE id = 2');

    $after = QueueStats::etaFor($token);

    $t->assertSame(3, $before['open_counters'], 'three desks before');
    $t->assertSame(2, $after['open_counters'], 'two desks after');
    $t->assertTrue(
        $after['minutes'] > $before['minutes'],
        sprintf('closing a desk must raise the wait (was %d, now %d)', $before['minutes'], $after['minutes']),
    );
    $t->assertSame(13, $after['minutes'], 'ceil(5 x 5 / 2) = 13');
});

$t->test('finishing several fast services lowers the estimate', function (TestRunner $t): void {
    Fixtures::seed();

    // A slow week: 9 minutes per visitor.
    Fixtures::completedWithDurations(array_fill(0, 10, 540));

    $token = Fixtures::tokenWithAhead(5);
    $before = QueueStats::etaFor($token);

    // Ten more visitors are served quickly. The rolling window is the last ten,
    // so the new times push the slow ones out entirely.
    Fixtures::pdo()->exec("DELETE FROM tokens WHERE status = 'completed'");
    Fixtures::completedWithDurations(array_fill(0, 10, 120)); // 2 minutes each

    $after = QueueStats::etaFor($token);

    $t->assertSame(9.0, $before['average_minutes'], 'slow history before');
    $t->assertSame(2.0, $after['average_minutes'], 'fast history after');
    $t->assertTrue(
        $after['minutes'] < $before['minutes'],
        sprintf('faster service must lower the wait (was %d, now %d)', $before['minutes'], $after['minutes']),
    );

    // ceil(5 x 2 / 3) = 4
    $t->assertSame(4, $after['minutes'], 'recomputed from the new data');
});

$t->test('a brand new service says so instead of pretending to know', function (TestRunner $t): void {
    Fixtures::seed();

    $token = Fixtures::tokenWithAhead(3);
    $eta   = QueueStats::etaFor($token);

    $t->assertSame('fallback', $eta['source'], 'no history at all');
    $t->assertContains('rough estimate', $eta['label'], 'the label warns the visitor');
});

exit($t->summary());
