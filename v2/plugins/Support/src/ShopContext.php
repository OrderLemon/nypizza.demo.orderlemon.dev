<?php

declare(strict_types=1);

namespace Plugins\Support;

use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class ShopContext
{
    /**
     * @param array<string, string> $params matched route params (must include "shop_id")
     */
    public static function capture(array $params): ?Response
    {
        $shopId = $params['shop_id'] ?? null;

        if ($shopId === null || $shopId === '') {
            return Response::error(422, ['validation_failed' => 'Missing required parameter: shop_id']);
        }

        define('shop_id', $shopId);
        return null;
    }

    /**
     * Wraps a route handler so it only runs once the request's shop_id has
     * been captured; otherwise short-circuits with the 422 from capture().
     */
    public static function wrap(callable $handler): callable
    {
        return function (Request $req, array $p) use ($handler): Response {
            if ($err = self::capture($p)) {
                return $err;
            }
            return $handler($req, $p);
        };
    }
}
