<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\PageController;
use App\Controllers\StaffController;
use App\Controllers\TokenController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\Throttle;

/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
| Returns a registrar closure so public/index.php stays a tiny front
| controller. Handlers that return an array are wrapped in the standard
| {"ok":true,"data":...} envelope by the Router.
|
| {public_id} is pinned to 16 hex characters, so an obviously bogus id is a
| 404 from the router rather than a database round trip.
|
| Middleware order on staff writes is deliberate: authenticate first (401),
| then check CSRF (403). Asking an anonymous caller for a CSRF token would
| leak whether a session exists.
*/

return static function (Router $router): void {
    // --- infrastructure ---------------------------------------------------
    $router->get('/api/health', [HealthController::class, 'show']);

    // --- public: visitor --------------------------------------------------
    $router->get('/api/services', [TokenController::class, 'services']);
    $router->post(
        '/api/tokens',
        [TokenController::class, 'store'],
        [Throttle::middleware('tokens', Config::int('limits.tokens_per_minute', 10))]
    );
    $router->get('/api/tokens/{public_id:[A-Fa-f0-9]{16}}', [TokenController::class, 'show']);
    $router->post('/api/tokens/{public_id:[A-Fa-f0-9]{16}}/cancel', [TokenController::class, 'cancel']);

    // --- staff pages ------------------------------------------------------
    $router->get('/staff/login', [AuthController::class, 'showLogin']);
    $router->post('/staff/login', [AuthController::class, 'login'], [Csrf::formMiddleware('/staff/login')]);
    $router->post('/staff/logout', [AuthController::class, 'logout'], [Csrf::formMiddleware('/staff/login')]);
    $router->get('/staff', [PageController::class, 'staff']);

    // --- staff API --------------------------------------------------------
    $staffWrite = [Auth::middleware(), Csrf::middleware()];
    $staffRead  = [Auth::middleware()];

    $router->get('/api/staff/overview', [StaffController::class, 'overview'], $staffRead);
    $router->post('/api/staff/counters/{id:\d+}/call-next', [StaffController::class, 'callNext'], $staffWrite);
    $router->post('/api/staff/tokens/{id:\d+}/start', [StaffController::class, 'start'], $staffWrite);
    $router->post('/api/staff/tokens/{id:\d+}/complete', [StaffController::class, 'complete'], $staffWrite);
    $router->post('/api/staff/tokens/{id:\d+}/no-show', [StaffController::class, 'noShow'], $staffWrite);
    $router->post('/api/staff/tokens/{id:\d+}/recall', [StaffController::class, 'recall'], $staffWrite);
};
