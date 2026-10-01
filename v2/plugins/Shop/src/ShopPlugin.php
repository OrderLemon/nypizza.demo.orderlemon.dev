<?php

declare(strict_types=1);

namespace Plugins\Shop;

use Plugins\Shop\Controllers\ShopController;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Services\ShopService;

/**
 * Shop identity resolution.
 *
 * Thin routes over the core {@see ShopService}, which bootstrap.php registers,
 * to resolve a shop by id or by phone number.
 */
final class ShopPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
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
