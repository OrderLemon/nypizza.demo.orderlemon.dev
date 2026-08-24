<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use DateTimeImmutable;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Helpers\CustomerHelper;
use Pmsrapi\V2\Orders\OrderStatus;
use Pmsrapi\V2\Support\Logger;

final class OrderQueryService
{
    /** "In progress / paid" range (see v1's flows archiving notes) — never touched by archiving. */
    private const array IN_PROGRESS_STATUS_IDS = [2, 3, 4, 5, 6, 7];

    /** CartService's CART_STATUS_ID — still an open cart, not a placed order yet. */
    private const int CART_STATUS_ID = 1;

    private const int CANCELLED_STATUS_ID = 8;
    private const string CANCELLED_STATUS_LABEL = 'cancelled';

    public function __construct(
        private readonly Repository $repo,
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly TrackingService $trackingService,
    ) {
    }

    // ------------------------------------------------------------- reading

    /** Every order for the configured shop, items attached. */
    public function indexActive(): array
    {
        return $this->allOrders();
    }

    /** This phone's orders for the configured shop, most recent first. */
    public function loadForPhone(string $phone): array
    {
        return $this->ordersForPhone($phone);
    }

    /**
     * This shopper's non-terminal orders, most recent first.
     *
     * @return list<array<string,mixed>>
     */
    public function activeOrdersFor(string $phone): array
    {
        $orders = array_values(array_filter(
            $this->ordersForPhone($phone),
            fn(array $order): bool => !$this->statusOf($order)->isTerminal(),
        ));

        $this->logger->info('orders.activeOrdersFor', [
            'phone'  => $phone,
            'found'  => count($orders),
            'orders' => array_map(
                fn(array $o): array => [
                    'order_id' => (int) ($o['id'] ?? 0),
                    'status'   => $this->statusOf($o)->value,
                ],
                $orders
            ),
        ]);

        return $orders;
    }

    /** Finds an order for the configured shop by id, items attached, or null if it doesn't exist. */
    public function getById(int $orderId): ?array
    {
        $shopId = $this->shopId();

        try {
            $order = $this->repo->selectRow($this->table('orders', $shopId), ['id' => $orderId]);

            if ($order === null) {
                return null;
            }

            $order['items'] = $this->repo->selectRows(
                $this->table('order_items', $shopId),
                ['order_id' => $orderId],
            );

            return $order;
        } catch (ApiException $e) {
            $this->logger->error('Error loading order: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param int|null $shopId defaults to the configured company.shop_id
     * @return bool whether the order was archived
     */
    public function archiveOrder(int $orderId, ?int $shopId = null): bool
    {
        $shopId ??= $this->shopId();
        $ordersTable = $this->table('orders', $shopId);
        $itemsTable = $this->table('order_items', $shopId);

        $order = $this->repo->selectRow($ordersTable, ['id' => $orderId]);

        if ($order === null) {
            return false;
        }

        if (in_array((int) ($order['status_id'] ?? 0), self::IN_PROGRESS_STATUS_IDS, true)) {
            return false;
        }

        if ((int) ($order['status_id'] ?? 0) === self::CART_STATUS_ID) {
            $order['status_id'] = self::CANCELLED_STATUS_ID;
            $order['status_label'] = self::CANCELLED_STATUS_LABEL;
        }

        foreach ($this->repo->selectRows($itemsTable, ['order_id' => $orderId]) as $item) {
            $this->repo->insertRow($this->archiveTable('order_items', $shopId), $item);
            $this->repo->deleteById($itemsTable, (int) $item['id']);
        }

        $this->repo->insertRow($this->archiveTable('orders', $shopId), $order);
        $this->repo->deleteById($ordersTable, $orderId);

        return true;
    }

    /** Loads an order by id and builds its receipt data, or null if not found. */
    public function prepareForTicket(int $orderId): ?array
    {
        $order = $this->getById($orderId);

        return $order === null ? null : $this->addReceiptData($order);
    }

    /** Builds the receipt data structure (locales, company/shop info, order info) used to render a ticket. */
    public function addReceiptData(array $order): array
    {
        $company = $this->config->secret('company.name');
        $companyAddress = $this->config->secret('company.address');
        $shop = $this->config->secret('company.shop.name');
        $shopAddress = $this->config->secret('company.shop.address');

        return [
            'locales' => [
                'vat_ticket'         => 'Ticket',
                'vat'                => 'VAT',
                'total'              => 'Total',
                'vat_excl'           => 'EXCL. VAT',
                'vat_incl'           => 'INCL. VAT',
                'order'              => 'Order',
                'payment_type_label' => $order['payment_type_label'] ?? '',
                'show_this_code'     => 'Show this code in shop!',
                'thank_you'          => 'Thank you and see you soon!',
            ],
            'company_info' => [
                'name'               => $company,
                'address_formatted'  => $companyAddress,
                'vat_number'         => 'AAABBBCCC111222',
            ],
            'shop_info' => [
                'name'              => $shop,
                'address_formatted' => $shopAddress,
            ],
            'order_info' => [
                'ordered_time' => $order['ordered_time'],
                'order_items'  => $this->ticketItems($order['items'] ?? []),
            ],
        ];
    }

    private function ticketItems(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $ticketItem = [
                'id'                => $item['id'],
                'item_description'  => $item['item_description'],
                'unit_price'        => $item['unit_price'],
                'vat_percentage'    => $item['vat_percentage'],
                'quantity'          => $item['quantity'],
                'product_id'        => $item['product_id'],
                'config'            => $item['config'] ?? [],
            ];

            $result[] = $ticketItem;

            foreach ($ticketItem['config'] as $configIndex => $configItem) {
                $ticketItem['config'][$configIndex]['parent_id'] = $item['id'];
                $result[] = $ticketItem['config'][$configIndex];
            }
        }

        return $result;
    }

    /** Builds the signed URL to the receipt HTML template for a given order. */
    private function generateTemplateUrl(int $id): string
    {
        $token = $this->config->secret('ms_server_token');
        $templatePath = (string) $this->config->secret('receipt.template');

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $protocol = isset($_SERVER['SERVER_PROTOCOL']) && str_contains((string) $_SERVER['SERVER_PROTOCOL'], 'https')
            ? 'https' : 'http';

        $ticketUri = $protocol . '://' . $host . '/' . ltrim($templatePath, '/');
        $query = http_build_query(['order_id' => $id, 'token' => $token]);

        return $ticketUri . '?' . $query;
    }

    /** Requests a ticket image url for an order and returns its full URL, or null on failure. */
    public function createTicket(int $id): ?string
    {
        $ticketGenerationApiUrl = (string) $this->config->secret('receipt.api');

        $payload = [
            'a' => 'create_ticket_image',
            'data' => [
                'url'  => $this->generateTemplateUrl($id),
                'path' => $this->generateImagePath($id),
            ],
        ];

        $result = $this->callTicketGenerationApi($ticketGenerationApiUrl, $payload);

        if ($result === null || trim($result) === '') {
            return null;
        }

        return rtrim($ticketGenerationApiUrl, '/') . '/' . ltrim($result, '/');
    }

    /** Calls the ticket generation API and returns the generated image path, or null on failure. */
    private function callTicketGenerationApi(string $url, array $payload): ?string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false || $curlError !== '') {
            $this->logger->error("Ticket generation API call failed: {$curlError}");

            return null;
        }

        if ($statusCode !== 200) {
            $this->logger->error("Ticket generation API returned HTTP {$statusCode}: {$response}");

            return null;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true || !isset($decoded['data']['path'])) {
            $this->logger->error("Ticket generation API returned an unexpected response: {$response}");

            return null;
        }

        return $decoded['data']['path'];
    }

    /** Generates a unique image filename for the order's ticket. */
    private function generateImagePath(int $id): string
    {
        $stamp = (new DateTimeImmutable())->getTimestamp();
        $base = $this->config->name() . '_order_' . $id . '_' . $stamp . '_';

        return $base . hash('sha256', $base) . '.png';
    }

    // ---------------------------------------------------------------- helpers

    private function shopId(): int
    {
        if (!defined("shop_id") || !is_numeric(shop_id)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) shop_id;
    }

    private function table(string $tablePrefix, int $shopId): string
    {
        return $tablePrefix . '_active_' . $shopId;
    }

    private function archiveTable(string $tablePrefix, int $shopId): string
    {
        return $tablePrefix . '_archive_' . $shopId;
    }

    /** Every order for the configured shop, items attached. */
    private function allOrders(): array
    {
        $shopId = $this->shopId();

        try {
            return $this->withItems($this->repo->selectRows($this->table('orders', $shopId)), $shopId);
        } catch (ApiException $e) {
            $this->logger->error('Error loading orders: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * This shopper's orders for the configured shop, most recent first.
     *
     * @return list<array<string,mixed>>
     */
    private function ordersForPhone(string $phone): array
    {
        $orders = array_values(array_filter(
            $this->allOrders(),
            static fn(array $order): bool => CustomerHelper::samePhone((string) ($order['phonenumber'] ?? ''), $phone),
        ));

        usort(
            $orders,
            static fn(array $a, array $b): int => strcmp(
                (string) ($b['ordered_time'] ?? ''),
                (string) ($a['ordered_time'] ?? ''),
            ),
        );

        return $orders;
    }

    /**
     * Attaches each order's items in one extra query rather than one per order.
     *
     * @param list<array<string,mixed>> $orders
     * @return list<array<string,mixed>>
     */
    private function withItems(array $orders, int $shopId): array
    {
        if ($orders === []) {
            return [];
        }

        $itemsByOrderId = [];

        foreach ($this->repo->selectRows($this->table('order_items', $shopId)) as $item) {
            $itemsByOrderId[$item['order_id']][] = $item;
        }

        foreach ($orders as &$order) {
            $order['items'] = $itemsByOrderId[$order['id']] ?? [];
            // Computed for callers (e.g. MarvinTools) that key off a single
            // lifecycle field rather than the DB's status_id/status_label pair.
            $order['status'] = $this->statusOf($order)->value;
        }
        unset($order);

        return $orders;
    }

    /** Tolerant parse of the DB's free-text status_label into the demo's OrderStatus lifecycle. */
    private function statusOf(array $order): OrderStatus
    {
        return OrderStatus::fromMixed(strtolower((string) ($order['status_label'] ?? '')));
    }
}
