<?php

declare(strict_types=1);

/*
| Parallel call-next worker, used by tests/double_call_next_test.php.
|
|   php tests/support/call_next_worker.php <counterId> <barrierEpochSeconds>
|
| A domain outcome (queue empty, counter busy) exits 0 with ok:false and the
| HTTP status the controller would have produced; only an unexpected failure
| exits 1.
*/

use App\Core\Database;
use App\Core\Exceptions\AppException;
use App\Models\Token;

require dirname(__DIR__) . '/bootstrap.php';

$counterId = (int) ($argv[1] ?? 1);
$startAt   = (float) ($argv[2] ?? 0.0);

try {
    Database::connection();

    while ($startAt > 0 && microtime(true) < $startAt) {
        usleep(200);
    }

    $t0    = microtime(true);
    $token = Token::callNext($counterId);
    $t1    = microtime(true);

    fwrite(STDOUT, (string) json_encode([
        'ok'      => true,
        'claimed' => $token !== null,
        't0'      => $t0,
        't1'      => $t1,
        'token'   => $token === null ? null : [
            'id'           => (int) $token['id'],
            'display_code' => (string) $token['display_code'],
            'counter_id'   => (int) $token['counter_id'],
        ],
    ]));

    exit(0);
} catch (AppException $e) {
    fwrite(STDOUT, (string) json_encode([
        'ok'     => false,
        'status' => $e->statusCode(),
        'error'  => $e->getMessage(),
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
