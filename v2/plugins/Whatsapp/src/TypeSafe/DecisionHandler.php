<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Plugins\Whatsapp\AI\ItemDraft;
use Plugins\Whatsapp\AI\Marvin;
use Plugins\Whatsapp\AI\MarvinTool;
use Plugins\Whatsapp\Support\SupportContacts;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Services\JsonService;
use Pmsrapi\V2\Services\ShopService;
use Pmsrapi\V2\Support\Logger;

/**
 * Turns TypeSafe's Decision into the reply, in the shape the controller sends
 * (buttons, links and tracking exactly as before). TypeSafe decides what to
 * do; nothing else ever does:
 *
 *   acted         code ran the tool(s) for the decision, Marvin worded the
 *                 result (writer prompt, no tools)
 *   marvin_tools  the decision needs details only Marvin reads from the
 *                 message (which items, how many, an exact time): Marvin with
 *                 the full menu and ONLY the branch's tools ("tools" in the flow)
 *   fallback      TypeSafe failed or was not sure, the branch is "unclear", or
 *                 there was nothing to run: the fixed, translated fallback
 *                 reply, no AI call, and a log line saying why
 *   no_text       a message without text (photo, sticker)
 *
 * By the branch's "do":
 *   tool:<name>  code runs the tool; tool:change_logistics only for "as soon
 *                as possible", otherwise Marvin with the branch's tools
 *   marvin       Marvin with only the branch's tools (products, remove)
 *   reply        nothing to do: Marvin writes one kind line, no tools
 *   language     detect_language with the requested language; not clear which:
 *                Marvin asks, naming the languages he writes
 *   faq          by the FAQ entry's type (no entry matches: Marvin says this
 *                chat has no information on it):
 *                  answer       the approved text, sent as is when it exists in
 *                               the shopper's language (no Claude call)
 *                  facts        Marvin answers only from these facts
 *                  answer_from  Marvin answers only from shop data (stores)
 *                  do           the tool runs, Marvin replies from its result
 *   handoff      Marvin, with the branch instructions and the handoff text
 *   fallback     the fallback reply
 */
final class DecisionHandler
{
    /** Tools code runs without any input from the message. */
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

    /** How the last reply came about: acted, marvin_tools, fallback or no_text. */
    private string $outcome = '';

    /** Why the last reply was the fallback, or null. */
    private ?string $fallbackReason = null;

    public function __construct(
        private readonly Marvin $marvin,
        private readonly FlowLoader $flows,
        private readonly OrderSlots $slots,
        private readonly ItemDraft $drafts,
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
        $language = $language !== null && $language !== '' ? $language : $this->flows->forShop()->defaultLanguage();

        $draft = $this->drafts->summary($phone);

        // Building an item and torn between finishing it and checking out: both
        // start with finishing the item, so act on the branch that builds items.
        if ($decision->reason() === 'low_confidence' && $draft !== null) {
            $decision = $this->finishingItem($decision) ?? $decision;
        }

        if ($decision->cannotAct()) {
            return $this->fallback($decision, (string) $decision->reason(), $conversation, $language);
        }

        // Run these calls, Marvin only words the result (short prompt, no tools).
        $reply = fn(array $calls, string $note): array
            => $this->marvin->replyWith($conversation, $clientName, $phone, $language, $calls, $note);
        // Marvin fills in the details, with only the branch's tools.
        $withTools = function () use ($decision, $conversation, $clientName, $phone, $language): ?array {
            if ($decision->tools === []) {
                return null;
            }
            $this->outcome = 'marvin_tools';
            $this->logger->info('typesafe: Marvin with tools', ['branch' => $decision->branch, 'tools' => $decision->tools]);

            return $this->marvin->replyWithTools($conversation, $clientName, $phone, $language, $decision->tools, $this->toolsNote($decision, $phone));
        };

        $this->outcome = 'acted';
        $this->fallbackReason = null;
        $result = match ($decision->do) {
            'faq'      => $this->faq($decision, $language, $reply),
            'handoff'  => $this->handoff($decision, $language, $reply),
            'language' => $this->language($decision, $reply),
            'marvin'   => $withTools(),
            'reply'    => $reply([], $this->note($decision, instructions: $this->join(
                'Nothing needs doing: reply briefly and kindly in one line, without asking anything new.',
                $decision->instructions,
            ))),
            'fallback' => null,
            default    => $this->tool($decision, $reply, $withTools, $phone),
        };

        if ($result === null) {
            return $this->fallback($decision, $decision->do === 'fallback' ? 'unclear' : 'not_handled', $conversation, $language);
        }
        // Marvin failed (prompt missing, API error): he already returned the fallback reply.
        if (($result['type'] ?? null) === Marvin::FALLBACK_SOURCE) {
            $this->outcome = 'fallback';
            $this->fallbackReason = 'marvin_failed';
            $this->logger->warning('typesafe: fallback', [
                'why'     => 'marvin_failed',
                'message' => $this->latestMessage($conversation),
                'branch'  => $decision->branch,
            ]);
        }

        return $result;
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
        $this->fallbackReason = null;

        $reply = $this->marvin->replyWith($conversation, $clientName, $phone, $language, [],
            'The shopper sent something without text, like a photo or a sticker. Say in one line that you can read text and voice messages, and ask what they would like.');
        if (($reply['type'] ?? null) === Marvin::FALLBACK_SOURCE) {
            $this->outcome = 'fallback';
            $this->fallbackReason = 'marvin_failed';
        }

        return $reply;
    }

    /** acted, marvin_tools, fallback or no_text, for the webhook response. */
    public function outcome(): string
    {
        return $this->outcome;
    }

    /** @return list<string> the tools that actually ran for the last reply */
    public function calledTools(): array
    {
        return $this->outcome === 'fallback' && $this->fallbackReason !== 'marvin_failed' ? [] : $this->marvin->calledTools();
    }

    /** Why the last reply was the fallback, for the webhook response; null otherwise. */
    public function fallbackReason(): ?string
    {
        return $this->fallbackReason;
    }

    // ---------------------------------------------------------------- branches

    /**
     * The fixed fallback reply, logged with the message and why.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    private function fallback(Decision $decision, string $why, array $conversation, string $language): array
    {
        $this->outcome = 'fallback';
        $this->fallbackReason = $why;

        $this->logger->warning('typesafe: fallback', [
            'why'     => $why,
            'message' => $this->latestMessage($conversation),
            'branch'  => $decision->branch,
            'top'     => $decision->topBranches(),
        ]);

        return $this->marvin->fallbackReply($language);
    }

    /**
     * @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply
     * @param callable(): ?array<string,mixed>                                                     $withTools
     */
    private function tool(Decision $decision, callable $reply, callable $withTools, string $phone): ?array
    {
        $tool = MarvinTool::tryFrom((string) $decision->tool());

        // Pickup or delivery "as soon as possible" is set here; an exact time is read by Marvin.
        if ($tool === MarvinTool::ChangeLogistics) {
            $input = $this->slots->changeLogistics($decision);
            if ($input === null) {
                return $withTools();
            }
            $this->logger->info('typesafe: running tool', ['branch' => $decision->branch, 'tool' => $tool->value, 'input' => $input]);

            return $reply([[$tool->value, $input]], $this->note($decision, ranTool: true, instructions: $decision->instructions));
        }

        if ($tool === null || !in_array($tool, self::NO_INPUT_TOOLS, true)) {
            return $withTools();
        }

        // Checking out while an item is still being built: ask first whether to
        // finish it or leave it out; asked once, then checking out leaves it out.
        $draft = $tool === MarvinTool::CheckoutOrder ? $this->drafts->get($phone) : null;
        if ($draft !== null && !$draft['reminded']) {
            $this->drafts->save($phone, ['reminded' => true] + $draft);
            $summary = $this->drafts->summary($phone);
            $this->logger->info('typesafe: checkout with an unfinished item, asking', ['item' => $summary['item'] ?? null]);

            return $reply([], $this->note($decision, instructions: sprintf(
                'Nothing was done yet. They want to check out, but %s is not finished: chosen so far: %s; still open: %s. '
                . 'Say so in one short message and ask whether they want to finish it now or check out without it.',
                $summary['item'] ?? 'an item', implode(', ', $summary['chosen'] ?? []) ?: 'nothing',
                implode(', ', $summary['waiting_for'] ?? []) ?: 'nothing',
            )));
        }
        if ($draft !== null) {
            $this->logger->info('typesafe: checkout without the unfinished item', ['product_id' => $draft['product_id']]);
            $this->drafts->clear($phone);
        }

        $this->logger->info('typesafe: running tool', ['branch' => $decision->branch, 'tool' => $tool->value]);

        return $reply([[$tool->value, []]], $this->note($decision, ranTool: true, instructions: $decision->instructions));
    }

    /** @param callable(list<array{0: string, 1: array<string,mixed>}>, string): array<string,mixed> $reply */
    private function language(Decision $decision, callable $reply): ?array
    {
        $requested = $decision->requestedLanguage;

        // Not clear which language, or one the shop does not list: say which ones he speaks.
        $languages = $this->flows->forShop()->languages();
        if ($requested === null || !in_array($requested, $languages, true)
            || !$decision->isConfident($decision->requestedLanguageConfidence)) {
            $this->logger->info('typesafe: language not clear', ['requested' => $requested]);

            return $reply([], $this->note($decision, instructions: 'They asked about the language of this chat, but it is not clear '
                . 'which supported language they want. You can write in these languages, by ISO code: ' . implode(', ', $languages)
                . '. Name them in plain words and ask which one they would like, in one short message.'));
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
            // A shop question with no answer here: say so and point to the team,
            // in context, instead of the general fallback. Logged so a FAQ can be added.
            $this->logger->warning('typesafe: faq without answer', ['faq' => $decision->faq, 'conf' => round($decision->faqConfidence, 2)]);

            return $reply([], $this->note($decision, facts: 'Team contacts, one per line: ' . $this->contacts(), instructions:
                'This chat has no information about what they asked. Say so plainly in one line, without guessing, then give the '
                . 'team contacts exactly as listed, each on its own line.'));
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

        return $reply([], $this->note($decision, facts: $this->join(
            $text === null ? '' : $this->fill($text),
            'Team contacts, one per line: ' . $this->contacts(),
        ), instructions: $this->join(
            $decision->instructions,
            'This is something only staff can do. Do not promise anything yourself. Give the team contacts exactly as listed, each on its own line.',
        )));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * When the two most likely branches are checkout and the branch that
     * builds items (its tools include add_to_order), the decision for the
     * latter, taken as sure enough; null otherwise.
     */
    private function finishingItem(Decision $decision): ?Decision
    {
        $flow = $this->flows->forShop();
        $top = array_keys($decision->topBranches());
        $builds = null;
        $checkout = false;
        foreach ($top as $id) {
            $branch = $flow->branch((string) $id);
            if ($branch === null) {
                return null;
            }
            if (($branch['do'] ?? null) === 'tool:' . MarvinTool::CheckoutOrder->value) {
                $checkout = true;
            } elseif (in_array(MarvinTool::AddToOrder->value, (array) ($branch['tools'] ?? []), true)) {
                $builds = $branch;
            }
        }
        if (!$checkout || $builds === null) {
            return null;
        }

        $this->logger->info('typesafe: close call while building an item, finishing it', ['top' => $decision->topBranches()]);

        return new Decision(
            ok: true,
            threshold: $decision->threshold,
            branch: (string) $builds['id'],
            do: (string) $builds['do'],
            tools: array_values(array_map('strval', (array) ($builds['tools'] ?? []))),
            instructions: $builds['instructions'] ?? null,
            branchConfidence: $decision->threshold,
            branchProbabilities: $decision->branchProbabilities,
            language: $decision->language,
            languageConfidence: $decision->languageConfidence,
            requestedLanguage: $decision->requestedLanguage,
            requestedLanguageConfidence: $decision->requestedLanguageConfidence,
            faq: $decision->faq,
            faqConfidence: $decision->faqConfidence,
            orderTime: $decision->orderTime,
            logistics: $decision->logistics,
            tokens: $decision->tokens,
            seconds: $decision->seconds,
            minLead: $decision->minLead,
        );
    }

    /**
     * What Marvin is told when he fills in the details: what was decided,
     * which tools to use, and to ask instead of guessing.
     */
    private function toolsNote(Decision $decision, string $phone): string
    {
        $draft = in_array(MarvinTool::AddToOrder->value, $decision->tools, true) ? $this->drafts->summary($phone) : null;

        $mentioned = array_filter([
            $decision->logistics,
            $decision->orderTime === null ? null : str_replace('_', ' ', $decision->orderTime),
        ]);

        return $this->note($decision, instructions: $this->join(
            'Use ' . implode(' or ', $decision->tools) . ' for this message. Take what goes into the call, such as '
            . 'which items, how many, which options or what time, from the message and the conversation. If '
            . 'something you need is not said, ask for exactly that in one short message instead of guessing.',
            $mentioned !== [] && in_array(MarvinTool::ChangeLogistics->value, $decision->tools, true)
                ? 'They also mentioned ' . implode(', ', $mentioned) . '; set it with change_logistics once you know both the type and the time.'
                : null,
            $draft === null ? null : sprintf(
                'You are building %s [product_id %d] with them. Chosen so far: %s. Waiting for: %s. A name in their message that '
                . 'matches an option of this item is that option, not a new menu item: call add_to_order for this product with only '
                . 'the new option ids, earlier choices are kept. No thanks to an optional group: skip_optional. That\'s it: '
                . 'no_more_options. They no longer want it: discard. Other products they ask for are added right away with their '
                . 'own add_to_order call; the item being built simply stays waiting, so never hold them back until it is finished.',
                $draft['item'], $draft['product_id'], $draft['chosen'] === [] ? 'nothing' : implode(', ', $draft['chosen']),
                $draft['waiting_for'] === [] ? 'nothing' : implode(', ', $draft['waiting_for']),
            ),
            $decision->instructions,
        ));
    }

    /** @param array<string, mixed> $conversation */
    private function latestMessage(array $conversation): string
    {
        foreach (array_reverse((array) ($conversation['data']['messages'] ?? [])) as $entry) {
            if (is_array($entry) && ($entry['direction'] ?? 'in') === 'in' && is_string($entry['message'] ?? null)) {
                return mb_substr(trim($entry['message']), 0, 300);
            }
        }

        return '';
    }

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

    /** The team contacts, "Name: contact" separated by " | " for the note (Marvin puts each on its own line). */
    private function contacts(): string
    {
        return implode(' | ', SupportContacts::lines($this->config->secret('support', [])));
    }

    /** {{SHOP_NAME}}, {{SUPPORT_1}}, {{SUPPORT_2}}, {{SUPPORT_CONTACTS}} in flow texts. */
    private function fill(string $text): string
    {
        $support = array_values(array_map('strval', (array) $this->config->secret('support', [])));

        return strtr($text, [
            '{{SHOP_NAME}}' => $this->shops->name(),
            '{{SUPPORT_1}}' => $support[0] ?? '',
            '{{SUPPORT_2}}' => $support[1] ?? $support[0] ?? '',
            '{{SUPPORT_CONTACTS}}' => SupportContacts::text($this->config->secret('support', [])),
        ]);
    }

    private function join(?string ...$parts): string
    {
        return implode(' ', array_filter(array_map(static fn(?string $p): string => trim((string) $p), $parts)));
    }
}
