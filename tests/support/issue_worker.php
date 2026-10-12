<?php

declare(strict_types=1);

/*
| Parallel token issuer, used by tests/concurrency_test.php.
|
|   php tests/support/issue_worker.php <serviceId> <barrierEpochSeconds>
|
| The connection is opened before the barrier so that connection setup cost
| does not smear the start time; everything after the barrier is the actual
| race. Prints one JSON line on stdout.
*/

use App\Core\Database;
use App\Models\Token;

require dirname(__DIR__) . '/bootstrap.php';

$serviceId = (int) ($argv[1] ?? 1);
$startAt   = (float) ($argv[2] ?? 0.0);

try {
    Database::connection();

    while ($startAt > 0 && microtime(true) < $startAt) {
        usleep(200);
    }

    // t0/t1 bracket only the database work. The test uses them to prove the
    // workers really overlapped instead of being serialised by the harness.
    $t0    = microtime(true);
    $token = Token::issue($serviceId);
    $t1    = microtime(true);

    fwrite(STDOUT, (string) json_encode([
        'ok'           => true,
        'number'       => (int) $token['number'],
        'display_code' => (string) $token['display_code'],
        'public_id'    => (string) $token['public_id'],
        't0'           => $t0,
        't1'           => $t1,
    ]));

    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, (string) json_encode([
        'ok'    => false,
        'class' => $e::class,
        'error' => $e->getMessage(),
    ]));

    exit(1);
}
