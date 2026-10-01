<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\OrderingUnavailableException;
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


    public const int PICKUP_LOGISTIC_TYPE = 1;
    public const int DELIVERY_LOGISTIC_TYPE = 2;


    public const int DELIVERY_FEE_PRODUCT_ID = -1;
    public const int DELIVERY_FEE_CATEGORY_ID = -1;
    public const string DELIVERY_FEE_DESCRIPTION = 'Delivery Fee';

    private const array ADDRESS_FIELDS = ['country', 'state', 'city', 'zip', 'street', 'box'];

    public const array LOGISTIC_LABELS = [
        self::PICKUP_LOGISTIC_TYPE   => "pick_up",
        self::DELIVERY_LOGISTIC_TYPE => "delivery",
    ];

    public function __construct(
        private readonly Repository $repo,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ClientService $clients,
        private readonly ConversationService $conversations,
        private readonly CampaignDiscountService $campaignDiscounts,
        private readonly ShopService $shops,
    ) {}

    /**
     * The phone's ongoing cart, or a new one. An ongoing cart is always
     * returned — it stays editable whatever the time. A new one only opens
     * while the shop has a window left to order for: today before its last
     * order time, or a later day as a pre-order. $logistics narrows that to
     * pickup or delivery; null means either.
     */
    public function openCart(string $phoneNumber, ?int $logistics = null): array
    {
        return $this->activeOrderFor($phoneNumber) ?? $this->newOrderIfOrderable($phoneNumber, $logistics);
    }

    private function newOrderIfOrderable(string $phoneNumber, ?int $logistics): array
    {
        $now = new \DateTimeImmutable();

        if (!$this->shops->canOpenCart($logistics, $now)) {
            throw new OrderingUnavailableException('no_orderable_slot', $this->shops->availability($now));
        }

        return $this->newOrder($phoneNumber);
    }

    private function newOrder(string $phoneNumber): array
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

    /**
     * The cart $items apply to: the ongoing one, else a new one if the shop
     * is taking orders (see openCart()). A call that only removes things never
     * opens an empty cart.
     */
    private function resolveCartFor(string $phoneNumber, array $items, ?int $logistics): int
    {
        $order = $this->activeOrderFor($phoneNumber);

        if ($order !== null) {
            return (int) $order['id'];
        }

        $addsSomething = array_filter($items, static fn(array $i): bool => (int) ($i['quantity'] ?? 0) > 0);

        if ($addsSomething === []) {
            throw new NotFoundException('No active cart for this phone number');
        }

        return (int) $this->newOrderIfOrderable($phoneNumber, $logistics)['id'];
    }

    /**
     * Applies each item's quantity change and returns the refreshed cart.
     * $logistics is only written when given, so an add never resets a
     * delivery cart back to pickup.
     */
    public function updateCart(array $items, string $phoneNumber, ?int $logistics = null): array
    {
        $orderId = $this->resolveCartFor($phoneNumber, $items, $logistics);

        $changes = [];

        foreach ($items as $item) {
            $changes = [...$changes, ...$this->applyItem($orderId, $item)];
        }

        if ($logistics !== null) {
            $this->repo->updateById($this->ordersTable(), $orderId, ['logistics_type' => $logistics]);
        }

        $changes = [...$changes, ...$this->applyCampaigns($orderId)];

        return $this->withItemsAndTotal($orderId, $changes);
    }

 
    public function applyCampaigns(int $orderId): array
    {
        $table = $this->orderItemsTable();
        $lines = $this->repo->selectRows($table, ['order_id' => $orderId]);

        try {
            $wanted = $this->campaignDiscounts->discountsFor($lines);
        } catch (ApiException $e) {
            $this->logger->error('cart: campaigns unavailable, discount lines left untouched', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }

        // Like cart.ms's apply_promotions: drop every discount line and insert
        // the ones the cart earns now. Each discount is counted before and
        // after (by campaign, product and amount) so only real differences
        // are reported, not the reinsert itself.
        $counts = [];

        foreach ($lines as $line) {
            if (!CampaignDiscountService::isDiscountLine($line)) {
                continue;
            }

            $key = CampaignDiscountService::discountKey($line);
            $counts[$key] ??= ['line' => $line, 'before' => 0, 'after' => 0];
            $counts[$key]['before'] += (int) $line['quantity'];
            $this->repo->deleteById($table, (int) $line['id']);
        }

        foreach ($wanted as $want) {
            $columns = $this->discountLineColumns($want);
            $lineId = $this->repo->insertRow($table, [...$columns, 'order_id' => $orderId]);

            $key = CampaignDiscountService::discountKey($columns);
            $counts[$key] ??= ['line' => ['id' => $lineId, ...$columns], 'before' => 0, 'after' => 0];
            $counts[$key]['after'] += $columns['quantity'];
        }

        $changes = [];

        foreach ($counts as ['line' => $line, 'before' => $before, 'after' => $after]) {
            if ($before !== $after) {
                $changes[] = $this->changeRecord($line, $before, $after);
            }
        }

        return $changes;
    }

    private function discountLineColumns(array $discount): array
    {
        return [
            'product_id'        => CampaignDiscountService::DISCOUNT_PRODUCT_ID,
            'category_id'       => CampaignDiscountService::DISCOUNT_CATEGORY_ID,
            'item_description'  => $discount['item_description'],
            'unit_price'        => $discount['unit_price'],
            'vat_percentage'    => $discount['vat_percentage'],
            'quantity'          => $discount['quantity'],
            'campaign_id'         => $discount['campaign_id'],
            'discount_product_id' => $discount['discount_product_id'],
            'product_reference'   => null,
            'parent_id'           => null,
        ];
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
     * order), else by product_id/campaign_id/parent_id among lines that also
     * carry the exact same set of configs (toppings) — callers like Marvin's
     * "add to order" never know a line's id, so without this a repeat "add
     * another one" of the same customized product would always insert a new
     * line instead of bumping the existing one's quantity. Two lines with
     * different toppings must never merge, so an exact config match is
     * required, not just a product match.
     *
     * The web cart never sends an id either, and adds a complex product in
     * two calls — a plain "add" the moment it's tapped, then a second "add"
     * once the shopper picks its configs in a follow-up prompt — with nothing
     * to correlate the two. If this item now carries configs and no line
     * matches them exactly, but there IS a still-configless line for the same
     * product (the first call's placeholder), treat that as the same
     * in-progress addition finishing rather than a genuinely separate one, and
     * report it via "completingConfigs" so the caller applies its quantity as
     * a replacement, not an addition.
     *
     * @return array{line: ?array<string, mixed>, completingConfigs: bool}
     */
    private function findMatchingLine(int $orderId, array $item): array
    {
        if (isset($item['id']) && $item["id"] !== null) {
            $line = $this->repo->selectRow($this->orderItemsTable(), [
                'order_id' => $orderId,
                'id'       => (int) $item['id'],
            ]);

            return ['line' => $line, 'completingConfigs' => false];
        }

        $candidates = $this->repo->selectRows(
            $this->orderItemsTable(),
            [
                'order_id'    => $orderId,
                'product_id'  => (int) $item['product_id'],
                'campaign_id' => $item['campaign_id'] ?? null,
                'parent_id'   => $item['parent_id'] ?? null,
            ],
            orderBy: 'id:desc',
        );

        $wanted = $this->configSignature(is_array($item['configs'] ?? null) ? $item['configs'] : []);
        $configlessCandidate = null;

        foreach ($candidates as $candidate) {
            $existingSignature = $this->configSignature(
                $this->repo->selectRows($this->orderItemsTable(), ['parent_id' => (int) $candidate['id']]),
            );

            if ($existingSignature === $wanted) {
                return ['line' => $candidate, 'completingConfigs' => false];
            }

            if ($existingSignature === [] && $configlessCandidate === null) {
                $configlessCandidate = $candidate;
            }
        }

        if ($wanted !== [] && $configlessCandidate !== null) {
            return ['line' => $configlessCandidate, 'completingConfigs' => true];
        }

        return ['line' => null, 'completingConfigs' => false];
    }

    /**
     * An order-independent, repeat-sensitive fingerprint of a config set's
     * products — two toppings of the same product are not the same as one,
     * so this is a sorted list, not a set. A config may arrive shaped as a
     * cart item ("product_id") or a resolved option ("option_id"); either is
     * accepted since normalizeIds() only runs on top-level items, not on the
     * "configs" nested inside them.
     *
     * @param list<array<string, mixed>> $configs
     * @return list<int>
     */
    private function configSignature(array $configs): array
    {
        $ids = array_map(
            static fn(array $config): int => (int) ($config['product_id'] ?? $config['option_id'] ?? 0),
            $configs,
        );

        sort($ids);

        return $ids;
    }

    // ---------------------------------------------------------------- write

    /**
     * Applies one item's quantity to its matching line (insert/update/delete),
     * then its configs. Returns a change record for each line that changed.
     */
    private function applyItem(int $orderId, array $item): array
    {
        $item = $this->normalizeIds($item);
        $this->validateItem($item);

        ['line' => $existingLine, 'completingConfigs' => $completingConfigs] = $this->findMatchingLine($orderId, $item);
        $existingQuantity = $existingLine !== null ? (int) $existingLine['quantity'] : 0;

        // Completing a pending configless line is the same cart action
        // finishing, not a second addition — set its quantity outright
        // instead of adding this call's quantity on top of what's already there.
        $override = (bool) ($item['override_quantity'] ?? false) || $completingConfigs;
        $requestedQuantity = (int) $item['quantity'];
        $newQuantity = max(0, $override ? $requestedQuantity : $existingQuantity + $requestedQuantity);

        if ($newQuantity === 0) {
            if ($existingLine === null) {
            $this->logger->error("Inexisting line!");

                // Nothing existed and nothing changed.
                return [];
            }

            $this->logger->error("Removing items", $existingLine);
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
            $lineId = $this->repo->insertRow($this->orderItemsTable(), [...$columns, 'order_id' => $orderId]);
        }

        $line = ['id' => $lineId, ...$columns];

        $changes = $existingQuantity !== $newQuantity
            ? [$this->changeRecord($line, $existingQuantity, $newQuantity)]
            : [];

        return [
            ...$changes,
            ...$syncedChanges,
            ...$this->applyConfigs($orderId, $item['configs'] ?? [], $lineId),
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
    private function applyConfigs(int $orderId, mixed $configs, int $hostLineId): array
    {
        if (!is_array($configs)) {
            throw new ValidationException(['configs' => '"configs" must be a list of items']);
        }

        $changes = [];

        foreach ($configs as $topping) {
            if (!is_array($topping)) {
                throw new ValidationException(['configs' => 'Each topping must be an item object']);
            }

            $changes = [...$changes, ...$this->applyItem($orderId, [...$topping, 'parent_id' => $hostLineId])];
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
        $order['totals'] = $this->computeTotals($items);

        $this->repo->updateById($ordersTable, $orderId, ['total' => round($order['totals']["total"], 2), "display_currency_total" => $order['totals']["total"]]);

        // $order was read before this update, so its "total" is still the
        // previous one. Callers (Marvin reads it back to the shopper) must get
        // the total that was just written, not the one from before the change.
        $order['total'] = round($order['totals']["total"], 2);
        $order['display_currency_total'] = $order['totals']["total"];

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
     * block. Campaign discount lines (see {@see applyCampaigns()}) carry a
     * negative unit_price, so items_total/tax/total already include them;
     * "savings" reports their sum as a positive amount for display. Every
     * line's unit_price is VAT-inclusive, so "tax" is the portion already
     * embedded in items_total (extracted, not added again).
     *
     *
     * @param list<array<string, mixed>> $items flat rows, as loaded above
     * @return array{subtotal: float, savings: float, items_total: float, delivery_fee: float, tax: float, total: float}
     */
    private function computeTotals(array $items): array
    {
        $productLines = array_filter($items, static fn(array $item): bool => !self::isDeliveryFeeLine($item));

        $itemsTotal = array_reduce(
            $productLines,
            static fn(float $carry, array $item): float => $carry + ((float) $item['unit_price'] * (float) $item['quantity']),
            0.0,
        );

        $tax = array_reduce(
            $productLines,
            static function (float $carry, array $item): float {
                $lineTotal = (float) $item['unit_price'] * (float) $item['quantity'];
                $vatRate = (float) $item['vat_percentage'] / 100;

                return $carry + ($vatRate > 0 ? $lineTotal - $lineTotal / (1 + $vatRate) : 0.0);
            },
            0.0,
        );

        $deliveryFee = array_reduce(
            $items,
            static fn(float $carry, array $item): float => $carry
                + (self::isDeliveryFeeLine($item) ? (float) $item['unit_price'] * (float) $item['quantity'] : 0.0),
            0.0,
        );

        $savings = array_reduce(
            $items,
            static fn(float $carry, array $item): float => $carry
                + (CampaignDiscountService::isDiscountLine($item) ? -(float) $item['unit_price'] * (float) $item['quantity'] : 0.0),
            0.0,
        );

        $itemsTotal = round($itemsTotal, 2);

        return [
            'subtotal'     => round($itemsTotal - $tax, 2),
            'savings'      => round($savings, 2),
            'items_total'  => $itemsTotal,
            'delivery_fee' => round($deliveryFee, 2),
            'tax'          => round($tax, 2),
            'total'        => round($itemsTotal + $deliveryFee, 2),
        ];
    }

    /** Identifies a row as the synthetic delivery-fee line, see DELIVERY_FEE_* above. */
    private static function isDeliveryFeeLine(array $item): bool
    {
        return (int) ($item['product_id'] ?? 0) === self::DELIVERY_FEE_PRODUCT_ID
            && (int) ($item['category_id'] ?? 0) === self::DELIVERY_FEE_CATEGORY_ID;
    }

    /**
     * order_items_active columns for a synthetic delivery-fee line (minus
     * order_id, which only the caller knows). Not taxed — vat_percentage 0 —
     * matching the old computed delivery_fee, which was likewise added on top
     * without VAT extraction.
     *
     * @return array<string, mixed>
     */
    public function deliveryFeeLineColumns(float $fee): array
    {
        return [
            'product_id'        => self::DELIVERY_FEE_PRODUCT_ID,
            'category_id'       => self::DELIVERY_FEE_CATEGORY_ID,
            'item_description'  => self::DELIVERY_FEE_DESCRIPTION,
            'unit_price'        => $fee,
            'vat_percentage'    => 0,
            'quantity'          => 1,
            'campaign_id'       => null,
            'product_reference' => null,
            'parent_id'         => null,
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

        $logistics = (int) ($checkoutData['logistics_type'] ?? $order['logistics_type'] ?? self::PICKUP_LOGISTIC_TYPE);

        if (!isset(self::LOGISTIC_LABELS[$logistics])) {
            throw new ValidationException(['logistics_type' => "Unknown logistics type {$logistics}"]);
        }

        // Re-checked here, not only when the moment was chosen: time has
        // passed since, and the chosen moment may be gone or past its last
        // order time by now.
        $moment = $logistics === self::DELIVERY_LOGISTIC_TYPE
            ? ($checkoutData['delivery_moment'] ?? $order['delivery_moment'] ?? null)
            : ($checkoutData['pick_up_moment'] ?? $checkoutData['pickup_moment'] ?? $order['pick_up_moment'] ?? null);

        $this->assertOrderable($logistics, $moment);

        $checkoutData['logistics_type'] = $logistics;

        if ($logistics !== self::DELIVERY_LOGISTIC_TYPE) {
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
            'pick_up_time' => $orderFields["pick_up_moment"] ?? $orderFields["pickup_moment"] ?? null, // pickup key naming
            'status_id'    => self::CHECKED_OUT_STATUS_ID,
            'status_label' => "ordered",
            'logistics_label' => self::LOGISTIC_LABELS[$logistics]
        ]);

        return $this->withItemsAndTotal($orderId, [], false);
    }

    /**
     * Throws unless $moment ("Y-m-d H:i[:s]") is still orderable for this
     * logistics type — see ShopService::momentProblem().
     */
    private function assertOrderable(int $logistics, ?string $moment): void
    {
        $now = new \DateTimeImmutable();

        if ($moment === null || trim($moment) === '') {
            throw new OrderingUnavailableException('moment_required', $this->shops->availability($now));
        }

        try {
            $parsed = new \DateTimeImmutable($moment);
        } catch (\Exception) {
            throw new OrderingUnavailableException('invalid_moment', $this->shops->availability($now));
        }

        $problem = $this->shops->momentProblem($logistics, $parsed, $now);

        if ($problem !== null) {
            throw new OrderingUnavailableException($problem, $this->shops->availability($now));
        }
    }

    public function updateCartLogistics(int $logisticsType, \DateTimeImmutable $moment, string $phone): void
    {
        if (!isset(self::LOGISTIC_LABELS[$logisticsType])) {
            throw new ValidationException(['logistics_type' => "Unknown logistics type {$logisticsType}"]);
        }

        $order = $this->activeOrderFor($phone);

        if ($order === null) {
            throw new NotFoundException('No active cart to update for this phone number');
        }

        $now = new \DateTimeImmutable();
        $problem = $this->shops->momentProblem($logisticsType, $moment, $now);

        if ($problem !== null) {
            throw new OrderingUnavailableException($problem, $this->shops->availability($now));
        }

        $formatted  = $moment->format('Y-m-d H:i:s');
        $isDelivery = $logisticsType === self::DELIVERY_LOGISTIC_TYPE;

        $this->repo->updateById($this->ordersTable(), (int) $order['id'], [
            'logistics_type'  => $logisticsType,
            'logistics_label' => self::LOGISTIC_LABELS[$logisticsType],
            'pick_up_moment'  => $isDelivery ? null : $formatted,
            'delivery_moment' => $isDelivery ? $formatted : null,
        ]);
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
