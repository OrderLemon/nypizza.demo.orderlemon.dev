<?php

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Services\JsonService;
use Pmsrapi\V2\Services\TrackingService;
use Pmsrapi\V2\Orders\OrderStatus;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Helpers\CustomerHelper;

final class OrderQueryService extends JsonService
{
    function __construct(
        protected Logger $logger,
        protected Config $config,
        protected TrackingService $trackingService,
    ){
        parent::__construct($logger, $config);
    }
    // Inserts a new order and returns its generated id, or false on failure.
    public function create(array $order): ?array
    {
        try{

            $orderDate = new \DateTime();
            $order["ordered_time"] = $orderDate->format("Y-m-d h:i:s");
            $order["status"] = OrderStatus::Ordered->value;

            foreach($order["items"] as $index => $item){
                if(!isset($item["id"])){
                    $order["items"][$index]["id"] = $index + 1;
                }
            }

            $ids = $this->addItems([$order], "orders");

            if(count($ids) < 1){
                return null;
            }

            $order["id"] = $ids[0];

            $this->trackingService->seed($order);
            return $order;
        }
        catch(\Exception $e){
            $this->logger->error("Error in creating order: " . $e->getMessage());
            return null;
        }
    }

    // Validates that each order item has a numeric price, qty, and product_id.
    public function validateIems(array $items): void
    {
        foreach($items as $index => $item){
            if(!isset($item["item_description"]) || !is_string($item["item_description"])){
                throw new ValidationException(["order_item" => "Description is required and must be a string"]);
            }

            if(!isset($item["unit_price"]) || !is_numeric($item["unit_price"])){
                throw new ValidationException(["order_item" => "Price is required and must be a numeric value"]);
            }
          
            if(!isset($item["vat_percentage"]) || !is_numeric($item["vat_percentage"])){
                throw new ValidationException(["order_item" => "Vat percentage is required and must be a numeric value"]);
            }

            if(!isset($item["quantity"]) || !is_numeric($item["quantity"])){
                throw new ValidationException(["order_item" => "Quantity is required and must be a numeric value"]);
            }

            if(!isset($item["product_id"]) || !is_numeric($item["product_id"])){
                throw new ValidationException(["order_item" => "Product Id is required and must be a numeric value"]);
            }

            if(isset($item["config"]) && is_array($item["config"]) && count($item["config"]) > 0){

                foreach($item["config"] as $configIndex => $configItem){
                    if(!isset($configItem["item_description"]) || !is_string($configItem["item_description"])){
                        throw new ValidationException(["order_item" => "Config description is required and must be a string"]);
                    }
          
                    if(!isset($configItem["vat_percentage"]) || !is_numeric($configItem["vat_percentage"])){
                        throw new ValidationException(["order_item" => "Vat percentage is required and must be a numeric value"]);
                    }

                    if(!isset($configItem["unit_price"]) || !is_numeric($configItem["unit_price"])){
                        throw new ValidationException(["order_item" => "Config price is required and must be a numeric value"]);
                    }

                    if(!isset($configItem["quantity"]) || !is_numeric($configItem["quantity"])){
                        throw new ValidationException(["order_item" => "Config quantity is required and must be a numeric value"]);
                    }

                    if(!isset($configItem["group_id"])){
                        throw new ValidationException(["order_item" => "Config group Id is required!"]);
                    }

                    if(!isset($configItem["option_id"])){
                        throw new ValidationException(["order_item" => "Config option Id is required!"]);
                    }
                }
            }
        }
    }

    // Validates that an order has a phone number and at least one item.
    public function validateOrder(array $order): void
    {
        if( !isset($order["client_phone"])){
            throw new ValidationException([(string) "Phone Number" => 'Order must have phone number!']);
        }

        if( !isset($order["payment_method"]) || !is_string($order["payment_method"]) || trim($order["payment_method"]) === ""){
            throw new ValidationException([(string) "Payment Method" => 'Order must have a valid payment method!']);
        }

        if( !isset($order["payment_method_label"]) || !is_string($order["payment_method_label"]) || trim($order["payment_method_label"]) === ""){
            throw new ValidationException([(string) "Payment Method" => 'Order must have a valid payment method!']);
        }

        if(!isset($order["items"]) || count($order["items"]) === 0){
            throw new ValidationException([(string) "Items" => 'Order must have items']);
        }

        $this->validateIems($order["items"]);
    }

    // Finds an order by id, or null if it doesn't exist.
    public function getById(int $id) : ?array
    {
        $orders = $this->load("orders");

        $order = null;

        foreach ($orders as $o) {
            if ($o["id"] == $id) {
                $order = $o;
                break;
            }
        }

        return $order;
    }

    // Loads an order by id and builds its receipt data, or null if not found.
    public function prepareForTicket(int $id) : ?array
    {
        $order = $this->getByid($id);

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