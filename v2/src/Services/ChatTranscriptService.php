<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;

/**
 * The shopper<->Marvin transcript: one JSON file per phone number under
 * local_resources.conversations.path, "<phone>.json". This is the exact
 * shape {@see \Plugins\Whatsapp\AI\Marvin::history()} reads — a flat
 * "data.messages" list, each entry {id, direction, message, message_type,
 * at[, source_tool]}, "in" for the shopper and "out" for Marvin.
 *
 * Pulled out as a core service (rather than staying private inside
 * WhatsappController, where it originated) so any part of the service can
 * append a fact to a shopper's transcript — not only the inbound WhatsApp
 * receiver. The motivating case: the shopper can open the live cart link
 * attached to every add_to_order/remove_from_order/checkout_order reply and
 * finish checkout on the web at any point — Marvin never sees that happen.
 * CartController::checkout() uses this to record it, so Marvin's next reply
 * knows the order he was building is placed and done.
 */
final class ChatTranscriptService
{
    public function __construct(
        private readonly Config $config,
    ) {}

    /** @return array<string, mixed> decoded transcript, or [] if it doesn't exist yet or is empty */
    public function load(string $phone): array
    {
        $cts = @file_get_contents($this->pathFor($phone));

        if ($cts === false || trim($cts) === '') {
            return [];
        }

        $decoded = json_decode($cts, true);

        if ($decoded === null) {
            throw new ApiException('Conversation file is malformed!');
        }

        return $decoded;
    }

    /**
     * Appends one entry and writes the transcript back, same shape the
     * WhatsApp inbound flow already produces.
     */
    public function append(
        string $phone,
        string $message,
        string $direction = 'out',
        string $messageType = 'text',
        ?string $sourceTool = null,
    ): void {
        $conversation = $this->load($phone);

        if (!isset($conversation['data']['messages']) || !is_array($conversation['data']['messages'])) {
            $conversation = ['phone' => $phone, 'data' => ['total' => 0, 'messages' => []]];
        }

        $total = count($conversation['data']['messages']);

        $entry = [
            'id'           => $total + 1,
            'direction'    => $direction,
            'message'      => $message,
            'message_type' => $messageType,
            'at'           => date('c'),
        ];

        if ($direction === 'out' && $sourceTool !== null) {
            $entry['source_tool'] = $sourceTool;
        }

        $conversation['data']['messages'][] = $entry;
        $conversation['data']['total'] = $total + 1;

        $encoded = json_encode($conversation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new ApiException('Could not encode conversation transcript');
        }

        if (file_put_contents($this->pathFor($phone), $encoded, LOCK_EX) === false) {
            throw new ApiException('Could not write conversation transcript');
        }
    }

    /**
     * Absolute path to this phone's transcript file, creating the
     * conversations directory if needed. $phone can arrive from an inbound
     * webhook body or a checkout request and is concatenated into a
     * filesystem path, so it is stripped to digits first.
     */
    private function pathFor(string $phone): string
    {
        if (!defined('shop_id') || !is_numeric(shop_id)) {
            throw new ValidationException(['shop id' => 'Shop Id must be a numeric value!']);
        }

        $dir = $this->config->secret('local_resources.conversations.path');

        if ($dir === null || trim((string) $dir) === '') {
            throw new ApiException('Path to conversation directory is invalid!');
        }

        $dir = str_replace('{{shop_id}}', (string) shop_id, (string) $dir);

        if (!file_exists($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new ApiException('Could not create conversations directory!');
        }

        $safePhone = preg_replace('/[^0-9]/', '', $phone);

        if ($safePhone === null || $safePhone === '') {
            throw new ApiException('Phone number is not a usable identifier!');
        }

        return $dir . DIRECTORY_SEPARATOR . $safePhone . '.json';
    }
}
