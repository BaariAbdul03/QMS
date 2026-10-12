<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Demo reset
|--------------------------------------------------------------------------
|
|   php database/reset.php          # empty the queue, keep services/counters
|   php database/reset.php --all    # also remove services and counters
|
| Clears the queue tables so a demo can be run again from a clean slate, and
| drops the ephemeral state that would otherwise make the next run confusing:
| rate-limit windows and staff sessions.
|--------------------------------------------------------------------------
*/

use App\Core\Clock;
use App\Core\Database;
use App\Core\RateLimiter;

require dirname(__DIR__) . '/app/bootstrap.php';

$all = in_array('--all', $argv, true);

$pdo = Database::connection();

$before = [
    'tokens'         => (int) $pdo->query('SELECT COUNT(*) FROM tokens')->fetchColumn(),
    'queue_counters' => (int) $pdo->query('SELECT COUNT(*) FROM queue_counters')->fetchColumn(),
];

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('TRUNCATE TABLE tokens');
$pdo->exec('TRUNCATE TABLE queue_counters');

if ($all) {
    $pdo->exec('TRUNCATE TABLE counters');
    $pdo->exec('TRUNCATE TABLE services');
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

RateLimiter::clear();

// Staff sessions: expired ones would be pruned by PHP eventually, but a reset
// should leave the machine as it found it.
$sessionPath = (string) ini_get('session.save_path');

if ($sessionPath !== '' && is_dir($sessionPath)) {
    foreach (glob(rtrim($sessionPath, '/\\') . '/sess_*') ?: [] as $file) {
        @unlink($file);
    }
}

echo PHP_EOL . 'Queue reset.' . PHP_EOL;
echo sprintf('  removed %d token(s) and %d counter row(s)%s', $before['tokens'], $before['queue_counters'], PHP_EOL);
echo sprintf('  services/counters: %s%s', $all ? 'removed' : 'kept', PHP_EOL);
echo sprintf('  today was %s%s', Clock::today(), PHP_EOL);
echo '  rate-limit windows cleared, staff sessions dropped' . PHP_EOL . PHP_EOL;
