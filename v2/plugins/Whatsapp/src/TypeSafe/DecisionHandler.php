<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Plugins\Whatsapp\AI\Marvin;
use Plugins\Whatsapp\AI\MarvinTool;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Services\JsonService;
use Pmsrapi\V2\Services\ShopService;
use Pmsrapi\V2\Support\Logger;

/**
 * Turns TypeSafe's Decision into the reply, in the shape the controller sends
 * (buttons, links and tracking exactly as before). Always returns a reply;
 * Marvin never decides or runs tools on his own here:
 *
 *   acted        code acted on the decision, Marvin worded the result (writer)
 *   safe         code could not act (TypeSafe unsure, unclear, nothing to do,
 *                or a case not handled yet): Marvin, without tools or menu,
 *                asks one clarifying question, replies kindly, or says he
 *                cannot help with this here and gives the team contacts
 *   unavailable  TypeSafe failed: the fixed, translated "can't help right now"
 *   no_text      a message without text (photo, sticker)
 *
 * Handled:
 *   tool:<name>  tools that need no input: Marvin replies from their result
 *   language     detect_language with the requested language
 *   faq          by the FAQ entry's type:
 *                  answer       the approved text, sent as is when it exists in
 *                               the shopper's language (no Claude call)
 *                  facts        Marvin answers only from these facts
 *                  answer_from  Marvin answers only from shop data (stores)
 *                  do           the tool runs, Marvin replies from its result
 *   handoff      Marvin, with the branch instructions, the handoff text and the
 *                support contacts
 *   order        add_to_order with the menu item, options and quantity TypeSafe
 *                read (OrderSlots); several items ("a salad and 2 nachos", or
 *                "yes" to a list): add_to_order per item with its own quantity
 *                (DecisionService::orderedItems()); still unclear: safe reply
 *   tool:change_logistics
 *                pickup or delivery "as soon as possible"; a later time: Marvin
 *   tool:filter_products
 *                one clear menu item; "something with chicken": the matching
 *                items (DecisionService::matchingProducts()); "show me the
 *                menu": the categories, with the menu button
 *   tool:remove_from_order
 *                every basket line TypeSafe says is to be taken out, whole or
 *                partly; none clear: safe reply asking which
 */
final class DecisionHandler
{
    /** Tools TypeSafe can run without any input from the message. */
    private const array NO_INPUT_TOOLS = [
        MarvinTool::GreetWithUsual,
        MarvinTool::TrackOrder,
        MarvinTool::GetCart,
        MarvinTool::CheckoutOrder,
        MarvinTool::GetUsualForUser,
        MarvinTool::GetLastOrder,
        MarvinTool::GetOrderingHours,
        MarvinTool::ShopBackground,
    ];

    /** Store fields that are useful to answer from; the rest (ids, zip lists) is left out. */
    private const array STORE_FIELDS = [
        'name', 'city', 'area', 'street', 'zip', 'country', 'phone', 'email',
        'openingHours', 'pickup', 'delivery', 'seating',
    ];

    /** Facts handed to Marvin are cut at this length; enough for a branch list. */
    private const int FACTS_MAX = 8000;

    /** How the last reply came about: acted, safe, unavailable or no_text. */
    private string $outcome = '';

    public function __construct(
        private readonly Marvin $marvin,
        private readonly FlowLoader $flows,
        private readonly OrderSlots $slots,
        private readonly DecisionService $decisions,
        private readonly MenuDigest $menu,
        private readonly JsonService $json,
        private readonly ShopService $shops,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $conversation the transcript, ending with the shopper's message
     * @return array<string, mixed> the reply
     */
    public function reply(Decision $decision, array $conversation, ?string $clientName, string $phone, ?string $language): array
    {
        if (!$decision->ok) {
            $this->logger->warning('typesafe: unavailable, fixed reply', ['reason' => $decision->fallbackReason]);
            $this->outcome = 'unavailable';

            return $this->marvin->unavailable($language);
        }

        $language = $language !== null && $language !== '' ? $language : $this->flows->forShop()->defaultLanguage();
        // Run these calls, Marvin only words the result (short prompt, no tools).
        $reply = fn(array $calls, string $note): array
            => $this->marvin->replyWith($conversation, $clientName, $phone, $language, $calls, $note);
        // Nothing was done: Marvin, without tools, asks, replies kindly or says he cannot help.
        $safe = function (string $why, string $facts = '', string $instructions = '') use ($decision, $reply): array {
            $this->outcome = 'safe';
            $this->logger->info('typesafe: safe reply', ['branch' => $decision->branch, 'why' => $why]);

            return $reply([], $this->safeNote($decision, $why, $facts, $instructions));
        };

        if ($decision->useMarvin()) {
            return $safe((string) $decision->reason());
        }

        $this->outcome = 'acted';
        $result = match ($decision->do) {
            'faq'      => $this->faq($decision, $language, $reply),
            'handoff'  => $this->handoff($decision, $language, $reply),
            'language' => $this->language($decision, $reply),
            'order'    => $this->order($decision, $reply, $safe),
            default    => $this->tool($decision, $reply, $safe),
        };

        return $result ?? $safe('not_handled');
    }

    /**
     * A message without text (a photo, a sticker): Marvin says he reads text
     * and voice messages and asks what they would like.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    public function noText(array $conversation, ?string $clientName, string $phone, ?string $language): array
    {
        $this->outcome = 'no_text';

        return $this->marvin->replyWith($conversation, $clientName, $phone, $language, [],
            'The shopper sent something without text, like a photo or a sticker. Say in one line that you can read text and voice messages, and ask what they would like.');
    }

    /** acted, safe, unavailable or no_text, for the webhook response. */
    public function outcome(): string
    {
        return $this->outcome;
    }

    // ---------------------------------------------------------------- branches

    /** @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply */
    /** @param callable(string, string=, string=): array<string,mixed> $safe */
    private function tool(Decision $decision, callable $reply, callable $safe): ?array
    {
        $tool = MarvinTool::tryFrom((string) $decision->tool());

        if ($tool === MarvinTool::RemoveFromOrder && ($lines = $this->slots->removeLines($decision)) !== []) {
            $this->logger->info('typesafe: removing', ['lines' => $lines]);

            return $reply(
                array_map(static fn(array $input): array => [MarvinTool::RemoveFromOrder->value, $input], $lines),
                $this->note($decision, ranTool: true, instructions: $this->join(
                    count($lines) > 1 ? 'Several lines were changed, one result each. Say what was removed in one short list. Give the total from the last result.' : null,
                    $decision->instructions,
                )),
            );
        }

        if ($tool === MarvinTool::RemoveFromOrder) {
            $this->logger->info('typesafe: remove without a clear basket line');

            $basket = (string) json_encode(array_map(
                static fn(array $l): array => ['item' => $l['item'] ?? '', 'quantity' => $l['quantity'] ?? 1, 'options' => $l['options'] ?? []],
                (array) ($decision->state['basket'] ?? []),
            ), JSON_UNESCAPED_UNICODE);

            return $safe('remove_unclear_line', "The basket: {$basket}", 'They want to take something out of the basket, but it is not clear which item: ask which one, naming the items. If the basket is empty, say so.');
        }

        if ($tool === MarvinTool::FilterProducts && $this->slots->filterProducts($decision) === null) {
            return $this->products($decision, $reply, $safe);
        }

        $input = match ($tool) {
            MarvinTool::ChangeLogistics => $this->slots->changeLogistics($decision),
            MarvinTool::FilterProducts  => $this->slots->filterProducts($decision),
            default                     => false,
        };
        if ($input !== false) {
            if ($input === null) {
                return null;
            }
            $this->logger->info('typesafe: running tool', ['branch' => $decision->branch, 'tool' => $tool->value, 'input' => $input]);

            return $reply([[$tool->value, $input]], $this->note($decision, ranTool: true, instructions: $decision->instructions));
        }

        if ($tool === null || !in_array($tool, self::NO_INPUT_TOOLS, true)) {
            return null;
        }

        $this->logger->info('typesafe: running tool', ['branch' => $decision->branch, 'tool' => $tool->value]);

        return $reply([[$tool->value, []]], $this->note($decision, ranTool: true, instructions: $decision->instructions));
    }

    /**
     * Showing products when TypeSafe did not pick one item: the whole menu as
     * categories, or the items that match a description.
     *
     * @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply
     * @param callable(string, string=, string=): array<string,mixed> $safe
     */
    private function products(Decision $decision, callable $reply, callable $safe): ?array
    {
        $confident = $decision->isConfident($decision->productRequestConfidence);

        if ($confident && $decision->productRequest === 'overview') {
            $overview = $this->menu->categoryOverview();
            if ($overview !== '') {
                $this->logger->info('typesafe: menu overview');

                return $reply([], $this->note($decision, facts: "The menu's categories: {$overview}.", instructions: $this->join(
                    'List these categories in one short message, grouped as given, and ask what they would like to see. Do not list products.',
                    $decision->instructions,
                )));
            }
        }

        if ($confident && $decision->productRequest === 'matching') {
            $ids = $this->decisions->matchingProducts($decision);
            if ($ids !== null && $ids !== []) {
                $this->logger->info('typesafe: showing matching products', ['product_ids' => $ids]);

                return $reply([[MarvinTool::FilterProducts->value, ['product_ids' => $ids]]], $this->note($decision, ranTool: true, instructions: $this->join(
                    'Show these products, one per line, with price and a few words, in the order given.',
                    $decision->instructions,
                )));
            }
            if ($ids === []) {
                $overview = $this->menu->categoryOverview();

                return $safe('nothing_matching', $overview === '' ? '' : "The menu's categories: {$overview}.",
                    'They asked to see products, but nothing on the menu matches. Say so in one line and offer the categories to choose from.');
            }
        }

        return null;
    }

    /**
     * One clear menu item: add it with the options and quantity TypeSafe read;
     * add_to_order itself reports missing choices, deals and closed ordering,
     * and Marvin words that. Not one clear item: Marvin decides, with what
     * TypeSafe read as a note.
     *
     * @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply
     * @param callable(string, string=, string=): array<string,mixed> $safe
     */
    private function order(Decision $decision, callable $reply, callable $safe): ?array
    {
        $input = $this->slots->addToOrder($decision);

        // Several items in one message: one add_to_order per item, each with its own quantity.
        if ($input === null && $decision->item === Decision::ITEM_SEVERAL
            && ($items = $this->decisions->orderedItems($decision)) !== null && $items !== []) {
            $calls = array_map(
                static fn(array $i): array => [MarvinTool::AddToOrder->value, ['product_id' => $i['product_id'], 'quantity' => $i['quantity']]],
                $items,
            );
            $logistics = $this->slots->changeLogistics($decision);
            if ($logistics !== null) {
                $calls[] = [MarvinTool::ChangeLogistics->value, $logistics];
            }
            $this->logger->info('typesafe: adding several items', ['items' => $items]);

            return $reply($calls, $this->note($decision, ranTool: true, instructions: $this->join(
                'Several items were added, one result each. Say what was added in one short list; for any result that says '
                . 'needs_options, ask for exactly those choices for that item. Give the total from the last result.',
                $this->slots->extras($decision, logisticsHandled: $logistics !== null),
                $decision->instructions,
            )));
        }

        if ($input === null) {
            $read = match ($decision->item) {
                Decision::ITEM_SEVERAL => 'it fits several menu items or names more than one',
                default                => 'no item on the menu matches it',
            };
            // The items TypeSafe found most likely, so Marvin can ask "which one?".
            $candidates = array_slice(array_keys(array_filter($decision->itemProbabilities, static fn(float $p): bool => $p >= 0.02)), 0, 5);
            $facts = $this->menu->facts($candidates);

            return $safe('order_unclear_item', $facts === '' ? '' : "Menu items that may be meant: {$facts}",
                "They want to order, but it is not clear which menu item: {$read}. "
                . ($facts === '' ? 'Ask what they would like, or say it is not on the menu.' : 'Ask which of these they mean, with prices; if none fits, say it is not on the menu.'));
        }

        // "Pickup / delivery as soon as possible" in the same message: set it right after adding.
        $calls = [[MarvinTool::AddToOrder->value, $input]];
        $logistics = $this->slots->changeLogistics($decision);
        if ($logistics !== null) {
            $calls[] = [MarvinTool::ChangeLogistics->value, $logistics];
        }

        $this->logger->info('typesafe: adding to order', ['input' => $input, 'logistics' => $logistics]);

        return $reply($calls, $this->note($decision, ranTool: true, instructions: $this->join(
            'If the result says needs_options, ask for exactly those choices in one short message.',
            $this->slots->extras($decision, logisticsHandled: $logistics !== null),
            $decision->instructions,
        )));
    }

    /** @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply */
    private function language(Decision $decision, callable $reply): ?array
    {
        $requested = $decision->requestedLanguage;

        // "other" is a language the shop does not list; Marvin handles that one himself.
        if ($requested === null || !in_array($requested, $this->flows->forShop()->languages(), true)
            || !$decision->isConfident($decision->requestedLanguageConfidence)) {
            return null;
        }

        $this->logger->info('typesafe: switching language', ['language' => $requested]);

        return $reply([[MarvinTool::DetectLanguage->value, ['language' => $requested]]], $this->note($decision, ranTool: true, instructions: $decision->instructions));
    }

    /** @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply */
    private function faq(Decision $decision, string $language, callable $reply): ?array
    {
        $flow = $this->flows->forShop();
        $entry = $decision->faq === null ? null : $flow->faqEntry($decision->faq);
        if ($entry === null || !$decision->isConfident($decision->faqConfidence)) {
            return null;
        }

        $instructions = $this->join($decision->instructions, $entry['instructions'] ?? null, $this->ask($entry, $language));
        $this->logger->info('typesafe: answering FAQ', ['faq' => $entry['id']]);

        // A tool answers it: run the tool, Marvin replies from the result.
        if (isset($entry['do'])) {
            $tool = MarvinTool::tryFrom(substr((string) $entry['do'], 5));
            if ($tool === null || !in_array($tool, self::NO_INPUT_TOOLS, true)) {
                return null;
            }

            return $reply([[$tool->value, []]], $this->note($decision, ranTool: true, instructions: $instructions));
        }

        // An approved answer: sent as is when it exists in the shopper's language.
        if (isset($entry['answer'])) {
            $text = $flow->textIn((string) $entry['answer'], $language);
            if ($text !== null && $instructions === '') {
                return ['type' => 'text', 'message' => $this->fill($text)];
            }

            // Not in this language (or it needs wording): Marvin gives exactly this answer, translated.
            $facts = $this->fill((string) $flow->text((string) $entry['answer'], $language));

            return $reply([], $this->note($decision, facts: $facts, instructions: $this->join(
                'Give the shopper this answer in their language, without adding anything.',
                $instructions,
            )));
        }

        // Facts to answer from: from the flow, or from the shop's own data.
        $facts = match (true) {
            isset($entry['facts'])                  => $this->fill((string) $flow->text((string) $entry['facts'], $language)),
            ($entry['answer_from'] ?? '') === 'stores' => $this->storesFacts(),
            default                                 => '',
        };
        if ($facts === '') {
            return null;
        }

        return $reply([], $this->note($decision, facts: $facts, instructions: $instructions));
    }

    /** @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply */
    private function handoff(Decision $decision, string $language, callable $reply): ?array
    {
        $flow = $this->flows->forShop();
        $key = (string) ($flow->handoff()['answer'] ?? '');
        $text = $key === '' ? null : $flow->text($key, $language);

        $this->logger->info('typesafe: handoff', ['branch' => $decision->branch]);

        return $reply([], $this->note($decision, facts: $text === null ? '' : $this->fill($text), instructions: $this->join(
            $decision->instructions,
            'This is something only staff can do. Do not promise anything yourself.',
        )));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * What Marvin is told along with the decision. Kept free of round brackets:
     * it is wrapped in a "(SYSTEM NOTE, …)" that is cut at the first ")".
     */
    private function note(Decision $decision, bool $ranTool = false, string $facts = '', ?string $instructions = null): string
    {
        $note = "TypeSafe read the shopper's latest message as '{$decision->branch}'"
            . ($decision->faq !== null && $decision->do === 'faq' ? ", question '{$decision->faq}'" : '')
            . sprintf(', confidence %.2f.', $decision->branchConfidence);

        if ($ranTool) {
            $note .= ' What it needed was already done; reply from the results.';
        }
        if ($facts !== '') {
            $note .= ' Answer only from these facts, never add or guess anything beyond them: ' . $facts;
        }
        if ($instructions !== null && trim($instructions) !== '') {
            $note .= ' ' . trim($instructions);
        }

        return str_replace(['(', ')'], ['[', ']'], $note);
    }

    /**
     * The note for a safe reply: nothing was done, Marvin may not do anything
     * himself, and he either replies kindly, asks one clarifying question, or
     * says he cannot help with this here and gives the team contacts.
     */
    private function safeNote(Decision $decision, string $why, string $facts, string $instructions): string
    {
        $note = 'Nothing was done for the shopper\'s latest message. You cannot change the basket, place or look up '
            . 'orders, or give any information that is not in this note or the earlier conversation. Never say that you '
            . 'can, will or did do something; ask or explain instead.';

        if ($decision->ok && $decision->branch === 'no_action' && $instructions === '') {
            return $note . ' Nothing needs doing: reply briefly and kindly in one line, without asking anything new.';
        }

        if ($instructions !== '') {
            $note .= ' ' . $instructions;
        } else {
            // The two most likely readings, so the question can offer them.
            $readings = [];
            $probabilities = $decision->branchProbabilities;
            arsort($probabilities);
            foreach (array_keys($probabilities) as $id) {
                $branch = $this->flows->forShop()->branch((string) $id);
                if ($branch !== null && ($branch['do'] ?? '') !== 'marvin') {
                    $readings[] = $branch['description'];
                }
                if (count($readings) === 2) {
                    break;
                }
            }
            $note .= ' If it is unclear what they want, ask one short question to find out'
                . ($readings !== [] ? ', for example whether they mean: ' . implode(', or: ', $readings) : '') . '.'
                . ' If it is something you cannot help with in this chat, say so plainly in one line and give the team contacts: '
                . $this->fill('{{SUPPORT_1}} or {{SUPPORT_2}}') . '.';
        }
        if ($facts !== '') {
            $note .= ' Facts: ' . $facts;
        }

        return str_replace(['(', ')'], ['[', ']'], $note);
    }

    /** A follow-up question the entry needs answered first, in the shopper's language. */
    private function ask(array $entry, string $language): ?string
    {
        $key = $entry['ask']['question'] ?? null;
        if (!is_string($key)) {
            return null;
        }
        $question = $this->flows->forShop()->text($key, $language);

        return $question === null ? null : "If you need to know this first, ask it in one short message: {$question}";
    }

    /** The shop's branches, trimmed to what helps answer: name, address, hours, contact. */
    private function storesFacts(): string
    {
        try {
            $stores = $this->json->load('stores');
        } catch (ApiException $e) {
            $this->logger->warning('typesafe: stores not available', ['error' => $e->getMessage()]);

            return '';
        }
        $stores = array_is_list($stores) ? $stores : ($stores['stores'] ?? []);

        $trimmed = array_map(
            static fn(array $s): array => array_intersect_key($s, array_flip(self::STORE_FIELDS)),
            array_values(array_filter($stores, 'is_array')),
        );
        $json = (string) json_encode($trimmed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_strlen($json) > self::FACTS_MAX ? mb_substr($json, 0, self::FACTS_MAX) . '…' : $json;
    }

    /** {{SHOP_NAME}}, {{SUPPORT_1}}, {{SUPPORT_2}} in flow texts. */
    private function fill(string $text): string
    {
        $support = array_values(array_map('strval', (array) $this->config->secret('support', [])));

        return strtr($text, [
            '{{SHOP_NAME}}' => $this->shops->name(),
            '{{SUPPORT_1}}' => $support[0] ?? '',
            '{{SUPPORT_2}}' => $support[1] ?? $support[0] ?? '',
        ]);
    }

    private function join(?string ...$parts): string
    {
        return implode(' ', array_filter(array_map(static fn(?string $p): string => trim((string) $p), $parts)));
    }
}
