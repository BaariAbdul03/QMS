<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\StaffController;
use App\Controllers\TokenController;
use App\Core\Router;

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
*/

return static function (Router $router): void {
    // --- infrastructure ---------------------------------------------------
    $router->get('/api/health', [HealthController::class, 'show']);

    // --- public: visitor ------------------------------------------------
    $router->get('/api/services', [TokenController::class, 'services']);
    $router->post('/api/tokens', [TokenController::class, 'store']);
    $router->get('/api/tokens/{public_id:[A-Fa-f0-9]{16}}', [TokenController::class, 'show']);
    $router->post('/api/tokens/{public_id:[A-Fa-f0-9]{16}}/cancel', [TokenController::class, 'cancel']);

    // --- staff ----------------------------------------------------------
    // TODO(phase 3): wrap these in Auth::staffMiddleware() + CSRF.
    $router->get('/api/staff/overview', [StaffController::class, 'overview']);
    $router->post('/api/staff/counters/{id:\d+}/call-next', [StaffController::class, 'callNext']);
    $router->post('/api/staff/tokens/{id:\d+}/start', [StaffController::class, 'start']);
    $router->post('/api/staff/tokens/{id:\d+}/complete', [StaffController::class, 'complete']);
    $router->post('/api/staff/tokens/{id:\d+}/no-show', [StaffController::class, 'noShow']);
    $router->post('/api/staff/tokens/{id:\d+}/recall', [StaffController::class, 'recall']);
};
