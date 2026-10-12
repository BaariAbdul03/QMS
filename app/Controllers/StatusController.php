<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Request;
use App\Core\Response;
use App\Models\Counter;
use App\Models\QueueStats;
use App\Models\Service;
use App\Models\Token;

/**
 * GET /api/status — everything the waiting-room board shows, in one poll.
 *
 * Public on purpose: this is the information a visitor could read off the
 * screen anyway. Note what is NOT here: no public_id, no internal token id, no
 * staff names. The board only needs the codes people are called by.
 */
final class StatusController
{
    /**
     * @param array<string, string|null> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $date = Clock::today();

        $counters = [];

        foreach (Counter::listWithCurrentToken($date) as $row) {
            $counters[] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'is_open' => (int) $row['is_open'] === 1,
                'service' => $row['service_id'] === null ? null : [
                    'id'   => (int) $row['service_id'],
                    'name' => (string) ($row['service_name'] ?? ''),
                ],
                'token'   => $row['token_id'] === null ? null : [
                    'display_code' => (string) $row['token_code'],
                    'number'       => (int) $row['token_number'],
                    'status'       => (string) $row['token_status'],
                    'called_at'    => $row['called_at'],
                    'serving_at'   => $row['serving_at'],
                ],
            ];
        }

        $services = array_map(static function (array $service): array {
            return [
                'id'                      => $service['id'],
                'name'                    => $service['name'],
                'prefix'                  => $service['prefix'],
                'waiting'                 => $service['waiting'],
                'open_counters'           => $service['open_counters'],
                'shared_counters'         => $service['shared_counters'],
                'average_service_minutes' => QueueStats::serviceSnapshot($service['id']),
            ];
        }, Service::listWithWaiting($date));

        return Response::ok([
            'date'         => $date,
            'generated_at' => Clock::now()->format(\DATE_ATOM),
            'counters'     => $counters,
            'services'     => $services,
            'totals'       => Token::statusCounts($date),
        ])->withoutCache();
    }
}
