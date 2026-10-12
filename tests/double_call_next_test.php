<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 2 · double call-next — exactly one winner
|--------------------------------------------------------------------------
|
|   php tests/double_call_next_test.php
|
| Two counters click "Call next" at the same instant with one visitor waiting.
| FOR UPDATE SKIP LOCKED must give the token to exactly one of them; the other
| must be told the queue is empty, never handed the same visitor.
|
| Also covers two clicks on the SAME counter, where the counter row lock plus
| the "already holding a token" guard must reject the second click.
|--------------------------------------------------------------------------
*/

use Tests\Support\Fixtures;
use Tests\Support\ProcessPool;
use Tests\Support\TestRunner;

require __DIR__ . '/bootstrap.php';

$t = new TestRunner('Phase 2 · double call-next race');

/**
 * @param list<int> $counterIds
 * @return list<array{stdout: string, stderr: string, exit: int, json: array<string, mixed>|null}>
 */
function raceCallNext(array $counterIds): array
{
    $commands = [];

    foreach ($counterIds as $counterId) {
        $commands[] = ProcessPool::worker(__DIR__ . '/support/call_next_worker.php', [(string) $counterId]);
    }

    return ProcessPool::runParallel($commands);
}

$t->test('two counters, one visitor: exactly one claims it', function (TestRunner $t): void {
    Fixtures::seed();
    $token = \App\Models\Token::issue(Fixtures::GENERAL);

    $results = raceCallNext([Fixtures::COUNTER_1, Fixtures::COUNTER_2]);

    $claimed = [];
    $empty   = [];

    foreach ($results as $result) {
        $t->assertSame(0, $result['exit'], 'worker exit code (stderr: ' . $result['stderr'] . ')');
        $t->assertTrue(is_array($result['json']), 'worker produced JSON: ' . $result['stdout']);

        if (($result['json']['claimed'] ?? false) === true) {
            $claimed[] = $result['json']['token'];
        } else {
            $empty[] = $result['json'];
        }

        $t->assertTrue(
            ($result['json']['t0'] ?? null) !== null,
            'worker reported a timing bracket so overlap is provable',
        );
    }

    $t->assertSame(1, count($claimed), 'exactly one counter got the token');
    $t->assertSame(1, count($empty), 'the other counter got an empty queue');

    // The plan's wording: the loser is told the queue is empty, not handed the
    // same visitor.
    $t->assertSame(null, $empty[0]['token'], 'loser received no token');
    $t->assertSame(true, (bool) $empty[0]['ok'], 'an empty queue is not an error');
    $t->assertFalse(isset($empty[0]['status']), 'no 4xx for a simple empty queue');

    $t->assertSame((int) $token['id'], (int) $claimed[0]['id'], 'the single waiting visitor was the one called');

    // And the database agrees: the token belongs to exactly one counter.
    $row = Fixtures::pdo()->query(
        'SELECT status, counter_id FROM tokens WHERE id = ' . (int) $token['id']
    )->fetch();

    $t->assertSame('called', (string) $row['status'], 'token status in the database');
    $t->assertSame((int) $claimed[0]['counter_id'], (int) $row['counter_id'], 'one recorded owner');
});

$t->test('five counters, one visitor: still exactly one winner', function (TestRunner $t): void {
    Fixtures::seed();

    // A fifth counter so five desks race for a single token.
    Fixtures::pdo()->exec("INSERT INTO counters (id, name, service_id, staff_name, is_open)
                           VALUES (4, 'Counter 4', 1, 'Dev', 1), (5, 'Counter 5', 1, 'Nik', 1)");

    \App\Models\Token::issue(Fixtures::GENERAL);

    $results = raceCallNext([1, 2, 3, 4, 5]);

    $claimed = 0;

    foreach ($results as $result) {
        if (($result['json']['claimed'] ?? false) === true) {
            $claimed++;
        }
    }

    $t->assertSame(1, $claimed, 'one winner out of five');

    $row = Fixtures::pdo()->query("SELECT COUNT(*) AS c FROM tokens WHERE status = 'called'")->fetch();
    $t->assertSame(1, (int) $row['c'], 'exactly one called token');
});

$t->test('five counters, three visitors: exactly three win, nobody is dropped or doubled', function (TestRunner $t): void {
    Fixtures::seed();
    Fixtures::pdo()->exec("INSERT INTO counters (id, name, service_id, staff_name, is_open)
                           VALUES (4, 'Counter 4', 1, 'Dev', 1), (5, 'Counter 5', 1, 'Nik', 1)");

    $first  = \App\Models\Token::issue(Fixtures::GENERAL);
    $second = \App\Models\Token::issue(Fixtures::GENERAL);
    $third  = \App\Models\Token::issue(Fixtures::GENERAL);

    $results = raceCallNext([1, 2, 3, 4, 5]);

    $claimedIds = [];
    $empty      = 0;

    foreach ($results as $result) {
        if (($result['json']['claimed'] ?? false) === true) {
            $claimedIds[] = (int) $result['json']['token']['id'];
        } else {
            $empty++;
        }
    }

    sort($claimedIds);

    $t->assertCount(3, $claimedIds, 'three winners for three visitors');
    $t->assertSame(3, count(array_unique($claimedIds)), 'no token was handed out twice');
    $t->assertSame(
        [(int) $first['id'], (int) $second['id'], (int) $third['id']],
        $claimedIds,
        'every waiting visitor was served — no counter was starved by another counter\'s locks',
    );
    $t->assertSame(2, $empty, 'the two spare counters were told the queue is empty');
    $t->assertSame(0, \App\Models\Token::waitingCount(Fixtures::GENERAL), 'the queue drained completely');
});

$t->test('sequential call-next is strict FIFO by token number', function (TestRunner $t): void {
    Fixtures::seed();

    $first  = \App\Models\Token::issue(Fixtures::GENERAL);
    $second = \App\Models\Token::issue(Fixtures::GENERAL);
    $third  = \App\Models\Token::issue(Fixtures::GENERAL);

    // Real desks click a few seconds apart, so this is the ordering that
    // actually matters day to day. Simultaneous clicks are a tie: every waiting
    // visitor still gets served, but which desk gets which token is undefined.
    $order = [];

    foreach ([Fixtures::COUNTER_1, Fixtures::COUNTER_2, Fixtures::COUNTER_3] as $counterId) {
        $claimed = \App\Models\Token::callNext($counterId);
        $order[] = (int) $claimed['id'];
    }

    $t->assertSame(
        [(int) $first['id'], (int) $second['id'], (int) $third['id']],
        $order,
        'oldest waiting token first',
    );
});

$t->test('two clicks on the same counter: one call, one conflict', function (TestRunner $t): void {
    Fixtures::seed();
    \App\Models\Token::issue(Fixtures::GENERAL);
    \App\Models\Token::issue(Fixtures::GENERAL);

    $results = raceCallNext([Fixtures::COUNTER_1, Fixtures::COUNTER_1]);

    $claimed  = 0;
    $rejected = 0;

    foreach ($results as $result) {
        $t->assertSame(0, $result['exit'], 'worker exit code (stderr: ' . $result['stderr'] . ')');

        if (($result['json']['claimed'] ?? false) === true) {
            $claimed++;
        } elseif (($result['json']['status'] ?? 0) === 409) {
            $rejected++;
            $t->assertContains('before calling the next visitor', (string) $result['json']['error'], 'friendly busy message');
        }
    }

    $t->assertSame(1, $claimed, 'one click won');
    $t->assertSame(1, $rejected, 'the second click was rejected with 409');

    $row = Fixtures::pdo()->query(
        "SELECT COUNT(*) AS c FROM tokens WHERE counter_id = 1 AND status IN ('called', 'serving')"
    )->fetch();

    $t->assertSame(1, (int) $row['c'], 'the counter holds exactly one token');
});

exit($t->summary());
