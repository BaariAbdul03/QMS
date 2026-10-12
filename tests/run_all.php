<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Run every test file in its own process
|--------------------------------------------------------------------------
|   bash bin/php.sh tests/run_all.php
|
| http_auth_test.php drives a running server and skips itself (with a reason)
| when none is listening. Export QMS_TEST_STAFF_PASSWORD to exercise its
| signed-in flow:
|
|   QMS_TEST_STAFF_PASSWORD='your-password' bash bin/php.sh tests/run_all.php
|--------------------------------------------------------------------------
*/

$tests = [
    'state_machine_test.php',
    'concurrency_test.php',
    'double_call_next_test.php',
    'eta_test.php',
    'security_test.php',
    'http_auth_test.php',
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
