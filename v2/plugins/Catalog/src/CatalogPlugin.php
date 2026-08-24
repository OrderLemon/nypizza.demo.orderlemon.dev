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
use Plugins\Support\ShopContext;

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
        $router->get('/{shop_id}/products', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(ProductsController::class)->index($r)));

        $router->get('/{shop_id}/products/{id}', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(ProductsController::class)->show($r, $p["id"])));

        $router->get('/{shop_id}/categories/products', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CategoryController::class)->indexWithProducts($r)));

        $router->get('/{shop_id}/categories/{id}', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CategoryController::class)->show($r, $p["id"])));

        $router->get('/{shop_id}/categories/{id}/products', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CategoryController::class)->indexProductsForCategory($r, $p["id"])));

        $router->get('/{shop_id}/categories', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CategoryController::class)->index($r)));

        // Static subpath BEFORE {id} so "active" is not swallowed by {id}.
        $router->get('/{shop_id}/campaigns/active', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CampaignController::class)->active($r)));

        $router->get('/{shop_id}/campaigns/{id}', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CampaignController::class)->show($r, $p["id"])));

        $router->get('/{shop_id}/campaigns', ShopContext::wrap(fn(Request $r, array $p): Response
            => $container->get(CampaignController::class)->index($r)));
    }
}