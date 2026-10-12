<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
| Everything the app needs before a request is handled:
|   1. PSR-4-style autoloader for the App\ namespace (no Composer)
|   2. .env into memory
|   3. config into the Config store
|   4. default timezone (Asia/Kolkata — see the plan, item 7)
|   5. global error handler so failures are JSON, never a stack trace
|
| Included by public/index.php and by every CLI script in tests/ and database/.
*/

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\Logger;

if (defined('QMS_BOOTSTRAPPED')) {
    return;
}

define('QMS_BOOTSTRAPPED', true);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');

// --- 1. autoloader: App\Core\Env -> app/Core/Env.php -----------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// --- 2 & 3. environment + config ------------------------------------------
Env::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config/config.php');

// --- 3b. view helpers (e(), asset(), url()) -------------------------------
require APP_PATH . '/Core/helpers.php';

// --- 4. timezone ----------------------------------------------------------
// The queue day boundary is computed in this zone and passed to MySQL as a
// parameter; CURDATE() is never trusted (its session tz is pinned separately).
date_default_timezone_set(Config::string('app.timezone', 'Asia/Kolkata'));

// --- 5. errors ------------------------------------------------------------
Logger::configure(STORAGE_PATH . '/logs');

// CLI keeps PHP's own error reporting: a crashed script or test must exit
// non-zero and show why, rather than print a friendly JSON envelope that looks
// like success. The JSON boundary exists for HTTP clients.
if (PHP_SAPI === 'cli') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ErrorHandler::register(Config::bool('app.debug', false));
}
