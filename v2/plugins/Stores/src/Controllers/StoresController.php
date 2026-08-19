<?php

declare(strict_types=1);

namespace Plugins\Stores\Controllers;

use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Services\JsonService;

/**
 * A plain controller. It returns a {@see Response} exactly like a core
 * controller does — the envelope, status codes, and streaming helpers are all
 * available to plugins.
 */
final class StoresController
{
    function __construct(
        private readonly JsonService $jsonService
    ){}

    public function index(Request $request): Response
    {
        $stores = $this->jsonService->load("stores");

        return Response::ok($stores);
    }
}
