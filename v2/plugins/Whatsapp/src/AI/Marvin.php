<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Plugins\Whatsapp\AI\AnthropicClient;
use Plugins\Whatsapp\AI\MarvinTools;
use Plugins\Whatsapp\AI\MarvinTool;
use Plugins\Whatsapp\Support\LanguageHelper;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Helpers\CustomerHelper;
use Pmsrapi\V2\Services\MenuService;
use Pmsrapi\V2\Services\JsonService;
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

    // holds the shop info for the current request, including name, and stores
    // This would be better in a company info service, but for now we will set it here until we have a better place to put it
    private array $shopInfo = [];

    private string $clientName = "";

    /** Set from reply()'s $language argument; drives fallback()'s translated text only — never the cached system prompt. */
    private string $replyLanguage = 'en';

    private const STALE = [
        MarvinTool::GetUsualForUser->value => '[Told them their usual. Details omitted — look it up again if asked.]',
        MarvinTool::TrackOrder->value           => '[Reported delivery status. Details omitted — look it up again if asked.]',
        MarvinTool::GreetWithUsual->value            => '[Greeted them and offered their usual. Details omitted — look it up again if asked.]',
        MarvinTool::GetCart->value              => '[Read back their cart/order. Details omitted — look it up again if asked.]',
        MarvinTool::DetectLanguage->value       => '[Detected language. Details omitted — look it up again if asked.]',
    ];

    /**
     * Ceiling on tool round trips for one shopper message. A well-behaved turn
     * uses one. More than a couple means the model is looping, and a shopper
     * waiting on WhatsApp would rather have the fallback than a 40s silence.
     */
    private const MAX_TOOL_TURNS = 4;

    private ?string $systemText = null;

    /** @var array<string,mixed>|null */
    private ?array $settings = null;

    public function __construct(
        private readonly AnthropicClient $client,
        private readonly MarvinTools $tools,
        private readonly MenuService $menuService,
        private readonly JsonService $jsonService,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly LanguageHelper $language,
    ) {}

    // ---------------------------------------------------------------- replying

    /**
     * Answer the latest message in this conversation.
     *
     * The inbound message is expected to already be the last entry in
     * $conversation — the controller logs it via ChatTranscriptService before this.
     *
     * The loop's intermediate turns are deliberately NOT written back to
     * <phone>.json. That log is the shopper-visible transcript, and a persisted
     * tool_use block that lost its matching tool_result is a hard API error on
     * the next message.
     *
     * @param array<string,mixed> $conversation decoded <phone>.json
     * @param string|null $phone from the gateway envelope. Pass it explicitly:
     *                    it is the only identity the tools can trust.
     * @param string|null $language ISO 639-1 code the shopper is detected to be
     *                    writing in (see LanguageHelper). Only ever picks which
     *                    translated string fallback() returns — it is NOT sent
     *                    to Claude, so it cannot affect systemBlocks() (which
     *                    must stay byte-identical across shoppers for the
     *                    prompt cache to warm) or the messages array.
     */
    public function reply(array $conversation, array $shopInfo, ?string $clientName = "", ?string $phone = null, ?string $language = null): array
    {
        $this->updateShopInfo($shopInfo);

        $this->clientName = $clientName ?? "";
        $this->replyLanguage = ($language !== null && $language !== '') ? $language : 'en';

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

        //Marvin prompt requries shop name and address 
        if(!isset($this->shopInfo["stores"]) || empty($this->shopInfo["stores"])){
            throw new ApiException("No stores found in shop info to build prompt!");
        }

        //Marvin prompt requries shop name and address 
        if(!isset($this->shopInfo["name"]) || trim($this->shopInfo["name"]) === ""){
            throw new ApiException("Shop name required to build prompt!");
        }

        if(!isset($this->shopInfo["street"]) || trim($this->shopInfo["street"]) === ""){
            throw new ApiException("Shop address required to build prompt!");
        }

        //build the address
        // $address = isset($this->shopInfo["country"]) ? $this->shopInfo["country"] . ", " : " ";
        // $address .= isset($this->shopInfo["city"]) ? $this->shopInfo["city"] . ", " : " ";
        // $address .= $this->shopInfo["street"] ?? " ";

        $locations = json_encode($this->shopInfo["stores"]);
    
        
        $this->systemText = str_replace('{{CLIENT_NAME}}', ucwords($this->clientName), $template);

        // company name will be the shop name in the demo version
        $this->systemText = str_replace('{{COMPANY_NAME}}', strtoupper($this->shopInfo["name"]), $this->systemText);

        $this->systemText = str_replace('{{LOCATIONS}}', strtoupper($locations), $this->systemText);

        $this->systemText = str_replace('{{MENU_JSON}}', $this->menuJson(), $this->systemText);

        return $this->systemText;
    }

    /** Derived from the prompt filename, so versions stay traceable in logs. */
    public function promptVersion(): string
    {
        return basename($this->promptPath(), '.txt');
    }

    private function promptPath(): string
    {
        $path = $this->config->secret('marvin.prompts.main',);

        if(!defined("shop_id") || !is_numeric(shop_id)){
            throw new ValidationException(["shop id" => "Shop Id must be a numeric value!"]);
        }

        if (!is_string($path) || trim($path) === '') {
            throw new ApiException('marvin.prompts.main is not set in the secret config. Provided value: ' . var_export($path, true));
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
        $products = $this->menuService->promptProducts();

        if ($products === []) {
            throw new ApiException(
                'Marvin has an empty menu — check the "menu" key in ' . $this->configPath()
            );
        }

        return json_encode(
            [
                'products'  => $products,
                'campaigns' => $this->menuService->promptCampaigns(),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
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

        if(!defined("shop_id") || !is_numeric(shop_id)){
            throw new ValidationException(["shop id" => "shop id must be a numeric value!"]);
        }

        $path = $this->configPath();

        $path = str_replace("{{shop_id}}", (string) shop_id, $path);

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
        $override = $this->setting('fallback', null);
        
        $supps = $this->config->secret("support");

        [$support1, $support2] = array_values($supps);

        if (is_string($override) && trim($override) !== '') {
            return $override;
        }

        return $this->language->translate('marvin_fallback', $this->replyLanguage, ["support_1" => $support1, "support_2" => $support2]);
    }

    // ------------------------------------------------------------- history

    /**
     * Map the message log onto the API's messages array.
     *
     * Rules the API enforces: the history cannot be empty and cannot open on an
     * assistant turn. It also must not END on one, or Marvin answers himself.
     * It also requires roles to strictly alternate — two log entries can land
     * back to back with the same direction (a fact appended outside the normal
     * reply flow, e.g. a web checkout completing, logged right after Marvin's
     * own last "out" turn), so those are folded into one turn rather than sent
     * as two of the same role.
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

            $role = ($entry['direction'] ?? 'in') === 'out' ? 'assistant' : 'user';

            $last = $out === [] ? null : array_key_last($out);
            if ($last !== null && $out[$last]['role'] === $role) {
                $out[$last]['content'] .= "\n\n" . $text;
                continue;
            }

            $out[] = [
                'role'    => $role,
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
    public function selfCheck(array $shopData): array
    {
        try {
            $this->shopInfo = $shopData;
            
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
                'menu_items'  => count($this->menuService->index()),
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

    private function updateShopInfo(array $shopInfo): void
    {
        if(!isset($shopInfo["name"]) || trim($shopInfo["name"]) === ""){
            throw new ValidationException(["Shop name" => "Shop name is required to build prompt!"]);
        }

        if(!isset($shopInfo["street"]) || trim($shopInfo["street"]) === ""){
            throw new ValidationException(["Shop address" => "Shop address is required to build prompt!"]);
        }

        $this->shopInfo = $shopInfo;

        // Set stores in the shop info
        $stores = $this->jsonService->load("stores");

        // $stores = array_map(static function (array $store): array {
        //     if (isset($store["street"]) && trim($store["street"]) !== "") {
        //         $store["fullAddress"] = "Street: " . $store["street"] . " ";
        //         $store["fullAddress"] .= ", City: " . 
        //             (isset($store["city"]) ? $store["city"] : "");
        //     }

        //     if (isset($store["openingHours"])) {
        //         $store["openingHoursFormated"] = join(", ", array_map(
        //             static fn($day, $hours) => ucfirst($day) . ": " . $hours,
        //             array_keys($store["openingHours"]),
        //             $store["openingHours"],
        //         ));
        //     }

        //     return $store;
        // }, $stores);

        $this->shopInfo["stores"] = $stores;

    }
}