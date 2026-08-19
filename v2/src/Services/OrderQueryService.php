<?php

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Services\JsonService;
use Pmsrapi\V2\Services\TrackingService;
use Pmsrapi\V2\Orders\OrderStatus;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Helpers\CustomerHelper;
use Pmsrapi\V2\Helpers\ApiRequestHelper;
use UnexpectedValueException;

final class OrderQueryService
{
    function __construct(
        private readonly Repository $repo,
        protected Logger $logger,
        protected Config $config,
        protected TrackingService $trackingService,
    ){ }


    // Inserts a new order and returns its generated id, or false on failure.
    public function create(array $data): ?array
    {
        $shopId = $this->config->secret("company.shop_id");
        $companyId = $this->config->secret("company.company_id");
        // $conversationId = $data["conversation_id"];

        if(!is_numeric($shopId)){
            throw new ApiException("Invalid configuration for shop id");
        }

        if(!is_numeric($companyId)){
            throw new ApiException("Invalid configuration for company id");
        }

        // if(!is_numeric($conversationId)){
        //     throw new ApiException("Invalid conversation id");
        // }

        $apiUrl = $this->config->secret("orderlemon_api.url", "");
        $token = $this->config->secret("orderlemon_api.token", "");
        
        try{
            $orderData = $this->orderPayload($data);
            $upsertOrderData = $this->repo->upsert($this->table("orders", $shopId), $orderData);

            if($upsertOrderData === null || !isset($upsertOrderData["id"]) || !is_numeric($upsertOrderData["id"])){
                throw new ApiException("Error inserting a new order");
            }

            //update the items with the new order id
            $data["items"] = array_map(function($row) use ($upsertOrderData) {
                $row['order_id'] = (int)$upsertOrderData["id"];
                return $row;
            }, $data["items"]);
            
            $upsertOrderData["items"] = [];

            foreach($data["items"] as $item){
                $upsertItemData = $this->repo->upsert($this->table("order_items", $shopId), $item);
                $upsertOrderData["items"][] = $upsertItemData["record"];
            }

            return $upsertOrderData;
        }
        catch(ApiException $e){
            $this->logger->error("Error in creating order: " . $e->getMessage());
            return null;
        }
    }

    private function table(string $tablePrefix, int $shopId) : string
    {
        return $tablePrefix . "_active_" . $shopId;
    } 

    private function orderPayload(array $data) : array
    {
        $total = $this->orderTotal($data["items"]);
        $orderedTime = new \DateTime();
        $orderedTime = $orderedTime->format("Y-m-d H:i:s");
        return [
            "phonenumber" => $data["phonenumber"],
            "total" => $total,
            "logistic_type" => $data["logistic_type"] ?? 1,
            "payment_type" => $data["payment_type"] ?? 1,
            "full_name" => $data["full_name"] ?? null,
            "note" => $data["note"] ?? null,
            "country" => $data["country"] ?? null,
            "state" => $data["state"] ?? null,
            "city" => $data["city"] ?? null,
            "zip" => $data["zip"] ?? null,
            "street" => $data["street"] ?? null,
            "pick_up_moment" => $data["pick_up_moment"] ?? null,
            "delivery_moment" => $data["delivery_moment"] ?? null,
            "ordered_time" => $orderedTime,
            "status_id" => 2,
            "payment_status_id" => 2,
            "happy_hour" => 0
        ];
    }

    private function orderTotal(array $items) : float
    {
        return array_reduce(
            $items,
            fn(float $carry, array $itm): float => $carry + ($itm["unit_price"] * $itm["quantity"]),
            0.0
        );
    }

    public function indexActive() : ?array
    {
        $shopId = $this->config->secret("company.shop_id");

        try{
            $orders = $this->repo->selectRows(
                $this->table("orders", $shopId),
            );


            $orderItems = $this->repo->selectRows($this->table("order_items", $shopId));
            
            $itemsByOrderId = [];

            foreach ($orderItems as $item) {
                $itemsByOrderId[$item["order_id"]][] = $item;
            }

            foreach ($orders as &$order) {
                $order["items"] = $itemsByOrderId[$order["id"]] ?? [];
            }

            return $orders;
        }catch(\Exception $e){
            throw new ApiException($e->getMessage());
            return null;
        }
    }


    // Validates that each order item has a numeric price, qty, and product_id.
    public function validateItems(array $items): void
    {
        $itemRules = [
            "item_description"  => ["type" => "string",  "label" => "Description"],
            "unit_price"        => ["type" => "numeric", "label" => "Price"],
            "vat_percentage"    => ["type" => "numeric", "label" => "Vat percentage"],
            "quantity"          => ["type" => "numeric", "label" => "Quantity"],
            "product_id"        => ["type" => "numeric", "label" => "Product Id"],
        ];

        foreach ($items as $index => $item) {
            foreach ($itemRules as $field => $rule) {
                $value = $item[$field] ?? null;
                $valid = $rule["type"] === "string"
                    ? isset($value) && is_string($value)
                    : isset($value) && is_numeric($value);

                if (!$valid) {
                    throw new ValidationException([
                        "order_item" => "{$rule['label']} is required and must be a {$rule['type']} value (item #{$index})"
                    ]);
                }
            }
        }
    }

    function isValidMySQLDateTime(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return false;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);

        return $dt !== false && $dt->format('Y-m-d H:i:s') === $value;
    }

    // Validates that an order has a phone number and at least one item.
    public function validateOrder(array $order): void
    {
        if (!isset($order["phonenumber"])) {
            throw new ValidationException(["Phone Number" => "Order must have phone number!"]);
        }

        $fieldRules = [
            "logistic_type"    => ["type" => "numeric",  "label" => "Logistic type"],
            "payment_type"     => ["type" => "numeric",  "label" => "Payment type"],
            "pick_up_time"     => ["type" => "datetime", "label" => "Pickup time"],
            "pick_up_moment"   => ["type" => "datetime", "label" => "Pickup moment"],
            "delivery_moment"  => ["type" => "datetime", "label" => "Delivery moment"],
            "full_name"        => ["type" => "string",   "label" => "Full name"],
            "note"             => ["type" => "string",   "label" => "Note"],
            "country"          => ["type" => "string",   "label" => "Country"],
            "state"            => ["type" => "string",   "label" => "State"],
            "city"             => ["type" => "string",   "label" => "City"],
            "zip"              => ["type" => "string",   "label" => "Zip"],
            "street"           => ["type" => "string",   "label" => "Street"],
        ];

        foreach ($fieldRules as $field => $rule) {
            if (!isset($order[$field])) {
                continue; // all these fields are optional
            }
            $value = $order[$field];
            $valid = match ($rule["type"]) {
                "numeric"  => is_numeric($value),
                "string"   => is_string($value),
                "datetime" => $this->isValidMySQLDateTime($value),
            };

            if (!$valid) {
                $message = $rule["type"] === "datetime"
                    ? "Invalid date format for {$rule['label']}!"
                    : "{$rule['label']} must be a {$rule['type']} value!";

                throw new ValidationException([$rule["label"] => $message]);
            }
        }

        if (empty($order["items"])) {
            throw new ValidationException(["Items" => "Order must have items"]);
        }

        $this->validateItems($order["items"]);
    }

    // Finds an order by id, or null if it doesn't exist.
    public function getById(int $orderId, int $shopId) : ?array
    {
        $apiUrl = $this->config->secret("orderlemon_api.url", "");
        $apiToken = $this->config->secret("orderlemn_api.token", "");

        if( trim($apiUrl) === "" || trim($apiToken) === ""){
            throw new UnexpectedValueException("Api url or token is missing!");
        }

        try{
            $order = ApiRequestHelper::apiRequest(
                $apiUrl,
                $this->table("orders", $shopId),
                "select_row",
                [],
                "id = " . $orderId,
                $apiToken,                
                );
                
            if( $order === null || $order === false){
                return null;
            }

            $orderItems = ApiRequestHelper::apiRequest(
                    $apiUrl,
                    $this->table("order_items", $shopId),
                    "select_rows",
                    [],
                    "order_id = " . $orderId,
                    $apiToken,                
                );
            $order["items"] = $orderItems;

            return $order;
        } 
        catch(\Exception $e){
            $this->logger->error("Error in creating order: " . $e->getMessage());
            return null;
        }
    }

    // Loads an order by id and builds its receipt data, or null if not found.
    public function prepareForTicket(int $orderId, int $shopId) : ?array
    {
        $order = $this->getByid($orderId, $shopId);

        if($order === null){
            return null;
        }

        return $this->addReceiptData($order);
    }


    // Builds the receipt data structure (locales, company/shop info, order info) used to render a ticket.
    public function addReceiptData(array $order) : array
    {

        $company = $this->config->secret("company.name");
        $companyAddress = $this->config->secret("company.address");
        $shop = $this->config->secret("company.shop.name");
        $shopAddress = $this->config->secret("company.shop.address");

        $orderItems = $this->ticketOrderitems($order["items"]);

        $receiptData = ["locales" => 
                            [
                                "vat_ticket" => "Ticket",
                                "vat" => "VAT",
                                "total" => "Total",
                                "vat_excl" => "EXCL. VAT",
                                "vat_incl" => "INCL. VAT",
                                "order" => "Order",
                                "payment_type_label" => $order["payment_method_label"] ?? "",
                                "show_this_code" => "Show this code in shop!",
                                "thank_you" => "Thank you and see you soon!",
                                ],

                        "company_info" => 
                            [
                                "name" => $company,
                                "address_formatted" => $companyAddress,
                                "vat_number" => "AAABBBCCC111222"
                            ],

                        "shop_info" =>
                            [
                                "name" => $shop,
                                "address_formatted" => $shopAddress
                            ],
                            
                        "order_info" => 
                            [
                                "ordered_time" => $order["ordered_time"],
                                "order_items" => $orderItems
                            ]
                        ];

        return $receiptData;
    }

    private function ticketOrderitems(array $items) : array
    {
        $result = [];

        foreach($items as $item){

            $ticketItem = [
                "id" => $item["id"],
                "item_description" => $item["item_description"],
                "unit_price" => $item["unit_price"],
                "vat_percentage" => $item["vat_percentage"],
                "quantity" => $item["quantity"],
                "product_id" => $item["product_id"],
                // "config" => isset($item["config"]) ? $this->ticketOrderitems($item["config"]) : []
                "config" => $item["config"] ?? []
            ];

            $result[] = $ticketItem;

            foreach($ticketItem["config"] as $configIndex => $configItem){
                $ticketItem["config"][$configIndex]["parent_id"] = $item["id"];
                $result[] = $ticketItem["config"][$configIndex];
            }
            
        }
        return $result;
    }

    // Builds the signed URL to the receipt HTML template for a given order.
    private function generateTemplateUrl(int $id) : ?string
    {
        $token = $this->config->secret("ms_server_token");

        $templatePath = $this->config->secret("receipt.template");

        $host = $_SERVER['HTTP_HOST'];

        $protocol = isset($_SERVER['SERVER_PROTOCOL']) && strpos(trim($_SERVER["SERVER_PROTOCOL"]), "https") !== false
            ? 'https' : 'http';

        $ticketUri = $protocol . "://" . $host . "/" . ltrim($templatePath, "/");
        
        $query = http_build_query(["order_id" => $id, "token" => $token]);

        $ticketUri .= "?" . $query;

        return $ticketUri;
    }

    // Requests a ticket image url for an order and returns its full URL, or null on failure.
    public function createTicket(int $id): ?string
    {

        $templateUrl = $this->generateTemplateUrl($id);
        
        $ticketGenerationApiUrl = $this->config->secret("receipt.api");

        $generatedImagePath = $this->generateImagePath($id);
        
        $payload = [
            "a" => "create_ticket_image",
            "data" => [
                "url" => $templateUrl,
                "path" => $generatedImagePath
            ]];

        $result =  $this->callTicketGenerationApi($ticketGenerationApiUrl, $payload);

        if($result === null || trim($result) === ""){
            return null;
        }

        return rtrim($ticketGenerationApiUrl, "/") . "/" . ltrim($result, "/"); 
    }

    // Calls the ticket generation API and returns the generated image path, or null on failure.
    private function callTicketGenerationApi(string $url, array $payload): ?string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false || $curlError !== "") {
            $this->logger->error("Ticket generation API call failed: {$curlError}");
            return null;
        }

        if ($statusCode !== 200) {
            $this->logger->error("Ticket generation API returned HTTP {$statusCode}: {$response}");
            return null;
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || ($decoded["success"] ?? false) !== true || !isset($decoded["data"]["path"])) {
            $this->logger->error("Ticket generation API returned an unexpected response: {$response}");
            return null;
        }

        return $decoded["data"]["path"];
    }

    // Generates a unique image filename for the order's ticket.
    private function generateImagePath(int $id) : string
    {
        $currentDate = new \DateTime();
        $stamp = $currentDate->getTimestamp();

        $base = "dominos_demo_order_" . $id . "_" . $stamp . "_";
        $hashed = hash("sha256", $base);

        return $base . $hashed . ".png";
    }

    public function loadForPhone(string $phone): array
    {
        $orders = $this->load("orders");

        $userOrders = [];

        foreach ($orders as $order) {
            if (isset($order["client_phone"]) && $order["client_phone"] === $phone) {
                $userOrders[] = $order;
            }
        }

        return $userOrders;
    }
    
    /**
     * This shopper's non-terminal orders, most recent first.
     *
     * @return list<array<string,mixed>>
     */
    public function activeOrdersFor(string $phone): array
    {
        $orders = $this->load('orders');
        $mine   = [];

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            if (!CustomerHelper::samePhone((string) ($order['client_phone'] ?? ''), $phone)) {
                continue;
            }

            $status = (string) ($order['status'] ?? 'pending');
            if ($status === OrderStatus::Cancelled->value || $status === strtolower(OrderStatus::Delivered->value)) {
                continue;
            }

            $mine[] = $order;
        }
        
        usort(
            $mine,
            static fn(array $a, array $b): int => strcmp(
                (string) ($b['ordered_time'] ?? ''),
                (string) ($a['ordered_time'] ?? '')
            )
        );

        $this->logger->info('marvin.activeOrdersFor', [
            'phone'  => $phone,
            'found'  => count($mine),
            'orders' => array_map(
                fn(array $o): array => [
                    'order_id' => (int) ($o['id'] ?? 0),
                    'status'   => (string) ($o['status'] ?? 'pending'),
                ],
                $mine
            ),
        ]);

        return array_values($mine);
    }
}
?>