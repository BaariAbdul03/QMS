<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap
|--------------------------------------------------------------------------
| Every test and worker script starts here. QMS_TESTING tells the app
| bootstrap to keep raw error reporting, so a crashed test exits non-zero
| instead of being caught by the JSON error handler.
*/

putenv('QMS_TESTING=1');

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
