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

/**
 * Order read/write access against the shop's `orders_active_{shop}` /
 * `order_items_active_{shop}` tables, plus the receipt/ticket assembly used
 * to render and image a customer's ticket.
 */
final class OrderQueryService
{
    private const int DEFAULT_STATUS_ID = 2;
    private const int DEFAULT_PAYMENT_STATUS_ID = 2;

    public function __construct(
        private readonly Repository $repo,
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly TrackingService $trackingService,
    ) {
    }

    // ------------------------------------------------------------- writing

    /** Inserts a new order and its items, seeds delivery tracking, and returns the order row. */
    public function create(array $data): ?array
    {
        $shopId = $this->shopId();

        try {
            $order = $this->repo->upsert($this->table('orders', $shopId), $this->orderPayload($data));

            $orderId = $order['id'] ?? null;
            if ($orderId === null || !is_numeric($orderId)) {
                throw new ApiException('Error inserting a new order');
            }

            $items = [];

            // Nested item config (modifiers) is not persisted as its own row yet;
            // only the top-level item columns known to order_items are kept.
            foreach ($data['items'] as $item) {
                $item['order_id'] = (int) $orderId;
                $inserted = $this->repo->upsert($this->table('order_items', $shopId), $item);
                $items[] = $inserted['record'];
            }

            $record = $order['record'] ?? [];
            $record['items'] = $items;

            $this->trackingService->seed($record);

            return $record;
        } catch (ApiException $e) {
            $this->logger->error('Error in creating order: ' . $e->getMessage());

            return null;
        }
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

    // ---------------------------------------------------------- validation

    /** Validates that each order item has a numeric price, qty, category and product id. */
    public function validateItems(array $items): void
    {
        $itemRules = [
            'item_description' => ['type' => 'string',  'label' => 'Description'],
            'unit_price'       => ['type' => 'numeric', 'label' => 'Price'],
            'vat_percentage'   => ['type' => 'numeric', 'label' => 'Vat percentage'],
            'quantity'         => ['type' => 'numeric', 'label' => 'Quantity'],
            'product_id'       => ['type' => 'numeric', 'label' => 'Product Id'],
            'category_id'      => ['type' => 'numeric', 'label' => 'Category Id'],
        ];

        foreach ($items as $index => $item) {
            foreach ($itemRules as $field => $rule) {
                $value = $item[$field] ?? null;
                $valid = $rule['type'] === 'string'
                    ? isset($value) && is_string($value)
                    : isset($value) && is_numeric($value);

                if (!$valid) {
                    throw new ValidationException([
                        'order_item' => "{$rule['label']} is required and must be a {$rule['type']} value (item #{$index})",
                    ]);
                }
            }
        }
    }

    /** Validates that an order has a phone number and at least one item. */
    public function validateOrder(array $order): void
    {
        if (!isset($order['phonenumber'])) {
            throw new ValidationException(['Phone Number' => 'Order must have phone number!']);
        }

        $fieldRules = [
            'logistic_type'   => ['type' => 'numeric',  'label' => 'Logistic type'],
            'payment_type'    => ['type' => 'numeric',  'label' => 'Payment type'],
            'pick_up_time'    => ['type' => 'datetime', 'label' => 'Pickup time'],
            'pick_up_moment'  => ['type' => 'datetime', 'label' => 'Pickup moment'],
            'delivery_moment' => ['type' => 'datetime', 'label' => 'Delivery moment'],
            'full_name'       => ['type' => 'string',   'label' => 'Full name'],
            'note'            => ['type' => 'string',   'label' => 'Note'],
            'country'         => ['type' => 'string',   'label' => 'Country'],
            'state'           => ['type' => 'string',   'label' => 'State'],
            'city'            => ['type' => 'string',   'label' => 'City'],
            'zip'             => ['type' => 'string',   'label' => 'Zip'],
            'street'          => ['type' => 'string',   'label' => 'Street'],
        ];

        foreach ($fieldRules as $field => $rule) {
            if (!isset($order[$field])) {
                continue; // all these fields are optional
            }

            $value = $order[$field];
            $valid = match ($rule['type']) {
                'numeric'  => is_numeric($value),
                'string'   => is_string($value),
                'datetime' => $this->isValidMySQLDateTime((string) $value),
            };

            if (!$valid) {
                $message = $rule['type'] === 'datetime'
                    ? "Invalid date format for {$rule['label']}!"
                    : "{$rule['label']} must be a {$rule['type']} value!";

                throw new ValidationException([$rule['label'] => $message]);
            }
        }

        if (empty($order['items'])) {
            throw new ValidationException(['Items' => 'Order must have items']);
        }

        $this->validateItems($order['items']);
    }

    private function isValidMySQLDateTime(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return false;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);

        return $dt !== false && $dt->format('Y-m-d H:i:s') === $value;
    }

    // --------------------------------------------------------------- ticket

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
        $shopId = $this->config->secret('company.shop_id');

        if (!is_numeric($shopId)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) $shopId;
    }

    private function table(string $tablePrefix, int $shopId): string
    {
        return $tablePrefix . '_active_' . $shopId;
    }

    private function orderPayload(array $data): array
    {
        return [
            'phonenumber'      => $data['phonenumber'],
            'total'            => $this->orderTotal($data['items']),
            'logistics_type'   => $data['logistic_type'] ?? 1,
            'payment_type'     => $data['payment_type'] ?? 1,
            'full_name'        => $data['full_name'] ?? null,
            'note'             => $data['note'] ?? null,
            'country'          => $data['country'] ?? null,
            'state'            => $data['state'] ?? null,
            'city'             => $data['city'] ?? null,
            'zip'              => $data['zip'] ?? null,
            'street'           => $data['street'] ?? null,
            'pick_up_moment'   => $data['pick_up_moment'] ?? null,
            'delivery_moment'  => $data['delivery_moment'] ?? null,
            'ordered_time'     => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'status_id'        => self::DEFAULT_STATUS_ID,
            'payment_status_id' => self::DEFAULT_PAYMENT_STATUS_ID,
            'happy_hour'       => 0,
        ];
    }

    private function orderTotal(array $items): float
    {
        return array_reduce(
            $items,
            fn(float $carry, array $item): float => $carry + ((float) $item['unit_price'] * (float) $item['quantity']),
            0.0,
        );
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
