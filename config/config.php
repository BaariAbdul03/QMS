<?php

declare(strict_types=1);

use App\Core\Env;

/*
|--------------------------------------------------------------------------
| Application config
|--------------------------------------------------------------------------
| Read through App\Core\Config::get('db.host'). Values come from .env so the
| same code runs on a laptop and on a server without edits.
*/

return [
    'app' => [
        'name'     => Env::get('APP_NAME', 'Smart QMS'),
        'env'      => Env::get('APP_ENV', 'local'),
        'debug'    => Env::bool('APP_DEBUG', false),
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
    ],

    'db' => [
        'host'     => Env::get('DB_HOST', '127.0.0.1'),
        'port'     => Env::int('DB_PORT', 3306),
        'name'     => Env::get('DB_NAME', 'qms'),
        'user'     => Env::get('DB_USER', 'root'),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
        // Pin the MySQL session timezone to the app zone so NOW() and the
        // queue_date the app computes always agree (plan item 7).
        'timezone' => Env::get('DB_TIMEZONE', '+05:30'),
    ],

    'queue' => [
        // Token numbers are padded to this width in the display code: A-014.
        'number_padding' => Env::int('QUEUE_NUMBER_PADDING', 3),
        // Days of history the display board and ETA keep in mind.
        'history_days'   => Env::int('QUEUE_HISTORY_DAYS', 3),
    ],

    'security' => [
        // password_hash() of the staff password. Generate with:
        //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
        'staff_password_hash' => Env::get('STAFF_PASSWORD_HASH', '') ?? '',
        'session_name'        => Env::get('SESSION_NAME', 'qms_staff'),
    ],

    'limits' => [
        // Simple per-IP budget for POST /api/tokens.
        'tokens_per_minute' => Env::int('RATE_LIMIT_TOKENS_PER_MINUTE', 10),
    ],

    'paths' => [
        'storage' => STORAGE_PATH,
        'views'   => APP_PATH . '/Views',
    ],
];
