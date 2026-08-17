<?php

declare(strict_types=1);

namespace Plugins\Orders\Controllers;

use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Services\TrackingService;

/**
 * Delivery tracking endpoints.
 *
 *   GET  /v2/orders/{id}/tracking        full payload (map + AI)
 *   POST /v2/orders/{id}/tracking/reset  restart the clock for a re-demo
 *
 * The payload is deliberately flat and pre-formatted: the AI is handed
 * `eta_minutes`, `eta_clock`, `status_label` and a ready `message` rather than
 * raw timestamps, so it never has to do arithmetic it can get wrong.
 */
final class TrackingController
{
    public function __construct(
        protected readonly TrackingService $tracking,
    ) {
    }

    public function show(Request $request, string $id): Response
    {
        if(!is_numeric($id) || (int)$id <= 0){
            throw new ApiException('A valid order id is required!');
        }

        $view = $this->tracking->resolve((int)$id);

        if ($view === null) {
            throw new ApiException('No tracking found for this order!');
        }

        return Response::ok(['success' => true, 'data' => $view]);
    }

    public function reset(Request $request, array $params): Response
    {
        $view = $this->tracking->reset($this->orderId($params));

        if ($view === null) {
            throw new ApiException('No tracking found for this order!');
        }

        return Response::ok(['success' => true, 'data' => $view]);
    }

    private function orderId(array $params): int
    {
        $id = $params['id'] ?? null;

        if (!is_numeric($id) || (int) $id <= 0) {
            throw new ApiException('A valid order id is required!');
        }

        return (int) $id;
    }
}