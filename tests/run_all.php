<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Run every Phase 2 test file in its own process
|--------------------------------------------------------------------------
|   php tests/run_all.php
|--------------------------------------------------------------------------
*/

$tests = [
    'state_machine_test.php',
    'concurrency_test.php',
    'double_call_next_test.php',
];

$failed = 0;

foreach ($tests as $test) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $test);
    $code    = 0;

    passthru($command, $code);

    if ($code !== 0) {
        $failed++;
    }
}

fwrite(STDOUT, sprintf(
    "\n%s: %d of %d test files failed\n\n",
    $failed === 0 ? 'ALL GREEN' : 'FAILURES',
    $failed,
    count($tests),
));

exit($failed === 0 ? 0 : 1);
