<?php

declare(strict_types=1);

namespace Plugins\Cart;

use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Services\CartService;
use Plugins\Cart\Controllers\CartController;

final class CartPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(CartController::class, static fn(Container $c): CartController => new CartController(
            $c->get(CartService::class),
        ));
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->put('/update', static fn(Request $r): Response
            => $container->get(CartController::class)->update($r));

        $router->post('/checkout', static fn(Request $r): Response
            => $container->get(CartController::class)->checkout($r));

        $router->get('/{phone}', static fn(Request $r, $p): Response
            => $container->get(CartController::class)->getCart($r, $p["phone"]));
    }
}