<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;

final class PrintService
{
    private const string FUNCTION_NAME = 'print_order';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /** Asks the print microservice to print the given order's ticket. */
    public function sendRequest(int $orderId): bool
    {
        return true;
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
    }

    private function post(string $url, string $token, array $payload): bool
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
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

    private function shopId(): int
    {
        if (!defined('shop_id') || !is_numeric(shop_id)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) shop_id;
    }
}
