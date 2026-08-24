<?php

declare(strict_types=1);

namespace Plugins\Stores;

use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Services\JsonService;
use Plugins\Orders\Controllers\OrderController;
use Plugins\Stores\Controllers\StoresController;
use Plugins\Support\ShopContext;

final class StoresPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(StoresController::class, static fn(Container $c): StoresController => new StoresController(
            $c->get(JsonService::class),
        ));
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/{shop_id}', ShopContext::wrap(fn(Request $r): Response
            => $container->get(StoresController::class)->index($r)));
    }
}