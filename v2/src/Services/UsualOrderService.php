<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Helpers\CustomerHelper;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;

/**
 * "The usual" — the basket a returning shopper orders most often.
 *
 *
 * The fingerprint deliberately ignores:
 *   - prices, which change (a price rise must not split one usual into two)
 *   - discount and adjustment lines, where product_id is 0
 *   - item ids and campaign slots, which are per-order bookkeeping
 * and sorts config options, so "extra cheese + mushrooms" and "mushrooms +
 * extra cheese" are the same meal.
 *
 * Counting happens over a RECENCY WINDOW rather than all time. Tastes change:
 * twenty Vegi Max last year should lose to three Diavola this month.
 *
 * The result reports its own confidence, because the copy has to change with it.
 * Calling something "the usual" when it was ordered once reads as a system
 * pretending to know you.
 */
final class UsualOrderService
{
    function __construct(
        private readonly Repository $repo,
        private readonly Logger $logger,
    ){}

    public const MOCKUP = 'orders';

    /** How many recent orders to consider. */
    private const WINDOW = 10;

    /** Minimum repeats before a basket earns the phrase "the usual". */
    private const USUAL_THRESHOLD = 2;

    /** Baskets from these statuses don't count — they were never eaten. */
    private const IGNORED_STATUSES = ['cancelled'];

    /**
     * @return array{
     *   confidence: string,
     *   times_ordered: int,
     *   considered: int,
     *   source_order_id: int,
     *   signature: string,
     *   summary: string,
     *   button_title: string,
     *   items: list<array<string,mixed>>,
     *   total: float
     * }|null  null when there is no history to reorder from
     */
    public function forPhone(string $phone): ?array
    {
        $orders = $this->historyFor($phone);

        if ($orders === []) {
            return null;
        }

        // Newest first, so the first sighting of a signature is its recency rank.
        $groups = [];

        foreach ($orders as $rank => $order) {
            $signature = $this->fingerprint($order);

            if ($signature === '') {
                continue;   // nothing but discount lines
            }

            if (!isset($groups[$signature])) {
                $groups[$signature] = ['count' => 0, 'rank' => $rank, 'order' => $order];
            }

            $groups[$signature]['count']++;
        }

        if ($groups === []) {
            return null;
        }

        // Most frequent wins; most recent breaks the tie.
        uasort(
            $groups,
            static fn(array $a, array $b): int => $b['count'] <=> $a['count']
                ?: $a['rank'] <=> $b['rank']
        );

        $signature = array_key_first($groups);
        $winner    = $groups[$signature];
        $items     = $this->basket($winner['order']);

        $isUsual = $winner['count'] >= self::USUAL_THRESHOLD;

        return [
            'confidence'      => $isUsual ? 'usual' : 'last',
            'times_ordered'   => $winner['count'],
            'considered'      => count($orders),
            'source_order_id' => (int) ($winner['order']['id'] ?? 0),
            'hash'            => $signature,
            'summary'         => $this->summarise($items),
            // WhatsApp reply button titles are capped at 20 characters, so the
            // basket contents go in the body text, never the button.
            'button_title'    => $isUsual ? 'Order the usual' : 'Same as last time',
            'items'           => $items,
            'total'           => $this->total($winner['order']),
        ];
    }

    /**
     * Rebuild the basket for a signature, so a tapped button can be turned into
     * a new order without trusting anything the client sent back.
     *
     * @return list<array<string,mixed>>|null
     */
    public function basketFor(string $phone, string $signature): ?array
    {
        foreach ($this->historyFor($phone) as $order) {
            if ($this->fingerprint($order) === $signature) {
                $this->logger->debug("basket for $phone", $order);
                return $this->basket($order);
            }
        }

        return null;
    }

    // ------------------------------------------------------------ fingerprint

    /**
     * A stable signature for what was actually eaten. Same meal, same string —
     * regardless of price changes, item ordering, or option ordering.
     *
     * There is no nested "config" array any more: a config IS an order_items
     * row, distinguished from a normal line only by carrying a parent_id that
     * points at its host line's id. So this walks the flat list once to group
     * children under their parent, then fingerprints each top-level line by
     * its own product_id/campaign_id/quantity plus its children's
     * product_id:quantity pairs, sorted so option order never matters.
     */
    public function fingerprint(array $order): string
    {
        $items = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));

        $childrenByParent = [];
        foreach ($items as $item) {
            $parentId = (int) ($item['parent_id'] ?? 0);
            if ($parentId !== 0) {
                $childrenByParent[$parentId][] = $item;
            }
        }

        $parts = [];

        foreach ($items as $item) {
            if ((int) ($item['parent_id'] ?? 0) !== 0) {
                continue;   // a config, folded into its host below
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId === 0) {
                continue;   // discount / adjustment line
            }

            $configs = [];
            foreach ($childrenByParent[(int) ($item['id'] ?? 0)] ?? [] as $config) {
                $configs[] = ((int) ($config['product_id'] ?? 0)) . ':' . max(1, (int) ($config['quantity'] ?? 1));
            }
            sort($configs);

            $parts[] = implode('|', [
                $productId,
                (int) ($item['campaign_id'] ?? 0),
                max(1, (int) ($item['quantity'] ?? 1)),
                implode(',', $configs),
            ]);
        }

        if ($parts === []) {
            return '';
        }

        sort($parts);

        return md5(implode(';', $parts));
    }

    // ----------------------------------------------------------------- basket

    /**
     * The reorderable items, stripped of per-order bookkeeping. Prices are left
     * in only as a hint for the confirmation message — recalculate from the live
     * catalogue before writing the new order, or a stale discount rides along.
     *
     * Configs are folded in from the flat list by parent_id (see fingerprint()),
     * the same as CartService/MarvinTools see them — there is no nested
     * "config" array on a real order_items row.
     *
     * @return list<array<string,mixed>>
     */
    private function basket(array $order): array
    {
        $rows = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));

        $childrenByParent = [];
        foreach ($rows as $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);
            if ($parentId !== 0) {
                $childrenByParent[$parentId][] = $row;
            }
        }

        $items = [];

        foreach ($rows as $item) {
            if ((int) ($item['parent_id'] ?? 0) !== 0 || (int) ($item['product_id'] ?? 0) === 0) {
                continue;
            }

            $entry = [
                'product_id'       => (int) $item['product_id'],
                'item_description' => (string) ($item['item_description'] ?? ''),
                'quantity'         => max(1, (int) ($item['quantity'] ?? 1)),
                'campaign_id'      => $item['campaign_id'] ?? null,
            ];

            $configs = $childrenByParent[(int) ($item['id'] ?? 0)] ?? [];

            if ($configs !== []) {
                $entry['config'] = array_map(
                    static fn(array $c): array => [
                        'product_id'       => (int) ($c['product_id'] ?? 0),
                        'item_description' => (string) ($c['item_description'] ?? ''),
                        'quantity'         => max(1, (int) ($c['quantity'] ?? 1)),
                    ],
                    $configs,
                );
            }

            $items[] = $entry;
        }

        return $items;
    }

    /** Human summary for the message body — not the button. */
    private function summarise(array $items): string
    {
        $parts = [];

        foreach ($items as $item) {
            $line = $item['quantity'] > 1
                ? $item['quantity'] . '× ' . $item['item_description']
                : $item['item_description'];

            $options = [];
            foreach ((array) ($item['config'] ?? []) as $option) {
                if (is_string($option['item_description'] ?? null)) {
                    $options[] = $option['item_description'];
                }
            }

            if ($options !== []) {
                $line .= ' (' . implode(', ', array_slice($options, 0, 3)) . ')';
            }

            $parts[] = $line;
        }

        return implode(', ', $parts);
    }

    /**
     * A config is just another order_items row, so it already carries its own
     * unit_price and quantity in the flat list — summing every row, host and
     * config alike, is all a total needs.
     */
    private function total(array $order): float
    {
        $total = 0.0;

        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $total += (float) ($item['unit_price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
        }

        return round($total, 2);
    }

    // ---------------------------------------------------------------- history

    /**
     * This shopper's countable orders, newest first, capped at the window.
     *
     * @return list<array<string,mixed>>
     */
    private function historyFor(string $phone): array
    {
        try {
            //get the orders from db
            $orders = $this->repo->selectRows(
                $this->ordersTable(),
                ["phonenumber" => $phone]
            );

            if($orders === [] || $orders === null){
                return [];
            }

            //get the order items and attach the correct ones to each order
            $itemsByOrderId = [];

            foreach ($this->repo->selectRows($this->orderItemsTable()) as $item) {
                $itemsByOrderId[$item["order_id"]][] = $item;
            }

            foreach ($orders as &$order) {
                $order["items"] = $itemsByOrderId[$order["id"]] ?? [];
            }
            unset($order);

        } catch (ApiException $e) {
            $this->logger->error('usual: cannot load orders: ' . $e->getMessage());

            return [];
        }
  
        usort(
            $orders,
            static fn(array $a, array $b): int => strcmp(
                (string) ($b['ordered_time'] ?? ''),
                (string) ($a['ordered_time'] ?? '')
            )
        );

        return array_slice(array_values($orders), 0, self::WINDOW);
    }


    private function ordersTable() : string
    {
        if(!defined("shop_id") || !is_numeric(shop_id)){
            throw new ValidationException(["shop id" => "Shop Id must be a numeric value!"]);
        }

        return "orders_active_" . shop_id;
    }

    private function orderItemsTable() : string
    {
        if(!defined("shop_id") || !is_numeric(shop_id)){
            throw new ValidationException(["shop id" => "Shop Id must be a numeric value!"]);
        }

        return "order_items_active_" . shop_id;
    }

}