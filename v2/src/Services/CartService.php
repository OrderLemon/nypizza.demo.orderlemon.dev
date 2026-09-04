<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\ServiceException;
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

    // Same figures ShopController::present() reports to the client — delivery
    // is free once the cart's items_total clears the threshold.
    private const float DELIVERY_FEE = 2.5;
    private const float FREE_DELIVERY_THRESHOLD = 25.0;

    private const array ADDRESS_FIELDS = ['country', 'state', 'city', 'zip', 'street', 'box'];

    private const array LOGISTIC_LABELS = [1 => "pick_up", 2 => "delivery"];

    public function __construct(
        private readonly Repository $repo,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ClientService $clients,
        private readonly ConversationService $conversations,
    ) {}

    public function newOrder(string $phoneNumber): array
    {
        $table = $this->ordersTable();

        $client = $this->clients->getByPhone($phoneNumber);

        if($client === null){
            throw new ApiException("No client for $phoneNumber found!");
        }

        // $conversation = $this->conversations->getByPhone($phoneNumber);

        // if($conversation === null){
        //     throw new ApiException("No active conversation for $phoneNumber found!");
        // }

        $id = $this->repo->insertRow($table, [
            'full_name'    => $client["full_name"] ?? "", 
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

        // $this->conversations->upsertConversation($phoneNumber, ["order_id" =>$order["id"]]);

        $order['items'] = [];


        return $order;
    }

    /** Applies each item's quantity change and returns the refreshed cart. */
    public function updateCart(array $items, string $phoneNumber, int $logistics = 1): array
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

        $order["logistics_type"] = $logistics;

        //TO-DO: send the order as apram to withItemsAndTotal
        $orderUpdated = $this->repo->updateById($this->ordersTable(),
            $orderId,
            $order,
        );

        if($orderUpdated < 0 ){
            throw new ServiceException('Could not update order logistiscs!');
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
        $item = $this->normalizeIds($item);
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
            'vat_percentage'    => self::normalizeVatPercentage($item['vat_percentage']),
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
     * $nestConfigs=false keeps the historic shape — a flat list, configs and
     * hosts side by side — which every existing caller (findLine, checkout,
     * MarvinTools::summarize's own folding) matches lines against by id. Pass
     * true to fold each config into its host's "configs" key instead; the
     * total is computed from the flat rows either way, so nesting never
     * changes what gets charged.
     *
     * @param list<array<string, mixed>> $changes
     */
    public function withItemsAndTotal(
        int $orderId,
        array $changes = [],
        bool $includeChanges = true,
        bool $nestConfigs = false,
    ): array {
        $ordersTable = $this->ordersTable();

        $items = $this->repo->selectRows($this->orderItemsTable(), ['order_id' => $orderId]);

        // A config line (parent_id set) also carries its identity back out as
        // option_id/group_id — the shape MarvinTools/normalizeIds() write it
        // in as — alongside the untouched product_id/category_id, so callers
        // can read either without caring which one the row was inserted with.
        $items = array_map(
            static function (array $item): array {
                if ((int) ($item['parent_id'] ?? 0) !== 0) {
                    $item['option_id'] = $item['product_id'];
                    $item['group_id']  = $item['category_id'];
                }

                return $item;
            },
            $items,
        );

        $order = $this->repo->selectRow($ordersTable, ['id' => $orderId]);

        if ($order === null) {
            throw new ApiException('Cart order disappeared while updating it');
        }

        $order['items'] = $nestConfigs ? $this->nestConfigs($items) : $items;
        $order['totals'] = $this->computeTotals($items, (int) ($order['logistics_type'] ?? 1));

        $this->repo->updateById($ordersTable, $orderId, ['total' => round($order['totals']["total"], 2), "display_currency_total" => $order['totals']["total"]]);

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

    /**
     * Breaks the cart's flat items_total down into a receipt-style figure
     * block. There's no list/original-price column on order_items_active_{shop}
     * to diff a campaign discount against, so "savings" is always 0 and
     * "subtotal" mirrors "items_total" until that data exists — both are
     * reported anyway so the response shape is stable for the client. Every
     * line's unit_price is VAT-inclusive, so "tax" is the portion already
     * embedded in items_total (extracted, not added again); only delivery_fee
     * is added on top to produce "total". delivery_fee only ever applies to a
     * delivery order (logistics_type === self::DELIVERY_LOGISTIC_TYPE) — a
     * pickup order, or one whose logistics_type isn't set yet, never gets one.
     *
     * @param list<array<string, mixed>> $items flat rows, as loaded above
     * @return array{subtotal: float, savings: float, items_total: float, delivery_fee: float, tax: float, total: float}
     */
    private function computeTotals(array $items, int $logisticsType): array
    {
        
        $itemsTotal = array_reduce(
            $items,
            static fn(float $carry, array $item): float => $carry + ((float) $item['unit_price'] * (float) $item['quantity']),
            0.0,
        );


        $tax = array_reduce(
            $items,
            static function (float $carry, array $item): float {
                $lineTotal = (float) $item['unit_price'] * (float) $item['quantity'];
                $vatRate = (float) $item['vat_percentage'] / 100;

                return $carry + ($vatRate > 0 ? $lineTotal - $lineTotal / (1 + $vatRate) : 0.0);
            },
            0.0,
        );

        $isDelivery = $logisticsType === self::DELIVERY_LOGISTIC_TYPE;
        $deliveryFee = $isDelivery && $itemsTotal <= self::FREE_DELIVERY_THRESHOLD ? self::DELIVERY_FEE : 0.0;
        $itemsTotal = round($itemsTotal, 2);

        return [
            'subtotal'     => $itemsTotal,
            'savings'      => 0.0,
            'items_total'  => $itemsTotal,
            'delivery_fee' => $deliveryFee,
            'tax'          => round($tax, 2),
            'total'        => round($itemsTotal + $deliveryFee, 2),
        ];
    }

    /**
     * Folds each config (parent_id set) into its host's "configs" key,
     * dropping it from the top level. Hosts with no configs get an empty
     * array, not a missing key, so callers never need an isset() guard.
     *
     * @param list<array<string, mixed>> $items flat rows, as {@see withItemsAndTotal()} loads them
     * @return list<array<string, mixed>> top-level lines only, each with a "configs" key
     */
    private function nestConfigs(array $items): array
    {
        $configsByParent = [];
        foreach ($items as $item) {
            $parentId = (int) ($item['parent_id'] ?? 0);
            if ($parentId !== 0) {
                $configsByParent[$parentId][] = $item;
            }
        }

        $nested = [];
        foreach ($items as $item) {
            if ((int) ($item['parent_id'] ?? 0) !== 0) {
                continue; // folded into its host above
            }

            $item['configs'] = $configsByParent[(int) $item['id']] ?? [];
            $nested[] = $item;
        }

        return $nested;
    }

    /**
     * A cart line's VAT rate can arrive either as a whole-number percent
     * (6, 21 — the catalog/MarvinTools convention) or as a fraction
     * (0.06, 0.21). The `vat_percentage` column is a whole-number `int(2)`,
     * so a fraction is scaled up before storage — a bare `(int)` cast would
     * otherwise truncate 0.21 straight to 0 and silently zero out the tax.
     * No real VAT rate is below 1% as a whole number, so "< 1" reliably means
     * "this is a fraction, not a percent".
     */
    public static function normalizeVatPercentage(mixed $value): int
    {
        $rate = (float) $value;

        return (int) round($rate < 1 ? $rate * 100 : $rate);
    }

    /**
     * A config may arrive shaped as a resolved option ("option_id"/"group_id")
     * rather than a cart item ("product_id"/"category_id"). Normalize it
     * before anything downstream — validation, line matching, the
     * insert/update columns — reads product_id/category_id.
     */
    private function normalizeIds(array $item): array
    {
        if (!isset($item["product_id"]) && isset($item["option_id"])) {
            $item["product_id"] = $item["option_id"];
        }

        if (!isset($item["category_id"]) && isset($item["group_id"])) {
            $item["category_id"] = $item["group_id"];
        }

        return $item;
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
            throw new ValidationException(['item_description' => "Field \"item_description\" is required for a cart item"]);
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

        if ((int) ($checkoutData['logistics_type'] ?? null) !== self::DELIVERY_LOGISTIC_TYPE) {
            $checkoutData = array_diff_key($checkoutData, array_flip(self::ADDRESS_FIELDS));
        }

        if(isset($checkoutData["country"])){
            $checkoutData["country"] = strtoupper(substr($checkoutData["country"],0,2));
        }

        //update client delivery address
        $this->clients->upsertFromCheckout($phone, $checkoutData);

        $orderId = (int) $order['id'];

        $orderFields = $checkoutData;

        // Explicit status fields are placed last so checkout data can never
        // smuggle its own status_id/status_label past the ones set here.
        $this->repo->updateById($this->ordersTable(), $orderId, [
            ...$orderFields,
            'pick_up_time' => $orderFields["pick_up_moment"] ?? $orderFields["pickup_moment"], // pickup key naming 
            'status_id'    => self::CHECKED_OUT_STATUS_ID,
            'status_label' => "ordered",
            'logistics_label' => self::LOGISTIC_LABELS[$orderFields["logistics_type"]]
        ]);

        return $this->withItemsAndTotal($orderId, [], false);
    }

    // --------------------------------------------------------------- helpers

    private function shopId(): int
    {

        if (!defined("shop_id") || !is_numeric(shop_id)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) shop_id;
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
