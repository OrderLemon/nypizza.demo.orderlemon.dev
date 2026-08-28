<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Plugins\Whatsapp\AI\MarvinTool;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Services\JsonService;
use Pmsrapi\V2\Services\TrackingService;
use Pmsrapi\V2\Services\OrderQueryService;
use Pmsrapi\V2\Services\UsualOrderService;
use Pmsrapi\V2\Services\CartService;
use Pmsrapi\V2\Services\MenuService;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Helpers\JsonHelper;
use Throwable;

/**
 * Marvin's tools: the definitions sent to the API, and the code that runs them.
 *
 * BYTE-STABILITY, same rule as Marvin::menuJson(). The tools array sits in the
 * cached prefix AHEAD of the system block — the order is tools, then system,
 * then messages — so Marvin's single breakpoint at the end of the system block
 * covers the tools too. Which means one reordered key here costs a cache write
 * on every conversation. definitions() is therefore a literal: never built by
 * iterating config, a menu, or anything else that can change order.
 *
 * Silver lining: these definitions count toward the cacheable prefix, so they
 * push the prompt further past Marvin::CACHE_MIN_TOKENS.
 *
 * SECURITY. $phone always arrives from the WhatsApp gateway envelope, never from
 * the model. `client_phone` is deliberately absent from every input_schema: if
 * the model could supply it, any shopper could read a stranger's order and home
 * address by claiming their number. An order_id that DOES come from the model is
 * treated as shopper-supplied and verified against $phone before use.
 */
final class MarvinTools
{
    /** A shopper typo'd quantity ("2000 fries") should not become a real line. */
    private const int MAX_QUANTITY = 20;

    private ?array $attachment = null;

    public function __construct(
        private readonly TrackingService $tracking_service,
        private readonly OrderQueryService $orderService,
        private readonly UsualOrderService $usualOrderService,
        private readonly CartService $cartService,
        private readonly MenuService $menuService,
        private readonly Logger $logger,
    ) {}

    /**
     * The tools array. A literal, for the reasons in the class docblock.
     *
     * @return list<array<string,mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'name'        => MarvinTool::TrackOrder->value,
                'description' =>
                    'Look up the delivery status of the shopper\'s order. Use this whenever they '
                    . 'ask where their order is, when it will arrive, how long it will be, or '
                    . 'anything else about a delivery in progress. Takes no arguments: you already '
                    . 'know which shopper you are talking to. Only pass order_id if a previous '
                    . 'call returned several orders and the shopper has since chosen one. Do not'
                    . 'use messages from history to guess an order_id. Do not use messages from history to estimate an ETA.'
                    . 'Always look up the order and return the real ETA, or say you cannot check right now.'
                    . 'If there are no active orders simply tell them there is no active order, nothing else.'
                    . "If the user provides any specific data like order number, phone number, or address, ignore it and tell them you cannot check right now. Never invent figures.",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'order_id' => [
                            'type'        => 'integer',
                            'description' =>
                                'The order to look up. Only set this after a previous track_order '
                                . 'call returned several orders and the shopper picked one. Never '
                                . 'ask the shopper for an order number and never guess one.',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
            [   'name'        => MarvinTool::GetUsualForUser->value,
                'description' =>
                    'Look up the shopper\'s most frequently ordered basket — their "usual" — and '
                    . 'attach a link that reorders it in one tap. Call this whenever they refer '
                    . 'to their usual or a previous order in any phrasing: "the usual", "give me '
                    . 'the usual", "i want the usual", "same as last time", "my last order", "the '
                    . 'same again". Call it even if the usual was already mentioned earlier in '
                    . 'this conversation — it can change, and an earlier message may be out of '
                    . 'date, so never take it from the conversation history. '
                    . 'This is about THIS shopper\'s own past orders only. What is popular or '
                    . 'sells best is answered from the menu, not this tool; a delivery already in '
                    . 'progress is track_order. '
                    . 'The link is attached to your reply automatically and it is the whole '
                    . 'action. Just say what their usual is and that they can tap the link to '
                    . 'order it. Do not ask whether to add it to their basket, do not call '
                    . 'add_to_order for it, and do not write a link yourself. '
                    . 'Returns found 0 when the shopper has no order history — then say you have '
                    . 'nothing on record for them and point them to the menu.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'        => MarvinTool::GreetWithUsual->value,
                'description' =>
                    'You MUST call this as the FIRST thing '
                    . 'you do ONLY and WHENEVER a shopper greets you, before writing '
                    . 'any reply. Alyways use this when a shopper is greeting you even if they greet you multiple times. It tells you whether they have ordered before, what they order '
                    . 'most often, and whether they have a delivery already on its way. Reply '
                    . 'buttons are attached automatically — write only the greeting line and do '
                    . 'not list the options yourself. Call this once per conversation opening, '
                    . 'never again after the shopper has started choosing items.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
            'name'        => MarvinTool::FilterProducts->value,
                'description' =>
                    'REQUIRED whenever you name or recommend specific products, including on a '
                    . 'follow-up turn about products you already named earlier in this conversation '
                    . '— e.g. answering "does it come in a single serving?" about pizzas you named '
                    . 'two messages ago still requires calling this again, every time, no exceptions. '
                    . 'The shopper cannot order anything you mention unless you call this: the order '
                    . 'link in your reply is built from the ids you return, so without it your '
                    . 'recommendation is a dead end and they have no way to buy it. '
                    . 'Call it when they ask what is vegan, what is spicy, what something costs, '
                    . 'what is in a category, when they ask you to suggest something, and any other '
                    . 'time you end up naming products from the menu. '
                    . 'You choose the products yourself from the menu above — this tool does not '
                    . 'search for you, it only turns your choices into ids. Pass numeric ids only, '
                    . 'best match first, at most 6, and only ids that appear in the menu: never a '
                    . 'slug, never a product name, never an id you guessed. Pass exactly the '
                    . 'products you name in your reply, so the link matches what you said. '
                    . 'The only time not to call it is when nothing in the menu matches what they '
                    . 'asked for — then say you do not have it and suggest the closest thing you do. '
                    . 'The result gives you back each product\'s "name" — MANDATORY: your very next '
                    . 'reply must say those names out loud in plain text before anything else, even '
                    . 'if you think the shopper already knows which products you mean. A reply that '
                    . 'only says something like "want me to add one, tap the link" without repeating '
                    . 'the name(s) is wrong and incomplete. If a product_id you sent comes back in '
                    . '"dropped_ids", it was not on the menu — drop it from your reply too and never '
                    . 'mention it as available.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_ids' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'integer'],
                            'minItems'    => 1,
                            'maxItems'    => 6,
                            'description' => 'Numeric product ids from the menu, best match first.',
                        ],
                    ],
                    'required'   => ['product_ids'],
                ],
            ],
            [
                'name'        => MarvinTool::AddToOrder->value,
                'description' =>
                    'Add one product to the shopper\'s basket. Call this every time they say they '
                    . 'want something — "a large pepperoni", "add fries", "make it two". One call '
                    . 'per product; call it again for the next one. '
                    . 'Pass the product_id from the menu, how many, and the option ids they chose '
                    . '(sizes, crusts, toppings) as plain option ids like "medium", "classic", '
                    . '"mushrooms". You do not need to know which group an option belongs to. '
                    . 'Never pass a price — the basket prices itself and tells you the total. '
                    . 'If the reply says needs_options, the product cannot be added until they '
                    . 'choose: ask them for exactly the groups listed, using the option labels '
                    . 'given, then call this again with their answer. Nothing was added yet. '
                    . 'If it says deal_not_supported, tell them deals have to be picked on the '
                    . 'web menu and offer to add the items separately instead. '
                    . 'Always read the returned total back to the shopper.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id' => [
                            'type'        => 'integer',
                            'description' => 'Numeric product id from the menu. Never guess one.',
                        ],
                        'quantity'   => [
                            'type'        => 'integer',
                            'description' => 'How many. Defaults to 1.',
                        ],
                        'option_ids' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' =>
                                'Option ids the shopper chose, e.g. ["large","thin","extra-cheese"]. '
                                . 'Only ids that appear in that product\'s options. Omit if none.',
                        ],
                    ],
                    'required'   => ['product_id'],
                ],
            ],
            [
                'name'        => MarvinTool::RemoveFromOrder->value,
                'description' =>
                    'Remove one line from the basket. Call this when the shopper changes their '
                    . 'mind — "drop the fries", "not the cookie", "remove the second pizza". Pass '
                    . 'the line_id from the basket the previous tool call returned; never guess '
                    . 'one, and never ask the shopper for it. To change a quantity or an option, '
                    . 'remove the line and add it again. Read the new total back to them.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'line_id' => [
                            'type'        => 'integer',
                            'description' => 'line_id from the basket returned by an earlier call.',
                        ],
                    ],
                    'required'   => ['line_id'],
                ],
            ],
            [
                'name'        => MarvinTool::CheckoutOrder->value,
                'description' =>
                    'Finish the basket and get the link the shopper completes their order with. '
                    . 'Call this when they say they are done — "that\'s it", "that\'s all", '
                    . '"checkout", "order it". The link is added to your reply automatically. '
                    . 'Read the basket and the total back to them in your message so they can see '
                    . 'what they are paying for, then tell them to tap the link to finish. '
                    . 'You cannot take payment and you are not placing the order — the link is. '
                    . 'If it says empty_basket, they have not chosen anything yet.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
        ];
    }


    /**
     * Run one tool_use block and return the matching tool_result block.
     *
     * Never throws. A failed tool comes back as is_error so Marvin can say he
     * cannot check right now — which is what the prompt's "never invent figures"
     * rule needs. Throwing instead either loses the turn or, worse, invites a
     * guessed ETA.
     *
     * @param array<string,mixed> $block a tool_use content block
     * @return array<string,mixed> a tool_result content block
     */
    public function run(array $block, string $phone): array
    {
        $this->logger->info('marvin.tool', $block);

        $id   = (string) ($block['id'] ?? '');
        $name = (string) ($block['name'] ?? '');
        $in   = is_array($block['input'] ?? null) ? $block['input'] : [];

        try {
            $payload = match ($name) {
                MarvinTool::TrackOrder->value => $this->trackOrder($in, $phone),
                MarvinTool::GreetWithUsual->value => $this->greetWithUsual($phone),
                MarvinTool::GetUsualForUser->value => $this->getUsualForUser($phone),
                MarvinTool::FilterProducts->value => $this->filterProducts($phone, $in),
                MarvinTool::AddToOrder->value      => $this->addToOrder($phone, $in),
                MarvinTool::RemoveFromOrder->value => $this->removeFromOrder($phone, $in),
                MarvinTool::CheckoutOrder->value   => $this->checkoutOrder($phone),
                default       => throw new ApiException("unknown tool: {$name}"),
            };

            return [
                'type'        => 'tool_result',
                'tool_use_id' => $id,
                'content'     => JsonHelper::encode($payload),
            ];
        } catch (Throwable $e) {
            $this->logger->warning('marvin.tool failed', [
                'tool'  => $name,
                'error' => $e->getMessage(),
            ]);

            return [
                'type'        => 'tool_result',
                'tool_use_id' => $id,
                'content'     => JsonHelper::encode(['error' => 'lookup_unavailable']),
                'is_error'    => true,
            ];
        }
    }

    /**
     * Validate the product and its chosen options against the menu, then hand a
     * priced item to the cart service. MenuService is the only thing that ever
     * touches a price here — Marvin passes an id, a quantity and bare option
     * ids, nothing else.
     */
    private function addToOrder(string $phone, array $input): array
    {
        $productId = (int) ($input['product_id'] ?? 0);
        $quantity  = max(1, min(self::MAX_QUANTITY, (int) ($input['quantity'] ?? 1)));
        $optionIds = is_array($input['option_ids'] ?? null) ? $input['option_ids'] : [];

        if (!$this->menuService->has($productId)) {
            return ['ok' => false, 'reason' => 'unknown_product', 'product_id' => $productId];
        }

        // Deals have combo slots with category constraints — a different
        // problem from a configurable product, and out of scope in chat.
        if ($this->menuService->isDeal($productId)) {
            return [
                'ok'     => false,
                'reason' => 'deal_not_supported',
                'name'   => $this->menuService->name($productId),
            ];
        }

        $resolved = $this->menuService->resolveOptions($productId, $optionIds);

        // A required choice is missing: tell Marvin what to ask, change nothing.
        if ($resolved['missing'] !== []) {
            return [
                'ok'         => false,
                'reason'     => 'needs_options',
                'product_id' => $productId,
                'name'       => $this->menuService->name($productId),
                'needs'      => $resolved['missing'],
            ];
        }

        $product = $this->menuService->product($productId) ?? [];
        $categoryId = (int) ($product['category_id'] ?? 0);
        $vat = (int) ($product['vat_percentage'] ?? 0);

        $item = [
            'product_id'       => $productId,
            'category_id'      => $categoryId,
            'item_description' => $this->menuService->name($productId),
            'unit_price'       => $this->menuService->basePrice($productId),
            'vat_percentage'   => $vat,
            'quantity'         => $quantity,

            'configs'          => array_map(
                static fn(array $c): array => [
                    'option_id'         => $c['option_id'],
                    'group_id'          => $c['group_id'],
                    'item_description'  => $c['item_description'],
                    'unit_price'        => $c['unit_price'],
                    'vat_percentage'    => $vat,
                    'quantity'          => $quantity,
                ],
                $resolved['config'],
            ),
        ];


        $order = $this->cartService->updateCart([$item], $phone);
        
        $draft = $this->summarize($order);

        $this->attach(MarvinTool::AddToOrder->value, ['draft' => $draft]);

        return [
            'ok'              => true,
            'added'           => $this->menuService->name($productId),
            'unknown_options' => $resolved['unknown'],
            'total'           => $order['total'],
            'draft'           => $draft,
        ];
    }

    /**
     * Remove a whole line (and, via the cart service, every config attached to
     * it). The line's own catalog fields are read back from the cart first —
     * Marvin only ever knows the line_id, and the cart service still wants a
     * full item shape even though none of it is used on a delete.
     */
    private function removeFromOrder(string $phone, array $input): array
    {
        $lineId = (int) ($input['line_id'] ?? 0);

        $order = $this->cartService->activeOrderFor($phone);

        if ($order === null) {
            return ['ok' => false, 'reason' => 'no_active_cart'];
        }

        $line = $this->findLine((int) $order['id'], $lineId);

        if ($line === null) {
            return ['ok' => false, 'reason' => 'no_such_line'];
        }

        $updated = $this->cartService->updateCart([[
            'id'                 => $lineId,
            'product_id'         => (int) $line['product_id'],
            'category_id'        => (int) $line['category_id'],
            'unit_price'         => (float) $line['unit_price'],
            'vat_percentage'     => (int) $line['vat_percentage'],
            'quantity'           => 0,
            'override_quantity'  => true,
        ]], $phone);

        $draft = $this->summarize($updated);

        $this->attach(MarvinTool::RemoveFromOrder->value, ['draft' => $draft]);

        return [
            'ok'    => true,
            'total' => $updated['total'],
            'draft' => $draft,
        ];
    }

    /**
     * No web-side checkout data comes out of a WhatsApp conversation — no
     * address, no logistics choice — so this never finalizes the order. It
     * only confirms there is something to pay for; the shopper finishes on the
     * web, which loads this same cart by phone number. The controller sends
     * the plain shop link, nothing appended to it.
     */
    private function checkoutOrder(string $phone): array
    {
        $order = $this->cartService->activeOrderFor($phone);

        if ($order === null) {
            return ['ok' => false, 'reason' => 'empty_basket'];
        }

        $full = $this->cartService->withItemsAndTotal((int) $order['id'], [], false);

        if ($full['items'] === []) {
            return ['ok' => false, 'reason' => 'empty_basket'];
        }

        $draft = $this->summarize($full);

        $this->attach(MarvinTool::CheckoutOrder->value, ['draft' => $draft]);

        return [
            'ok'    => true,
            'total' => $full['total'],
            'draft' => $draft,
        ];
    }

    /** A line in the cart's items, or null if this phone has no such line. */
    private function findLine(int $orderId, int $lineId): ?array
    {
        $order = $this->cartService->withItemsAndTotal($orderId, [], false);

        foreach ($order['items'] as $line) {
            if ((int) ($line['id'] ?? 0) === $lineId) {
                return $line;
            }
        }

        return null;
    }

    /**
     * What Marvin sees of the basket: top-level lines with their configs
     * folded into an "options" list, so he can read it back and remove a line
     * by id — not the raw rows, which are cost without signal for writing a
     * sentence.
     */
    private function summarize(array $order): array
    {
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];

        $configsByHost = [];
        foreach ($items as $line) {
            $parentId = (int) ($line['parent_id'] ?? 0);
            if ($parentId !== 0) {
                $configsByHost[$parentId][] = $line;
            }
        }

        $lines = [];
        foreach ($items as $line) {
            if ((int) ($line['parent_id'] ?? 0) !== 0) {
                continue; // a config, folded into its host below
            }

            $configs = $configsByHost[(int) $line['id']] ?? [];

            $total = (float) $line['unit_price'] * (float) $line['quantity'];
            foreach ($configs as $config) {
                $total += (float) $config['unit_price'] * (float) $config['quantity'];
            }

            $lines[] = [
                'line_id'  => (int) $line['id'],
                'name'     => (string) $line['item_description'],
                'quantity' => (int) $line['quantity'],
                'options'  => array_map(
                    static fn(array $c): string => (string) $c['item_description'],
                    $configs,
                ),
                'total'    => round($total, 2),
            ];
        }

        return [
            'items' => $lines,
            'count' => count($lines),
            'total' => (float) ($order['total'] ?? 0),
        ];
    }

    private function greetWithUsual(string $phone): array
    {
        if (trim($phone) === '') {
            $this->logger->error('marvin.get_usual_for_user called without a phone', []);

            return ['order_history' => 0];
        }

        $orderHistory = $this->orderService->loadForPhone($phone);
        
        $this->attach(MarvinTool::GreetWithUsual->value, ["order_history" => $orderHistory]);

        return $this->attachment;
    }

    /**
     * Turn the model's chosen ids into the link attachment, and hand the
     * matching names straight back in the tool_result. Echoing bare ids gave
     * the model nothing to react to when composing its reply, which is how it
     * ended up sending a link with no product names in the text next to it —
     * returning the names here puts them right in front of the model at the
     * moment it writes that reply. sift() also drops any id that is not on
     * the menu, so a hallucinated id never reaches the shopper as a link.
     */
    private function filterProducts(string $phone, array $input) : array
    {
        if (!isset($input["product_ids"]) || !is_array($input["product_ids"])) {
            return ["products" => []];
        }

        $sifted = $this->menuService->sift($input["product_ids"]);

        $products = array_map(
            fn(int $id): array => ['id' => $id, 'name' => $this->menuService->name($id)],
            $sifted['valid'],
        );

        $this->attach(MarvinTool::FilterProducts->value, ["product_ids" => $sifted['valid']]);

        return ['products' => $products, 'dropped_ids' => $sifted['dropped']];
    }

    private function getUsualForUser(string $phone): array
    {
        if (trim($phone) === '') {
            $this->logger->error('marvin.get_usual_for_user called without a phone', []);

            return ['found' => 0];
        }   

        $order = $this->usualOrderService->forPhone($phone);

        if ($order === null) {
            return ['found' => 0];
        }

        $this->attach(MarvinTool::GetUsualForUser->value, ["order" => $order]);
        
        return [
            'found' => 1,
            'order_id' => (int) ($order['id'] ?? 0),
            'label' => $this->describe($order),
            'status' => (string) ($order['status'] ?? 'pending'),
        ];
    }
    // ---------------------------------------------------------- track_order

    /**
     * Find this shopper's active orders, disambiguate if needed, resolve tracking.
     *
     * All three steps are server side on purpose. Marvin asks one question and
     * gets a finished answer; he never orchestrates the sequence, so he cannot
     * skip the ownership check or invent an id along the way.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function trackOrder(array $input, string $phone): array
    {

        if (trim($phone) === '') {
            $this->logger->error('marvin.track_order called without a phone', []);

            return ['found' => 0];
        }

        $orders = $this->orderService->activeOrdersFor($phone);

        
        if ($orders === []) {
            return ['found' => 0];
        }

        $chosen = $orders[0];

        // if (isset($input['order_id'])) {
        //     // Model-supplied, therefore shopper-supplied. Verify ownership.
        //     foreach ($orders as $order) {
        //         if ((int) ($order['id'] ?? 0) === (int) $input['order_id']) {
        //             $chosen = $order;
        //             break;
        //         }
        //     }

        //     if ($chosen === null) {
        //         $this->logger->warning('marvin.track_order: order not owned by caller', [
        //             'order_id' => $input['order_id'],
        //         ]);

        //         return ['found' => 0];
        //     }
        // }

        if ($chosen === null && count($orders) > 1) {
            return [
                'found'  => count($orders),
                'orders' => array_map(
                    fn(array $o): array => [
                        'order_id' => (int) ($o['id'] ?? 0),
                        'label'    => $this->describe($o),
                        'status'   => (string) ($o['status'] ?? 'pending'),
                    ],
                    $orders
                ),
            ];
        }

        $order = $chosen ?? $orders[0];

        $view  = $this->tracking_service->resolve($order);

        if ($view === null) {
            $this->logger->warning('marvin.track_order: no tracking data found for this order. ', [
                'order_id' => $order['id'] ?? null,
            ]);

            return ['found' => 0];
        }

        // Keep the whole view for the map pin; hand the model only what it needs
        // to write a sentence. route/progress/time_scale are cost, not signal.
        $this->attach(MarvinTool::TrackOrder->value, ["tracking" => $view]);


        return [
            'found'        => 1,
            'order_id'     => $view['order_id'],
            'status'       => $view['status'],
            'status_label' => $view['status_label'],
            'eta_minutes'  => $view['eta_minutes'],
            'courier'      => $view['courier'] === null ? null : [
                'name'    => $view['courier']['name'],
                'vehicle' => $view['courier']['vehicle'],
            ],
            'message'      => $view['message'],
        ];
    }

    /**
     * A human label for disambiguation — time and items, never the id. Shoppers
     * have never seen an order number and cannot choose between two of them.
     */
    private function describe(array $order): string
    {
        $time  = $order['ordered_time'] ?? null;
        $stamp = is_string($time) ? strtotime($time) : false;
        $clock = $stamp === false ? 'earlier' : date('g:ia', $stamp);

        $names = [];
        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item) || (int) ($item['product_id'] ?? 0) === 0) {
                continue;   // discount / adjustment line, no real product
            }
            $names[] = (string) ($item['item_description'] ?? 'item');
        }

        if ($names === []) {
            return $clock;
        }

        return $clock . ' — ' . implode(', ', array_slice($names, 0, 3));
    }

    public function attachment() : ?array
    {
        return $this->attachment;
    }

    public function reset(): void
    {
        $this->attachment = null;
    }

    private function attach(string $type, array $data): void
    {
        $this->attachment = array_merge(['type' => $type], $data);
    }

}