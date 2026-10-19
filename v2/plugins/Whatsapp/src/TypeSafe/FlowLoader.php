<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Plugins\Whatsapp\AI\MarvinTool;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;

/**
 * Builds a shop's Flow: the shared default flow (in the repo, used by every
 * shop) merged with the shop's own flow.json, if it has one.
 *
 *   plugins/Whatsapp/flows/default.flow.json      every shop
 *   typesafe.flow_path, default
 *   /home/demo.data/{{shop_id}}/flow.json          optional, only what differs
 *
 * Merge rules for the shop file:
 *   settings, languages, handoff  keys override the default's
 *   router, faq                   merged by "id": an existing id is updated
 *                                 field by field, a new id is added, and
 *                                 "disabled": true removes a shared entry
 *   texts                         merged by key, then by language
 *
 * A shop file that is broken (bad JSON, unknown keys, failed validation) never
 * takes the shop down: the errors are logged and the shop gets the default
 * flow. The default flow itself must be valid; a test checks it, and a broken
 * one throws.
 */
final class FlowLoader
{
    private const array TOP_LEVEL_KEYS = ['settings', 'languages', 'router', 'faq', 'handoff', 'texts'];
    /** marvin: Marvin with only the branch's tools; reply: one line, no tools; fallback: the fixed reply. */
    private const array DO_ACTIONS = ['faq', 'language', 'handoff', 'marvin', 'reply', 'fallback'];
    private const array ANSWER_SOURCES = ['stores'];

    /** The ways an FAQ entry can be answered; an entry has exactly one. */
    private const array ANSWER_KEYS = ['answer', 'facts', 'do', 'answer_from'];

    /** Transcript-only "tools" that are not real Marvin tools. */
    private const array NOT_TOOLS = ['idle_reminder', 'checkout_completed', 'order_lost'];

    /** @var array<int, Flow> */
    private array $flows = [];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /** The flow for a shop; the current request's shop when no id is given. */
    public function forShop(?int $shopId = null): Flow
    {
        $shopId ??= $this->currentShopId();

        return $this->flows[$shopId] ??= $this->load($shopId);
    }

    /**
     * Validation errors for a shop flow file merged with the default, for a
     * CLI check before uploading a file. An empty list means it is valid.
     *
     * @return list<string>
     */
    public function check(string $shopFlowPath): array
    {
        $default = $this->readJson($this->defaultPath());
        $shop = $this->readJson($shopFlowPath);
        if ($shop === null) {
            return ["{$shopFlowPath}: not readable or not valid JSON"];
        }

        return $this->validate($this->merge($default ?? [], $shop), $shop);
    }

    private function load(int $shopId): Flow
    {
        $default = $this->readJson($this->defaultPath());
        $errors = $default === null ? ['not readable or not valid JSON'] : $this->validate($default);
        if ($errors !== []) {
            throw new TypeSafeException(
                'default flow is invalid: ' . implode('; ', $errors),
                'typesafe_flow_invalid',
                statusCode: 500,
            );
        }

        $path = $this->shopPath($shopId);
        if ($path === null || !is_file($path)) {
            return new Flow($shopId, $default, usesShopFile: false);
        }

        $shop = $this->readJson($path);
        $merged = $shop === null ? null : $this->merge($default, $shop);
        $errors = $merged === null ? ['not readable or not valid JSON'] : $this->validate($merged, $shop);

        if ($errors !== []) {
            $this->logger->error('typesafe: shop flow is invalid, using the default flow', [
                'shop_id' => $shopId,
                'path'    => $path,
                'errors'  => $errors,
            ]);

            return new Flow($shopId, $default, usesShopFile: false);
        }

        return new Flow($shopId, $merged, usesShopFile: true);
    }

    // ---------------------------------------------------------------- merge

    /**
     * @param array<string, mixed> $default
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function merge(array $default, array $shop): array
    {
        $merged = $default;

        foreach (['settings', 'languages', 'handoff'] as $key) {
            if (is_array($shop[$key] ?? null)) {
                $merged[$key] = array_merge($default[$key] ?? [], $shop[$key]);
            }
        }
        foreach (['router', 'faq'] as $key) {
            if (is_array($shop[$key] ?? null)) {
                $merged[$key] = $this->mergeById($default[$key] ?? [], $shop[$key]);
            }
        }
        if (is_array($shop['texts'] ?? null)) {
            foreach ($shop['texts'] as $textKey => $byLanguage) {
                $merged['texts'][$textKey] = array_merge(
                    $default['texts'][$textKey] ?? [],
                    is_array($byLanguage) ? $byLanguage : [],
                );
            }
        }

        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $base
     * @param list<mixed>                 $changes
     * @return list<array<string, mixed>>
     */
    private function mergeById(array $base, array $changes): array
    {
        $entries = [];
        foreach ($base as $entry) {
            $entries[(string) $entry['id']] = $entry;
        }

        foreach ($changes as $change) {
            if (!is_array($change) || !isset($change['id'])) {
                continue; // reported by validate()
            }
            $id = (string) $change['id'];
            if (($change['disabled'] ?? false) === true) {
                unset($entries[$id]);
                continue;
            }
            unset($change['disabled']);
            $base = $entries[$id] ?? [];
            // An entry answers in one way only: a shop that sets a new way
            // (e.g. answer_from instead of do) replaces the shared one.
            if (array_intersect(self::ANSWER_KEYS, array_keys($change)) !== []) {
                $base = array_diff_key($base, array_flip(self::ANSWER_KEYS));
            }
            $entries[$id] = array_merge($base, $change);
        }

        return array_values($entries);
    }

    // ---------------------------------------------------------------- validate

    /**
     * @param array<string, mixed>      $flow merged flow
     * @param array<string, mixed>|null $shop the shop file as written, to report unknown keys
     * @return list<string>
     */
    private function validate(array $flow, ?array $shop = null): array
    {
        $errors = [];

        foreach (array_keys($shop ?? []) as $key) {
            if (!in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = "unknown top-level key '{$key}'";
            }
        }
        foreach (['router', 'faq'] as $key) {
            foreach ((array) ($shop[$key] ?? []) as $i => $entry) {
                if (!is_array($entry) || !isset($entry['id'])) {
                    $errors[] = "{$key}[{$i}] has no id";
                }
            }
        }

        $threshold = $flow['settings']['threshold'] ?? null;
        if (!is_int($threshold) && !is_float($threshold) || $threshold < 0 || $threshold > 1) {
            $errors[] = 'settings.threshold must be a number from 0 to 1';
        }
        $lead = $flow['settings']['min_lead'] ?? 0.15;
        if (!is_int($lead) && !is_float($lead) || $lead < 0 || $lead > 1) {
            $errors[] = 'settings.min_lead must be a number from 0 to 1';
        }
        $turns = $flow['settings']['history_turns'] ?? null;
        if (!is_int($turns) || $turns < 0 || $turns > 20) {
            $errors[] = 'settings.history_turns must be a whole number from 0 to 20';
        }

        $supported = $flow['languages']['supported'] ?? null;
        $default = $flow['languages']['default'] ?? null;
        if (!is_array($supported) || $supported === [] || !array_is_list($supported)
            || array_filter($supported, fn($l) => !$this->isLanguage($l)) !== []) {
            $errors[] = 'languages.supported must be a list of two-letter codes';
        } elseif (!$this->isLanguage($default) || !in_array($default, $supported, true)) {
            $errors[] = 'languages.default must be one of languages.supported';
        }

        foreach (['save_min_confidence'] as $key) {
            $value = $flow['languages'][$key] ?? 0.8;
            if (!is_int($value) && !is_float($value) || $value < 0 || $value > 1) {
                $errors[] = "languages.{$key} must be a number from 0 to 1";
            }
        }
        if (!is_int($flow['languages']['save_min_words'] ?? 3) || ($flow['languages']['save_min_words'] ?? 3) < 0) {
            $errors[] = 'languages.save_min_words must be a whole number';
        }

        $router = $flow['router'] ?? null;
        if (!is_array($router) || $router === []) {
            $errors[] = 'router must have at least one branch';
        } else {
            $this->validateEntries($router, 'router', 'description', $flow, $errors);
            foreach ($router as $branch) {
                if (!$this->isValidDo($branch['do'] ?? null)) {
                    $errors[] = "router '{$branch['id']}': unknown do '" . json_encode($branch['do'] ?? null) . "'";
                }
                $this->validateTools($branch, $errors);
            }
        }

        $faq = $flow['faq'] ?? [];
        if (!is_array($faq)) {
            $errors[] = 'faq must be a list';
        } else {
            $this->validateEntries($faq, 'faq', 'match', $flow, $errors);
            foreach ($faq as $entry) {
                $this->validateFaqAnswer($entry, $flow, $errors);
            }
        }

        if (isset($flow['handoff']['answer'])) {
            $this->requireText($flow, (string) $flow['handoff']['answer'], 'handoff.answer', $errors);
        }

        foreach ((array) ($flow['texts'] ?? []) as $key => $byLanguage) {
            if (!is_array($byLanguage) || $byLanguage === []
                || array_filter($byLanguage, static fn($t) => !is_string($t) || trim($t) === '') !== []) {
                $errors[] = "texts.{$key} must map languages to non-empty text";
            }
        }

        return $errors;
    }

    /**
     * Shared checks for router and faq entries.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<string>               $errors
     * @param array<string, mixed>       $flow
     */
    private function validateEntries(array $entries, string $section, string $textField, array $flow, array &$errors): void
    {
        $seen = [];
        foreach ($entries as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if (preg_match('/^[a-z0-9_]+$/', $id) !== 1) {
                $errors[] = "{$section} id '{$id}' must be lowercase letters, digits and _";
            }
            if (isset($seen[$id])) {
                $errors[] = "{$section} id '{$id}' is used twice";
            }
            $seen[$id] = true;
            if (!is_string($entry[$textField] ?? null) || trim($entry[$textField]) === '') {
                $errors[] = "{$section} '{$id}' needs a {$textField}";
            }
            if (isset($entry['instructions']) && (!is_string($entry['instructions']) || trim($entry['instructions']) === '')) {
                $errors[] = "{$section} '{$id}': instructions must be text";
            }
        }
        if (count($entries) > 250) {
            $errors[] = "{$section} has more than 250 entries";
        }
    }

    /**
     * An FAQ entry answers in exactly one way: a fixed text, facts for Marvin
     * to word, a tool, or shop data.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $flow
     * @param list<string>         $errors
     */
    private function validateFaqAnswer(array $entry, array $flow, array &$errors): void
    {
        $id = (string) ($entry['id'] ?? '');
        $ways = array_values(array_intersect(self::ANSWER_KEYS, array_keys($entry)));
        if (count($ways) !== 1) {
            $errors[] = "faq '{$id}' needs exactly one of answer, facts, do, answer_from";

            return;
        }

        $way = $ways[0];
        if ($way === 'answer' || $way === 'facts') {
            $this->requireText($flow, (string) $entry[$way], "faq '{$id}'", $errors);
        } elseif ($way === 'do' && (!str_starts_with((string) $entry['do'], 'tool:') || !$this->isValidDo($entry['do']))) {
            $errors[] = "faq '{$id}': do must be a tool, e.g. tool:get_ordering_hours";
        } elseif ($way === 'answer_from' && !in_array($entry['answer_from'], self::ANSWER_SOURCES, true)) {
            $errors[] = "faq '{$id}': answer_from must be one of: " . implode(', ', self::ANSWER_SOURCES);
        }

        if (isset($entry['ask'])) {
            $ask = $entry['ask'];
            if (!is_array($ask) || !isset($ask['question'])) {
                $errors[] = "faq '{$id}': ask needs a question";
            } else {
                $this->requireText($flow, (string) $ask['question'], "faq '{$id}' ask", $errors);
                if (isset($ask['options_from']) && !in_array($ask['options_from'], self::ANSWER_SOURCES, true)) {
                    $errors[] = "faq '{$id}': ask.options_from must be one of: " . implode(', ', self::ANSWER_SOURCES);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $flow
     * @param list<string>         $errors
     */
    private function requireText(array $flow, string $key, string $where, array &$errors): void
    {
        $texts = $flow['texts'][$key] ?? null;
        $default = $flow['languages']['default'] ?? 'en';
        if (!is_array($texts)) {
            $errors[] = "{$where}: text '{$key}' does not exist in texts";
        } elseif (!isset($texts[$default]) && !isset($texts['en'])) {
            $errors[] = "{$where}: text '{$key}' has no '{$default}' or 'en' version";
        }
    }

    /**
     * "tools": the tools Marvin may call for this branch. Required for
     * do "marvin"; on a tool:<name> branch they are used when code cannot
     * fill the input itself (e.g. a pickup time other than as soon as possible).
     *
     * @param array<string, mixed> $branch
     * @param list<string>         $errors
     */
    private function validateTools(array $branch, array &$errors): void
    {
        $id = (string) ($branch['id'] ?? '');
        $tools = $branch['tools'] ?? null;
        if ($tools === null) {
            if (($branch['do'] ?? null) === 'marvin') {
                $errors[] = "router '{$id}': do marvin needs a list of tools";
            }

            return;
        }
        if (!is_array($tools) || !array_is_list($tools) || $tools === []) {
            $errors[] = "router '{$id}': tools must be a non-empty list of tool names";

            return;
        }
        foreach ($tools as $tool) {
            if (!is_string($tool) || !$this->isValidDo("tool:{$tool}")) {
                $errors[] = "router '{$id}': unknown tool " . json_encode($tool);
            }
        }
    }

    private function isValidDo(mixed $do): bool
    {
        if (!is_string($do)) {
            return false;
        }
        if (in_array($do, self::DO_ACTIONS, true)) {
            return true;
        }
        if (!str_starts_with($do, 'tool:')) {
            return false;
        }
        $tool = MarvinTool::tryFrom(substr($do, 5));

        return $tool !== null && !in_array($tool->value, self::NOT_TOOLS, true);
    }

    private function isLanguage(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[a-z]{2}$/', $code) === 1;
    }

    // ---------------------------------------------------------------- files

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        // {} decodes to [], which is a valid (empty) flow file, not a list.
        return is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : null;
    }

    private function defaultPath(): string
    {
        return dirname(__DIR__, 2) . '/flows/default.flow.json';
    }

    private function shopPath(int $shopId): ?string
    {
        $template = $this->config->secret('typesafe.flow_path', '/home/demo.data/{{shop_id}}/flow.json');

        return is_string($template) && $template !== '' ? str_replace('{{shop_id}}', (string) $shopId, $template) : null;
    }

    private function currentShopId(): int
    {
        if (!defined('shop_id') || !is_numeric(shop_id)) {
            throw new TypeSafeException('no shop_id for this request', 'typesafe_no_shop', statusCode: 500);
        }

        return (int) shop_id;
    }
}
