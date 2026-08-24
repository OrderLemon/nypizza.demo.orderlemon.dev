<?php

namespace Plugins\Support;

use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;

abstract class AbstractShopPlugin extends AbstractPlugin
{
    protected function withShop(callable $handler): callable
    {
        return function (Request $req, array $p) use ($handler): Response {
            if ($err = ShopContext::capture($req)) {
                return $err; // 422, handler never runs
            }
            return $handler($req, $p);
        };
    }
}

?>