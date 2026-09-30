<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Services\MenuService;
use Pmsrapi\V2\Support\Logger;
use RedisException;
use RuntimeException;


final class CheckoutUpsell
{
    /** How long the same item is not offered to the same shopper again. */
    private const int OFFERED_ITEM_TTL = 2592000;

    /** Longer than any basket stays open. */
    private const int OFFERED_BASKET_TTL = 172800;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly MenuService $menuService,
        private readonly Logger $logger,
    ) {}

    /**
     * The offer for this checkout, or null when there is none to make. Marks
     * the basket and the offered item.
     *
     * @param list<array<string,mixed>> $items the basket's cart rows
     * @return array{product_id: int, name: string, price: float, pitch?: string}|null
     */
    public function offerFor(string $phone, int $orderId, array $items): ?array
    {
        if (!defined('shop_id') || !is_numeric(shop_id) || !$this->redis->isEnabled()) {
            return null;
        }

        $shopId = (int) shop_id;

        try {
            $redis = $this->redis->connection();

            if ($redis->exists($this->basketKey($shopId, $orderId))) {
                return null;
            }

            $product = $this->pick($shopId, $phone, $items);
            if ($product === null) {
                return null;
            }

            // NX: only the first of two overlapping requests makes the offer.
            if (!$redis->set($this->basketKey($shopId, $orderId), '1', ['nx', 'ex' => self::OFFERED_BASKET_TTL])) {
                return null;
            }

            $redis->setex($this->itemKey($shopId, $phone, (int) $product['id']), self::OFFERED_ITEM_TTL, '1');
        } catch (RedisException | RuntimeException $e) {
            $this->logger->warning('marvin.upsell failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);

            return null;
        }

        $offer = [
            'product_id' => (int) $product['id'],
            'name'       => $this->menuService->name((int) $product['id']),
            'price'      => $this->menuService->basePrice((int) $product['id']),
        ];

        $pitch = $product['upsell_pitch'] ?? null;
        if (is_string($pitch) && trim($pitch) !== '') {
            $offer['pitch'] = trim($pitch);
        }

        return $offer;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     *
     * @throws RedisException
     */
    private function pick(int $shopId, string $phone, array $items): ?array
    {
        $inBasket = [];
        foreach ($items as $line) {
            if (is_array($line) && (int) ($line['parent_id'] ?? 0) === 0) {
                $inBasket[(int) ($line['product_id'] ?? 0)] = true;
            }
        }

        foreach ($this->menuService->index() as $id => $product) {
            if (($product['upsell'] ?? false) !== true || isset($inBasket[$id]) || $this->menuService->isDeal($id)) {
                continue;
            }

            if (!$this->redis->connection()->exists($this->itemKey($shopId, $phone, $id))) {
                return ['id' => $id, ...$product];
            }
        }

        return null;
    }

    private function itemKey(int $shopId, string $phone, int $productId): string
    {
        return "marvin:upsell:{$shopId}:{$phone}:{$productId}";
    }

    private function basketKey(int $shopId, int $orderId): string
    {
        return "marvin:upsell_basket:{$shopId}:{$orderId}";
    }
}
