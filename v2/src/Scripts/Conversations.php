<?php

declare(strict_types=1);

/**
 * CLI script — archive stale conversations.
 *
 * A row in conversations_{shop_id} tracks live session state (step,
 * reminders, msgids...) for a phone number. The actual message transcript
 * lives separately, one JSON file per phone under
 * local_resources.conversations.path (see
 * WhatsappController::loadConversations for the read/write side).
 *
 * A conversation is considered stale once its last INBOUND (customer-sent,
 * direction "in") message is older than IDLE_SECONDS. Stale conversations are
 * archived via ConversationService::archive() — which also archives the
 * conversation's order (and its items), if it has one. The transcript file
 * itself is left untouched.
 *
 * No CLI argument is needed — it runs for every shop id listed in SHOPS
 * below, one after another (each overriding the company.shop_id normally
 * read from the secret config for that iteration).
 *
 *   php v2/src/Scripts/Conversations.php
 */

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Services\ConversationService;
use Pmsrapi\V2\Support\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const IDLE_SECONDS = 300;
const SHOPS = [98];

/** @var Container $container */
$container = require __DIR__ . '/../../bootstrap.php';

$conversations = $container->get(ConversationService::class);
$config = $container->get(Config::class);
$logger = $container->get(Logger::class);

/**
 * The transcript for this phone, or null when there is none to read.
 *
 * @return array<string, mixed>|null
 */
function loadTranscript(string $phone, Config $config, Logger $logger): ?array
{
    $dir = $config->secret('local_resources.conversations.path');

    if (!is_string($dir) || trim($dir) === '') {
        return null;
    }

    $safePhone = preg_replace('/[^0-9]/', '', $phone);

    if ($safePhone === null || $safePhone === '') {
        return null;
    }

    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $safePhone . '.json';

    if (!file_exists($path)) {
        return null;
    }

    $contents = file_get_contents($path);

    if ($contents === false || trim($contents) === '') {
        return null;
    }

    $decoded = json_decode($contents, true);

    if (!is_array($decoded)) {
        $logger->warning('conversations.archive.malformed_transcript', ['path' => $path]);
        return null;
    }

    return $decoded;
}

/**
 * The timestamp of the most recent inbound (customer-sent) message in the
 * transcript, or null when there isn't one.
 *
 * @param array<string, mixed> $transcript
 */
function lastCustomerMessageAt(array $transcript): ?DateTimeImmutable
{
    $messages = $transcript['data']['messages'] ?? null;

    if (!is_array($messages)) {
        return null;
    }

    $latest = null;

    foreach ($messages as $message) {
        if (!is_array($message) || ($message['direction'] ?? null) !== 'in') {
            continue;
        }

        $at = $message['at'] ?? null;

        if (!is_string($at)) {
            continue;
        }

        try {
            $sentAt = new DateTimeImmutable($at);
        } catch (\Exception) {
            continue;
        }

        if ($latest === null || $sentAt > $latest) {
            $latest = $sentAt;
        }
    }

    return $latest;
}

function isStale(DateTimeImmutable $lastMessageAt, DateTimeImmutable $now): bool
{
    return ($now->getTimestamp() - $lastMessageAt->getTimestamp()) > IDLE_SECONDS;
}

function run(ConversationService $conversations, Config $config, Logger $logger, int $shopId): void
{
    $rows = $conversations->activeConversations($shopId);
    $now = new DateTimeImmutable();
    $archived = 0;

    foreach ($rows as $conversation) {
        $phone = (string) ($conversation['phonenumber'] ?? '');

        if ($phone === '') {
            continue;
        }

        $transcript = loadTranscript($phone, $config, $logger);
        $lastMessageAt = $transcript !== null ? lastCustomerMessageAt($transcript) : null;

        if ($lastMessageAt === null || !isStale($lastMessageAt, $now)) {
            continue;
        }

        $conversations->archive($conversation, $shopId);
        $archived++;
    }

    $logger->info('conversations.archive', [
        'shop_id' => $shopId,
        'archived' => $archived,
        'total' => count($rows),
    ]);

    echo "Archived {$archived} of " . count($rows) . " conversation(s) for shop {$shopId}\n";
}

foreach (SHOPS as $shopId) {
    run($conversations, $config, $logger, $shopId);
}
