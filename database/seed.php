<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Demo seed
|--------------------------------------------------------------------------
|
|   php database/seed.php            # top up today's queue, keep history
|   php database/seed.php --fresh    # wipe the queue and rebuild everything
|
| Creates a queue that looks lived-in so the ETA has real history to learn
| from on first launch: two services, three counters, ~200 completed visitors
| over the last three days with 4-10 minute service times, and a few people
| still waiting today.
|
| All timestamps are computed in APP_TIMEZONE and written as literals, so the
| data always agrees with the queue_date the application derives.
|--------------------------------------------------------------------------
*/

use App\Core\Clock;
use App\Core\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

$fresh = in_array('--fresh', $argv, true);

$pdo = Database::connection();
$today = Clock::today();

// ---------------------------------------------------------------------------
// Services and counters
// ---------------------------------------------------------------------------

$pdo->exec(
    "INSERT INTO services (id, name, prefix, avg_service_minutes, is_active) VALUES
        (1, 'General OPD', 'A', 7.00, 1),
        (2, 'Billing',     'B', 4.00, 1)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name), prefix = VALUES(prefix),
        avg_service_minutes = VALUES(avg_service_minutes), is_active = 1"
);

$pdo->exec(
    "INSERT INTO counters (id, name, service_id, staff_name, is_open) VALUES
        (1, 'Counter 1', 1,    'Asha',  1),
        (2, 'Counter 2', 1,    'Ravi',  1),
        (3, 'Counter 3', NULL, 'Meera', 1)
     ON DUPLICATE KEY UPDATE
        name = VALUES(name), service_id = VALUES(service_id),
        staff_name = VALUES(staff_name), is_open = 1"
);

if ($fresh) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE tokens');
    $pdo->exec('TRUNCATE TABLE queue_counters');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Queue tables cleared." . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Completed history
// ---------------------------------------------------------------------------

/**
 * Walk a working day for one desk, creating completed tokens.
 *
 * @return array{0: int, 1: DateTimeImmutable} last number used, closing time
 */
function seedDay(PDO $pdo, string $date, int $serviceId, string $prefix, int $counterId, DateTimeImmutable $open, DateTimeImmutable $until, int $startNumber): array
{
    $number = $startNumber;
    $cursor = $open;
    $insert = $pdo->prepare(
        "INSERT INTO tokens (public_id, service_id, counter_id, queue_date, number, display_code, status,
                             taken_at, called_at, serving_at, completed_at)
         VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?)"
    );

    while ($cursor < $until && $number < 999) {
        $duration = random_int(4, 10);          // minutes actually served
        $gap      = random_int(1, 5);           // idle time before the next visitor

        $taken     = $cursor;
        $called    = $taken->modify('+1 minute');
        $serving   = $called->modify('+1 minute');
        $completed = $serving->modify(sprintf('+%d minutes', $duration));

        $number++;

        $insert->execute([
            bin2hex(random_bytes(8)),
            $serviceId,
            $counterId,
            $date,
            $number,
            $prefix . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            $taken->format('Y-m-d H:i:s'),
            $called->format('Y-m-d H:i:s'),
            $serving->format('Y-m-d H:i:s'),
            $completed->format('Y-m-d H:i:s'),
        ]);

        $cursor = $completed->modify(sprintf('+%d minutes', $gap));
    }

    return [$number, $cursor];
}

// 1 = General OPD served by counters 1 and 2; 2 = Billing served by counter 3.
$desks = [
    ['service' => 1, 'counter' => 1, 'prefix' => 'A'],
    ['service' => 1, 'counter' => 2, 'prefix' => 'A'],
    ['service' => 2, 'counter' => 3, 'prefix' => 'B'],
];

$created = 0;

for ($daysAgo = 2; $daysAgo >= 0; $daysAgo--) {
    $day   = Clock::now()->modify(sprintf('-%d days', $daysAgo));
    $date  = $day->format('Y-m-d');
    $open  = $day->setTime(9, 0, 0);
    $close = $day->setTime(17, 0, 0);

    // Today only has history up to now, so the board does not show the future.
    if ($daysAgo === 0) {
        $now     = Clock::now();
        $close   = $now < $open ? $open->modify('+30 minutes') : $now->modify('-15 minutes');
    }

    // Per day, numbering restarts; keep one running number per service.
    $next = [1 => 1, 2 => 1];

    foreach ($desks as $desk) {
        [$last] = seedDay(
            $pdo,
            $date,
            $desk['service'],
            $desk['prefix'],
            $desk['counter'],
            $open,
            $close,
            $next[$desk['service']]
        );

        $next[$desk['service']] = $last;

        if ($daysAgo > 0 || $last > 0) {
            $created += 1;
        }
    }

    // Keep the allocation row ahead of the history for every service/day.
    foreach ([1 => 'A', 2 => 'B'] as $serviceId => $prefix) {
        $max = (int) $pdo->query(
            sprintf('SELECT COALESCE(MAX(number), 0) FROM tokens WHERE service_id = %d AND queue_date = %s', $serviceId, $pdo->quote($date))
        )->fetchColumn();

        $pdo->prepare(
            'INSERT INTO queue_counters (service_id, queue_date, last_number) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, ?)'
        )->execute([$serviceId, $date, $max, $max]);
    }
}

// ---------------------------------------------------------------------------
// Today's live queue
// ---------------------------------------------------------------------------

$now = Clock::now();

// A counter holds at most one token, so the two active visitors for General OPD
// sit on two different desks rather than both on Counter 1.
$live = [
    [
        'service' => 1,
        'prefix'  => 'A',
        'active'  => [
            ['counter' => 1, 'status' => 'serving'],
            ['counter' => 2, 'status' => 'called'],
        ],
        'waiting' => 5,
    ],
    [
        'service' => 2,
        'prefix'  => 'B',
        // Billing is served by the floating Counter 3, which starts idle so the
        // demo can show a shared desk picking up work.
        'active'  => [],
        'waiting' => 3,
    ],
];

$insertLive = $pdo->prepare(
    "INSERT INTO tokens (public_id, service_id, counter_id, queue_date, number, display_code, status,
                         taken_at, called_at, serving_at, completed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

$advance = $pdo->prepare(
    'INSERT INTO queue_counters (service_id, queue_date, last_number) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, ?)'
);

foreach ($live as $spec) {
    $serviceId = $spec['service'];
    $row = $pdo->prepare('SELECT last_number FROM queue_counters WHERE service_id = ? AND queue_date = ?');
    $row->execute([$serviceId, $today]);
    $number = (int) $row->fetchColumn();

    $total = count($spec['active']) + $spec['waiting'];
    $index = 0;

    // The active visitors were taken first, so they get the earliest slots.
    foreach ($spec['active'] as $active) {
        $number++;
        $minsAgo = $total - $index + 4;

        $insertLive->execute([
            bin2hex(random_bytes(8)),
            $serviceId,
            $active['counter'],
            $today,
            $number,
            $spec['prefix'] . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            $active['status'],
            $now->modify(sprintf('-%d minutes', $minsAgo))->format('Y-m-d H:i:s'),
            $now->modify(sprintf('-%d minutes', $minsAgo - 1))->format('Y-m-d H:i:s'),
            $active['status'] === 'serving'
                ? $now->modify(sprintf('-%d minutes', $minsAgo - 2))->format('Y-m-d H:i:s')
                : null,
            null,
        ]);

        $index++;
    }

    for ($i = 0; $i < $spec['waiting']; $i++) {
        $number++;
        $minsAgo = max(1, $total - $index);

        $insertLive->execute([
            bin2hex(random_bytes(8)),
            $serviceId,
            null,
            $today,
            $number,
            $spec['prefix'] . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'waiting',
            $now->modify(sprintf('-%d minutes', $minsAgo))->format('Y-m-d H:i:s'),
            null,
            null,
            null,
        ]);

        $index++;
    }

    $advance->execute([$serviceId, $today, $number, $number]);
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

$summary = $pdo->query(
    "SELECT queue_date, status, COUNT(*) AS c FROM tokens GROUP BY queue_date, status ORDER BY queue_date, status"
)->fetchAll();

echo PHP_EOL . 'Seeded demo data.' . PHP_EOL . PHP_EOL;

$byDate = [];

foreach ($summary as $row) {
    $byDate[(string) $row['queue_date']][(string) $row['status']] = (int) $row['c'];
}

foreach ($byDate as $date => $counts) {
    $parts = [];

    foreach ($counts as $status => $count) {
        $parts[] = $status . '=' . $count;
    }

    echo sprintf('  %s  %s%s', $date, implode(', ', $parts), PHP_EOL);
}

echo PHP_EOL;
echo '  Staff login: http://127.0.0.1:8080/staff' . PHP_EOL;
echo '  Display:     http://127.0.0.1:8080/display' . PHP_EOL;
echo '  Visitor:     http://127.0.0.1:8080/' . PHP_EOL . PHP_EOL;
