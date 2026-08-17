<?php

declare(strict_types=1);

namespace Plugins\Whatsapp;

use Plugins\Whatsapp\Controllers\WhatsappController;
use Plugins\Whatsapp\Gateway\WhatsappGateway;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Support\Logger;
use Plugins\Whatsapp\AI\Marvin;
use Plugins\Whatsapp\AI\AnthropicClient;
use Plugins\Whatsapp\AI\MarvinTools;
use Pmsrapi\V2\Services\TrackingService;
use Pmsrapi\V2\Services\OrderQueryService;
use Pmsrapi\V2\Services\JsonService;
use Pmsrapi\V2\Services\UsualOrderService;
use Pmsrapi\V2\Services\DraftOrderService;

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
final class WhatsappPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(MarvinTools::class, static fn(Container $c): MarvinTools => new MarvinTools(
            $c->get(TrackingService::class),
            $c->get(OrderQueryService::class),
            $c->get(UsualOrderService::class),
            $c->get(DraftOrderService::class),
            $c->get(Logger::class)
        ));
 
        $registrar->singleton(AnthropicClient::class, static fn(Container $c): AnthropicClient => new AnthropicClient(
            $c->get(Config::class),
            $c->get(Logger::class)
        ));

        $registrar->singleton(Marvin::class, static fn(Container $c): Marvin => new Marvin(
            $c->get(AnthropicClient::class),
            $c->get(MarvinTools::class),
            $c->get(Config::class),
            $c->get(Logger::class)
        ));

        $registrar->singleton(
            WhatsappGateway::class,
            static fn(Container $c): WhatsappGateway => new WhatsappGateway(
                $c->get(Config::class),
                $c->get(Logger::class),
                $c->get(ServiceClient::class),
            ),
        );

        $registrar->singleton(
            WhatsappController::class,
            static fn(Container $c): WhatsappController => new WhatsappController(
                $c->get(WhatsappGateway::class),
                $c->get(Logger::class),
                $c->get(Config::class),
                $c->get(Marvin::class),
            ),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->post('/', static fn(Request $request, array $params): Response
            => $container->get(WhatsappController::class)->receive($request));

        $router->get('/health', static fn(Request $request, array $params): Response
            => $container->get(WhatsappController::class)->health($request));
    }
}