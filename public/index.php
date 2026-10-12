<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Front controller
|--------------------------------------------------------------------------
| Every request enters here. Apache/XAMPP rewrites unknown paths to this file
| (see public/.htaccess); the PHP built-in server uses it as the router script.
*/

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Router;

require dirname(__DIR__) . '/app/bootstrap.php';

$request = Request::fromGlobals();
$router  = new Router();

// Route table lives in config/routes.php; later phases add their routes there.
(require BASE_PATH . '/config/routes.php')($router);

try {
    $router->dispatch($request)->send();
} catch (Throwable $e) {
    ErrorHandler::report($e);
    ErrorHandler::response($e)->send();
}
