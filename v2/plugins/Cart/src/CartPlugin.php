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
use Pmsrapi\V2\Services\CartSyncService;
use Pmsrapi\V2\Services\OrderQueryService;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Services\ChatTranscriptService;
use Pmsrapi\V2\Services\PrintService;
use Pmsrapi\V2\Services\ShopService;
use Plugins\Whatsapp\Gateway\WhatsappGateway;
use Plugins\Whatsapp\Support\LanguageHelper;
use Plugins\Support\ShopContext;

final class CartPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(CartController::class, static fn(Container $c): CartController => new CartController(
            $c->get(CartService::class),
            $c->get(CartSyncService::class),
            $c->get(WhatsappGateway::class),
            $c->get(OrderQueryService::class),
            $c->get(Config::class),
            $c->get(Logger::class),
            $c->get(ChatTranscriptService::class),
            $c->get(PrintService::class),
            $c->get(ShopService::class),
            $c->get(LanguageHelper::class),
        ));
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->put('/{shop_id}/update', ShopContext::wrap(fn(Request $r): Response
            => $container->get(CartController::class)->sync($r)));

        // $router->put('/{shop_id}/update', ShopContext::wrap(fn(Request $r): Response
        //     => $container->get(CartController::class)->update($r)));

        $router->post('/{shop_id}/checkout', ShopContext::wrap(fn(Request $r): Response
            => $container->get(CartController::class)->checkout($r)));

        $router->get('/{shop_id}/{phone}', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CartController::class)->getCart($r, $p["phone"])));

        $router->get('/{shop_id}/ticket/{id}', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CartController::class)->ticketData($r, $p["id"])));
    }
}