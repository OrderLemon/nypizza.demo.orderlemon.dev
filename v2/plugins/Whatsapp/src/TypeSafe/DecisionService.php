<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;

/**
 * Turns one shopper message into a Decision with a single TypeSafe call.
 *
 * The questions come from the shop's Flow (router branches, FAQs, languages)
 * and its menu (MenuDigest: items and config groups such as Size and Base), so
 * the same code serves every shop. All questions are asked in parallel about
 * the same state; the ordering questions are asked even when the message turns
 * out not to be an order, and simply come back "not mentioned".
 *
 * Never throws: when TypeSafe or the data behind the state is unavailable the
 * Decision is a fallback and the caller hands the message to Marvin.
 */
final class DecisionService
{
    private const string CONTEXT =
        '`history` is the end of a WhatsApp conversation between a shopper and Marvin, the ordering assistant '
        . 'of a shop; `latest_message` is the shopper\'s new message (button taps arrive as their label, voice '
        . 'notes as their transcript). `basket` is what is in the shopper\'s basket now, `active_order` whether '
        . 'an earlier order is placed and on its way, `checkout_link_sent` whether Marvin already sent a payment '
        . 'link for this basket.';

    private const array LANGUAGE_NAMES = [
        'en' => 'English', 'nl' => 'Dutch', 'tr' => 'Turkish', 'es' => 'Spanish', 'de' => 'German',
        'fr' => 'French', 'ar' => 'Arabic', 'it' => 'Italian', 'fy' => 'Frisian', 'pl' => 'Polish',
        'pt' => 'Portuguese', 'ro' => 'Romanian', 'uk' => 'Ukrainian', 'ru' => 'Russian', 'zh' => 'Chinese',
    ];

    public function __construct(
        private readonly TypeSafeClient $client,
        private readonly FlowLoader $flows,
        private readonly MenuDigest $menu,
        private readonly StateBuilder $states,
        private readonly Logger $logger,
    ) {}

    /** Decide for a live message: builds the state from the transcript, cart and orders. */
    public function decide(string $phone, string $message): Decision
    {
        try {
            $flow = $this->flows->forShop();
            $state = $this->states->build($phone, $message, $flow->historyTurns());
        } catch (TypeSafeException | ApiException | \mysqli_sql_exception $e) {
            $this->logger->warning('typesafe: cannot build decision state', ['error' => $e->getMessage()]);

            return Decision::fallback('state_unavailable');
        }

        return $this->decideFor($state, $flow);
    }

    /**
     * Decide for a state that is already built (StateBuilder shape). Used by
     * decide() and by the eval script, which replays transcripts offline.
     *
     * @param array<string, mixed> $state
     */
    public function decideFor(array $state, ?Flow $flow = null): Decision
    {
        try {
            $flow ??= $this->flows->forShop();
            [$questions, $maps] = $this->questions($flow, $state);
            $result = $this->client->ask($state, $questions);
            $decision = $this->read($result, $flow, $maps, $state);
        } catch (TypeSafeException $e) {
            $this->logger->warning('typesafe: decision failed, using Marvin', [
                'code'  => $e->errorCode(),
                'error' => $e->getMessage(),
            ]);

            return Decision::fallback($e->errorCode(), isset($flow) ? $flow->threshold() : 0.6);
        } catch (ApiException $e) {
            // The menu could not be loaded for this shop.
            $this->logger->warning('typesafe: decision data unavailable, using Marvin', ['error' => $e->getMessage()]);

            return Decision::fallback('data_unavailable', isset($flow) ? $flow->threshold() : 0.6);
        }

        $this->logger->info('typesafe: decision', $decision->summary());

        return $decision;
    }

    // ---------------------------------------------------------------- questions

    /**
     * @param array<string, mixed> $state
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, string>>}
     *         questions, and per config question: option key => option label
     */
    private function questions(Flow $flow, array $state): array
    {
        $router = [];
        foreach ($flow->router() as $branch) {
            $router[$branch['id']] = $branch['description'];
        }

        $faq = [];
        foreach ($flow->faq() as $entry) {
            $faq[$entry['id']] = $entry['match'];
        }
        $faq['none'] = 'The message is not one of these questions';

        $languages = [];
        foreach ($flow->languages() as $code) {
            $languages[$code] = self::LANGUAGE_NAMES[$code] ?? $code;
        }

        $items = $this->menu->choiceOptions($flow->aliases());
        $items['none'] = 'No menu item is meant, or the item is not on this menu';
        $items['several'] = 'More than one item, or a vague description that fits several items';

        $questions = [
            'route' => [
                'type' => 'choice',
                'instructions' => self::CONTEXT . ' What should Marvin do now? Read short replies such as yes, 1, '
                    . 'the second one or ok as answers to what Marvin last offered or asked.',
                'criteria' => $router,
            ],
            'language' => [
                'type' => 'choice',
                'instructions' => 'Which language is `latest_message` written in? Judge the sentence itself, not '
                    . 'product names or emoji in it.',
                'criteria' => $languages + ['other' => 'Another language, or too short or too generic to tell (ok, emoji, a product name)'],
            ],
            'requested_language' => [
                'type' => 'choice',
                'instructions' => 'Does `latest_message` ask the assistant to talk in a particular language, or is '
                    . 'it a tap on a language button? If so, which language is asked FOR (not the language the '
                    . 'request is written in)? A word that answers another question, such as Italian for a pizza '
                    . 'base, is not a language request.',
                'criteria' => ['none' => 'The message does not ask for a language']
                    + $languages
                    + ['other' => 'Asks for a language that is not listed here'],
            ],
            'faq' => [
                'type' => 'choice',
                'instructions' => 'Which of these questions does the shopper ask in `latest_message`?',
                'criteria' => $faq,
            ],
            'menu_item' => [
                'type' => 'choice',
                'instructions' => self::CONTEXT . ' Which single item from the menu does the shopper want or ask '
                    . 'about in `latest_message`? Use `history` when they refer to an item with this one, that '
                    . 'one, a number or a yes. Allow for typos and other languages.',
                'criteria' => $items,
            ],
            'product_request' => [
                'type' => 'choice',
                'instructions' => self::CONTEXT . ' When the shopper asks to see products in `latest_message`, what do they ask for?',
                'criteria' => [
                    'overview' => 'The menu in general or what there is, without saying what kind',
                    'matching' => 'Products that fit a description: an ingredient, diet, category, taste or price, such as something with chicken, vegan, drinks, the cheapest',
                    'one_item' => 'One specific item they name or point to',
                    'none' => 'They do not ask to see products',
                ],
            ],
            'quantity' => [
                'type' => 'choice',
                'instructions' => 'How many of one item does `latest_message` order? Count only an explicit number '
                    . 'for a single item (een pizza, 2x, üç tane, three). Words like both or allebei that name two '
                    . 'different items are not a quantity.',
                'criteria' => [
                    'one' => 'One', 'two' => 'Two', 'three' => 'Three', 'four' => 'Four', 'five_or_more' => 'Five or more',
                    'not_mentioned' => 'No explicit number for a single item',
                ],
            ],
            'modification' => [
                'type' => 'noul',
                'instructions' => 'Does `latest_message` ask to change the ingredients of a dish, such as leaving '
                    . 'something off, adding extra of something, or swapping an ingredient?',
            ],
            'order_time' => [
                'type' => 'choice',
                'instructions' => 'Does `latest_message` say when the shopper wants the order?',
                'criteria' => [
                    'asap' => 'As soon as possible, now',
                    'today_later' => 'A later time today',
                    'another_day' => 'Another day, such as tomorrow',
                    'not_mentioned' => 'No time is given',
                ],
            ],
            'logistics' => [
                'type' => 'choice',
                'instructions' => 'Does `latest_message` say whether the shopper wants to pick the order up or have '
                    . 'it delivered?',
                'criteria' => [
                    'pickup' => 'Pick up or collect it',
                    'delivery' => 'Have it delivered',
                    'not_mentioned' => 'Neither is said or meant',
                ],
            ],
        ];

        // One question per config group on this shop's menu (Size, Base, …).
        $maps = [];
        foreach ($this->menu->configGroups() as $label => $optionLabels) {
            $id = 'option_' . trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
            if ($id === 'option_' || isset($questions[$id]) || count($optionLabels) > 250) {
                continue;
            }
            $criteria = [];
            foreach (array_values($optionLabels) as $i => $optionLabel) {
                $criteria['o' . ($i + 1)] = $optionLabel;
            }
            $maps[$id] = ['label' => $label] + $criteria;
            $questions[$id] = [
                'type' => 'choice',
                'instructions' => "Does `latest_message` choose a {$label}, in any language? Only count a "
                    . "{$label} the message itself says.",
                'criteria' => $criteria + ['not_mentioned' => "No {$label} is chosen in this message"],
            ];
        }

        // Per basket line: is it to be taken out, and how many units. A basket
        // has few lines, so this fits in the same call and handles "remove the
        // salad and the platter" as well as "yes" to removing what Marvin named.
        $hasLines = false;
        foreach ((array) ($state['basket'] ?? []) as $line) {
            if (!is_array($line) || !isset($line['line_id'], $line['item'])) {
                continue;
            }
            $hasLines = true;
            $id = (int) $line['line_id'];
            $options = (array) ($line['options'] ?? []);
            $describe = sprintf('%s x%d', $line['item'], (int) ($line['quantity'] ?? 1)) . ($options !== [] ? ', ' . implode(', ', $options) : '');
            $questions["remove_l{$id}"] = [
                'type' => 'noul',
                'instructions' => "Does `latest_message` ask to take this basket line out, all or some of it: {$describe}? "
                    . 'Use `history` when they say yes to removing items the assistant named.',
            ];
            $questions["remove_l{$id}_count"] = [
                'type' => 'choice',
                'instructions' => "How many units of {$line['item']} does `latest_message` ask to take out of the basket?",
                'criteria' => [
                    'all' => 'All of them, the whole line, or no number given',
                    'one' => 'One', 'two' => 'Two', 'three' => 'Three', 'four_or_more' => 'Four or more',
                ],
            ];
        }
        if ($hasLines) {
            $maps['__basket'] = array_map(
                static fn(array $l): string => (string) (int) ($l['quantity'] ?? 1),
                array_column(array_filter((array) $state['basket'], static fn($l) => is_array($l) && isset($l['line_id'])), null, 'line_id'),
            );
        }

        return [$questions, $maps];
    }

    // ---------------------------------------------------------------- answers

    /**
     * Menu items that match what the shopper asked to see ("something with
     * chicken", "vegan", "drinks"), best first, at most $max: a second, small
     * call with one yes/no question per candidate. Candidates are the items the
     * first call found most likely; when it spread almost nothing over the
     * items, every item is checked. Null when TypeSafe is unavailable.
     *
     * @return list<int>|null product ids
     */
    public function matchingProducts(Decision $decision, int $max = 5): ?array
    {
        $items = $this->menu->items();

        $questions = [];
        foreach ($this->candidates($decision) as $id) {
            if (!isset($items[$id])) {
                continue;
            }
            $questions["p{$id}"] = [
                'type' => 'noul',
                'instructions' => 'Does the shopper in `latest_message` ask to see products like this one: '
                    . $items[$id]['name'] . ($items[$id]['about'] !== '' ? ': ' . $items[$id]['about'] : '')
                    . '? Yes only when it fits what they describe, such as an ingredient, diet, category, taste or price.',
            ];
        }
        if ($questions === []) {
            return null;
        }

        try {
            $result = $this->client->ask([
                'latest_message' => $decision->state['latest_message'] ?? '',
                'history' => $decision->state['history'] ?? [],
            ], $questions);
        } catch (TypeSafeException $e) {
            $this->logger->warning('typesafe: matching products failed', ['code' => $e->errorCode()]);

            return null;
        }

        $yes = [];
        foreach ($questions as $key => $_) {
            $p = (float) $result->noul($key)->noul;
            if ($p >= 0.5) {
                $yes[(int) substr($key, 1)] = $p;
            }
        }
        arsort($yes);
        $ids = array_slice(array_keys($yes), 0, $max);

        $this->logger->info('typesafe: matching products', [
            'checked' => count($questions), 'matched' => count($yes), 'tokens' => $result->inputTokens + $result->outputTokens,
        ]);

        return $ids;
    }

    /**
     * The items an order message names and how many of each ("a seaweed salad
     * and 2 poke nachos", or "yes" to items the assistant listed): a second,
     * small call with, per candidate, "is it ordered?" and "how many?".
     * Best first, at most $max. Null when TypeSafe is unavailable.
     *
     * @return list<array{product_id: int, quantity: int}>|null
     */
    public function orderedItems(Decision $decision, int $max = 6): ?array
    {
        $items = $this->menu->items();

        $questions = [];
        foreach ($this->candidates($decision) as $id) {
            if (!isset($items[$id])) {
                continue;
            }
            $item = $items[$id]['name'] . ($items[$id]['about'] !== '' ? ': ' . $items[$id]['about'] : '');
            $questions["p{$id}"] = [
                'type' => 'noul',
                'instructions' => "Does the shopper order this menu item in `latest_message`: {$item}? Use `history` when they say yes "
                    . 'to items the assistant just offered or listed. Only yes for this exact item, not for an item with a similar word in its name.',
            ];
            $questions["q{$id}"] = [
                'type' => 'choice',
                'instructions' => "How many of {$items[$id]['name']} does the shopper order in `latest_message`, or in the offer they say yes to in `history`?",
                'criteria' => [
                    'one' => 'One, or no number given', 'two' => 'Two', 'three' => 'Three', 'four' => 'Four',
                    'five' => 'Five', 'six_or_more' => 'Six or more',
                ],
            ];
        }
        if ($questions === []) {
            return null;
        }

        try {
            $result = $this->client->ask([
                'latest_message' => $decision->state['latest_message'] ?? '',
                'history' => $decision->state['history'] ?? [],
            ], $questions);
        } catch (TypeSafeException $e) {
            $this->logger->warning('typesafe: ordered items failed', ['code' => $e->errorCode()]);

            return null;
        }

        $ordered = [];
        foreach ($questions as $key => $question) {
            if ($question['type'] !== 'noul' || ($p = (float) $result->noul($key)->noul) < 0.5) {
                continue;
            }
            $id = (int) substr($key, 1);
            $ordered[$id] = ['p' => $p, 'quantity' => match ($result->choice("q{$id}")->choice) {
                'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six_or_more' => 6, default => 1,
            }];
        }
        uasort($ordered, static fn(array $a, array $b): int => $b['p'] <=> $a['p']);

        $items = [];
        foreach (array_slice($ordered, 0, $max, true) as $id => $o) {
            $items[] = ['product_id' => $id, 'quantity' => $o['quantity']];
        }

        $this->logger->info('typesafe: ordered items', [
            'checked' => count($questions) / 2, 'items' => $items, 'tokens' => $result->inputTokens + $result->outputTokens,
        ]);

        return $items;
    }

    /**
     * Menu items to ask about in a follow-up call, at most 20: the items the
     * first call found likely, then items whose name shares a word with the
     * shopper's message or the assistant's last message (for a "yes" to a
     * list). Every item only when neither gives anything.
     *
     * @return list<int>
     */
    private function candidates(Decision $decision): array
    {
        $likely = array_keys(array_filter($decision->itemProbabilities, static fn(float $p): bool => $p >= 0.01));

        $lastAssistant = '';
        foreach (array_reverse((array) ($decision->state['history'] ?? [])) as $turn) {
            if (($turn['from'] ?? '') === 'Marvin') {
                $lastAssistant = (string) ($turn['text'] ?? '');
                break;
            }
        }
        $text = $this->words(($decision->state['latest_message'] ?? '') . ' ' . $lastAssistant);

        $named = [];
        foreach ($this->menu->items() as $id => $item) {
            if (array_intersect($this->words($item['name']), $text) !== []) {
                $named[] = $id;
            }
        }

        $candidates = array_slice(array_values(array_unique([...array_slice($likely, 0, 15), ...$named])), 0, 20);

        return $candidates !== [] ? $candidates : array_slice(array_keys($this->menu->items()), 0, 120);
    }

    /**
     * Lowercase words of at least 4 letters, accents removed ("Poké" = "poke").
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        $plain = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($text)) ?: mb_strtolower($text);
        $words = preg_split('/[^a-z0-9]+/', $plain) ?: [];

        return array_values(array_unique(array_filter($words, static fn(string $w): bool => strlen($w) >= 4)));
    }

    /** @param array<string, array<string, string>> $maps */
    private function read(TypeSafeResult $result, Flow $flow, array $maps, array $state): Decision
    {
        $route = $result->choice('route');
        $branch = $flow->branch($route->choice);

        $language = $result->choice('language');
        $requested = $result->choice('requested_language');
        $faq = $result->choice('faq');
        $item = $result->choice('menu_item');
        $quantity = $result->choice('quantity');
        $time = $result->choice('order_time');
        $logistics = $result->choice('logistics');

        $productId = $this->menu->productId($item->choice);
        $itemProbabilities = [];
        foreach ($item->probabilities as $option => $probability) {
            $id = $this->menu->productId((string) $option);
            if ($id !== null) {
                $itemProbabilities[$id] = (float) $probability;
            }
        }
        arsort($itemProbabilities);
        $productRequest = $result->has('product_request') ? $result->choice('product_request') : null;
        $itemKind = match (true) {
            $productId !== null          => Decision::ITEM_ONE,
            $item->choice === 'several'  => Decision::ITEM_SEVERAL,
            default                      => Decision::ITEM_NONE,
        };

        $basketQuantities = $maps['__basket'] ?? [];
        unset($maps['__basket']);

        $removeLines = [];
        foreach ($basketQuantities as $lineId => $quantity) {
            $lineId = (int) $lineId;
            if (!$result->has("remove_l{$lineId}") || ($p = (float) $result->noul("remove_l{$lineId}")->noul) < 0.5) {
                continue;
            }
            $removeLines[] = [
                'line_id'  => $lineId,
                'quantity' => (int) $quantity,
                'remove'   => match ($result->choice("remove_l{$lineId}_count")->choice) {
                    'one' => 1, 'two' => 2, 'three' => 3, 'four_or_more' => 4, default => null,
                },
                'p'        => $p,
            ];
        }

        $options = [];
        foreach ($maps as $questionId => $map) {
            $answer = $result->choice($questionId);
            $options[$map['label']] = [
                'option'     => $answer->choice === 'not_mentioned' ? null : ($map[$answer->choice] ?? null),
                'confidence' => (float) $answer->confidence,
            ];
        }

        return new Decision(
            ok: true,
            threshold: $flow->threshold(),
            branch: $branch === null ? null : $route->choice,
            do: $branch['do'] ?? null,
            instructions: $branch['instructions'] ?? null,
            branchConfidence: (float) $route->confidence,
            branchProbabilities: $route->probabilities,
            language: $language->choice,
            languageConfidence: (float) $language->confidence,
            requestedLanguage: $requested->choice === 'none' ? null : $requested->choice,
            requestedLanguageConfidence: (float) $requested->confidence,
            faq: $faq->choice === 'none' || $flow->faqEntry($faq->choice) === null ? null : $faq->choice,
            faqConfidence: (float) $faq->confidence,
            item: $itemKind,
            productId: $productId,
            productName: $productId === null ? null : $this->menu->name($productId),
            itemConfidence: (float) $item->confidence,
            options: $options,
            quantity: match ($quantity->choice) {
                'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five_or_more' => 5, default => null,
            },
            modification: (float) $result->noul('modification')->noul,
            orderTime: $time->choice === 'not_mentioned' ? null : $time->choice,
            logistics: $logistics->choice === 'not_mentioned' ? null : $logistics->choice,
            productRequest: $productRequest === null || $productRequest->choice === 'none' ? null : $productRequest->choice,
            productRequestConfidence: (float) ($productRequest?->confidence ?? 0.0),
            itemProbabilities: $itemProbabilities,
            state: $state,
            removeLines: $removeLines,
            tokens: $result->inputTokens + $result->outputTokens,
            seconds: $result->seconds,
        );
    }
}
