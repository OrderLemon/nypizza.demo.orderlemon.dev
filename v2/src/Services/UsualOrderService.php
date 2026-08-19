<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;
use Pmsrapi\V2\Helpers\CustomerHelper;
use Pmsrapi\V2\Exception\ApiException;

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
final class UsualOrderService extends JsonService
{
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
     */
    public function fingerprint(array $order): string
    {
        $parts = [];

        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId === 0) {
                continue;   // discount / adjustment line
            }

            $campaingId = (int)($item['campaign_id'] ?? null);

            //the options part will be removed as the options will be products themselvs
            $options = [];
            foreach ((array) ($item['config'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $options[] = ($option['group_id'] ?? '') . ':' . ($option['option_id'] ?? '');
            }
            sort($options);

            $parts[] = implode('|', [
                $productId,
                $campaingId,
                max(1, (int) ($item['quantity'] ?? 1)),
                implode(',', $options),
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
     * @return list<array<string,mixed>>
     */
    private function basket(array $order): array
    {
        $items = [];

        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item) || (int) ($item['product_id'] ?? 0) === 0) {
                continue;
            }

            $entry = [
                'product_id'       => (int) $item['product_id'],
                'item_description' => (string) ($item['item_description'] ?? ''),
                'quantity'         => max(1, (int) ($item['quantity'] ?? 1)),
                'campaign_id'      => $item["campaign_id"]
            ];

            if (isset($item['config']) && is_array($item['config'])) {
                $entry['config'] = array_values(array_map(
                    static fn(array $o): array => [
                        'group_id'         => $o['group_id'] ?? null,
                        'option_id'        => $o['option_id'] ?? null,
                        'item_description' => $o['item_description'] ?? null,
                        'quantity'         => max(1, (int) ($o['quantity'] ?? 1)),
                    ],
                    array_filter($item['config'], 'is_array')
                ));
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

    private function total(array $order): float
    {
        $total = 0.0;

        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qty   = max(1, (int) ($item['quantity'] ?? 1));
            $total += (float) ($item['unit_price'] ?? 0) * $qty;

            foreach ((array) ($item['config'] ?? []) as $option) {
                if (is_array($option)) {
                    $total += (float) ($option['unit_price'] ?? 0)
                        * max(1, (int) ($option['quantity'] ?? 1));
                }
            }
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
        $apiUrl = $this->config->secret("orderlemon_api.url");
        $token = $this->config->secret("orderlemon_api.token");

        try {
            //get the orders from db
            $orders = [];

            if($orders === []){
                return [];
            }

            //get their ids
            $orderIds = array_column($orders,"id");

            //get the order items
            $orderItems = [];
            //attach correct item to each order
            $itemsByOrderId = [];

            foreach ($orderItems as $item) {
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

}