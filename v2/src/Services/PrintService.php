<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Cache\RedisLock;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;

/**
 * A single physical printer is shared by every shop: the printer row's
 * shop_id is repointed at whichever shop is printing right now, then the
 * print job is sent. Because that update+print pair isn't atomic on its own,
 * it's wrapped in a RedisLock so two shops can't repoint/print at the same
 * time and cross-route a ticket. The lock TTL and the curl timeout share one
 * constant so the lock can never expire while a print call is still in flight.
 */
final class PrintService
{
    private const int PRINT_TIMEOUT_SECONDS = 10;
    private const string LOCK_KEY = 'print:shared_printer';
    private const string FUNCTION_NAME = 'print_order';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly RedisLock $lock,
        private readonly Repository $repo,
    ) {}

    /** Asks the print microservice to print the given order's ticket. */
    public function sendRequest(int $orderId): bool
    {
        $enabled = $this->config->secret("receipt.printing_enabled", false);

        if (!$enabled) {
            $this->logger->warning("Printing service disabled from config!", []);
            return true;
        }

        // Holds the lock for the whole update+print critical section below,
        // not just the HTTP call, so no other shop can repoint the printer
        // in between.
        if (!$this->lock->acquire(self::LOCK_KEY, ttlSeconds: self::PRINT_TIMEOUT_SECONDS, timeoutMs: self::PRINT_TIMEOUT_SECONDS * 1000)) {
            $this->logger->error("Timeout waiting for the shared printer!");
            return false;
        }

        try {
            if (!$this->updatePrinter()) {
                return false;
            }

            $url = rtrim((string) $this->config->secret('receipt.print_service_api'), '/') . '/';
            $token = (string) $this->config->secret('receipt.print_service_token');

            $payload = [
                'function' => self::FUNCTION_NAME,
                'parameters' => [
                    'shop_id'  => $this->shopId(),
                    'order_id' => $orderId,
                ],
            ];

            return $this->post($url, $token, $payload);
        } finally {
            $this->lock->release(self::LOCK_KEY);
        }
    }

    /** Repoints the shared printer row at the current shop before printing. */
    private function updatePrinter(): bool
    {
        $printerId = $this->config->secret("receipt.printer", 0);
        $table = $this->config->secret("receipt.printers_table", "");

        if ($printerId < 1) {
            $this->logger->error("Printer id not set in the configuration!");
            return false;
        }

        if ($table === "") {
            $this->logger->error("Printers table not set in the configuration!");
            return false;
        }

        $result = $this->repo->updateById($table, (int) $printerId, ["shop_id" => $this->shopId()]);

        if ($result < 1) {
            $this->logger->error("Unable to update the printers table!");
            return false;
        }

        return true;
    }

    /** Sends the print job to the print microservice over HTTP. */
    private function post(string $url, string $token, array $payload): bool
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::PRINT_TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false || $curlError !== '') {
            $this->logger->error("Print service call failed: {$curlError}");

            return false;
        }

        if ($statusCode !== 200) {
            $this->logger->error("Print service returned HTTP {$statusCode}: {$response}");

            return false;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            $this->logger->error("Print service returned an unexpected response: {$response}");

            return false;
        }

        return true;
    }

    /** Reads the shop id index.php resolved for this request. */
    private function shopId(): int
    {
        if (!defined('shop_id') || !is_numeric(shop_id)) {
            $this->logger->error("Invalid shop_id found in printer service!", ["shop_id" => shop_id]);
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) shop_id;
    }
}
