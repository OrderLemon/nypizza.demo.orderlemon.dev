<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Orders;

/**
 * Pure order-basket math: turning a raw order row (with its flat order_items
 * list attached under "items") into a stable reorder signature and a clean,
 * catalogue-agnostic basket.
 *
 * Deliberately stateless — no Repository, no shop_id, no notion of which
 * table the row came from. Anything holding an order array shaped like an
 * orders_active_ or orders_archive_ row, plus its matching order_items_
 * active_/order_items_archive_ rows, can use this directly, whether that's
 * UsualOrderService building "the usual"/"last order" or a caller (e.g.
 * MarvinTools) handed a live active-order row from OrderQueryService.
 */
final class OrderBasket
{
    private function __construct()
    {
        // static-only
    }

    /**
     * The reorder-link payload for a single, already-loaded order: hash,
     * human summary, reorderable items, and a price hint.
     *
     * @return array{hash: string, summary: string, items: list<array<string,mixed>>, total: float}|null
     *   null when the order has nothing but discount lines to reorder
     */
    public static function reorderPayload(array $order): ?array
    {
        $signature = self::fingerprint($order);

        if ($signature === '') {
            return null;
        }

        $items = self::basket($order);

        return [
            'hash'    => $signature,
            'summary' => self::summarise($items),
            'items'   => $items,
            'total'   => self::total($order),
        ];
    }

    /**
     * A stable signature for what was actually eaten. Same meal, same string —
     * regardless of price changes, item ordering, or option ordering.
     *
     * There is no nested "config" array any more: a config IS an order_items
     * row, distinguished from a normal line only by carrying a parent_id that
     * points at its host line's id. So this walks the flat list once to group
     * children under their parent, then fingerprints each top-level line by
     * its own product_id/quantity plus its children's product_id:quantity
     * pairs, sorted so option order never matters. Campaign splits are folded
     * back first, see withoutCampaigns().
     */
    public static function fingerprint(array $order): string
    {
        $items = self::withoutCampaigns($order);

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

    /**
     * The reorderable items, stripped of per-order bookkeeping. Prices are left
     * in only as a hint for the confirmation message — recalculate from the live
     * catalogue before writing the new order, or a stale discount rides along.
     *
     * Configs are folded in from the flat list by parent_id (see fingerprint()),
     * the same as CartService/MarvinTools see them — there is no nested
     * "config" array on a real order_items row. Each config is reshaped to
     * "group_id"/"option_id" (rather than "category_id"/"product_id"), the
     * same resolved-option naming MenuService::resolveOptions() and
     * CartService::normalizeIds() use, since a config here identifies a menu
     * option to re-resolve against the live catalogue, not a line to insert
     * as-is.
     *
     * @return list<array<string,mixed>>
     */
    public static function basket(array $order): array
    {
        $rows = self::withoutCampaigns($order);

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
                $entry['configs'] = array_map(
                    static fn(array $c): array => [
                        'group_id'         => (int) ($c['category_id'] ?? 0),
                        'option_id'        => (int) ($c['product_id'] ?? 0),
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

    /**
     * The order's rows with campaign bookkeeping undone. The cart splits a
     * product's units into a line per campaign plus an untagged line
     * (CartService::applyCampaigns()), so the same meal can be stored as
     * "3× cake" or as "2× cake (campaign 5) + 1× cake" depending on which
     * campaigns were live. Folds such lines back into one — same product,
     * same configs — with campaign_id cleared, so a reorder never carries a
     * stale campaign and "the usual" sees the same meal either way. The
     * cart re-applies whatever campaigns are live when the reorder lands.
     *
     * @return list<array<string,mixed>> flat rows, configs still pointing at their host by parent_id
     */
    private static function withoutCampaigns(array $order): array
    {
        $rows = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));

        $childrenByParent = [];
        foreach ($rows as $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);
            if ($parentId !== 0) {
                $childrenByParent[$parentId][] = $row;
            }
        }

        $hosts = [];      // family key => merged host row
        $children = [];   // family key => the first host's config rows

        foreach ($rows as $row) {
            if ((int) ($row['parent_id'] ?? 0) !== 0) {
                continue;
            }

            $configs = $childrenByParent[(int) ($row['id'] ?? 0)] ?? [];
            $configIds = array_map(static fn(array $c): int => (int) ($c['product_id'] ?? 0), $configs);
            sort($configIds);

            // Discount / adjustment lines (product_id 0) keep their own row;
            // callers already skip them.
            $key = (int) ($row['product_id'] ?? 0) === 0
                ? 'row:' . ($row['id'] ?? count($hosts))
                : (int) $row['product_id'] . '|' . implode(',', $configIds);

            if (isset($hosts[$key])) {
                $hosts[$key]['quantity'] = (int) $hosts[$key]['quantity'] + (int) ($row['quantity'] ?? 0);
                continue;
            }

            $hosts[$key] = [...$row, 'campaign_id' => null];
            $children[$key] = $configs;
        }

        $out = [];

        foreach ($hosts as $key => $host) {
            $out[] = $host;

            // A config's quantity tracks its host's, so it follows the merge.
            foreach ($children[$key] as $config) {
                $out[] = [...$config, 'quantity' => $host['quantity']];
            }
        }

        return $out;
    }

    /** Human summary for the message body — not the button. */
    public static function summarise(array $items): string
    {
        $parts = [];

        foreach ($items as $item) {
            $line = $item['quantity'] > 1
                ? $item['quantity'] . '× ' . $item['item_description']
                : $item['item_description'];

            $options = [];
            foreach ((array) ($item['configs'] ?? []) as $option) {
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
    public static function total(array $order): float
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
}
