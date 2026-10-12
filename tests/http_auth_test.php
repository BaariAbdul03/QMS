<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Phase 3 · end-to-end HTTP auth walkthrough
|--------------------------------------------------------------------------
|
|   bash bin/dev-server.sh start
|   QMS_TEST_STAFF_PASSWORD='your-password' bash bin/php.sh tests/http_auth_test.php
|
| Drives the real HTTP interface with a real cookie jar: sign-in, CSRF on
| staff writes, sign-out. This is the layer the browser actually uses, so it is
| tested through HTTP rather than by calling the classes directly.
|
| Skips (with a reason) when no server is listening, and skips the
| password-dependent steps when QMS_TEST_STAFF_PASSWORD is not exported.
|--------------------------------------------------------------------------
*/

use Tests\Support\TestRunner;

require __DIR__ . '/bootstrap.php';

$base     = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$password = (string) (getenv('QMS_TEST_STAFF_PASSWORD') ?: '');

$t = new TestRunner('Phase 3 · HTTP auth walkthrough (' . $base . ')');

$jar = tempnam(sys_get_temp_dir(), 'qms-cookies-');

/**
 * @param array{body?: string, headers?: list<string>} $options
 * @return array{status: int, headers: string, body: string}
 */
function http(string $method, string $url, array $options = [], ?string $jar = null): array
{
    $handle = curl_init($url);

    $headers = array_merge(['Accept: application/json'], $options['headers'] ?? []);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($jar !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $jar);
    }

    if (isset($options['body'])) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $options['body']);
    }

    $raw    = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $split  = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $error  = curl_error($handle);

    curl_close($handle);

    if ($raw === '' && $error !== '') {
        $status = 0;
    }

    return [
        'status'  => $status,
        'headers' => substr($raw, 0, $split),
        'body'    => substr($raw, $split),
        'error'   => $error,
    ];
}

$probe = http('GET', $base . '/api/health');

if ($probe['status'] !== 200) {
    $t->test('server reachability', function (TestRunner $t) use ($probe, $base): void {
        $t->skip(sprintf(
            "No QMS server answered at %s (%s).\n          Start one with: bash bin/dev-server.sh start",
            $base,
            $probe['status'] === 0 ? 'connection failed' : 'HTTP ' . $probe['status'],
        ));
    });

    exit($t->summary());
}

// ---------------------------------------------------------------------------
// Anonymous access
// ---------------------------------------------------------------------------

$t->test('staff API refuses anonymous callers with 401', function (TestRunner $t) use ($base, $jar): void {
    $response = http('GET', $base . '/api/staff/overview', [], $jar);

    $t->assertSame(401, $response['status'], 'status');

    $payload = json_decode($response['body'], true);
    $t->assertSame(false, $payload['ok'] ?? null, 'failure envelope');
    $t->assertSame('Staff login required.', $payload['error'] ?? null, 'message');
});

$t->test('the staff page redirects anonymous browsers to the login form', function (TestRunner $t) use ($base, $jar): void {
    $response = http('GET', $base . '/staff', [], $jar);

    $t->assertSame(302, $response['status'], 'status');
    $t->assertContains('/staff/login', $response['headers'], 'redirect target');
});

$t->test('the login form renders with a per-session CSRF token', function (TestRunner $t) use ($base, $jar): void {
    $response = http('GET', $base . '/staff/login', [], $jar);

    $t->assertSame(200, $response['status'], 'status');
    $t->assertContains('Staff sign in', $response['body'], 'heading');
    $t->assertContains('name="_token"', $response['body'], 'CSRF field');

    if (preg_match('/name="_token" value="([0-9a-f]{64})"/', $response['body'], $matches) !== 1) {
        $t->assertTrue(false, 'could not find a 64-char CSRF token in the form');

        return;
    }

    $t->assertSame(64, strlen($matches[1]), 'token length');
});

$t->test('a form post without a CSRF token is redirected, not accepted', function (TestRunner $t) use ($base): void {
    $response = http('POST', $base . '/staff/login', [
        'body'    => 'password=whatever',
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ], tempnam(sys_get_temp_dir(), 'qms-nocsrf-'));

    $t->assertSame(302, $response['status'], 'status');
    $t->assertContains('expired=1', $response['headers'], 'bounced back to the form with a reason');
});

$t->test('a wrong password is rejected', function (TestRunner $t) use ($base, $jar, $password): void {
    $form = http('GET', $base . '/staff/login', [], $jar);

    if (preg_match('/name="_token" value="([0-9a-f]{64})"/', $form['body'], $m) !== 1) {
        $t->assertTrue(false, 'no CSRF token available');

        return;
    }

    if ($password === '') {
        $t->skip('QMS_TEST_STAFF_PASSWORD is not set, so the real password cannot be exercised.');

        return;
    }

    $response = http('POST', $base . '/staff/login', [
        'body'    => http_build_query(['_token' => $m[1], 'password' => $password . '-definitely-wrong', 'next' => '/staff']),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ], $jar);

    $t->assertSame(401, $response['status'], 'status');
    $t->assertContains('not right', $response['body'], 'explains the failure');
});

// ---------------------------------------------------------------------------
// Authenticated flow
// ---------------------------------------------------------------------------

$t->test('sign in with the real password, then CSRF-guarded staff actions', function (TestRunner $t) use ($base, $jar, $password): void {
    if ($password === '') {
        $t->skip('QMS_TEST_STAFF_PASSWORD is not set. Export it to exercise the signed-in flow.');

        return;
    }

    $form = http('GET', $base . '/staff/login', [], $jar);

    preg_match('/name="_token" value="([0-9a-f]{64})"/', $form['body'], $m);
    $loginToken = $m[1] ?? '';

    $login = http('POST', $base . '/staff/login', [
        'body'    => http_build_query(['_token' => $loginToken, 'password' => $password, 'next' => '/staff']),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ], $jar);

    $t->assertSame(302, $login['status'], 'login redirects');
    $t->assertContains('Location: /staff', $login['headers'], 'to the panel');

    // The panel embeds the rotated CSRF token in its config block.
    $panel = http('GET', $base . '/staff', [], $jar);
    $t->assertSame(200, $panel['status'], 'panel renders');
    $t->assertContains('qms-config', $panel['body'], 'config block present');
    $t->assertContains('staff.js', $panel['body'], 'panel script present');

    preg_match('/"csrf":"([0-9a-f]{64})"/', $panel['body'], $cm);
    $csrf = $cm[1] ?? '';
    $t->assertSame(64, strlen($csrf), 'panel exposes a CSRF token');

    // 1. Session but no CSRF token -> 403
    $noCsrf = http('POST', $base . '/api/staff/tokens/999999/complete', [
        'body'    => '{}',
        'headers' => ['Content-Type: application/json'],
    ], $jar);
    $t->assertSame(403, $noCsrf['status'], 'missing CSRF token is refused');

    // 2. Session and CSRF token -> the action runs. Token 999999 does not
    //    exist, so a 404 proves the guards passed and the controller executed.
    $withCsrf = http('POST', $base . '/api/staff/tokens/999999/complete', [
        'body'    => '{}',
        'headers' => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
    ], $jar);
    $t->assertSame(404, $withCsrf['status'], 'with a valid CSRF token the request reaches the controller');

    // 3. The overview now works.
    $overview = http('GET', $base . '/api/staff/overview', [], $jar);
    $t->assertSame(200, $overview['status'], 'overview status');
    $payload = json_decode($overview['body'], true);
    $t->assertSame(true, $payload['ok'] ?? null, 'success envelope');
    $t->assertTrue(is_array($payload['data']['counters'] ?? null) && $payload['data']['counters'] !== [], 'counters present');
    $t->assertTrue(isset($payload['data']['totals']['in_queue']), 'totals present');

    // 4. Sign out and confirm the session is really gone.
    $logout = http('POST', $base . '/staff/logout', [
        'body'    => http_build_query(['_token' => $csrf]),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ], $jar);
    $t->assertSame(302, $logout['status'], 'logout redirects');

    $after = http('GET', $base . '/api/staff/overview', [], $jar);
    $t->assertSame(401, $after['status'], 'session is dead after logout');
});

$t->test('the public API still works for visitors', function (TestRunner $t) use ($base): void {
    $services = http('GET', $base . '/api/services');

    $t->assertSame(200, $services['status'], 'services status');

    $take = http('POST', $base . '/api/tokens', [
        'body'    => json_encode(['service_id' => 1]),
        'headers' => ['Content-Type: application/json'],
    ]);

    // 201 normally; 409 if service 1 is inactive in this database.
    $t->assertTrue(in_array($take['status'], [201, 409], true), 'token issuance returned ' . $take['status']);

    if ($take['status'] === 201) {
        $payload = json_decode($take['body'], true);
        $t->assertSame(true, $payload['ok'], 'success envelope');
        $t->assertFalse(isset($payload['data']['id']), 'internal id not exposed');

        // The visitor can read and act on only their own unguessable token.
        $id     = (string) $payload['data']['public_id'];
        $read   = http('GET', $base . '/api/tokens/' . $id);
        $t->assertSame(200, $read['status'], 'token readable by public_id');

        $cancel = http('POST', $base . '/api/tokens/' . $id . '/cancel');
        $t->assertSame(200, $cancel['status'], 'token cancellable by public_id');
    }
});

@unlink($jar);

exit($t->summary());
