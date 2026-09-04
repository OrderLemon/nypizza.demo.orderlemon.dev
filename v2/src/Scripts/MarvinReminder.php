<?php

declare(strict_types=1);

/**
 * CLI script — nudge shoppers who went quiet on Marvin.
 *
 * Meant to run every minute (cron). For every shop in SHOPS it scans
 * local_resources.conversations.path/{{shop_id}}/*.json directly — one file
 * per phone number, the same transcript format ChatTranscriptService reads
 * and writes (see that class' docblock). It does NOT go through
 * ChatTranscriptService for the read/list step because that service resolves
 * its path from the request-scoped "shop_id" constant and only knows how to
 * load a single phone at a time; this script needs every file for a shop it
 * does not yet have identities for. Same rationale as the shop-id-parameterised
 * loadTranscript() this replaced in the old Conversations.php archiver.
 *
 * A conversation is nudged when its LAST logged message is:
 *   - outbound (direction "out" — Marvin, not the shopper, spoke last), AND
 *   - not tagged source_tool "checkout_completed" (they finished on the web,
 *     nothing to chase), AND
 *   - not already an idle reminder (source_tool "idle_reminder" — one nudge
 *     per silence, not one every minute for as long as they stay quiet), AND
 *   - not a track_order (they're already in the middle of a tracking flow), AND
 *   - sent today (same calendar day as now), AND
 *   - older than IDLE_SECONDS defined in configs.
 *
 *   php v2/src/Scripts/MarvinReminder.php
 */

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Services\ChatTranscriptService;
use Pmsrapi\V2\Support\Logger;
use Plugins\Whatsapp\AI\AnthropicClient;
use Plugins\Whatsapp\AI\MarvinTool;
use Plugins\Whatsapp\Gateway\WhatsappGateway;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SHOPS = [98];
const FALLBACK_TEXT = 'Hey, still there?';

/** @var Container $container */
$container = require __DIR__ . '/../../bootstrap.php';

$config = $container->get(Config::class);
$logger = $container->get(Logger::class);
$anthropic = $container->get(AnthropicClient::class);
$gateway = $container->get(WhatsappGateway::class);
$transcripts = $container->get(ChatTranscriptService::class);

$reminderEnabled = $config->secret('marvin.reminder.enabled', false);

if (!$reminderEnabled) {
    $logger->info('marvin_reminder.disabled', ['shop_id' => SHOPS]);
    echo "Marvin reminder is disabled in config, exiting.\n";
    exit;
}

define("IDLE_SECONDS", $config->secret('marvin.reminder.idle_seconds', 180));

/** Absolute path to this shop's conversations directory, or null if unset. */
function conversationsDirFor(int $shopId, Config $config): ?string
{
    $dir = $config->secret('local_resources.conversations.path');

    if (!is_string($dir) || trim($dir) === '') {
        return null;
    }

    $dir = str_replace('{{shop_id}}', (string) $shopId, $dir);

    return is_dir($dir) ? rtrim($dir, '/\\') : null;
}

/** @return array<string, mixed>|null decoded transcript, or null if unreadable/malformed */
function loadTranscript(string $path, Logger $logger): ?array
{
    $contents = @file_get_contents($path);

    if ($contents === false || trim($contents) === '') {
        return null;
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        $logger->warning('marvin_reminder.malformed_transcript', ['path' => $path]);

        return null;
    }

    return $decoded;
}

/**
 * The last entry in data.messages, or null if there isn't one.
 *
 * @param array<string, mixed> $transcript
 * @return array<string, mixed>|null
 */
function lastMessage(array $transcript): ?array
{
    $messages = $transcript['data']['messages'] ?? null;

    if (!is_array($messages) || $messages === []) {
        return null;
    }

    $last = end($messages);

    return is_array($last) ? $last : null;
}

/**
 * @param array<string, mixed> $lastEntry
 */
function isDue(array $lastEntry, DateTimeImmutable $now): bool
{
    if (($lastEntry['direction'] ?? 'in') !== 'out') {
        return false;
    }

    $tool = $lastEntry['source_tool'] ?? null;
    if ($tool === MarvinTool::CheckoutCompleted->value
            || $tool === MarvinTool::IdleReminder->value
            || $tool === MarvinTool::OrderLost->value
            || $tool === MarvinTool::TrackOrder->value) {
        return false;
    }

    $at = $lastEntry['at'] ?? null;
    if (!is_string($at)) {
        return false;
    }

    try {
        $sentAt = new DateTimeImmutable($at);
    } catch (Exception) {
        return false;
    }

    $isLessThanIdle = ($now->getTimestamp() - $sentAt->getTimestamp()) < IDLE_SECONDS;

    $isSameDay = $now->format('Y-m-d') === $sentAt->format('Y-m-d');

    return $isSameDay && !$isLessThanIdle;
}

/** Concatenate the text blocks of an Anthropic response body. */
function textOf(array $body): string
{
    $text = '';
    foreach (($body['content'] ?? []) as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text') {
            $text .= $block['text'] ?? '';
        }
    }

    return trim($text);
}

/**
 * The shopper's own most recent line, for language detection only — never
 * their question or the full thread, or the model has something to "answer".
 *
 * @param array<string, mixed> $conversation
 */
function lastCustomerText(array $conversation): ?string
{
    $messages = $conversation['data']['messages'] ?? null;

    if (!is_array($messages)) {
        return null;
    }

    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $entry = $messages[$i];

        if (!is_array($entry) || ($entry['direction'] ?? null) !== 'in') {
            continue;
        }

        $text = is_string($entry['message'] ?? null) ? trim($entry['message']) : '';

        if ($text !== '') {
            return $text;
        }
    }

    return null;
}

/**
 * Ask Marvin's model for a short check-in nudge in the shopper's own language.
 *
 * @param array<string, mixed> $conversation
 */
function reminderText(array $conversation, Config $config, AnthropicClient $anthropic, Logger $logger): string
{
    $sample = lastCustomerText($conversation);

    if ($sample === null) {
        return FALLBACK_TEXT;
    }

    try {
        $body = $anthropic->messages(
            [['role' => 'user', 'content' => "Language sample only, not a question: \"{$sample}\""]],
            [['type' => 'text', 'text' => getPrompt($config)]],
        );

        $text = textOf($body);

        return $text !== '' ? $text : FALLBACK_TEXT;
    } catch (Throwable $e) {
        $logger->warning('marvin_reminder.generation_failed', ['error' => $e->getMessage()]);

        return FALLBACK_TEXT;
    }
}

/** Binds the request-scoped shop_id constant WhatsappGateway/ChatTranscriptService read from. */
function bindShopId(int $shopId, Logger $logger): bool
{
    if (defined('shop_id')) {
        if ((int) constant('shop_id') === $shopId) {
            return true;
        }

        $logger->error('marvin_reminder.shop_id_conflict', [
            'bound' => constant('shop_id'),
            'requested' => $shopId,
        ]);

        return false;
    }

    define('shop_id', $shopId);

    return true;
}

function run(
    int $shopId,
    Config $config,
    Logger $logger,
    AnthropicClient $anthropic,
    WhatsappGateway $gateway,
    ChatTranscriptService $transcripts,
): void {
    $dir = conversationsDirFor($shopId, $config);

    if ($dir === null) {
        $logger->warning('marvin_reminder.no_conversations_dir', ['shop_id' => $shopId]);

        return;
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $now = new DateTimeImmutable();
    $reminded = 0;

    foreach ($files as $path) {
        $phone = pathinfo($path, PATHINFO_FILENAME);

        if ($phone === '') {
            continue;
        }

        $transcript = loadTranscript($path, $logger);
        if ($transcript === null) {
            continue;
        }

        $last = lastMessage($transcript);
        if ($last === null || !isDue($last, $now)) {
            continue;
        }

        if (!bindShopId($shopId, $logger)) {
            continue;
        }

        $text = reminderText($transcript, $config, $anthropic, $logger);

        try {
            $gateway->sendText($phone, $text);
            $transcripts->append($phone, $text, 'out', 'text', MarvinTool::IdleReminder->value);
            $reminded++;
        } catch (Throwable $e) {
            $logger->error('marvin_reminder.send_failed', [
                'phone' => $phone,
                'shop_id' => $shopId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    $logger->info('marvin_reminder.run', [
        'shop_id' => $shopId,
        'reminded' => $reminded,
        'checked' => count($files),
    ]);

    echo "Reminded {$reminded} of " . count($files) . " conversation(s) for shop {$shopId}\n";
}

function getPrompt(Config $config): string
{
    $path = $config->secret('marvin.prompts.reminder');

    if (!is_string($path) || trim($path) === '' || !is_file($path)) {
        throw new RuntimeException("Marvin reminder prompt file not found at {$path}");
    }

    $cts = file_get_contents($path);

    if ($cts === false) {
        throw new RuntimeException("Failed to read Marvin reminder prompt file at {$path}");
    }

    return trim($cts);
}

foreach (SHOPS as $shopId) {
    run($shopId, $config, $logger, $anthropic, $gateway, $transcripts);
}
