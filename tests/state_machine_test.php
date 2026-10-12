<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 2 · state machine
|--------------------------------------------------------------------------
|   php tests/state_machine_test.php
|
| Every legal transition must pass; every illegal one must throw an
| InvalidTransitionException (HTTP 409) instead of silently changing the row.
|--------------------------------------------------------------------------
*/

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\ErrorHandler;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\InvalidTransitionException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Models\Counter;
use App\Models\Service;
use App\Models\Token;
use Tests\Support\Fixtures;
use Tests\Support\TestRunner;

require __DIR__ . '/bootstrap.php';

$t = new TestRunner('Phase 2 · token state machine');

// ---------------------------------------------------------------------------
// Issuing
// ---------------------------------------------------------------------------

$t->test('issue() creates a waiting token with a padded display code', function (TestRunner $t): void {
    Fixtures::seed();

    $token = Token::issue(Fixtures::GENERAL);

    $t->assertSame(Token::WAITING, (string) $token['status'], 'new token status');
    $t->assertSame(1, (int) $token['number'], 'first number of the day');
    $t->assertSame('A-001', (string) $token['display_code'], 'display code');
    $t->assertSame(Fixtures::GENERAL, (int) $token['service_id'], 'service');
    $t->assertSame(null, $token['counter_id'], 'counter is null while waiting');
    $t->assertSame(null, $token['called_at'], 'called_at is null while waiting');
    $t->assertSame(Clock::today(), (string) $token['queue_date'], 'queue date comes from Clock, not CURDATE()');
    $t->assertSame(16, strlen((string) $token['public_id']), 'public_id is 16 chars');
    $t->assertTrue(ctype_xdigit((string) $token['public_id']), 'public_id is hex');
});

$t->test('numbers increment per service and are independent between services', function (TestRunner $t): void {
    Fixtures::seed();

    $a1 = Token::issue(Fixtures::GENERAL);
    $a2 = Token::issue(Fixtures::GENERAL);
    $b1 = Token::issue(Fixtures::BILLING);
    $a3 = Token::issue(Fixtures::GENERAL);

    $t->assertSame([1, 2, 3], [(int) $a1['number'], (int) $a2['number'], (int) $a3['number']], 'service A numbers');
    $t->assertSame(1, (int) $b1['number'], 'service B restarts at 1');
    $t->assertSame('B-001', (string) $b1['display_code'], 'service B code uses its own prefix');
});

$t->test('the queue day rolls over: tomorrow starts again at 1', function (TestRunner $t): void {
    Fixtures::seed();
    Clock::freeze('2026-10-12 23:59:00');

    try {
        $today = Token::issue(Fixtures::GENERAL);
        Token::issue(Fixtures::GENERAL);

        Clock::freeze('2026-10-13 00:01:00');
        $tomorrow = Token::issue(Fixtures::GENERAL);

        $t->assertSame('2026-10-12', (string) $today['queue_date'], 'frozen date');
        $t->assertSame('2026-10-13', (string) $tomorrow['queue_date'], 'next day');
        $t->assertSame(1, (int) $tomorrow['number'], 'new day restarts numbering');

        $t->assertSame(2, Token::waitingCount(Fixtures::GENERAL, '2026-10-12'), 'yesterday still has its 2 tokens');
        $t->assertSame(1, Token::waitingCount(Fixtures::GENERAL, '2026-10-13'), 'today has 1');
    } finally {
        Clock::unfreeze();
    }
});

$t->test('issue() rejects unknown and inactive services', function (TestRunner $t): void {
    Fixtures::seed();
    Fixtures::pdo()->exec('UPDATE services SET is_active = 0 WHERE id = 2');

    $t->assertThrows(HttpException::class, fn () => Token::issue(999), 'unknown service');

    $closed = $t->assertThrows(HttpException::class, fn () => Token::issue(Fixtures::BILLING), 'inactive service');
    $t->assertSame(409, $closed->statusCode(), 'inactive service status');
});

// ---------------------------------------------------------------------------
// Legal transitions
// ---------------------------------------------------------------------------

$t->test('waiting -> called -> serving -> completed', function (TestRunner $t): void {
    Fixtures::seed();
    $token = Token::issue(Fixtures::GENERAL);

    $called = Token::callNext(Fixtures::COUNTER_1);
    $t->assertSame((int) $token['id'], (int) $called['id'], 'oldest waiting token is called');
    $t->assertSame(Token::CALLED, (string) $called['status'], 'status after call');
    $t->assertSame(Fixtures::COUNTER_1, (int) $called['counter_id'], 'counter is recorded');
    $t->assertTrue($called['called_at'] !== null, 'called_at is stamped');

    $serving = Token::start((int) $called['id'], Fixtures::COUNTER_1);
    $t->assertSame(Token::SERVING, (string) $serving['status'], 'status after start');
    $t->assertTrue($serving['serving_at'] !== null, 'serving_at is stamped (the ETA clock)');

    $completed = Token::complete((int) $serving['id']);
    $t->assertSame(Token::COMPLETED, (string) $completed['status'], 'status after complete');
    $t->assertTrue($completed['completed_at'] !== null, 'completed_at is stamped');
});

$t->test('waiting -> cancelled', function (TestRunner $t): void {
    Fixtures::seed();
    $token = Token::issue(Fixtures::GENERAL);

    $cancelled = Token::cancel((string) $token['public_id']);

    $t->assertSame(Token::CANCELLED, (string) $cancelled['status'], 'status after cancel');
    $t->assertSame(0, Token::waitingCount(Fixtures::GENERAL), 'cancelled token leaves the waiting queue');
});

$t->test('called -> no_show', function (TestRunner $t): void {
    Fixtures::seed();
    $called = Fixtures::tokenIn(Token::CALLED);

    $noShow = Token::markNoShow((int) $called['id']);

    $t->assertSame(Token::NO_SHOW, (string) $noShow['status'], 'status after no-show');
});

$t->test('recall re-announces without leaving the called state', function (TestRunner $t): void {
    Fixtures::seed();
    $called = Fixtures::tokenIn(Token::CALLED);
    $first  = (string) $called['called_at'];

    // Sleep so the timestamps differ, then recall twice inside the same second
    // to prove rowCount()==0 never happens for a matched row (FOUND_ROWS).
    sleep(1);
    $recalled = Token::recall((int) $called['id']);
    $again    = Token::recall((int) $called['id']);

    $t->assertSame(Token::CALLED, (string) $recalled['status'], 'still called');
    $t->assertSame(Token::CALLED, (string) $again['status'], 'a second recall in the same second still succeeds');
    $t->assertTrue((string) $again['called_at'] >= $first, 'called_at moves forward');
});

$t->test('a floating counter can serve more than one service', function (TestRunner $t): void {
    Fixtures::seed();
    Token::issue(Fixtures::BILLING);

    $claimed = Token::callNext(Fixtures::COUNTER_3);

    $t->assertTrue($claimed !== null, 'floating counter claimed a token');
    $t->assertSame(Fixtures::BILLING, (int) $claimed['service_id'], 'claimed the Billing token');
});

$t->test('callNext returns null when nobody is waiting', function (TestRunner $t): void {
    Fixtures::seed();

    $t->assertSame(null, Token::callNext(Fixtures::COUNTER_1), 'empty queue is null, not an exception');
});

$t->test('position counts only the waiting tokens ahead in the same service', function (TestRunner $t): void {
    Fixtures::seed();
    $first  = Token::issue(Fixtures::GENERAL);
    $second = Token::issue(Fixtures::GENERAL);
    Token::issue(Fixtures::BILLING);

    $t->assertSame(1, Token::positionOf($first), 'first in line');
    $t->assertSame(2, Token::positionOf($second), 'second in line');

    Token::callNext(Fixtures::COUNTER_1);
    $t->assertSame(1, Token::positionOf($second), 'moving up after a call');
});

// ---------------------------------------------------------------------------
// Illegal transitions -> 409
// ---------------------------------------------------------------------------

$t->test('start() only works from called and from the owning counter', function (TestRunner $t): void {
    Fixtures::seed();

    $waiting = Token::issue(Fixtures::GENERAL);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::start((int) $waiting['id'], Fixtures::COUNTER_1), 'start a waiting token')->statusCode(),
    );

    $called = Token::callNext(Fixtures::COUNTER_1);

    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::start((int) $called['id'], Fixtures::COUNTER_2), 'start from the wrong counter')->statusCode(),
    );

    $serving = Token::start((int) $called['id'], Fixtures::COUNTER_1);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::start((int) $serving['id'], Fixtures::COUNTER_1), 'start twice')->statusCode(),
    );
});

$t->test('complete() only works from serving', function (TestRunner $t): void {
    Fixtures::seed();

    $waiting = Token::issue(Fixtures::GENERAL);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::complete((int) $waiting['id']), 'complete a waiting token')->statusCode(),
    );

    Fixtures::resetQueue();
    Token::issue(Fixtures::GENERAL);
    $called = Token::callNext(Fixtures::COUNTER_1);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::complete((int) $called['id']), 'complete a called-but-not-served token')->statusCode(),
    );

    Fixtures::resetQueue();
    $completed = Fixtures::tokenIn(Token::COMPLETED);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::complete((int) $completed['id']), 'complete twice')->statusCode(),
    );
});

$t->test('no_show() only works from called', function (TestRunner $t): void {
    Fixtures::seed();

    $waiting = Token::issue(Fixtures::GENERAL);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::markNoShow((int) $waiting['id']), 'no-show a waiting token')->statusCode(),
    );

    Fixtures::resetQueue();
    $serving = Fixtures::tokenIn(Token::SERVING);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::markNoShow((int) $serving['id']), 'no-show a serving token')->statusCode(),
    );
});

$t->test('recall() only works from called', function (TestRunner $t): void {
    Fixtures::seed();

    $waiting = Token::issue(Fixtures::GENERAL);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::recall((int) $waiting['id']), 'recall a waiting token')->statusCode(),
    );

    Fixtures::resetQueue();
    $serving = Fixtures::tokenIn(Token::SERVING);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::recall((int) $serving['id']), 'recall a serving token')->statusCode(),
    );
});

$t->test('cancel() only works while waiting', function (TestRunner $t): void {
    Fixtures::seed();

    $called = Fixtures::tokenIn(Token::CALLED);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::cancel((string) $called['public_id']), 'cancel an already-called token')->statusCode(),
    );

    Fixtures::resetQueue();
    $completed = Fixtures::tokenIn(Token::COMPLETED);
    $t->assertSame(
        409,
        $t->assertThrows(InvalidTransitionException::class, fn () => Token::cancel((string) $completed['public_id']), 'cancel a completed token')->statusCode(),
    );
});

$t->test('unknown ids produce 404, not 409', function (TestRunner $t): void {
    Fixtures::seed();

    $t->assertSame(404, $t->assertThrows(HttpException::class, fn () => Token::complete(424242), 'unknown token')->statusCode());
    $t->assertSame(404, $t->assertThrows(HttpException::class, fn () => Token::callNext(424242), 'unknown counter')->statusCode());
    $t->assertSame(404, $t->assertThrows(HttpException::class, fn () => Token::cancel('ffffffffffffffff'), 'unknown public_id')->statusCode());
    $t->assertSame(404, $t->assertThrows(HttpException::class, fn () => Counter::findOrFail(424242), 'unknown counter lookup')->statusCode());
});

$t->test('a counter holding a token refuses to call another', function (TestRunner $t): void {
    Fixtures::seed();
    Token::issue(Fixtures::GENERAL);
    Token::issue(Fixtures::GENERAL);

    $first = Token::callNext(Fixtures::COUNTER_1);
    $busy  = $t->assertThrows(HttpException::class, fn () => Token::callNext(Fixtures::COUNTER_1), 'counter is busy');

    $t->assertSame(409, $busy->statusCode(), 'busy counter status');
    $t->assertContains('A-001', $busy->getMessage(), 'message names the token to finish');
    $t->assertSame(1, Token::waitingCount(Fixtures::GENERAL), 'the other visitor is untouched');
    $t->assertSame(Fixtures::COUNTER_1, (int) $first['counter_id'], 'first claim stands');
});

$t->test('a closed counter cannot call anyone', function (TestRunner $t): void {
    Fixtures::seed();
    Fixtures::pdo()->exec('UPDATE counters SET is_open = 0 WHERE id = 1');
    Token::issue(Fixtures::GENERAL);

    $closed = $t->assertThrows(HttpException::class, fn () => Token::callNext(Fixtures::COUNTER_1), 'closed counter');

    $t->assertSame(409, $closed->statusCode(), 'closed counter status');
    $t->assertSame(1, Token::waitingCount(Fixtures::GENERAL), 'nobody was called');
});

// ---------------------------------------------------------------------------
// API surface (router + controllers, no HTTP server needed)
// ---------------------------------------------------------------------------

$t->test('the API contract matches the plan: envelopes, status codes, no internal ids', function (TestRunner $t): void {
    Fixtures::seed();

    $router = new Router();
    (require dirname(__DIR__) . '/config/routes.php')($router);

    // Drive the router through the same error boundary the front controller
    // uses, so the tests assert on real HTTP responses, not raw exceptions.
    $dispatch = static fn (Request $request): Response => ErrorHandler::handle(
        static fn (): Response => $router->dispatch($request)
    );

    $get = static fn (string $path): Response => $dispatch(Request::create('GET', $path));

    // Staff endpoints sit behind auth + CSRF since Phase 3.
    // 1. Anonymous caller -> 401, before CSRF is even considered.
    Session::reset();
    Session::start();

    $callNextPath = '/api/staff/counters/' . Fixtures::COUNTER_1 . '/call-next';

    $t->assertSame(401, $dispatch(Request::create('POST', $callNextPath))->status(), 'anonymous staff write is 401');

    // 2. Sign in in-process, then a write with no CSRF token -> 403.
    $credentials = Config::all();
    $credentials['security']['staff_password_hash'] = password_hash('contract-test', PASSWORD_DEFAULT);
    Config::set($credentials);

    Auth::attempt('contract-test');
    $csrf = Csrf::token();

    $t->assertSame(403, $dispatch(Request::create('POST', $callNextPath))->status(), 'signed in but no CSRF token is 403');

    // 3. With credentials and a CSRF token, the API works.
    $post = static fn (string $path, array $body = []): Response => $dispatch(Request::create(
        'POST',
        $path,
        [],
        json_encode($body, JSON_THROW_ON_ERROR),
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf],
    ));

    // POST /api/tokens -> 201 with the public payload
    $created = $post('/api/tokens', ['service_id' => Fixtures::GENERAL]);
    $t->assertSame(201, $created->status(), 'create status');

    $payload = json_decode($created->body(), true, 512, JSON_THROW_ON_ERROR);
    $t->assertSame(true, $payload['ok'], 'ok flag');
    $t->assertFalse(isset($payload['data']['id']), 'internal auto-increment id must not leak');
    $t->assertSame('A-001', $payload['data']['display_code'], 'display code in payload');
    $t->assertSame(1, $payload['data']['position'], 'position in payload');
    $publicId = (string) $payload['data']['public_id'];

    // GET /api/tokens/{public_id}
    $shown = $get('/api/tokens/' . $publicId);
    $t->assertSame(200, $shown->status(), 'show status');

    // GET /api/services
    $services = $get('/api/services');
    $servicesPayload = json_decode($services->body(), true, 512, JSON_THROW_ON_ERROR);
    $t->assertSame(2, count($servicesPayload['data']), 'two active services');
    $t->assertSame(1, $servicesPayload['data'][0]['waiting'], 'waiting count for General OPD');
    $t->assertSame(3, $servicesPayload['data'][0]['open_counters'], 'capacity includes the floating desk');

    // Validation
    $invalid = $post('/api/tokens', []);
    $t->assertSame(422, $invalid->status(), 'missing service_id');

    // 404 / 405
    $t->assertSame(404, $get('/api/tokens/deadbeefdeadbeef')->status(), 'unknown token id');
    $t->assertSame(404, $get('/api/tokens/not-a-hex-id')->status(), 'malformed token id is a route miss');
    // "/api/tokens/" normalises to "/api/tokens", which exists for POST only.
    $t->assertSame(405, $get('/api/tokens/')->status(), 'trailing slash normalises to the collection route');
    $t->assertSame(405, $dispatch(Request::create('DELETE', '/api/services'))->status(), 'wrong method');

    // Staff: call next -> start -> complete, through the router
    $called   = $post('/api/staff/counters/' . Fixtures::COUNTER_1 . '/call-next');
    $t->assertSame(200, $called->status(), 'call-next status');
    $calledPayload = json_decode($called->body(), true, 512, JSON_THROW_ON_ERROR);
    $t->assertSame(1, (int) $calledPayload['data']['token']['number'], 'the only visitor was called');

    $internalId = (int) Fixtures::pdo()->query('SELECT id FROM tokens ORDER BY id LIMIT 1')->fetchColumn();

    $started = $post('/api/staff/tokens/' . $internalId . '/start', ['counter_id' => Fixtures::COUNTER_1]);
    $t->assertSame(200, $started->status(), 'start status');

    // Wrong order -> 409 with a machine-readable reason
    $again = $post('/api/staff/tokens/' . $internalId . '/start', ['counter_id' => Fixtures::COUNTER_1]);
    $t->assertSame(409, $again->status(), 'illegal transition over the API');
    $conflict = json_decode($again->body(), true, 512, JSON_THROW_ON_ERROR);
    $t->assertSame('serving', $conflict['details']['actual'], 'details report the actual status');

    $completed = $post('/api/staff/tokens/' . $internalId . '/complete');
    $t->assertSame(200, $completed->status(), 'complete status');

    $overview = $get('/api/staff/overview');
    $overviewPayload = json_decode($overview->body(), true, 512, JSON_THROW_ON_ERROR);
    $t->assertSame(3, count($overviewPayload['data']['counters']), 'three counters');
    $t->assertSame(1, $overviewPayload['data']['totals']['completed'], 'one completion today');
});

$t->test('Service::listWithWaiting carries the fallback duration and counter capacity', function (TestRunner $t): void {
    Fixtures::seed();

    $services = Service::listWithWaiting();
    $t->assertSame('General OPD', (string) $services[0]['name'], 'service name');
    $t->assertSame(7.0, (float) $services[0]['fallback_minutes'], 'fallback average');

    // Two dedicated desks plus the floating one, which really can drain this
    // queue. `shared_counters` lets a caller tell them apart.
    $t->assertSame(2, (int) $services[0]['open_counters'] - (int) $services[0]['shared_counters'], 'two dedicated counters');
    $t->assertSame(3, (int) $services[0]['open_counters'], 'capacity counts the floating desk too');
    $t->assertSame(1, (int) $services[0]['shared_counters'], 'one shared counter');

    // Billing has no dedicated desk at all: only the floating one.
    $t->assertSame(1, (int) $services[1]['open_counters'], 'Billing capacity');
    $t->assertSame(1, (int) $services[1]['shared_counters'], 'Billing has only the shared desk');
});

exit($t->summary());
