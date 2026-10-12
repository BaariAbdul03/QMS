<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Counter;
use App\Models\Service;

/**
 * The HTML pages. Phase 3 adds the staff panel; Phase 4 adds the visitor page
 * and the display board.
 */
final class PageController
{
    /**
     * GET / — the visitor page.
     *
     * Rendered without any ticket in it: the ticket lives in the browser's
     * localStorage, so a refresh keeps the visitor's place without the page
     * needing a cookie or a session.
     *
     * @param array<string, string|null> $params
     */
    public function visitor(Request $request, array $params = []): Response
    {
        return View::render('visitor', [
            'title'     => 'Take a token',
            'bodyClass' => 'qms-body qms-body--visitor',
            'services'  => Service::listWithWaiting(),
            'api'       => [
                'services' => '/api/services',
                'tokens'   => '/api/tokens',
            ],
        ])->withoutCache();
    }

    /**
     * GET /display — the waiting-room board.
     *
     * @param array<string, string|null> $params
     */
    public function display(Request $request, array $params = []): Response
    {
        return View::render('display', [
            'title'     => 'Now serving',
            'bodyClass' => 'qms-body qms-body--display',
            'api'       => ['status' => '/api/status'],
        ])->withoutCache();
    }

    /**
     * GET /staff
     *
     * @param array<string, string|null> $params
     */
    public function staff(Request $request, array $params = []): Response
    {
        if ($guard = Auth::requirePage($request)) {
            return $guard;
        }

        return View::render('staff', [
            'title'     => 'Staff panel',
            'bodyClass' => 'qms-body qms-body--staff',
            'csrf'      => Csrf::token(),
            'counters'  => Counter::all(),
        ])
            ->withoutCache()
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
