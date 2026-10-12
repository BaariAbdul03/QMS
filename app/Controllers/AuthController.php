<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Staff sign-in. The password is a password_hash() in .env; there is no user
 * table and no plain-text secret anywhere in the repository.
 */
final class AuthController
{
    /**
     * GET /staff/login
     *
     * @param array<string, string|null> $params
     */
    public function showLogin(Request $request, array $params = []): Response
    {
        if (Auth::check()) {
            return Response::redirect('/staff');
        }

        // A CSRF rejection redirects here with ?expired=1 (Csrf::formMiddleware).
        $expired = $request->query('expired') !== null
            ? 'That form had expired. Please sign in again.'
            : null;

        return $this->form($request, $expired);
    }

    /**
     * POST /staff/login
     *
     * @param array<string, string|null> $params
     */
    public function login(Request $request, array $params = []): Response
    {
        $password = (string) $request->input('password', '');

        if (Auth::attempt($password)) {
            return Response::redirect($this->safeNext((string) $request->input('next', '/staff')));
        }

        return $this->form($request, 'That password is not right.', 401);
    }

    /**
     * POST /staff/logout
     *
     * @param array<string, string|null> $params
     */
    public function logout(Request $request, array $params = []): Response
    {
        Auth::logout();

        return Response::redirect('/staff/login');
    }

    private function form(Request $request, ?string $error, int $status = 200): Response
    {
        return View::render('login', [
            'title'      => 'Staff sign in',
            'bodyClass'  => 'qms-body qms-body--login',
            'csrf'       => Csrf::token(),
            'next'       => $this->safeNext((string) $request->query('next', '/staff')),
            'error'      => $error,
            'configured' => Auth::isConfigured(),
        ])->withoutCache()->withHeader('X-Robots-Tag', 'noindex')
            // The login form must not be cached or back-button replayable.
            ->withHeader('Vary', 'Cookie')
            ->withStatus($status);
    }

    /**
     * Only ever redirect inside this application: an attacker-supplied ?next=
     * must not become an open redirect.
     */
    private function safeNext(string $next): string
    {
        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '/staff';
        }

        return $next;
    }
}
