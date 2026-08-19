<?php
declare(strict_types=1);

namespace Plugins\Orders\Controllers;

use Plugins\Whatsapp\Gateway\WhatsappGateway;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Services\OrderQueryService;
use Pmsrapi\V2\Services\UsualOrderService;
use Pmsrapi\V2\Services\DraftOrderService;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Support\Logger;

final class OrderController
{
    private const RESERVED_QUERY = ['page', 'per_page', 'order', 'fields'];

    public function __construct(
        private readonly OrderQueryService $queryService,
        private readonly UsualOrderService $usualOrderService,
        private readonly WhatsappGateway $whatsappGateway,
        private readonly DraftOrderService $drafts,
        private readonly Logger $logger,
        private readonly Config $config,
    ) {
    }

    public function indexActive(Request $request) : Response
    {
        $orders = $this->queryService->indexActive();
        return Response::ok(["orders" => $orders]);
    }

    public function referenceOrder(Request $request): Response
    {
        $values = $request->body;

        if(!isset($values["reference"])){
            throw new ValidationException(['reference' => 'Reference is missing!']);
        }

        $draft = $this->drafts->byReference($values["reference"]);

        if($draft === null || empty($draft)){
            return Response::error(404, ["not found" => "Draft not found!"]);
        }

        return Response::ok($draft);
    }

    public function usualFor(Request $request, $phone) : Response
    {
        if(empty($phone) || !is_numeric($phone)){
            throw new ValidationException(["Invalid data" => "Provided phone is invalid!"]);
        }

        $orders = $this->usualOrderService->forPhone($phone);

        if(empty($orders)){
            return Response::ok(["status" => "success", "message" => "No usual orders found.", "orders" => []]);
        }

        return Response::ok(["status" => "success", "orders" => $orders]);
    }

    public function indexActiveForClient(Request $request, string $phone) : Response
    {
        if(empty($phone) || !is_numeric($phone)){
            throw new ValidationException(["Invalid data" => "Provided phone is invalid!"]);
        }

        $orders = $this->queryService->loadForPhone($phone);

        if(empty($orders)){
            return Response::ok(["status" => "success", "message" => "No active orders found.", "orders" => []]);
        }

        return Response::ok(["status" => "success", "orders" => $orders]);
    }

    public function store(Request $request): Response
    {
        $data = $request->body['data'] ?? null;

        if (!is_array($data) || $data === []) {
            throw new ValidationException(['data' => 'A non-empty "data" object is required']);
        }

        foreach ($data as $key => $value) {
            if (!is_array($value) && $value !== null && !is_scalar($value)) {
                throw new ValidationException([(string) $key => 'Value must be a scalar or null']);
            }
        }

        $this->queryService->validateOrder($data);

        $order = $this->queryService->create($data); 

        if($order === null){
            // $this->sendOrderLostMessage($data["phonenumber"]);
            //send order lost message
            return Response::error(503, ["status" => "failed", 'message' => 'Failed to create order.']);
        }

        // $cleared = $this->drafts->clear($data["phonenumber"]);

        // $ticketUrl = $this->queryService->createTicket($order["id"]);

        
        // if($ticketUrl === null){
        //     // $this->sendOrderLostMessage($data["phonenumber"]);
        //     //send order lost message
        //     return Response::Ok(
        //         [
        //             "status" => "failure",
        //             "message" => "Order was created but the ticked could not be generated!",
        //             "data" => ["order_id" => $order["id"]]
        //         ]);
        // }

        //currently using order time for pickup time
        // $this->sendTicketToClient($values["client_phone"], $order["ordered_time"], $ticketUrl);

        // $this->sendThankYouMessage($values["client_phone"]);

        return Response::Ok(
            [
                "status" => "success",
                "data" => [
                    "order_id" => $order["id"],
                    // "ticket" => $ticketUrl,
            ]]);
    }

    public function show(Request $request, array $params): Response
    {
        $draft = $this->drafts->byReference(trim((string) $request->query('ref', '')));

        if ($draft === null) {
            throw new ApiException('No basket found for that reference', 404);
        }

        return Response::ok([
            'reference' => $draft['reference'],
            'items'     => $draft['items'],
            'total'     => $draft['total'],
        ]);
    }

    private function sendOrderLostMessage(string $phone, ?string $conversationId = null) : bool
    {
        try{
            $this->whatsappGateway->sendText($phone, "Your order got lost. Please contact our team: https://wa.me/ruvenss");
            return true;
        }catch(\Exception $ex){
            $this->logger->error("whatsapp: order lost", ["error" => $ex->getMessage()]);
            return false;
        }
    }

    private function sendConfirmationMessage(string $phone, ?string $conversationId = null) : bool
    {
        try{
            $this->whatsappGateway->sendText($phone, "You'll receive confirmation in a moment.");
            return true;
        }catch(\Exception $ex){
            $this->logger->error("whatsapp: order confirmation", ["error" => $ex->getMessage()]);
            return false;
        }
    }

    private function sendThankYouMessage(string $phone, ?string $conversationId = null) : bool
    {
        try
        {
            $this->whatsappGateway->sendButtons(
                $phone,
                "Thank you for ordering in Dominos Pizza Amsterdam!",
                $this->trackOrderButton(),
                $conversationId);
            
            return true;
        }catch(\Exception $ex){
            $this->logger->error("whatsapp: thank you message", ["error" => $ex->getMessage()]);
            return false;
        }
    }

    private function trackOrderButton() : array
    {
        return [
            [
                "id" => "campaigntype-1",
                "type" => "reply",
                "title" => "Track my order"
            ],
        ];
    }

    private function sendTicketToClient(string $phone, string $pickupDate,  string $ticketUrl) : bool
    {
        try
        {
            $shopAddress = $this->config->secret("company.shop.address");

            $caption = "Powered by OrderLemon\nTo pick up $shopAddress .\n\n At $pickupDate";

            $this->whatsappGateway->sendImage($phone, $ticketUrl, $caption);
            
            return true;
        }catch(\Exception $ex){
            $this->logger->error("whatsapp: order ticket", ["error" => $ex->getMessage()]);
            return false;
        }
    }

    public function ticketData(Request $request, string $shopId, string $orderId,) : Response
    {
        if(empty($orderId) || !is_numeric($orderId)){
            throw new ValidationException(["Invalid data" => "Provided order id is invalid!"]);
        }

        if(empty($shopId) || !is_numeric($shopId)){
            throw new ValidationException(["Invalid data" => "Provided shop id is invalid!"]);
        }

        $ticketData = $this->queryService->prepareForTicket((int)$orderId, (int)$shopId);

        if( $ticketData === null){
            return Response::error(503, ["error" => "error retrieveing data"]);
        }

        return Response::ok($ticketData);
    }

    public function decodeOrder(Request $request) : Response
    {
        $phone = trim((string) $request->body["phone"] ?? "");
 
        if ($phone === '') {
            throw new ValidationException(["phone" => 'phone is required']);
        }

        $hash = trim((string) $request->body['hash']);
 
        if ($hash === '') {
            throw new ValidationException(["hash" => 'hash is required']);
        }

        $items = $this->usualOrderService->basketFor($phone, $hash);

        if ($items === null) {
            return Response::error(404, ["basket" => "No basket found for that hash"]);
        }

        return Response::ok(["items" => $items]);
    }
}
?>