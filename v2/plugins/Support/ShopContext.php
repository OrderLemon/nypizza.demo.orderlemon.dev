<?php

namespace Plugins\Support;

use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

final class ShopContext
{

    public static function capture(Request $req): ?Response
    {
        $shopId = $req->query('shop_id') ?? $req->body['shop_id'] ?? null;

        if ($shopId === null || $shopId === '') {
            return Response::error(422, ['validation_failed' => 'Missing required parameter: shop_id']);
        }

        define("shop_id", $shopId);
        return null;
    }

}

?>