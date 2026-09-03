<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;
use Pmsrapi\V2\Orders\OrderBasket;
use Pmsrapi\V2\Support\Logger;

/**
 * "The usual" — the basket a returning shopper orders most often.
 *
 * The fingerprint/basket/summary/total math itself lives in the stateless
 * {@see OrderBasket}, and fetching orders (active vs. archive) is
 * {@see OrderQueryService}'s job — this class is only the ranking policy on
 * top: which orders count, over what window, and which one wins.
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
        private readonly OrderQueryService $orderQueryService,
        private readonly Logger $logger,
    ){}

    /** How many recent archived orders "the usual" ranks over. */
    private const WINDOW = 5;

    /** Minimum repeats before a basket earns the phrase "the usual". */
    private const USUAL_THRESHOLD = 2;

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
            $signature = OrderBasket::fingerprint($order);

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
        $items     = OrderBasket::basket($winner['order']);

        $isUsual = $winner['count'] >= self::USUAL_THRESHOLD;

        return [
            'confidence'      => $isUsual ? 'usual' : 'last',
            'times_ordered'   => $winner['count'],
            'considered'      => count($orders),
            'source_order_id' => (int) ($winner['order']['id'] ?? 0),
            'hash'            => $signature,
            'summary'         => OrderBasket::summarise($items),
            // WhatsApp reply button titles are capped at 20 characters, so the
            // basket contents go in the body text, never the button.
            'button_title'    => $isUsual ? 'Order the usual' : 'Same as last time',
            'items'           => $items,
            'total'           => OrderBasket::total($winner['order']),
        ];
    }

    /**
     * Rebuild the basket for a signature, so a tapped button can be turned into
     * a new order without trusting anything the client sent back.
     *
     * Unlike forPhone(), this is a point lookup, not a ranking — so it
     * deliberately does NOT go through the WINDOW-capped, status-filtered
     * historyFor(): a hash handed out on an older order (or one outside the
     * "usual" recency window) must still resolve. Checks the active table
     * first (an in-progress order the shopper is re-tapping), then archive.
     *
     * @return list<array<string,mixed>>|null
     */
    public function basketFor(string $phone, string $signature): ?array
    {
        foreach ([false, true] as $archived) {
            foreach ($this->orderQueryService->ordersFor($phone, $archived) as $order) {
                if (OrderBasket::fingerprint($order) === $signature) {
                    $this->logger->debug("basket for $phone", $order);
                    return OrderBasket::basket($order);
                }
            }
        }

        return null;
    }

    /**
     * The reorder-link payload for a single, already-loaded order — the same
     * fingerprint/basket algorithm forPhone() uses, exposed so a caller
     * holding an order row from any table that shares the order_items shape
     * (orders_active_* included) can build a "tap to reorder" link from it.
     *
     * Thin wrapper around {@see OrderBasket::reorderPayload()}: kept here as
     * the stable, injectable entry point plugins already call.
     *
     * @return array{hash: string, summary: string, items: list<array<string,mixed>>, total: float}|null
     *   null when the order has nothing but discount lines to reorder
     */
    public function reorderPayload(array $order): ?array
    {
        return OrderBasket::reorderPayload($order);
    }

    // ---------------------------------------------------------------- history

    /**
     * This shopper's countable ARCHIVED orders, newest first, capped at the
     * window — the pool forPhone() ranks over. Statuses that were never
     * actually fulfilled don't count.
     *
     * @return list<array<string,mixed>>
     */
    private function historyFor(string $phone): array
    {
        $orders = array_values(array_filter(
            $this->orderQueryService->ordersFor($phone, archived: true),
            static fn(array $order): bool => !in_array(
                (int) ($order['status_id'] ?? 0),
                OrderQueryService::UNCOUNTED_STATUS_IDS,
                true,
            ),
        ));

        // ordersFor() already sorts newest-first (ordered_time:desc).
        return array_slice($orders, 0, self::WINDOW);
    }
}
