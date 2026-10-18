<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Plugins\Whatsapp\AI\MarvinTool;
use Plugins\Whatsapp\AI\ShopBackground;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;

/**
 * Turns one shopper message into a Decision with a single TypeSafe call.
 *
 * The questions come from the shop's Flow (router branches, FAQs, languages),
 * so the same code serves every shop. TypeSafe decides what to do; which
 * items, how many and exact times are read by Marvin (with only the tools the
 * branch allows), so there are no menu questions here. All questions are
 * asked in parallel about the same state; pickup/delivery and "as soon as
 * possible" are asked every time and simply come back "not mentioned".
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
        private readonly StateBuilder $states,
        private readonly ShopBackground $shopBackground,
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

        try {
            return $this->decideFor($state, $flow);
        } catch (\Throwable $e) {
            // Boundary: an unexpected error must still give the shopper the fallback reply, not silence.
            $this->logger->error('typesafe: unexpected decision error', ['error' => $e->getMessage(), 'class' => $e::class]);

            return Decision::fallback('decision_error', $flow->threshold());
        }
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
            $result = $this->client->ask($state, $this->questions($flow));
            $decision = $this->read($result, $flow);
        } catch (TypeSafeException $e) {
            $this->logger->warning('typesafe: decision failed', [
                'code'  => $e->errorCode(),
                'error' => $e->getMessage(),
            ]);

            return Decision::fallback($e->errorCode(), isset($flow) ? $flow->threshold() : 0.6);
        } catch (ApiException $e) {
            $this->logger->warning('typesafe: decision data unavailable', ['error' => $e->getMessage()]);

            return Decision::fallback('data_unavailable', isset($flow) ? $flow->threshold() : 0.6);
        }

        $this->logger->info('typesafe: decision', $decision->summary());

        return $decision;
    }

    // ---------------------------------------------------------------- questions

    /** @return array<string, array<string, mixed>> */
    private function questions(Flow $flow): array
    {
        $faq = [];
        foreach ($flow->faq() as $entry) {
            // Answered from the shop's background page: only offered when the shop has one.
            if (($entry['do'] ?? null) === 'tool:' . MarvinTool::ShopBackground->value && !$this->shopBackground->isConfigured()) {
                continue;
            }
            $faq[$entry['id']] = $entry['match'];
        }

        // A faq branch is only as wide as the FAQs this shop can answer: it
        // lists them, so a question nothing answers goes elsewhere instead of
        // ending in a FAQ with no answer. No FAQs at all: no faq branch.
        $router = [];
        foreach ($flow->router() as $branch) {
            if (($branch['do'] ?? null) === 'faq') {
                if ($faq === []) {
                    continue;
                }
                $router[$branch['id']] = rtrim($branch['description'], '. ') . ': ' . implode('; ', $faq);
                continue;
            }
            $router[$branch['id']] = $branch['description'];
        }
        $hasFaq = $faq !== [];
        $faq['none'] = 'The message is not one of these questions';

        $languages = [];
        foreach ($flow->languages() as $code) {
            $languages[$code] = self::LANGUAGE_NAMES[$code] ?? $code;
        }

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
            // Only asked when the shop has FAQs: a choice with just "none" says nothing.
            'faq' => !$hasFaq ? null : [
                'type' => 'choice',
                'instructions' => 'Which of these questions does the shopper ask in `latest_message`?',
                'criteria' => $faq,
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

        return array_filter($questions, static fn(?array $q): bool => $q !== null);
    }

    private function read(TypeSafeResult $result, Flow $flow): Decision
    {
        $route = $result->choice('route');
        $branch = $flow->branch($route->choice);

        $language = $result->choice('language');
        $requested = $result->choice('requested_language');
        $faq = $result->has('faq') ? $result->choice('faq') : null;
        $time = $result->choice('order_time');
        $logistics = $result->choice('logistics');

        return new Decision(
            ok: true,
            threshold: $flow->threshold(),
            branch: $branch === null ? null : $route->choice,
            do: $branch['do'] ?? null,
            tools: array_values(array_map('strval', (array) ($branch['tools'] ?? []))),
            instructions: $branch['instructions'] ?? null,
            branchConfidence: (float) $route->confidence,
            branchProbabilities: $route->probabilities,
            language: $language->choice,
            languageConfidence: (float) $language->confidence,
            requestedLanguage: $requested->choice === 'none' ? null : $requested->choice,
            requestedLanguageConfidence: (float) $requested->confidence,
            faq: $faq === null || $faq->choice === 'none' || $flow->faqEntry($faq->choice) === null ? null : $faq->choice,
            faqConfidence: (float) ($faq?->confidence ?? 0.0),
            orderTime: $time->choice === 'not_mentioned' ? null : $time->choice,
            logistics: $logistics->choice === 'not_mentioned' ? null : $logistics->choice,
            tokens: $result->inputTokens + $result->outputTokens,
            seconds: $result->seconds,
        );
    }
}
