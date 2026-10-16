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
            [$questions, $maps] = $this->questions($flow);
            $result = $this->client->ask($state, $questions);
            $decision = $this->read($result, $flow, $maps);
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
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, string>>}
     *         questions, and per config question: option key => option label
     */
    private function questions(Flow $flow): array
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

        return [$questions, $maps];
    }

    // ---------------------------------------------------------------- answers

    /** @param array<string, array<string, string>> $maps */
    private function read(TypeSafeResult $result, Flow $flow, array $maps): Decision
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
        $itemKind = match (true) {
            $productId !== null          => Decision::ITEM_ONE,
            $item->choice === 'several'  => Decision::ITEM_SEVERAL,
            default                      => Decision::ITEM_NONE,
        };

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
            tokens: $result->inputTokens + $result->outputTokens,
            seconds: $result->seconds,
        );
    }
}
