<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Plugins\Whatsapp\AI\AnthropicClient;
use Plugins\Whatsapp\AI\MarvinTools;
use Plugins\Whatsapp\AI\MarvinTool;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Helpers\CustomerHelper;
use Throwable;

/**
 * Marvin. Takes a conversation (your <phone>.json file, already decoded) and
 * returns the text to send back on WhatsApp.
 *
 * Everything Marvin needs comes from the secret config's "marvin" block:
 *
 *   "marvin": {
 *     "config":  "/home/marvin.json",     <- menu + tunables, hot-editable
 *     "prompts": "/home/marvin.v1.txt"    <- the prompt text
 *   }
 *
 * Both live outside the code tree, so the prompt and the menu can change
 * without a redeploy. /home/marvin.json is either a bare menu array or an
 * object:
 *
 *   {
 *     "menu": [ { "name": "Vegi Max", ... } ],
 *     "fallback": "Sorry, ...",
 *     "max_messages": 20,
 *     "cache_ttl": "1h"
 *   }
 *
 * Three responsibilities:
 *
 *  1. Assemble the system prompt — the prompt text with the menu substituted —
 *     and mark it cacheable. The bytes must be identical on every request or
 *     the cache never warms, so nothing per-shopper or time-varying goes here.
 *  2. Map your message log onto the API's messages array:
 *     direction "in" -> role "user", direction "out" -> role "assistant".
 *  3. Run the tool-use loop. While the API answers with stop_reason "tool_use",
 *     execute what was asked for and hand the results back. See MarvinTools.
 *
 * reply() never throws. A WhatsApp thread should degrade to the fallback line
 * rather than go silent, so callers can use the return value unconditionally.
 */
final class Marvin
{
    /** Opus 4.8 silently ignores cache_control below this many tokens. */
    private const CACHE_MIN_TOKENS = 1024;

    private const STALE = [
        MarvinTool::GetUsualForUser->value => '[Told them their usual. Details omitted — look it up again if asked.]',
        MarvinTool::TrackOrder->value           => '[Reported delivery status. Details omitted — look it up again if asked.]',
        MarvinTool::GreetWithUsual->value            => '[Greeted them and offered their usual. Details omitted — look it up again if asked.]',
    ];

    /**
     * Ceiling on tool round trips for one shopper message. A well-behaved turn
     * uses one. More than a couple means the model is looping, and a shopper
     * waiting on WhatsApp would rather have the fallback than a 40s silence.
     */
    private const MAX_TOOL_TURNS = 4;

    private const DEFAULT_FALLBACK =
        'Sorry, I can\'t help you right now. A colleague will help you further: https://wa.me/ruvenss';

    private ?string $systemText = null;

    /** @var array<string,mixed>|null */
    private ?array $settings = null;

    public function __construct(
        private readonly AnthropicClient $client,
        private readonly MarvinTools $tools,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    // ---------------------------------------------------------------- replying

    /**
     * Answer the latest message in this conversation.
     *
     * The inbound message is expected to already be the last entry in
     * $conversation — the controller calls registerMessage() before this.
     *
     * The loop's intermediate turns are deliberately NOT written back to
     * <phone>.json. That log is the shopper-visible transcript, and a persisted
     * tool_use block that lost its matching tool_result is a hard API error on
     * the next message.
     *
     * @param array<string,mixed> $conversation decoded <phone>.json
     * @param string|null $phone from the gateway envelope. Pass it explicitly:
     *                    it is the only identity the tools can trust.
     */
    public function reply(array $conversation, ?string $phone = null): array
    {
        $this->tools->reset();

        $fallback = ['type' => 'text', 'message' => $this->fallback()];

        try {
            $messages = $this->history($conversation);

            if ($messages === []) {
                $this->logger->warning('marvin: no usable history, nothing to answer', []);

                return $fallback;
            }

            $phone ??= CustomerHelper::phoneOf($conversation);
            
            if($phone === '') {
                $this->logger->warning('marvin: no phone number in conversation', []);
                throw new ApiException('No phone number in conversation, cannot run tools.');
            }

            $system = $this->systemBlocks();
            $tools  = $this->tools->definitions();


            for ($turn = 1; $turn <= self::MAX_TOOL_TURNS; $turn++) {
                $body = $this->client->messages($messages, $system, $tools);


                if (($body['stop_reason'] ?? null) !== 'tool_use') {
                    // The only exit that carries an answer. $this->tracking was
                    // set on the previous pass, if a tool produced one.
                    return $this->replyFrom($body);
                }

                $content = is_array($body['content'] ?? null) ? $body['content'] : [];

                // The assistant turn goes back VERBATIM — text blocks and
                // tool_use blocks together. Appending only the text is the
                // usual way to break this.
                $messages[] = ['role' => 'assistant', 'content' => $this->normaliseToolUse($content)];

                $results = [];
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                        $results[] = $this->tools->run($block, $phone);
                    }
                }

                if ($results === []) {
                    $this->logger->warning('marvin: tool_use with no tool_use blocks', []);

                    return $fallback;
                }

                // ALL results in ONE user message. Splitting them across two
                // messages is a structure error, not a soft failure.
                $messages[] = ['role' => 'user', 'content' => $results];
            }

            $this->logger->warning('marvin: tool loop exhausted', [
                'turns' => self::MAX_TOOL_TURNS,
            ]);

            return $fallback;
        } catch (Throwable $e) {
            $this->logger->warning('marvin: reply failed', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    /**
     * Shape the outgoing reply. Type is "tracking" when a tool produced a
     * delivery view during this turn, so the controller knows to follow the text
     * with a location message.
     *
     * @param array<string,mixed> $body the final API response
     * @return array{type: string, message: string, tracking?: array<string,mixed>}
     */
     private function replyFrom(array $body): array
    {
        $message    = $this->textOf($body);
        $attachment = $this->tools->attachment();

        if ($attachment === null) {
            return ['type' => 'text', 'message' => $message];
        }

        $keys = array_keys($attachment);
        $dataKey = end($keys);

        return ['type' => $attachment['type'], 'message' => $message, $dataKey => $attachment[$dataKey]];
    }
    
   
    /** Concatenate the text blocks of a response, or fall back. */
    private function textOf(array $body): string
    {
        $text = '';
        foreach (($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $text = trim($text);

        if ($text === '') {
            $this->logger->warning('marvin: empty reply', [
                'stop_reason' => $body['stop_reason'] ?? null,
            ]);

            return $this->fallback();
        }

        return $text;
    }

    // ------------------------------------------------------------ the prompt

    /**
     * The cached system block.
     *
     * One block, one cache breakpoint at the end. Everything that varies per
     * shopper or per minute must go in the messages array instead — a single
     * changed byte here costs a cache write on every conversation.
     *
     * The tools array sits in the same cached prefix, ahead of this block, so
     * the breakpoint covers both. See MarvinTools::definitions().
     *
     * @return list<array<string,mixed>>
     */
    public function systemBlocks(): array
    {
        $cacheControl = ['type' => 'ephemeral'];

        $ttl = $this->setting('cache_ttl', '1h');
        if (is_string($ttl) && $ttl !== '') {
            $cacheControl['ttl'] = $ttl;
        }

        return [[
            'type'          => 'text',
            'text'          => $this->systemText(),
            'cache_control' => $cacheControl,
        ]];
    }

    /** The prompt text with the menu substituted where $jsondominos_menu was. */
    public function systemText(): string
    {
        if ($this->systemText !== null) {
            return $this->systemText;
        }

        $path = $this->promptPath();

        $template = @file_get_contents($path);
        if ($template === false) {
            throw new ApiException("Cannot read marvin.prompts file: {$path}");
        }

        if (!str_contains($template, '{{MENU_JSON}}')) {
            throw new ApiException(
                "Prompt file {$path} is missing the {{MENU_JSON}} placeholder — "
                . 'Marvin would have no menu to answer from.'
            );
        }

        return $this->systemText = str_replace('{{MENU_JSON}}', $this->menuJson(), $template);
    }

    /** Derived from the prompt filename, so versions stay traceable in logs. */
    public function promptVersion(): string
    {
        return basename($this->promptPath(), '.txt');
    }

    private function promptPath(): string
    {
        $path = $this->config->secret('marvin.prompts');

        if(!defined("shop_id") || is_numeric(shop_id)){
            throw new ValidationException(["shop id" => "Shop Id must be a numeric value!"]);
        }

        $path = str_replace("{{shop_id}}", (string)shop_id, $path);
        
        if (!is_string($path) || trim($path) === '') {
            throw new ApiException('marvin.prompts is not set in the secret config.');
        }

        return $path;
    }

    // -------------------------------------------------------------- the menu

    /**
     * The menu, encoded deterministically.
     *
     * Byte-stability is the whole game. Sort by a fixed key and round prices
     * before writing marvin.json — a float that serialises as 11.989999999999999
     * one call and 11.99 the next is a silent cache miss. Strip ids and
     * timestamps: they cost tokens, and a moving updated_at invalidates the
     * cache for nothing.
     */
    private function menuJson(): string
    {
        $menu = $this->menu();

        if ($menu === []) {
            throw new ApiException(
                'Marvin has an empty menu — check the "menu" key in ' . $this->configPath()
            );
        }

        return json_encode(
            $menu,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<mixed> */
    public function menu(): array
    {
        $settings = $this->settings();

        // Accept either a bare array (the file IS the menu) or { "menu": [...] }.
        if (array_is_list($settings)) {
            return $settings;
        }

        $menu = $settings['menu'] ?? [];

        return is_array($menu) ? $menu : [];
    }

    // ------------------------------------------------------- marvin.json load

    private function configPath(): string
    {
        $path = $this->config->secret('marvin.config');

        if (!is_string($path) || trim($path) === '') {
            throw new ApiException('marvin.config is not set in the secret config.');
        }

        return $path;
    }

    /**
     * marvin.json, decoded and memoised for the request.
     *
     * @return array<string,mixed>
     */
    private function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $path = $this->configPath();

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ApiException("Cannot read marvin.config file: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiException("marvin.config is not valid JSON: {$path}");
        }

        return $this->settings = $decoded;
    }

    /**
     * A tunable from marvin.json, falling back to the secret config's marvin
     * block, then the default. Lets you keep the knob wherever it is handier.
     */
    private function setting(string $key, mixed $default): mixed
    {
        try {
            $settings = $this->settings();
        } catch (Throwable) {
            $settings = [];
        }

        if (!array_is_list($settings) && array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return $this->config->secret("marvin.{$key}", $default);
    }

    private function fallback(): string
    {
        $fallback = $this->setting('fallback', self::DEFAULT_FALLBACK);

        return is_string($fallback) && trim($fallback) !== ''
            ? $fallback
            : self::DEFAULT_FALLBACK;
    }

    // ------------------------------------------------------------- history

    /**
     * Map the message log onto the API's messages array.
     *
     * Rules the API enforces: the history cannot be empty and cannot open on an
     * assistant turn. It also must not END on one, or Marvin answers himself.
     * Non-text messages (images, audio) have no body in the gateway envelope,
     * so they are dropped rather than sent as blanks.
     *
     * The whole history is resent on every call, so max_messages is a direct
     * cost lever.
     *
     * The union in the return type is for reply()'s benefit: the entries this
     * method produces are always plain strings, but the tool loop appends turns
     * whose content is a list of blocks.
     *
     * @param array<string,mixed> $conversation
     * @return list<array{role: string, content: string|list<array<string,mixed>>}>
     */
    public function history(array $conversation): array
    {
        $messages = $conversation['data']['messages'] ?? null;
        if (!is_array($messages)) {
            return [];
        }

        $out = [];
        foreach ($messages as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $text = is_string($entry['message'] ?? null) ? trim($entry['message']) : '';
            if ($text === '') {
                continue;
            }

            $tool = $entry['source_tool'] ?? null;
            if (($entry['direction'] ?? 'in') === 'out' && is_string($tool)) {
                $text = self::STALE[$tool] ?? $text;
            }

            $out[] = [
                'role'    => ($entry['direction'] ?? 'in') === 'out' ? 'assistant' : 'user',
                'content' => $text,
            ];
        }

        $max = (int) $this->setting('max_messages', 20);
        if ($max > 0 && count($out) > $max) {
            $out = array_slice($out, -$max);
        }

        while ($out !== [] && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        while ($out !== [] && end($out)['role'] !== 'user') {
            array_pop($out);
        }

        return array_values($out);
    }

    // ------------------------------------------------------------ self check

    /**
     * Validate the whole setup without calling the API. Run this on deploy or
     * from a healthcheck: it catches unreadable paths, a missing placeholder,
     * an empty menu, and the failure that is otherwise invisible — a prompt too
     * short to cache, where cache_control is ignored and you pay full price on
     * every single call.
     *
     * The tool definitions are counted in, since they share the cached prefix.
     *
     * @return array{ok: bool, version?: string, model?: string, menu_items?: int,
     *               tools?: int, tokens?: int, cacheable?: bool, prompt_path?: string,
     *               config_path?: string, error?: string}
     */
    public function selfCheck(): array
    {
        try {
            $text   = $this->systemText();
            $text  .= json_encode(
                $this->tools->definitions(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            $tokens = (int) ceil($length / 3.6);

            if ($tokens < self::CACHE_MIN_TOKENS) {
                $this->logger->warning('marvin: prompt too short to cache', [
                    'tokens'  => $tokens,
                    'minimum' => self::CACHE_MIN_TOKENS,
                    'model'   => $this->client->model(),
                ]);
            }

            return [
                'ok'          => true,
                'version'     => $this->promptVersion(),
                'model'       => $this->client->model(),
                'menu_items'  => count($this->menu()),
                'tools'       => count($this->tools->definitions()),
                'tokens'      => $tokens,
                'cacheable'   => $tokens >= self::CACHE_MIN_TOKENS,
                'prompt_path' => $this->promptPath(),
                'config_path' => $this->configPath(),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * PHP's json_decode(..., true) turns {} into an empty array, and json_encode
     * turns an empty array back into []. The API requires tool_use.input to be an
     * object, so an argument-less call round-trips into a 400:
     *   messages.N.content.M.tool_use.input: Input should be an object
     * Cast it back before the turn goes out again.
     *
     * @param list<mixed> $content
     * @return list<mixed>
     */
    private function normaliseToolUse(array $content): array
    {
        foreach ($content as $i => $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                $content[$i]['input'] = (object) $input;
            }
        }

        return $content;
    }
}