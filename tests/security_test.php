<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 3 · auth, CSRF and rate limiting
|--------------------------------------------------------------------------
|   php tests/security_test.php
|
| In-process checks of the staff security layer: credential verification,
| session fixation, CSRF validation and the abuse limit. The end-to-end HTTP
| walk-through lives in tests/http_auth_test.php.
|--------------------------------------------------------------------------
*/

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Throttle;
use Tests\Support\TestRunner;

require __DIR__ . '/bootstrap.php';

$t = new TestRunner('Phase 3 · staff security');

$knownPassword = 'correct horse battery staple';

/** Swap the configured hash for the duration of one test. */
$withHash = static function (string $hash, callable $body): void {
    $config = Config::all();
    $config['security']['staff_password_hash'] = $hash;
    Config::set($config);

    try {
        $body();
    } finally {
        $config['security']['staff_password_hash'] = '';
        Config::set($config);
    }
};

$freshSession = static function (): void {
    Session::reset();
    Session::start();
};

// ---------------------------------------------------------------------------
// Password handling
// ---------------------------------------------------------------------------

$t->test('the configured credential is a hash, never a plain-text password', function (TestRunner $t): void {
    $hash = Config::string('security.staff_password_hash', '');

    if ($hash === '') {
        $t->skip('STAFF_PASSWORD_HASH is empty in this .env, so there is nothing to inspect.');

        return;
    }

    $t->assertTrue(
        password_get_info($hash)['algo'] !== null && password_get_info($hash)['algo'] !== 0,
        'the stored value must be a recognisable password_hash(), not a password',
    );
    $t->assertTrue(strlen($hash) >= 55, 'hash length looks like bcrypt');
});

$t->test('login succeeds only with the right password', function (TestRunner $t) use ($withHash, $knownPassword, $freshSession): void {
    $withHash(password_hash($knownPassword, PASSWORD_DEFAULT), function () use ($t, $knownPassword, $freshSession): void {
        $freshSession();
        $t->assertFalse(Auth::attempt('wrong password'), 'a wrong password must not authenticate');
        $t->assertFalse(Auth::check(), 'no session after a failed attempt');

        $freshSession();
        $t->assertTrue(Auth::attempt($knownPassword), 'the right password authenticates');
        $t->assertTrue(Auth::check(), 'session is authenticated');
        $t->assertTrue(Auth::authenticatedAt() > 0, 'login time recorded');
    });
});

$t->test('login fails closed when no hash is configured', function (TestRunner $t) use ($freshSession): void {
    $config = Config::all();
    $config['security']['staff_password_hash'] = '';
    Config::set($config);

    $freshSession();

    $t->assertFalse(Auth::isConfigured(), 'not configured');
    $t->assertFalse(Auth::attempt(''), 'an empty password cannot log in');
    $t->assertFalse(Auth::attempt('anything'), 'nothing can log in');
    $t->assertFalse(Auth::check(), 'still anonymous');
});

$t->test('login rotates the session id (fixation defence)', function (TestRunner $t) use ($withHash, $knownPassword, $freshSession): void {
    $withHash(password_hash($knownPassword, PASSWORD_DEFAULT), function () use ($t, $knownPassword, $freshSession): void {
        $freshSession();
        $before = session_id();

        Auth::attempt($knownPassword);
        $after = session_id();

        $t->assertTrue($before !== '' && $after !== '', 'both ids exist');
        $t->assertTrue($before !== $after, 'the id changed on privilege change');
        $t->assertTrue(Auth::check(), 'and the new session is the authenticated one');
    });
});

$t->test('logout clears the session', function (TestRunner $t) use ($withHash, $knownPassword, $freshSession): void {
    $withHash(password_hash($knownPassword, PASSWORD_DEFAULT), function () use ($t, $knownPassword, $freshSession): void {
        $freshSession();
        Auth::attempt($knownPassword);
        $t->assertTrue(Auth::check(), 'signed in');

        Auth::logout();
        $freshSession();
        $t->assertFalse(Auth::check(), 'signed out');
    });
});

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

$t->test('a CSRF token is issued once per session and compared exactly', function (TestRunner $t): void {
    Session::reset();
    Session::start();

    $token = Csrf::token();

    $t->assertSame(64, strlen($token), 'token is 32 random bytes in hex');
    $t->assertSame($token, Csrf::token(), 'the same token is reused inside a session');
    $t->assertTrue(Csrf::validate($token), 'the issued token validates');
    $t->assertFalse(Csrf::validate(''), 'empty string does not validate');
    $t->assertFalse(Csrf::validate(null), 'missing token does not validate');
    $t->assertFalse(Csrf::validate(substr($token, 0, -1)), 'a truncated token does not validate');
    $t->assertFalse(Csrf::validate($token . 'x'), 'a longer token does not validate');

    Csrf::rotate();
    $rotated = Csrf::token();
    $t->assertTrue($rotated !== $token, 'rotate() issues a new token');
    $t->assertFalse(Csrf::validate($token), 'the old token is dead after rotation');
});

$t->test('the CSRF middleware accepts the header or the form field, and rejects the rest', function (TestRunner $t): void {
    Session::reset();
    Session::start();

    $token = Csrf::token();
    $guard = Csrf::middleware();

    $jsonPost = static fn (array $headers, string $body = '{}'): Request => Request::create('POST', '/api/staff/tokens/1/start', [], $body, $headers);

    // Header form
    $t->assertSame(null, $guard($jsonPost(['HTTP_X_CSRF_TOKEN' => $token]), []), 'valid header token passes');

    // Form field form
    $t->assertSame(null, $guard($jsonPost(['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], '_token=' . $token), []), 'valid form field passes');

    // Safe methods are exempt
    $t->assertSame(null, $guard(Request::create('GET', '/api/staff/overview'), []), 'GET is exempt');

    // Rejections
    $t->assertSame(403, $t->assertThrows(HttpException::class, static fn () => $guard($jsonPost([]), []), 'no token at all')->statusCode());
    $t->assertSame(403, $t->assertThrows(HttpException::class, static fn () => $guard($jsonPost(['HTTP_X_CSRF_TOKEN' => 'nope']), []), 'wrong token')->statusCode());

    Session::reset();
    Session::start();
    $t->assertSame(403, $t->assertThrows(HttpException::class, static fn () => $guard($jsonPost(['HTTP_X_CSRF_TOKEN' => $token]), []), 'token from a previous session')->statusCode());
});

$t->test('the auth middleware returns 401 for anonymous staff calls', function (TestRunner $t): void {
    Session::reset();
    Session::start();

    $guard = Auth::middleware();

    $failure = $t->assertThrows(HttpException::class, static fn () => $guard(Request::create('POST', '/api/staff/tokens/1/complete'), []), 'anonymous');

    $t->assertSame(401, $failure->statusCode(), '401, not 403 and not 500');
});

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

$t->test('the rate limiter allows up to the limit, then refuses', function (TestRunner $t): void {
    $dir = STORAGE_PATH . '/rate-limit-test';
    RateLimiter::clear(null, $dir);

    try {
        for ($i = 1; $i <= 3; $i++) {
            $t->assertTrue(RateLimiter::allows('probe', 3, 60, $dir), 'request ' . $i . ' within the limit');
        }

        $t->assertFalse(RateLimiter::allows('probe', 3, 60, $dir), 'the 4th request is refused');

        // A different key has its own budget.
        $t->assertTrue(RateLimiter::allows('other', 3, 60, $dir), 'a different key is unaffected');

        $t->assertTrue(RateLimiter::retryAfter('probe', $dir) > 0, 'Retry-After is positive');

        // The window is fixed: switching to a shorter window does not shorten a
        // window that is already open, so the budget must still be exhausted.
        $t->assertFalse(RateLimiter::allows('probe', 3, 0, $dir), 'an open window is not reset early');

        // Clearing the key (the demo reset path) does hand out a fresh budget.
        RateLimiter::clear('probe', $dir);
        $t->assertTrue(RateLimiter::allows('probe', 3, 60, $dir), 'clearing the key resets the budget');
    } finally {
        RateLimiter::clear(null, $dir);
        @rmdir($dir);
    }
});

$t->test('a zero limit disables the limiter', function (TestRunner $t): void {
    for ($i = 0; $i < 5; $i++) {
        $t->assertTrue(RateLimiter::allows('disabled', 0, 60), 'limit 0 always allows');
    }

    $t->assertSame(null, Throttle::middleware('tokens', 0)(Request::create('POST', '/api/tokens'), []), 'the middleware stands aside');
});

$t->test('the throttle middleware answers 429 with Retry-After', function (TestRunner $t): void {
    $dir = STORAGE_PATH . '/rate-limit-test';

    $guard = Throttle::middleware('tokens', 1);

    // Route the limiter at the test directory by cleaning the real one first;
    // the middleware uses the default store, so use a unique IP instead.
    $request = Request::create('POST', '/api/tokens', [], '{}', ['REMOTE_ADDR' => '203.0.113.77']);

    $t->assertSame(null, $guard($request, []), 'first request allowed');

    $response = $guard($request, []);

    $t->assertTrue($response instanceof Response, 'the second request is short-circuited with a response');
    $t->assertSame(429, $response->status(), 'status 429');
    $t->assertTrue(isset($response->headers()['Retry-After']), 'Retry-After header present');

    $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    $t->assertSame(false, $body['ok'], 'failure envelope');
    $t->assertContains('Too many requests', (string) $body['error'], 'readable message');

    RateLimiter::clear('tokens:203.0.113.77');
    @rmdir($dir);
});

exit($t->summary());
