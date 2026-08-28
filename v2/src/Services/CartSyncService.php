<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Cache\RedisLock;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;

/**
 * Replaces a cart's entire contents with the given items in one call:
 * wipes all existing lines for the order and reinserts the given
 * items/configs from scratch. Quantities are absolute, not deltas.
 */
final class CartSyncService
{
    public function __construct(
        private readonly Repository $repo,
        private readonly CartService $cart,
        private readonly RedisLock $lock,
    ) {}

    /**
     * @param list<array<string, mixed>> $items same item shape CartService
     *        takes — product_id (or option_id), category_id (or group_id),
     *        item_description, unit_price, vat_percentage, quantity,
     *        configs[] — minus ids.
     * @return array<string, mixed> the synced cart, items attached
     */
    public function replaceCart(array $items, string $phoneNumber): ?array
    {
        // Keyed by phone rather than order id: no cart may exist yet at this
        // point, and phone is what actually identifies "the same cart" to a
        // second, concurrent sync call.
        $lockKey = "cart:sync:{$phoneNumber}";

        if (!$this->lock->acquire($lockKey, ttlSeconds: 5, timeoutMs: 2000)) {
            // only after genuinely waiting ~2s and still can't get in — now it's a real problem
            return null;
        }

        try {
            $order = $this->cart->activeOrderFor($phoneNumber);

            if ($order === null) {
                if ($items === []) {
                    return ['phonenumber' => $phoneNumber, 'items' => [], 'total' => 0.0];
                }

                $order = $this->cart->newOrder($phoneNumber);
            }

            $orderId = (int) $order['id'];

            $this->wipeLines($orderId);

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new ValidationException(['items' => 'Each cart item must be an object']);
                }

                $this->insertLine($orderId, $item, null);
            }

            return $this->cart->withItemsAndTotal($orderId, [], false);
        } finally {
            $this->lock->release($lockKey);
        }
    }

    private function wipeLines(int $orderId): void
    {
        $table = $this->orderItemsTable();

        foreach ($this->repo->selectRows($table, ['order_id' => $orderId]) as $line) {
            $this->repo->deleteById($table, (int) $line['id']);
        }
    }

    /**
     * Inserts one item as a line, then recurses into its configs. A
     * zero-quantity item is a removal, not a real line — skip it (and, since
     * there's no line to attach them to, its configs too) rather than
     * inserting a dead 0-quantity row.
     */
    private function insertLine(int $orderId, array $item, ?int $parentId): void
    {
        $item = $this->normalizeIds($item);
        $this->validateItem($item);

        $quantity = max(0, (int) $item['quantity']);

        if ($quantity === 0) {
            return;
        }

        $lineId = $this->repo->insertRow($this->orderItemsTable(), [
            'order_id'          => $orderId,
            'product_id'        => (int) $item['product_id'],
            'category_id'       => (int) $item['category_id'],
            'item_description'  => (string) $item['item_description'],
            'unit_price'        => (float) $item['unit_price'],
            'vat_percentage'    => (int) $item['vat_percentage'],
            'quantity'          => $quantity,
            'campaign_id'       => $item['campaign_id'] ?? null,
            'product_reference' => $item['product_reference'] ?? null,
            'parent_id'         => $parentId,
        ]);

        $configs = $item['configs'] ?? [];

        if (!is_array($configs)) {
            throw new ValidationException(['configs' => '"configs" must be a list of items']);
        }

        foreach ($configs as $config) {
            if (!is_array($config)) {
                throw new ValidationException(['configs' => 'Each topping must be an item object']);
            }

            $this->insertLine($orderId, $config, $lineId);
        }
    }

    /**
     * A config may arrive shaped as a resolved option ("option_id"/"group_id")
     * rather than a cart item ("product_id"/"category_id"); normalize it
     * before validation/insert read product_id/category_id.
     */
    private function normalizeIds(array $item): array
    {
        if (!isset($item['product_id']) && isset($item['option_id'])) {
            $item['product_id'] = $item['option_id'];
        }

        if (!isset($item['category_id']) && isset($item['group_id'])) {
            $item['category_id'] = $item['group_id'];
        }

        return $item;
    }

    private function validateItem(array $item): void
    {
        $required = ['product_id', 'category_id', 'item_description', 'unit_price', 'vat_percentage', 'quantity'];

        foreach ($required as $field) {
            if (!isset($item[$field])) {
                throw new ValidationException([$field => "Field \"{$field}\" is required for a cart item"]);
            }
        }
    }

    private function shopId(): int
    {
        if (!defined('shop_id') || !is_numeric(shop_id)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) shop_id;
    }

    private function orderItemsTable(): string
    {
        return 'order_items_active_' . $this->shopId();
    }
}
