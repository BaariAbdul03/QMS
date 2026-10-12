<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Core\Router;

/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
| Returns a registrar closure so public/index.php stays a 15-line front
| controller. Handlers that return an array are wrapped in the standard
| {"ok":true,"data":...} envelope by the Router.
*/

return static function (Router $router): void {
    $router->get('/api/health', [HealthController::class, 'show']);
};
