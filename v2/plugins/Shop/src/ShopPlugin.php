<?php

declare(strict_types=1);

namespace Plugins\Shop;

use Plugins\Shop\Controllers\ShopController;
use Plugins\Shop\Services\ShopService;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

/**
 * Shop identity resolution.
 *
 * This plugin exposes {@see ShopService} so any other plugin (Whatsapp,
 * Cart, ...) can resolve a shop by id or by phone number against the local
 * `shops` table without hand-rolling its own Repository lookup, plus a
 * couple of thin routes over the same lookups.
 */
final class ShopPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            ShopService::class,
            static fn(Container $c): ShopService => new ShopService(
                $c->get(Repository::class),
            ),
        );

        $registrar->singleton(
            ShopController::class,
            static fn(Container $c): ShopController => new ShopController(
                $c->get(ShopService::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/phone/{phonenumber}', static fn(Request $r, array $p): Response
            => $container->get(ShopController::class)->byPhone($p['phonenumber']));

        $router->get('/{shop_id}', static fn(Request $r, array $p): Response
            => $container->get(ShopController::class)->show($p['shop_id']));
    }
}
