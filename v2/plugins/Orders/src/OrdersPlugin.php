<?php

declare(strict_types=1);

namespace Plugins\Orders;

use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\ResourceDefinition;
use Pmsrapi\V2\Http\Controllers\CrudController;
use Plugins\Whatsapp\Gateway\WhatsappGateway;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Services\OrderQueryService;
use Pmsrapi\V2\Services\UsualOrderService;
use Pmsrapi\V2\Services\DraftOrderService;
use Plugins\Orders\Controllers\OrderController;
use Plugins\Orders\Controllers\TrackingController;
use Plugins\Support\ShopContext;
/**
 * WhatsApp inbound receiver.
 *
 * This service is the "client webhook" the WhatsApp gateway
 * (api.wa.fabulor.io) forwards to. Its send_payload_to_client() POSTs an
 * { "a": "incoming", ... } envelope to the URL in the account's
 * `accounts.webhook` column, which for this project is this repo:
 *
 *   POST /v2/whatsapp   { "a": "incoming", "phonenumber": "<account>", ... }
 *
 * Bearer-authenticated like every other core endpoint. The inbound message is
 * caught here and replied to via the outbound {@see WhatsappGateway}. Core
 * services (Config, Logger) are injected; the plugin opens no DB/Redis handle
 * of its own.
 */
final class OrdersPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(OrderController::class, static fn(Container $c): OrderController => new OrderController(
            $c->get(OrderQueryService::class),
            $c->get(UsualOrderService::class),
            $c->get(WhatsappGateway::class),
            $c->get(DraftOrderService::class),
            $c->get(Logger::class),
            $c->get(Config::class),
        ));
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $def = ResourceDefinition::fromConfig((string) "orders_active_98", []);

        $router->get('/{shop_id}/active', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(OrderController::class)->indexActive($r)));

        $router->post('/{shop_id}', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(OrderController::class)->store($r)));

        $router->post('/{shop_id}/reorder', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(OrderController::class)->decodeOrder($r)));

        $router->post('/{shop_id}/reference', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(OrderController::class)->referenceOrder($r)));

        $router->get('/{shop_id}/usual/{phone}', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(OrderController::class)->usualFor($r, $p["phone"])));

        $router->get('/{shop_id}/active/{phone}', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(OrderController::class)->indexActiveForClient($r, $p["phone"])));

        $router->get('/{shop_id}/{id}/tracking', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(TrackingController::class)->show($r, $p["id"])));

    }
}