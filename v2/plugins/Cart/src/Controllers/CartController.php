<?php

declare(strict_types=1);

namespace Plugins\Cart\Controllers;

use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Services\CartService;
use Pmsrapi\V2\Services\CartSyncService;
use Pmsrapi\V2\Services\OrderQueryService;
use Pmsrapi\V2\Services\ChatTranscriptService;
use Pmsrapi\V2\Services\PrintService;
use Pmsrapi\V2\Services\ShopService;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;
use Plugins\Whatsapp\Gateway\WhatsappGateway;

/**
 * A plain controller. It returns a {@see Response} exactly like a core
 * controller does — the envelope, status codes, and streaming helpers are all
 * available to plugins.
 */
final class CartController
{
    function __construct(
        private readonly CartService $cartService,
        private readonly CartSyncService $cartSyncService,
        private readonly WhatsappGateway $whatsappGateway,
        private readonly OrderQueryService $queryService,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ChatTranscriptService $transcripts,
        private readonly PrintService $printService,
        private readonly ShopService $shopService,
    ){}

    public function update(Request $request): Response
    {
        $body = $request->body;

        if(!isset($body["phonenumber"])){
            throw new ValidationException(["phone" => "Missing phone parameter!"]);
        }

        if(!isset($body["items"])){
            throw new ValidationException(["phone" => "Missing items!"]);
        }

        $result = $this->cartService->updateCart($body["items"], $body["phonenumber"]);

        if($result === null){
            return Response::error(503, ["cart busy" => "Cart is busy! Please try again!"]);
        }

        return Response::ok($result);
    }

    public function sync(Request $request): Response
    {
        $body = $request->body;

        if(!isset($body["phonenumber"])){
            throw new ValidationException(["phone" => "Missing phone parameter!"]);
        }

        if(!isset($body["items"]) || !is_array($body["items"])){
            throw new ValidationException(["items" => "Missing or invalid items!"]);
        }

        $result = $this->cartSyncService->replaceCart($body["items"], $body["phonenumber"]);

        if($result === null){
            return Response::error(503, ["cart busy" => "Cart is busy! Please try again!"]);
        }
        
        return Response::ok($result);
    }

    public function checkout(Request $request): Response
    {
        $body = $request->body;

        if(!isset($body["phonenumber"])){
            throw new ValidationException(["phone" => "Missing phone parameter!"]);
        }

        if(!isset($body["checkout_data"])){
            throw new ValidationException(["checkout data" => "Missing checkout data!"]);
        }

        $order = $this->cartService->checkoutOrder($body["checkout_data"], $body["phonenumber"]);

        // Marvin never sees this HTTP call — the shopper can tap the live
        // cart link attached to every add/remove reply and finish on the web
        // at any point in the conversation. Without this note in his
        // transcript, his next reply has no way to know that basket is now
        // placed and closed, and he will keep treating it as still open.
        $this->noteCheckoutInTranscript($body["phonenumber"]);

        $ticketUrl = $this->queryService->createTicket($order["id"]);

        
        if($ticketUrl === null){
            $this->sendOrderLostMessage($body["phonenumber"]);
            //send order lost message
            return Response::Ok(
                [
                    "status" => "failure",
                    "message" => "Order was created but the ticked could not be generated!",
                    "data" => ["order_id" => $order["id"]]
                ]);
        }

        // currently using order time for pickup time
        $this->sendTicketToClient($body["phonenumber"], $order["ordered_time"], $ticketUrl);

        $this->sendThankYouMessage($body["phonenumber"]);

        $this->printService->sendRequest($order["id"]);

        return Response::Ok(
            [
                "status" => "success",
                "data" => $order
            ]);

    }

    public function getCart(Request $request, string $phone): Response
    {
        $body = $request->body;

        if(trim($phone) === ""){
            throw new ValidationException(["phone" => "Invalid phone parameter!"]);
        }

        $order = $this->cartService->activeOrderFor($phone);

        if($order === null){
            return Response::error(404, ["not found" => "No active cart for this phone number!"]);
        }

        $fullOrder = $this->cartService->withItemsAndTotal($order["id"], [], false, true);

        return Response::ok(["items" => $fullOrder["items"]]);

    }

    /**
     * Records that this phone's order was just checked out via the web link,
     * so Marvin::history() {@see \Plugins\Whatsapp\AI\Marvin} carries the
     * fact into the shopper's very next message. Best-effort: a transcript
     * write failure must never fail the checkout itself.
     */
    private function noteCheckoutInTranscript(string $phone) : void
    {
        try {
            $this->transcripts->append(
                $phone,
                "[System note - not shown to the shopper: they just finished checkout "
                    . "on the web for the order you were building. It is placed and done, "
                    . "and you have no way to change it anymore. Anything they ask for next "
                    . "is a brand-new order - call add_to_order like usual, a fresh basket "
                    . "opens on its own.]",
                'out',
                'text',
                'checkout_completed',
            );
        } catch (\Throwable $ex) {
            $this->logger->error("whatsapp: checkout transcript note", ["error" => $ex->getMessage()]);
        }
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

    public function ticketData(Request $request, string $orderId) : Response
    {
        if(empty($orderId) || !is_numeric($orderId)){
            throw new ValidationException(["Invalid data" => "Provided order id is invalid!"]);
        }

        $ticketData = $this->queryService->prepareForTicket((int)$orderId);

        if( $ticketData === null){
            return Response::error(503, ["error" => "error retrieveing data"]);
        }

        return Response::ok($ticketData);
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
                "Thank you for ordering at {$this->shopService->name()}!",
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

}
