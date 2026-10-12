<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Service;
use App\Models\Token;

/**
 * The visitor-facing half of the API: browse services, take a token, watch it,
 * cancel it.
 *
 * Visitors never see the internal auto-increment id — only public_id, which is
 * 8 random bytes in hex (plan item 6).
 */
final class TokenController
{
    /**
     * GET /api/services
     *
     * @param array<string, string|null> $params
     */
    public function services(Request $request, array $params = []): Response
    {
        return Response::ok(Service::listWithWaiting())->withoutCache();
    }

    /**
     * POST /api/tokens  { "service_id": 1 }
     *
     * @param array<string, string|null> $params
     */
    public function store(Request $request, array $params = []): Response
    {
        $serviceId = (int) $request->input('service_id', 0);

        if ($serviceId <= 0) {
            throw HttpException::unprocessable('service_id is required.', ['field' => 'service_id']);
        }

        $token = Token::issue($serviceId);

        return Response::ok(Token::present($token), 201)->withoutCache();
    }

    /**
     * GET /api/tokens/{public_id}
     *
     * @param array<string, string|null> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $token = Token::findByPublicIdOrFail((string) ($params['public_id'] ?? ''));

        return Response::ok(Token::present($token))->withoutCache();
    }

    /**
     * POST /api/tokens/{public_id}/cancel
     *
     * @param array<string, string|null> $params
     */
    public function cancel(Request $request, array $params = []): Response
    {
        $token = Token::cancel((string) ($params['public_id'] ?? ''));

        return Response::ok(Token::present($token))->withoutCache();
    }
}
