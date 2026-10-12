<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * GET /api/health
 *
 * Liveness plus a one-round-trip database probe, so a bad DB password shows up
 * here as a clean 503 instead of a blank page.
 */
final class HealthController
{
    /**
     * @param array<string, string|null> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $pdo     = Database::connection();
        $one     = (int) $pdo->query('SELECT 1')->fetchColumn();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        return Response::ok([
            'status'       => 'ok',
            'db'           => $one === 1 ? 'ok' : 'unexpected',
            'db_version'   => $version,
            'php_version'  => PHP_VERSION,
            'app'          => Config::string('app.name'),
            'environment'  => Config::string('app.env'),
            'timezone'     => date_default_timezone_get(),
            'server_time'  => date(DATE_ATOM),
        ])->withoutCache();
    }
}
