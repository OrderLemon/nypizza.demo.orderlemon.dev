<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Pmsrapi\V2\Services\ShopService;
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
 *     "prompts": {
 *       "writer": "/home/…/marvin_writer.v1.txt",  <- words a reply, no tools, no menu
 *       "menu":   "/home/…/marvin_menu.v1.txt"     <- items with the full menu and the branch's tools
 *     }
 *   }
 *
 * TypeSafe decides what to do; Marvin never does. He either words the result
 * of what code ran (replyWith, writer prompt) or fills in the details with
 * only the tools the decision allows (replyWithTools, menu prompt). A missing
 * or broken prompt, like any other failure, gives the fallback reply.
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
        MarvinTool::GetUsualForUser->value => '(SYSTEM NOTE, never repeat this to the shopper: already told them their usual. Call the tool again if asked.)',
        MarvinTool::TrackOrder->value      => '(SYSTEM NOTE, never repeat this to the shopper: already reported delivery status. Call the tool again if asked.)',
        MarvinTool::GreetWithUsual->value  => '(SYSTEM NOTE, never repeat this to the shopper: already greeted them and offered their usual. Call the tool again if asked.)',
        MarvinTool::GetCart->value         => '(SYSTEM NOTE, never repeat this to the shopper: already read back their cart/order. Call the tool again if asked.)',
        MarvinTool::DetectLanguage->value  => '(SYSTEM NOTE, never repeat this to the shopper: already detected language. Call the tool again if asked.)',
        // Basket changes too: with the real "X added. Total: €8.75" line in the
        // history, "add another one" gets answered by copying that line and no
        // add_to_order call, so the shopper is told it was added when it wasn't.
        // It must also say the change is DONE: "every add needs a call" alone
        // read as "that add still has to happen", and a "yes" to the finishing
        // question re-added the same 4 items.
        MarvinTool::AddToOrder->value      => '(SYSTEM NOTE, never repeat this to the shopper: the item asked for above was added and is in the basket. Never add it again for that request. The total shown then is out of date. Only a new request from the shopper, including "another one" of the same item, needs a new add_to_order call.)',
        MarvinTool::RemoveFromOrder->value => '(SYSTEM NOTE, never repeat this to the shopper: the change asked for above was made and is in the basket. Never redo it. The total shown then is out of date. Only a new request from the shopper needs a new tool call.)',
        MarvinTool::ChangeLogistics->value => '(SYSTEM NOTE, never repeat this to the shopper: the pickup/delivery choice and time asked for above were saved on the basket. Never redo it. Only a new request from the shopper to change them again needs a new change_logistics call.)',
        // A fallback is not a real answer. Left in the history verbatim, a few
        // of them in a row teach Marvin to keep saying "I can't help you".
        self::FALLBACK_SOURCE              => '(SYSTEM NOTE, never repeat this to the shopper: a technical error stopped the reply here. It was not a real answer and says nothing about what you can do. The basket may have changed before the error, so call get_cart before changing it again. Answer the shopper\'s latest message normally.)',
    ];

    /**
     * Reply type and transcript source_tool of a fallback reply, so the
     * controller can log it with a marker and history() can staleify it.
     */
    public const FALLBACK_SOURCE = 'fallback';

    /**
     * Ceiling on requests for one shopper message. A well-behaved turn uses
     * one or two. The last request is sent with tools switched off, so it
     * always ends in an answer: by then tools may already have changed the
     * basket, and the fallback would leave the shopper unaware of that.
     */
    private const MAX_TOOL_TURNS = 5;

    private ?string $systemText = null;

    /** History turns the writer gets; enough for "yes" and "that one" to make sense. */
    private const int WRITER_HISTORY = 8;

    /** Writer prompt, placeholders filled. */
    private ?string $writerText = null;

    /** @var array<string,mixed>|null */
    private ?array $settings = null;

    public function __construct(
        private readonly AnthropicClient $client,
        private readonly MarvinTools $tools,
        private readonly MenuService $menuService,
        private readonly ShopService $shopService,
        private readonly JsonService $jsonService,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly LanguageHelper $language,
    ) {}

    // ---------------------------------------------------------------- replying

    /**
     * The loop's intermediate turns are deliberately NOT written back to
     * <phone>.json. That log is the shopper-visible transcript, and a persisted
     * tool_use block that lost its matching tool_result is a hard API error on
     * the next message. $phone comes from the gateway envelope: it is the only
     * identity the tools can trust.
     */

    /**
     * Word a reply for a decision TypeSafe already made (writer mode).
     *
     * $calls are run first, through the same MarvinTools::run() Marvin uses,
     * so the cart, tracking and the reply's buttons and links behave exactly
     * as if he had called them. Their results, the products they name and
     * $note go to Marvin as one internal note on the shopper's message, with
     * the short writer prompt (marvin.prompts.writer) and no tools: he only
     * words the reply.
     *
     * @param list<array{0: string, 1: array<string,mixed>}> $calls tool name and input, run in order
     */
    public function replyWith(
        array $conversation,
        ?string $clientName,
        ?string $phone,
        ?string $language,
        array $calls,
        ?string $note = null,
    ): array {
        return $this->respond($conversation, $clientName, $phone, $language, ['mode' => 'writer', 'calls' => $calls, 'note' => $note]);
    }

    /**
     * Reply to a decision TypeSafe made that needs details only Marvin can
     * read from the message: which items, how many, an exact time.
     *
     * Full prompt and menu, but only $tools (e.g. add_to_order for an order):
     * he chooses what goes in the call, never which action it is. A call to
     * any other tool is blocked and logged. $note tells him what was decided.
     *
     * @param list<string> $tools tool names he may call
     */
    public function replyWithTools(
        array $conversation,
        ?string $clientName,
        ?string $phone,
        ?string $language,
        array $tools,
        ?string $note = null,
    ): array {
        return $this->respond($conversation, $clientName, $phone, $language, ['mode' => 'tools', 'tools' => $tools, 'calls' => [], 'note' => $note]);
    }

    /**
     * The fixed "I can't help right now" reply (marvin_fallback, translated,
     * with the support contacts). No AI call.
     *
     * @return array{type: string, message: string}
     */
    public function fallbackReply(?string $language): array
    {
        $this->replyLanguage = ($language !== null && $language !== '') ? $language : 'en';

        return ['type' => self::FALLBACK_SOURCE, 'message' => $this->fallback()];
    }

    /**
     * @param array{mode: string, calls: list<array{0: string, 1: array<string,mixed>}>, note: ?string, tools?: list<string>} $seed
     * @return array<string,mixed>
     */
    private function respond(array $conversation, ?string $clientName, ?string $phone, ?string $language, array $seed): array
    {
        $this->updateShopInfo();

        $this->clientName = $clientName ?? "";
        $this->replyLanguage = ($language !== null && $language !== '') ? $language : 'en';

        $this->tools->reset();

        $fallback = ['type' => self::FALLBACK_SOURCE, 'message' => $this->fallback()];

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

            $model   = null;
            // Tools mode: only these tools; anything else he calls is blocked.
            $allowed = $seed['mode'] === 'tools' ? $seed['tools'] ?? [] : null;

            if ($seed['mode'] === 'writer') {
                // Writer mode: decided and done; Marvin only words the reply.
                // Short prompt, recent turns, results in a note, no tools.
                $system   = $this->writerSystemBlocks();
                $tools    = [];
                $model    = $this->config->secret('marvin.writer_model', null);
                $messages = $this->noteOnShopper(
                    $this->recent($messages, self::WRITER_HISTORY),
                    $this->runCalls($seed['calls'], $phone, (string) ($seed['note'] ?? '')),
                );
                $this->logger->info('marvin: writer reply', [
                    'calls'        => array_column($seed['calls'], 0),
                    'prompt_chars' => mb_strlen($system[0]['text']),
                    'model'        => $model,
                ]);
            } elseif ($allowed !== null) {
                $system   = $this->systemBlocks();
                $tools    = array_values(array_filter(
                    $this->tools->definitions(),
                    static fn(array $d): bool => in_array($d['name'] ?? '', $allowed, true),
                ));
                // The name goes in the note, not the prompt: the prompt (with the
                // menu) stays the same for every shopper, so its cache is shared.
                $name     = trim($this->clientName);
                $messages = $this->noteOnShopper($messages, $this->join(
                    $name === '' ? '' : "The customer's name is {$name}.",
                    (string) ($seed['note'] ?? ''),
                ));
                $this->logger->info('marvin: reply with tools', ['tools' => $allowed]);
            } else {
                throw new ApiException("Unknown reply mode: {$seed['mode']}");
            }

            // Text Marvin writes in a turn that also calls a tool (e.g. a
            // product list next to filter_products) is part of his answer:
            // the final turn often only continues it ("We've also got…").
            $earlier = [];

            for ($turn = 1; $turn <= self::MAX_TOOL_TURNS; $turn++) {
                $lastTurn = $turn === self::MAX_TOOL_TURNS;
                if ($lastTurn) {
                    $this->logger->warning('marvin: tool turns used up, forcing an answer', ['turns' => $turn - 1]);
                }

                $body = $this->client->messages($messages, $system, $tools, $lastTurn && $tools !== [] ? ['type' => 'none'] : null, is_string($model) ? $model : null);

                if (($body['stop_reason'] ?? null) !== 'tool_use') {
                    if ($allowed !== null && $turn === 1) {
                        // Allowed: he may need to ask ("which pizza?"). Logged so it shows how often.
                        $this->logger->info('marvin: no tool called', ['tools' => $allowed]);
                    }
                    // The only exit that carries an answer. $this->tracking was
                    // set on the previous pass, if a tool produced one.
                    return $this->replyFrom($body, implode("\n\n", $earlier));
                }

                $content = is_array($body['content'] ?? null) ? $body['content'] : [];

                foreach ($content as $block) {
                    $text = is_array($block) && ($block['type'] ?? '') === 'text' ? trim($this->stripStaleNotes((string) ($block['text'] ?? ''))) : '';
                    if ($text !== '') {
                        $earlier[] = $text;
                    }
                }

                // The assistant turn goes back VERBATIM — text blocks and
                // tool_use blocks together. Appending only the text is the
                // usual way to break this.
                $messages[] = ['role' => 'assistant', 'content' => $this->normaliseToolUse($content)];

                $results = [];
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                        // Without this a failed turn only says "loop exhausted",
                        // not what Marvin was actually trying to do.
                        $this->logger->info('marvin: tool call', [
                            'turn'  => $turn,
                            'tool'  => $block['name'] ?? null,
                            'input' => $block['input'] ?? null,
                        ]);
                        if ($allowed !== null && !in_array($block['name'] ?? '', $allowed, true)) {
                            $this->logger->warning('marvin: tool blocked', ['tool' => $block['name'] ?? null, 'allowed' => $allowed]);
                            $results[] = [
                                'type'        => 'tool_result',
                                'tool_use_id' => $block['id'] ?? '',
                                'content'     => 'Not available for this message. Do not try it again.',
                                'is_error'    => true,
                            ];
                            continue;
                        }
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
     * "language" is folded in independently of "type": MarvinTools keeps only
     * one attachment slot, so if detect_language ran alongside another tool in
     * the same turn (e.g. a language switch noticed while also fetching the
     * cart), that other tool's attachment wins the slot and would otherwise
     * silently drop the language switch — leaving the controller's cached
     * conversation language, and therefore this reply's buttons/captions,
     * stuck one turn behind even though the DB was already updated.
     *
     * @param array<string,mixed> $body    the final API response
     * @param string              $earlier text from earlier turns of this reply that also called a tool
     * @return array{type: string, message: string, language?: string, tracking?: array<string,mixed>}
     */
     private function replyFrom(array $body, string $earlier = ''): array
    {
        $message    = $this->textOf($body);
        if ($earlier !== '') {
            // An empty final turn falls back in textOf(); the earlier text is the answer then.
            $message = $message === $this->fallback() || str_starts_with($message, $earlier)
                ? ($message === $this->fallback() ? $earlier : $message)
                : $earlier . "\n\n" . $message;
        }
        $attachment = $this->tools->attachment();

        if ($attachment === null) {
            // textOf() falls back on an empty reply; mark that one too.
            $type  = $message === $this->fallback() ? self::FALLBACK_SOURCE : 'text';
            $reply = ['type' => $type, 'message' => $message];
        } else {
            $keys = array_keys($attachment);
            $dataKey = end($keys);

            $reply = ['type' => $attachment['type'], 'message' => $message, $dataKey => $attachment[$dataKey]];
        }

        $language = $this->tools->detectedLanguage();
        if ($language !== null) {
            $reply['language'] = $language;
        }

        return $reply;
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

        $text = trim($this->stripStaleNotes($text));

        if ($text === '') {
            $this->logger->warning('marvin: empty reply', [
                'stop_reason' => $body['stop_reason'] ?? null,
            ]);

            return $this->fallback();
        }

        return $text;
    }

    /**
     * Hard backstop for shopper-facing output. The prompt tells Marvin the
     * self::STALE placeholders (see history()) are internal notes he must never
     * repeat, but that is advisory only — a WhatsApp bot cannot rely on the
     * model always complying. Strip any that leak through before the reply is
     * sent, and log it so the leak rate is visible instead of silent.
     */
    private function stripStaleNotes(string $text): string
    {
        $stripped = preg_replace('/\(SYSTEM NOTE,.*?\)\s*/su', '', $text);

        if ($stripped === null) {
            return $text;
        }

        if ($stripped !== $text) {
            $this->logger->warning('marvin: stripped leaked SYSTEM NOTE from reply', []);
        }

        return $stripped;
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
        return [[
            'type'          => 'text',
            'text'          => $this->systemText(),
            'cache_control' => $this->cacheControl(),
        ]];
    }

    /** @return array<string,string> */
    private function cacheControl(): array
    {
        $cacheControl = ['type' => 'ephemeral'];

        $ttl = $this->setting('cache_ttl', '1h');
        if (is_string($ttl) && $ttl !== '') {
            $cacheControl['ttl'] = $ttl;
        }

        return $cacheControl;
    }

    /** The menu prompt (marvin.prompts.menu) with the menu and shop data filled in. */
    public function systemText(): string
    {
        if ($this->systemText !== null) {
            return $this->systemText;
        }

        $path = $this->promptPath();

        $template = is_readable($path) ? file_get_contents($path) : false;
        if ($template === false) {
            throw new ApiException("Cannot read marvin.prompts.menu file: {$path}");
        }

        if (!str_contains($template, '{{MENU_JSON}}')) {
            throw new ApiException(
                "Prompt file {$path} is missing the {{MENU_JSON}} placeholder — "
                . 'Marvin would have no menu to answer from.'
            );
        }

        //Marvin prompt requries shop name and address 
        if(!isset($this->shopInfo["name"]) || trim($this->shopInfo["name"]) === ""){
            throw new ApiException("Shop name required to build prompt!");
        }

        [$pickupHours, $deliveyHours] = $this->shopService->getLogisticHours();


        $supps = $this->config->secret("support");

        [$support1, $support2] = array_values($supps);

        $this->systemText = str_replace('{{SUPPORT_1}}', ucwords($support1), $template);
        $this->systemText = str_replace('{{SUPPORT_2}}', ucwords($support2), $this->systemText);

        $this->systemText = str_replace('{{PICKUP_HOURS}}', json_encode($pickupHours, JSON_THROW_ON_ERROR), $this->systemText);
        $this->systemText = str_replace('{{DELIVERY_HOURS}}', json_encode($deliveyHours, JSON_THROW_ON_ERROR), $this->systemText);

        // Changes once a day, so the cached prompt is rewritten at most daily.
        $lastOrderTimes = array_map(
            static fn(?string $time): string => $time ?? 'closed today',
            $this->shopService->lastOrderTimes(new \DateTimeImmutable()),
        );
        $this->systemText = str_replace('{{LAST_ORDER_TODAY}}', json_encode($lastOrderTimes, JSON_THROW_ON_ERROR), $this->systemText);
        
        $this->systemText = str_replace('{{CLIENT_NAME}}', ucwords($this->clientName), $this->systemText);

        // company name will be the shop name in the demo version
        $this->systemText = str_replace('{{COMPANY_NAME}}', $this->shopInfo["name"], $this->systemText);

        $this->systemText = str_replace('{{MENU_JSON}}', $this->menuJson(), $this->systemText);


        return $this->systemText;
    }
    

    /** Derived from the prompt filename, so versions stay traceable in logs. */
    public function promptVersion(): string
    {
        return basename($this->promptPath(), '.txt');
    }

    /** The menu prompt file: marvin.prompts.menu, one for every shop. */
    private function promptPath(): string
    {
        $path = $this->config->secret('marvin.prompts.menu');

        if (!is_string($path) || trim($path) === '') {
            throw new ApiException('marvin.prompts.menu is not set in the secret config');
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

        // The last-resort reply must never fail itself: any number of contacts.
        $supps = array_values(array_map('strval', (array) $this->config->secret("support", [])));
        $support1 = $supps[0] ?? '';
        $support2 = $supps[1] ?? $support1;

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

            // Fallbacks logged before they carried a marker: same text, no tag.
            if (($entry['direction'] ?? 'in') === 'out' && $tool === null && $text === trim($this->fallback())) {
                $tool = self::FALLBACK_SOURCE;
            }

            if (($entry['direction'] ?? 'in') === 'out' && is_string($tool) && isset(self::STALE[$tool])) {
                $text = self::STALE[$tool] . $this->closingQuestion($text);
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

    /**
     * The question a staleified reply ended on ("Want to finish your order
     * now?"), kept after the note so a bare "yes" on the next turn still has
     * something to answer. Empty when the reply didn't end on a question.
     */
    private function closingQuestion(string $text): string
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $last  = trim((string) end($lines));

        return str_ends_with($last, '?') ? "\n" . $last : '';
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
    /**
     * Writer path: run the calls and put their results, the products they name
     * and the note into one note text.
     *
     * @param list<array{0: string, 1: array<string,mixed>}> $calls
     */
    private function runCalls(array $calls, string $phone, string $note): string
    {
        $parts = [trim($note)];
        foreach ($calls as [$tool, $input]) {
            $this->logger->info('marvin: writer tool call', ['tool' => $tool, 'input' => $input]);
            $result = $this->tools->run(['type' => 'tool_use', 'id' => 'toolu_ts_' . bin2hex(random_bytes(10)), 'name' => $tool, 'input' => $input], $phone);
            $parts[] = "Result of {$tool}: " . (is_string($result['content'] ?? null) ? $result['content'] : json_encode($result['content'] ?? null, JSON_UNESCAPED_UNICODE));
            if (($products = $this->productFacts($input)) !== '') {
                $parts[] = "Products involved: {$products}";
            }
        }

        return implode(' ', array_filter($parts, static fn(string $p): bool => $p !== ''));
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function noteOnShopper(array $messages, string $note): array
    {
        $last = array_key_last($messages);
        if ($note !== '' && $last !== null && is_string($messages[$last]['content'])) {
            $messages[$last]['content'] .= "\n\n" . $this->noteText($note);
        }

        return $messages;
    }

    private function join(string ...$parts): string
    {
        return implode(' ', array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== ''));
    }

    private function noteText(string $note): string
    {
        // Kept free of ")" inside: stripStaleNotes() cuts a note at the first one.
        return '(SYSTEM NOTE, never repeat this to the shopper: ' . str_replace(['(', ')'], ['[', ']'], $note) . ')';
    }

    /**
     * The writer prompt (marvin.prompts.writer, one for every shop), with its
     * placeholders filled. Missing or unreadable: an ApiException, so the
     * shopper gets the fallback reply.
     *
     * @return list<array<string,mixed>>
     */
    private function writerSystemBlocks(): array
    {
        if ($this->writerText === null) {
            $path = $this->config->secret('marvin.prompts.writer', null);
            if (!is_string($path) || !is_readable($path)) {
                throw new ApiException('marvin.prompts.writer is not set or not readable: ' . var_export($path, true));
            } else {
                $supports = array_values((array) $this->config->secret('support', []));
                $this->writerText = (string) preg_replace('/\{\{[A-Z_0-9]+\}\}/', '', strtr((string) file_get_contents($path), [
                    '{{COMPANY_NAME}}' => (string) ($this->shopInfo['name'] ?? ''),
                    '{{CLIENT_NAME}}'  => ucwords($this->clientName),
                    '{{SUPPORT_1}}'    => ucwords((string) ($supports[0] ?? '')),
                    '{{SUPPORT_2}}'    => ucwords((string) ($supports[1] ?? $supports[0] ?? '')),
                ]));
            }
        }

        return [[
            'type'          => 'text',
            'text'          => $this->writerText,
            'cache_control' => $this->cacheControl(),
        ]];
    }

    /**
     * The last $max turns of history(), still starting with the shopper.
     *
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function recent(array $messages, int $max): array
    {
        $messages = array_slice($messages, -$max);
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return array_values($messages);
    }

    /** Menu data for the products a tool input names (product_id / product_ids), as JSON; '' for none. */
    private function productFacts(array $input): string
    {
        $ids = array_map('intval', array_filter([$input['product_id'] ?? null, ...(array) ($input['product_ids'] ?? [])]));
        if ($ids === []) {
            return '';
        }

        $products = array_values(array_filter(
            $this->menuService->promptProducts(),
            static fn(array $p): bool => in_array((int) ($p['id'] ?? 0), $ids, true),
        ));

        return $products === [] ? '' : (string) json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

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

    private function updateShopInfo(): void
    {
        $shopInfo = $this->shopService->shopBrief();
        
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