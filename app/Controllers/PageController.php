<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Counter;

/**
 * The HTML pages. Phase 3 adds the staff panel; Phase 4 adds the visitor page
 * and the display board.
 */
final class PageController
{
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
