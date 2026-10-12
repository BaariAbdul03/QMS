<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
| Every test and worker script starts here. The app bootstrap already keeps
| raw error reporting for the CLI, so a crashed test exits non-zero instead of
| being swallowed by the JSON error handler.
*/

require dirname(__DIR__) . '/app/bootstrap.php';

// Tests\ -> tests/  (tests/support/TestRunner.php, ...)
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
