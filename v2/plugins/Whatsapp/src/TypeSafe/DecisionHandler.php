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
 * Acts on a confident Decision and returns the reply, in the same shape as
 * Marvin::reply(), so the controller sends buttons, links and tracking exactly
 * as before. Returns null when code cannot act on the decision; the caller then
 * hands the message to Marvin as before.
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
 *                read (OrderSlots); not one clear item: Marvin, with what
 *                TypeSafe read as a note
 *   tool:change_logistics
 *                pickup or delivery "as soon as possible"; a later time: Marvin
 *   tool:filter_products
 *                one clear menu item; vague requests: Marvin picks
 *   tool:remove_from_order
 *                the basket line TypeSafe picked, whole or partly; not one clear
 *                line: Marvin, with what TypeSafe read as a note
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

    public function __construct(
        private readonly Marvin $marvin,
        private readonly FlowLoader $flows,
        private readonly OrderSlots $slots,
        private readonly JsonService $json,
        private readonly ShopService $shops,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $conversation the transcript, ending with the shopper's message
     * @return array<string, mixed>|null the reply, or null to let Marvin handle the message
     */
    public function reply(Decision $decision, array $conversation, ?string $clientName, string $phone, ?string $language): ?array
    {
        if ($decision->useMarvin()) {
            return null;
        }

        $language = $language !== null && $language !== '' ? $language : $this->flows->forShop()->defaultLanguage();
        // Decided: run these calls, Marvin only words the reply (short prompt, no tools).
        $reply = fn(array $calls, string $note): array
            => $this->marvin->replyWith($conversation, $clientName, $phone, $language, $calls, $note);
        // Not decided: Marvin decides himself (full prompt, all tools), with what TypeSafe read.
        $hint = fn(string $note): array
            => $this->marvin->replyWithNote($conversation, $clientName, $phone, $language, $note);

        $result = match ($decision->do) {
            'faq'      => $this->faq($decision, $language, $reply),
            'handoff'  => $this->handoff($decision, $language, $reply),
            'language' => $this->language($decision, $reply),
            'order'    => $this->order($decision, $reply, $hint),
            default    => $this->tool($decision, $reply, $hint),
        };

        if ($result === null) {
            $this->logger->info('typesafe: not handled, using Marvin', [
                'branch' => $decision->branch,
                'do'     => $decision->do,
                'faq'    => $decision->faq,
            ]);
        }

        return $result;
    }

    // ---------------------------------------------------------------- branches

    /** @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply */
    private function tool(Decision $decision, callable $reply, callable $hint): ?array
    {
        $tool = MarvinTool::tryFrom((string) $decision->tool());

        if ($tool === MarvinTool::RemoveFromOrder && $this->slots->removeFromOrder($decision) === null) {
            $this->logger->info('typesafe: remove without one clear basket line, Marvin decides', ['line' => $decision->basketLineId]);

            return $hint($this->note($decision, instructions: $this->join(
                'The shopper wants to take something out of the basket, but TypeSafe could not pick one basket line. '
                . 'Call get_cart if you need the line ids, ask if it is not clear which item, and call remove_from_order as usual.',
                $decision->instructions,
            )));
        }

        $input = match ($tool) {
            MarvinTool::ChangeLogistics => $this->slots->changeLogistics($decision),
            MarvinTool::FilterProducts  => $this->slots->filterProducts($decision),
            MarvinTool::RemoveFromOrder => $this->slots->removeFromOrder($decision),
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
     * One clear menu item: add it with the options and quantity TypeSafe read;
     * add_to_order itself reports missing choices, deals and closed ordering,
     * and Marvin words that. Not one clear item: Marvin decides, with what
     * TypeSafe read as a note.
     *
     * @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply
     * @param callable(string): array<string,mixed> $hint
     */
    private function order(Decision $decision, callable $reply, callable $hint): ?array
    {
        $input = $this->slots->addToOrder($decision);

        if ($input === null) {
            $read = match ($decision->item) {
                Decision::ITEM_SEVERAL => 'it fits several menu items or names more than one',
                default                => 'no item on the menu matches it',
            };
            $this->logger->info('typesafe: order without one clear item, Marvin decides', ['item' => $decision->item]);

            return $hint($this->note($decision, instructions: $this->join(
                "The shopper wants to order, but TypeSafe could not pick one menu item: {$read}. Decide yourself which item they mean, ask if it is not clear, and call add_to_order as usual.",
                $this->slots->extras($decision),
                $decision->instructions,
            )));
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
