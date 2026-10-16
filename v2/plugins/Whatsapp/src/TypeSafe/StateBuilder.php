<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Services\ChatTranscriptService;
use Pmsrapi\V2\Services\OrderQueryService;

/**
 * The state TypeSafe gets with every shopper message: the message itself, the
 * last turns of the conversation, and the facts code already knows (basket,
 * an order on its way, a checkout link already sent).
 *
 *   {
 *     "latest_message": "Doe deze maar",
 *     "history": [{"from": "shopper", "text": "..."}, {"from": "Marvin", "text": "..."}],
 *     "basket": [{"item": "Margherita Pizza", "quantity": 1, "options": ["Medium (30cm)"]}],
 *     "active_order": {"on_its_way": true, "count": 1},
 *     "checkout_link_sent": false
 *   }
 *
 * Read-only by design: asking TypeSafe must never change data. That is why the
 * basket is read from the cart tables here instead of through
 * CartService::withItemsAndTotal() (which writes the total back) or
 * openCart() (which creates a cart).
 *
 * The menu is not part of the state; MenuDigest puts it in the menu_item
 * question, so it is sent once.
 */
final class StateBuilder
{
    /** Same values as CartService's private constants and table names. */
    private const int CART_STATUS_ID = 1;
    private const int CHECKED_OUT_STATUS_ID = 2;

    /** System notes and timers in the transcript that are not part of the conversation. */
    private const array NOTE_TOOLS = ['idle_reminder', 'checkout_completed', 'order_lost'];

    /** Tools that change the basket; a checkout link sent before one of these is stale. */
    private const array BASKET_TOOLS = ['add_to_order', 'remove_from_order'];

    private const int MESSAGE_MAX = 1000;
    private const int TURN_HEAD = 150;
    private const int TURN_TAIL = 250;

    private readonly int $historyTurns;

    public function __construct(
        private readonly ChatTranscriptService $transcripts,
        private readonly Repository $repo,
        private readonly OrderQueryService $orders,
        Config $config,
    ) {
        $this->historyTurns = max(0, (int) $config->secret('typesafe.history_turns', 4));
    }

    /**
     * @return array{
     *   latest_message: string,
     *   history: list<array{from: string, text: string}>,
     *   basket: list<array{item: string, quantity: int, options: list<string>}>,
     *   active_order: array{on_its_way: bool, count: int},
     *   checkout_link_sent: bool
     * }
     */
    public function build(string $phone, string $latestMessage, ?int $historyTurns = null): array
    {
        $messages = $this->transcriptMessages($phone);
        $latestMessage = trim($latestMessage);
        $turns = max(0, $historyTurns ?? $this->historyTurns);

        $activeOrders = $this->orders->activeOrdersFor($phone, ['status_id' => self::CHECKED_OUT_STATUS_ID]);

        return [
            'latest_message'     => $this->cut($latestMessage, self::MESSAGE_MAX),
            'history'            => $this->history($messages, $latestMessage, $turns),
            'basket'             => $this->basket($phone),
            'active_order'       => ['on_its_way' => $activeOrders !== [], 'count' => count($activeOrders)],
            'checkout_link_sent' => $this->checkoutLinkSent($messages),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function transcriptMessages(string $phone): array
    {
        $messages = $this->transcripts->load($phone)['data']['messages'] ?? [];

        return is_array($messages) ? array_values(array_filter($messages, 'is_array')) : [];
    }

    /**
     * The last turns before the latest message. The controller appends the
     * inbound message to the transcript before replying, so a trailing copy of
     * it is dropped here; it is already in latest_message.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array{from: string, text: string}>
     */
    private function history(array $messages, string $latestMessage, int $turns): array
    {
        $conversation = array_values(array_filter($messages, $this->isConversation(...)));

        $last = end($conversation);
        if ($last !== false && ($last['direction'] ?? '') === 'in' && trim((string) $last['message']) === $latestMessage) {
            array_pop($conversation);
        }

        if ($turns === 0) {
            return [];
        }

        return array_map(
            fn(array $m): array => [
                'from' => ($m['direction'] ?? '') === 'in' ? 'shopper' : 'Marvin',
                'text' => $this->shorten(trim((string) $m['message'])),
            ],
            array_slice($conversation, -$turns),
        );
    }

    /** @param array<string, mixed> $message */
    private function isConversation(array $message): bool
    {
        $text = trim((string) ($message['message'] ?? ''));
        if ($text === '' || in_array($message['source_tool'] ?? null, self::NOTE_TOOLS, true)) {
            return false;
        }

        // Notes written for Marvin, never shown to the shopper.
        return !str_starts_with($text, '(SYSTEM NOTE') && !str_starts_with($text, '[System note');
    }

    /** @param list<array<string, mixed>> $messages */
    private function checkoutLinkSent(array $messages): bool
    {
        $sent = false;
        foreach ($messages as $m) {
            $tool = $m['source_tool'] ?? null;
            if ($tool === 'checkout_order') {
                $sent = true;
            } elseif ($tool === 'checkout_completed' || in_array($tool, self::BASKET_TOOLS, true)) {
                $sent = false;
            }
        }

        return $sent;
    }

    /** @return list<array{item: string, quantity: int, options: list<string>}> */
    private function basket(string $phone): array
    {
        $shopId = $this->shopId();
        $cart = $this->repo->selectRows(
            "orders_active_{$shopId}",
            ['phonenumber' => $phone, 'status_id' => self::CART_STATUS_ID],
            orderBy: 'id:desc',
            limit: 1,
        )[0] ?? null;

        if ($cart === null) {
            return [];
        }

        // Ordered by id, so a basket always reads the same (items and options in the order they were added).
        $lines = $this->repo->selectRows("order_items_active_{$shopId}", ['order_id' => (int) $cart['id']], orderBy: 'id:asc');

        // Config lines (size, base, toppings) point at their host with parent_id.
        $options = [];
        foreach ($lines as $line) {
            $parent = (int) ($line['parent_id'] ?? 0);
            if ($parent !== 0) {
                $options[$parent][] = (string) $line['item_description'];
            }
        }

        $basket = [];
        foreach ($lines as $line) {
            if ((int) ($line['parent_id'] ?? 0) !== 0) {
                continue;
            }
            $basket[] = [
                'item'     => (string) $line['item_description'],
                'quantity' => (int) $line['quantity'],
                'options'  => $options[(int) $line['id']] ?? [],
            ];
        }

        return $basket;
    }

    /**
     * Long turns keep their start and their end: Marvin's product lists sit in
     * the middle, and his question ("which size?") is at the end.
     */
    private function shorten(string $text): string
    {
        if (mb_strlen($text) <= self::TURN_HEAD + self::TURN_TAIL) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::TURN_HEAD)) . ' … ' . ltrim(mb_substr($text, -self::TURN_TAIL));
    }

    private function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    private function shopId(): int
    {
        if (!defined('shop_id') || !is_numeric(shop_id)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) shop_id;
    }
}
