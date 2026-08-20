<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Support\Logger;

/**
 * The cart: an "ordering"-status row in `orders_active_{shop}` plus lines in
 * `order_items_active_{shop}`. Quantity is a signed delta by default
 * ("override_quantity" for an absolute set); a config's parent_id is its host
 * line's id, and its quantity always tracks the host's.
 */
final class CartService
{
    private const int CART_STATUS_ID = 1;
    private const string CART_STATUS_LABEL = 'ordering';

    private const int CHECKED_OUT_STATUS_ID = 2;

    private const int DELIVERY_LOGISTIC_TYPE = 2;

    private const array ADDRESS_FIELDS = ['country', 'state', 'city', 'zip', 'street', 'box'];

    private const array LOGISTIC_LABELS = [1 => "pick_up", 2 => "delivery"];

    public function __construct(
        private readonly Repository $repo,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ClientService $clients,
    ) {
    }

    public function newOrder(string $phoneNumber): array
    {
        $table = $this->ordersTable();

        $id = $this->repo->insertRow($table, [
            'phonenumber'  => $phoneNumber,
            'status_id'    => self::CART_STATUS_ID,
            'status_label' => self::CART_STATUS_LABEL,
            'ordered_time' => date('Y-m-d H:i:s'),
            'total'        => 0,
        ]);

        $order = $this->repo->selectRow($table, ['id' => $id]);

        if ($order === null) {
            throw new ApiException('Failed to create cart order');
        }

        $order['items'] = [];

        //TO-DO: this will be removed in the future when the shop is set with a phone number
        $this->clients->upsertClient($phoneNumber, "");

        return $order;
    }

    /** Applies each item's quantity change and returns the refreshed cart. */
    public function updateCart(array $items, string $phoneNumber): array
    {
        $order = $this->activeOrderFor($phoneNumber);
        $orderId = $order !== null ? (int) $order['id'] : null;

        $changes = [];

        foreach ($items as $item) {
            $changes = [...$changes, ...$this->applyItem($orderId, $item, $phoneNumber)];
        }

        if ($orderId === null) {
            throw new NotFoundException('No active cart for this phone number');
        }

        return $this->withItemsAndTotal($orderId, $changes);
    }

    // ------------------------------------------------------------- lookups

    public function activeOrderFor(string $phoneNumber): ?array
    {
        return $this->repo->selectRows(
            $this->ordersTable(),
            ['phonenumber' => $phoneNumber, 'status_id' => self::CART_STATUS_ID],
            orderBy: 'id:desc',
            limit: 1,
        )[0] ?? null;
    }

    /**
     * Finds the line this item matches: by "id" if given (scoped to this
     * order), else by product_id/campaign_id/parent_id — unless the item
     * carries its own "configs", which always forces a new line instead.
     */
    private function findMatchingLine(?int $orderId, array $item): ?array
    {
        if ($orderId === null) {
            return null;
        }

        if (isset($item['id']) && $item["id"] !== null) {
            return $this->repo->selectRow($this->orderItemsTable(), [
                'order_id' => $orderId,
                'id'       => (int) $item['id'],
            ]);
        }

        if (!empty($item['configs'])) {
            return null;
        }

        return $this->repo->selectRow($this->orderItemsTable(), [
            'order_id'    => $orderId,
            'product_id'  => (int) $item['product_id'],
            'campaign_id' => $item['campaign_id'] ?? null,
            'parent_id'   => $item['parent_id'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------- write

    /**
     * Applies one item's quantity to its matching line (insert/update/delete),
     * then its configs. Returns a change record for each line that changed.
     */
    private function applyItem(?int &$orderId, array $item, string $phoneNumber): array
    {
        $this->validateItem($item);

        $existingLine = $this->findMatchingLine($orderId, $item);
        $existingQuantity = $existingLine !== null ? (int) $existingLine['quantity'] : 0;

        $override = (bool) ($item['override_quantity'] ?? false);
        $newQuantity = max(0, $override ? (int) $item['quantity'] : $existingQuantity + (int) $item['quantity']);

        if ($newQuantity === 0) {
            if ($existingLine === null) {
                // Nothing existed and nothing changed.
                return [];
            }

            $change = $this->changeRecord($existingLine, $existingQuantity, 0);
            $this->deleteLine((int) $existingLine['id']);

            // Nothing left to attach configs to.
            return [$change];
        }

        // Every touch refreshes the line's catalog-derived fields to whatever
        // this request sent — description/price/etc. are not identity, so a
        // matched line never stays frozen at stale values
        $columns = [
            'product_id'        => (int) $item['product_id'],
            'category_id'       => (int) $item['category_id'],
            'item_description'  => (string) $item['item_description'],
            'unit_price'        => (float) $item['unit_price'],
            'vat_percentage'    => (int) $item['vat_percentage'],
            'quantity'          => $newQuantity,
            'campaign_id'       => $item['campaign_id'] ?? null,
            'product_reference' => $item['product_reference'] ?? null,
            'parent_id'         => $item['parent_id'] ?? null,
        ];

        $syncedChanges = [];

        if ($existingLine !== null) {
            $this->repo->updateById($this->orderItemsTable(), (int) $existingLine['id'], $columns);
            $lineId = (int) $existingLine['id'];

            if ($existingQuantity !== $newQuantity) {
                $syncedChanges = $this->syncConfigQuantities($lineId, $newQuantity);
            }
        } else {
            if ($orderId === null) {
                $orderId = (int) $this->newOrder($phoneNumber)['id'];
            }

            $lineId = $this->repo->insertRow($this->orderItemsTable(), [...$columns, 'order_id' => $orderId]);
        }

        $line = ['id' => $lineId, ...$columns];

        $changes = $existingQuantity !== $newQuantity
            ? [$this->changeRecord($line, $existingQuantity, $newQuantity)]
            : [];

        return [
            ...$changes,
            ...$syncedChanges,
            ...$this->applyConfigs($orderId, $item['configs'] ?? [], $lineId, $phoneNumber),
        ];
    }

    /**
     * Every config on a host line applies to every unit of it — there's no
     * per-unit split — so a config's quantity isn't independently meaningful;
     * it must track its host's. Whenever an existing host line's quantity
     * changes, pulls every config attached to it (parent_id = $hostLineId)
     * to match, unless this same call also explicitly names that config in
     * "configs" — {@see applyConfigs()} runs after this and has the final say.
     *
     * @return list<array<string, mixed>> change records, see {@see applyItem()}
     */
    private function syncConfigQuantities(int $hostLineId, int $hostQuantity): array
    {
        $table = $this->orderItemsTable();
        $changes = [];

        foreach ($this->repo->selectRows($table, ['parent_id' => $hostLineId]) as $config) {
            $before = (int) $config['quantity'];

            if ($before === $hostQuantity) {
                continue;
            }

            $this->repo->updateById($table, (int) $config['id'], ['quantity' => $hostQuantity]);
            $changes[] = $this->changeRecord($config, $before, $hostQuantity);
        }

        return $changes;
    }

    /**
     * @param list<mixed> $configs items in the same shape as a top-level
     *        cart item; each one's parent_id is forced to $hostLineId
     * @return list<array<string, mixed>> change records, see {@see applyItem()}
     */
    private function applyConfigs(?int &$orderId, mixed $configs, int $hostLineId, string $phoneNumber): array
    {
        if (!is_array($configs)) {
            throw new ValidationException(['configs' => '"configs" must be a list of items']);
        }

        $changes = [];

        foreach ($configs as $topping) {
            if (!is_array($topping)) {
                throw new ValidationException(['configs' => 'Each topping must be an item object']);
            }

            $changes = [...$changes, ...$this->applyItem($orderId, [...$topping, 'parent_id' => $hostLineId], $phoneNumber)];
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function changeRecord(array $line, int $quantityBefore, int $quantityAfter): array
    {
        return [
            'id'               => (int) $line['id'],
            'product_id'       => (int) $line['product_id'],
            'item_description' => (string) $line['item_description'],
            'parent_id'        => isset($line['parent_id']) ? (int) $line['parent_id'] : null,
            'quantity_before'  => $quantityBefore,
            'quantity_after'   => $quantityAfter,
        ];
    }

    /** Deletes a line and cascades to anything whose parent_id points at it (its configs). */
    private function deleteLine(int $rowId): void
    {
        $table = $this->orderItemsTable();

        foreach ($this->repo->selectRows($table, ['parent_id' => $rowId]) as $topping) {
            $this->repo->deleteById($table, (int) $topping['id']);
        }

        $this->repo->deleteById($table, $rowId);
    }

    /**
     * Reloads the cart's items, writes back the recomputed total, and
     * buckets $changes (see {@see applyItem()}) into "added" (quantity went
     * up, including brand-new lines) and "removed" (quantity went down,
     * including lines deleted outright) for the caller to show what this
     * call actually did.
     *
     * @param list<array<string, mixed>> $changes
     */
    public function withItemsAndTotal(int $orderId, array $changes = [], bool $includeChanges = true): array
    {
        $ordersTable = $this->ordersTable();

        $items = $this->repo->selectRows($this->orderItemsTable(), ['order_id' => $orderId]);

        $total = array_reduce(
            $items,
            static fn(float $carry, array $item): float => $carry + ((float) $item['unit_price'] * (float) $item['quantity']),
            0.0,
        );

        $this->repo->updateById($ordersTable, $orderId, ['total' => round($total, 2), "display_currency_total" => $total]);

        $order = $this->repo->selectRow($ordersTable, ['id' => $orderId]);

        if ($order === null) {
            throw new ApiException('Cart order disappeared while updating it');
        }

        $order['items'] = $items;

        if(!$includeChanges){
            return $order;
        }

        $order['added'] = array_values(array_filter(
            $changes,
            static fn(array $c): bool => $c['quantity_after'] > $c['quantity_before'],
        ));
        $order['removed'] = array_values(array_filter(
            $changes,
            static fn(array $c): bool => $c['quantity_after'] < $c['quantity_before'],
        ));

        return $order;
    }

    private function validateItem(array $item): void
    {
        $required = ['product_id', 'category_id', 'unit_price', 'vat_percentage', 'quantity'];
        
        foreach ($required as $field) {
            if (!isset($item[$field])) {
                throw new ValidationException([$field => "Field \"{$field}\" is required for a cart item"]);
            }
        }

        //when updating existing items decription is not required
        if( !isset($item["id"]) && !isset($item["item_description"])){
            throw new ValidationException([$field => "Field \"item_description\" is required for a cart item"]);
        }
    }

    /**
     * @param array<string, mixed> $checkoutData full_name, business_name,
     *        business_vat, business_tin, logistic_type,
     *        country/state/city/zip/street/box and their billing_*
     *        counterparts — see orders_active_{shop} in seed.sql. The
     *        shipping address (country/state/city/zip/street/box) is only
     *        saved — to the client and the order — when logistic_type is
     *        self::DELIVERY_LOGISTIC_TYPE; a pickup order has nowhere to ship
     *        to, so any address sent along with one is ignored rather than
     *        overwriting what's on file. Unknown keys are dropped by
     *        Repository's own schema whitelist on both writes.
     * @return array<string, mixed> the checked-out order, items attached
     */
    public function checkoutOrder(array $checkoutData, string $phone): array
    {
        $order = $this->activeOrderFor($phone);

        if ($order === null) {
            throw new NotFoundException('No active cart to checkout for this phone number');
        }

        if ((int) ($checkoutData['logistic_type'] ?? null) !== self::DELIVERY_LOGISTIC_TYPE) {
            $checkoutData = array_diff_key($checkoutData, array_flip(self::ADDRESS_FIELDS));
        }

        $this->clients->upsertFromCheckout($phone, $checkoutData);

        $orderId = (int) $order['id'];


        // orders_active_{shop} calls this column logistics_type, not
        // logistic_type — same client-facing name OrderQueryService uses.
        $orderFields = $checkoutData;
        if (array_key_exists('logistic_type', $orderFields)) {
            $orderFields['logistics_type'] = $orderFields['logistic_type'];
            unset($orderFields['logistic_type']);
        }

        // Explicit status fields are placed last so checkout data can never
        // smuggle its own status_id/status_label past the ones set here.
        $this->repo->updateById($this->ordersTable(), $orderId, [
            ...$orderFields,
            'status_id'    => self::CHECKED_OUT_STATUS_ID,
            'status_label' => "ordered",
            'logistics_label' => self::LOGISTIC_LABELS[$orderFields["logistics_type"]]
        ]);

        return $this->withItemsAndTotal($orderId, [], false);
    }

    // --------------------------------------------------------------- helpers

    private function shopId(): int
    {
        $shopId = $this->config->secret('company.shop_id');

        if (!is_numeric($shopId)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) $shopId;
    }

    private function ordersTable(): string
    {
        return 'orders_active_' . $this->shopId();
    }

    private function orderItemsTable(): string
    {
        return 'order_items_active_' . $this->shopId();
    }
}
