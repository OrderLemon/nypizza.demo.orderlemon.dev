<?php

declare(strict_types=1);


use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;
use Plugins\Whatsapp\AI\ShopBackground;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Container $container */
$container = require __DIR__ . '/../../bootstrap.php';

$logger = $container->get(Logger::class);
$background = $container->get(ShopBackground::class);

$sources = $background->sources();

if ($sources === []) {
    echo "No web_sources configured, nothing to refresh.\n";
    exit;
}

$failed = 0;

foreach ($sources as $shopId => $url) {
    try {
        $chars = $background->refresh($shopId, $url);

        $logger->info('shop_background.refreshed', ['shop_id' => $shopId, 'url' => $url, 'chars' => $chars]);
        echo "shop {$shopId}: refreshed, {$chars} chars\n";
    } catch (ApiException $e) {
        $failed++;

        $logger->error('shop_background.refresh_failed', [
            'shop_id' => $shopId,
            'url'     => $url,
            'error'   => $e->getMessage(),
        ]);
        echo "shop {$shopId}: FAILED, kept previous text ({$e->getMessage()})\n";
    }
}

exit($failed > 0 ? 1 : 0);
