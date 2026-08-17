<?php

declare(strict_types=1);

namespace Plugins\Catalog;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRouter;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Services\ProductsService;
use Pmsrapi\V2\Services\CategoryService;
use Pmsrapi\V2\Services\CampaignService;
use Plugins\Catalog\Controllers\ProductsController;
use Plugins\Catalog\Controllers\CategoryController;
use Plugins\Catalog\Controllers\CampaignController;
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
final class CatalogPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(ProductsController::class, static fn(Container $c): ProductsController => new ProductsController(
            $c->get(ProductsService::class),
        ));
 
        $registrar->singleton(CategoryController::class, static fn(Container $c): CategoryController => new CategoryController(
            $c->get(ProductsService::class),
            $c->get(CategoryService::class)
        ));

        $registrar->singleton(CampaignController::class, static fn(Container $c): CampaignController => new CampaignController(
            $c->get(CampaignService::class),
        ));
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/products', static fn(Request $r, array $p): Response
            => $container->get(ProductsController::class)->index($r));

        $router->get('/products/{id}', static fn(Request $r, array $p): Response
            => $container->get(ProductsController::class)->show($r, $p["id"]));

        $router->get('/categories/products', static fn(Request $r, array $p): Response
            => $container->get(CategoryController::class)->indexWithProducts($r));

        $router->get('/categories/{id}', static fn(Request $r, array $p): Response
            => $container->get(CategoryController::class)->show($r, $p["id"]));

        $router->get('/categories/{id}/products', static fn(Request $r, array $p): Response
            => $container->get(CategoryController::class)->indexProductsForCategory($r, $p["id"]));

        $router->get('/categories', static fn(Request $r, array $p): Response
            => $container->get(CategoryController::class)->index($r));

        // Static subpath BEFORE {id} so "active" is not swallowed by {id}.
        $router->get('/campaigns/active', static fn(Request $r, array $p): Response
            => $container->get(CampaignController::class)->active($r));

        $router->get('/campaigns/{id}', static fn(Request $r, array $p): Response
            => $container->get(CampaignController::class)->show($r, $p["id"]));

        $router->get('/campaigns', static fn(Request $r, array $p): Response
            => $container->get(CampaignController::class)->index($r));
    }
}