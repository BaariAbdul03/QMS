<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Counter;
use App\Models\Service;
use App\Models\Token;

/**
 * Staff actions. Every method here is behind the staff session middleware
 * (wired up in config/routes.php), and every one is a small, single-purpose
 * state change so the panel can enable/disable buttons from the token status.
 */
final class StaffController
{
    /**
     * GET /api/staff/overview
     *
     * Everything the panel needs in one poll: counters with their current
     * token, waiting counts per service, and today's totals.
     *
     * @param array<string, string|null> $params
     */
    public function overview(Request $request, array $params = []): Response
    {
        $date     = Clock::today();
        $counters = Counter::listWithCurrentToken($date);

        $payload = array_map(static function (array $row): array {
            $hasToken = $row['token_id'] !== null;

            return [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'staff_name' => $row['staff_name'] === null ? null : (string) $row['staff_name'],
                'is_open'    => (int) $row['is_open'] === 1,
                'service'    => $row['service_id'] === null ? null : [
                    'id'     => (int) $row['service_id'],
                    'name'   => (string) ($row['service_name'] ?? ''),
                    'prefix' => (string) ($row['service_prefix'] ?? ''),
                ],
                'current'    => $hasToken ? [
                    'id'           => (int) $row['token_id'],
                    'public_id'    => (string) $row['token_public_id'],
                    'display_code' => (string) $row['token_code'],
                    'number'       => (int) $row['token_number'],
                    'status'       => (string) $row['token_status'],
                    'called_at'    => $row['called_at'],
                    'serving_at'   => $row['serving_at'],
                ] : null,
            ];
        }, $counters);

        return Response::ok([
            'date'     => $date,
            'counters' => $payload,
            'services' => Service::listWithWaiting($date),
            'totals'   => Token::statusCounts($date),
        ])->withoutCache();
    }

    /**
     * POST /api/staff/counters/{id}/call-next
     *
     * @param array<string, string|null> $params
     */
    public function callNext(Request $request, array $params = []): Response
    {
        $counterId = (int) ($params['id'] ?? 0);
        $token     = Token::callNext($counterId);

        if ($token === null) {
            // Not an error: the desk is simply idle. 200 keeps the polling
            // panel from flashing red on an empty queue.
            return Response::ok([
                'token'   => null,
                'message' => 'No one is waiting for this counter right now.',
            ])->withoutCache();
        }

        return Response::ok(['token' => Token::presentForStaff($token)])->withoutCache();
    }

    /**
     * POST /api/staff/tokens/{id}/start
     *
     * @param array<string, string|null> $params
     */
    public function start(Request $request, array $params = []): Response
    {
        $tokenId   = (int) ($params['id'] ?? 0);
        $counterId = self::requireCounterId($request);
        $token     = Token::start($tokenId, $counterId);

        return Response::ok(['token' => Token::presentForStaff($token)])->withoutCache();
    }

    /**
     * POST /api/staff/tokens/{id}/complete
     *
     * @param array<string, string|null> $params
     */
    public function complete(Request $request, array $params = []): Response
    {
        $token = Token::complete((int) ($params['id'] ?? 0));

        return Response::ok(['token' => Token::presentForStaff($token)])->withoutCache();
    }

    /**
     * POST /api/staff/tokens/{id}/no-show
     *
     * @param array<string, string|null> $params
     */
    public function noShow(Request $request, array $params = []): Response
    {
        $token = Token::markNoShow((int) ($params['id'] ?? 0));

        return Response::ok(['token' => Token::presentForStaff($token)])->withoutCache();
    }

    /**
     * POST /api/staff/tokens/{id}/recall
     *
     * @param array<string, string|null> $params
     */
    public function recall(Request $request, array $params = []): Response
    {
        $token = Token::recall((int) ($params['id'] ?? 0));

        return Response::ok(['token' => Token::presentForStaff($token)])->withoutCache();
    }

    /**
     * The panel tells the API which desk it is; without it a start() cannot be
     * checked against the token's counter_id.
     */
    private static function requireCounterId(Request $request): int
    {
        $counterId = (int) $request->input('counter_id', 0);

        if ($counterId <= 0) {
            throw HttpException::unprocessable('counter_id is required for this action.', ['field' => 'counter_id']);
        }

        return $counterId;
    }
}
