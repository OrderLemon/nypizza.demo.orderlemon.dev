<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Controllers;

use Exception;
use Pmsrapi\v2\Services\ShopService;
use Plugins\Whatsapp\AI\Marvin;
use Plugins\Whatsapp\AI\MarvinTool;
use Plugins\Whatsapp\Gateway\WhatsappGateway;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Services\ClientService;
use Pmsrapi\V2\Services\ConversationService;
use Pmsrapi\V2\Services\ChatTranscriptService;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Core\Config;


/**
 * Inbound WhatsApp receiver.
 *
 * This service IS the "client webhook" the WhatsApp gateway
 * (api.wa.fabulor.io) forwards to: the gateway's send_payload_to_client()
 * POSTs an { "a": "incoming", ... } envelope to whatever URL is stored in that
 * account's `accounts.webhook` column, which for this project is this repo's
 *
 *   POST /v2/whatsapp
 *
 * We catch the inbound message, (later) process it, and reply to the sender via
 * the outbound {@see WhatsappGateway}. The reply is best-effort: we always ack a
 * 2xx once the payload is accepted, so the gateway marks the inbound delivered
 * even if the outbound leg is not yet configured.
 *
 * Two paths, decided by whether we have seen this phone number before:
 *
 *   first ever message -> welcomeCTA(): fixed greeting + catalogue link, no AI
 *   everything after   -> marvinReply(): Claude answers from the menu
 */
final class WhatsappController
{
    private string $clientPhone  = "";
    private ?string $clientName  = "";

    private array $messagePayload = [];

    /** @var array<string, mixed>|null the shop resolved by findShop() for this request */
    private ?array $shop = null;

    public function __construct(
        private readonly WhatsappGateway $gateway,
        private readonly ClientService $clientService,
        private readonly ConversationService $conversrationService,
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly Marvin $marvin,
        private readonly ShopService $shopService,
        private readonly ChatTranscriptService $transcripts,
    ) {}

    /**
     * POST /v2/whatsapp
     *
     * Body (from the gateway's send_payload_to_client envelope):
     *   { "a": "incoming", "phonenumber": "<account>", "sender_phone": "<user>",
     *     "message_type": "text|image|...", "data": { ... }, ... }
     */
    public function receive(Request $request): Response
    {
        $body = $request->body;

        $errors = [];

        $action = trim((string) ($body['a'] ?? ''));

        if ($action !== 'incoming') {
            $errors['a'] = 'Only the "incoming" action is handled here';
        }

        $errors = [...$errors, ...$this->getPayloadData($body)];

        if ($errors !== []) {
            $this->logger->error("whatsatpp: inbound message errors", $errors);
            throw new ValidationException($errors);
        }

        if(!$this->findShop()){
            $this->logger->error("whatsatpp: shop errors", ["shop not found" => "No shop found for this phone number {$this->messagePayload["shop_phone_number"]}"]);
            throw new ValidationException(["inexisting shop" => "Found no shop associated with this number!"]);
        }
        // $this->logger->info("whatsatpp: shop details", $this->shop);

        $reply = $this->reply();

        return Response::ok([
            'received' => true,
            'account' => $this->messagePayload["account"],
            'sender' => $this->messagePayload["phone_number"],
            'message_type' => $this->messagePayload["message_type"],
            'reply' => $reply,
        ]);
    }

    /**
     * Ask Claude, send the answer, log it as an outbound turn.
     *
     * The inbound message is already in the conversation transcript by the
     * time we get here (reply() logs it via $this->transcripts first), so the
     * transcript IS the request history. Nothing else to assemble.
     *
     * @return array{sent: bool, error?: string}
     */
    private function marvinReply() : array
    {

        $reply = $this->marvin->reply($this->transcripts->load($this->messagePayload["phone_number"]), $this->shopInfo(),  $this->messagePayload["full_name"]);
        
        // var_dump($reply["type"]);
        
        return match ($reply["type"] ?? '') {
            'text' => $this->sendMarvinText($reply["message"]),
            MarvinTool::TrackOrder->value => $this->sendTrackingLocation($reply),
            MarvinTool::GreetWithUsual->value => $this->greetWithUsual($reply),
            MarvinTool::GetUsualForUser->value => $this->ctaWithUsualOrder($reply),
            MarvinTool::FilterProducts->value => $this->ctaWithProducts($reply),
            MarvinTool::CheckoutOrder->value => $this->draftStatus($reply),
            MarvinTool::AddToOrder->value => $this->draftStatus($reply),
            MarvinTool::RemoveFromOrder->value => $this->draftStatus($reply),
            default => ['sent' => false, 'error' => "Marvin returned an unknown reply type: " . ($reply["type"] ?? '')],
        };
    }

    /**
     * The shared reply path for add_to_order, remove_from_order and
     * checkout_order: read the basket back in the message and send the plain
     * shop link, no reference or product ids appended. All three tools work
     * off the cart the phone number already owns server side, so the web
     * front end picks it straight up — nothing to encode in the URL.
     */
    private function draftStatus(array $reply) : array
    {
        if( !isset($reply["message"])){
            throw new ApiException("Marvin did not return a message or order history to send!");
        }

        try {

            $this->gateway->sendLink(
                $this->messagePayload["phone_number"],
                $reply["message"],
                "OPEN",
                $this->shopLinkWithCheckout(),
                "To get your menu always click here",
                null,
                $this->messagePayload["conversation_id"]);

            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context. Tagged with
            // the tool that actually produced this reply, not a fixed one, so
            // Marvin::history() only ever staleifies the right kind of turn.
            $this->transcripts->append($this->messagePayload["phone_number"], $reply["message"], 'out', 'text', $reply["type"] ?? null);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => "",
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function ctaWithProducts(array $reply) : array
    {
        if( !isset($reply["message"])){
            throw new ApiException("Marvin did not return a message or order history to send!");
        }

        if(!isset($reply["product_ids"]) || !is_array($reply["product_ids"])
                || count($reply["product_ids"]) < 1 ){
            //respond with the basic CTA link
            return $this->sendMarvinText($reply["message"]);
        }

        try {

            $this->gateway->sendLink(
                $this->messagePayload["phone_number"],
                $reply["message"],
                "OPEN",
                $this->shopLinkWithProducts($reply["product_ids"]),
                "To get your menu always click here",
                null,
                $this->messagePayload["conversation_id"]);
            
            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->transcripts->append($this->messagePayload["phone_number"], $reply["message"], 'out', 'text', MarvinTool::GetUsualForUser->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => "",
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function ctaWithUsualOrder(array $reply) : array
    {
        if( !isset($reply["message"]) || !isset($reply["order"])){
            throw new ApiException("Marvin did not return a message or order history to send!");
        }

        //no order history, just send the text message
        //TO DO: needs fix, marvin should not return a message with no order, but if it does, we just send the text
        if(is_array($reply["order"]) && count($reply["order"]) === 0){
            return $this->sendMarvinText($reply["message"]);
        }

        try {

            $this->gateway->sendLink(
                $this->messagePayload["phone_number"],
                $reply["message"],
                "OPEN",
                $this->shopLinkWithUsualOrder($reply["order"]["hash"]),
                "To get your menu always click here",
                null,
                $this->messagePayload["conversation_id"]);
            
            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->transcripts->append($this->messagePayload["phone_number"], $reply["message"], 'out', 'text', MarvinTool::GetUsualForUser->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => "",
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function greetWithUsual(array $reply) : array
    {
        if( !isset($reply["message"]) || !isset($reply["order_history"])){
            throw new ApiException("Marvin did not return a message or order history to send!");
        }

        //no order history, just send the text message
        //TO DO: needs fix, marvin should not return a message with no order, but if it does, we just send the text
        if(is_array($reply["order_history"]) && count($reply["order_history"]) === 0){
            return $this->sendMarvinText($reply["message"]);
        }

        try {
            $this->gateway->sendButtons(
                $this->messagePayload["phone_number"],
                $reply["message"],
                $this->getButtonsForReturningUser(),
                $this->messagePayload["conversation_id"]);
            
            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->transcripts->append($this->messagePayload["phone_number"], $reply["message"], 'out', 'text', MarvinTool::GreetWithUsual->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => "",
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendTrackingLocation(array $reply) : array
    {
        try {
            
            $this->sendMarvinText($reply["message"]);
        
            $this->gateway->sendLocation(
                $this->messagePayload["phone_number"],
                $reply["tracking"]["current"]["lat"],
                $reply["tracking"]["current"]["lng"],
                "",
                "",
                $this->messagePayload["conversation_id"]);

            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->transcripts->append($this->messagePayload["phone_number"], $reply["message"], 'out', 'text', MarvinTool::TrackOrder->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => "",
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendMarvinText(string $marvinReplyMessage) : array
    {
        try {
            $this->gateway->sendLink(
                $this->messagePayload["phone_number"],
                $marvinReplyMessage,
                "OPEN",
                $this->shopLink(),
                "To get your menu always click here",
                null,
                $this->messagePayload["conversation_id"]);

            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->transcripts->append($this->messagePayload["phone_number"], $marvinReplyMessage, 'out', 'text');

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => "text",
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Reply to user with the CTA link to the shop
     *
     * @return array{sent: bool, error?: string}
     */
 
    private function welcomeCTA(): array
    {
        $shopName = ucwords($this->shop["name"]);

        $greeting = "Hi, Welcome to " . $shopName;

        try {
            $this->gateway->sendLink(
                $this->messagePayload["phone_number"],
                $greeting,
                "OPEN",
                $this->shopLink(),
                "To get your menu always click here",
                $this->headerImage(),
                $this->messagePayload["conversation_id"]);

            // Record the greeting so Marvin knows the shopper was already
            // welcomed and does not greet them a second time.
            $this->transcripts->append($this->messagePayload["phone_number"], $greeting, 'out', 'text');

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->warning('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => '',
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }
 
    
    private function sendText(string $message): array
    {
        if(trim($message) === null){
            return ["sent" => false, "error" => "Empty message!"];
        }

        try {
            $this->gateway->sendText(
                $this->messagePayload["phone_number"],
                $message,
                false,
                $this->messagePayload["conversation_id"]);

            // Record the greeting so Marvin knows the shopper was already
            // welcomed and does not greet them a second time.
            $this->transcripts->append($this->messagePayload["phone_number"], $message, 'out', 'text');

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->warning('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => '',
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }


    private function findShop(): bool
    {
        $shop = $this->shopService->getByPhone($this->messagePayload["shop_phone_number"]);

        if ($shop === null || !isset($shop['id'])) {
            return false;
        }

        $this->shop = $shop;

        if (!defined('shop_id')) {
            define('shop_id', (int) $shop['id']);
        }

        return true;
    }

    private function headerImage() : ?string
    {
        return $this->config->secret("cta.header.image");
    }

    private function shopLinkWithProducts(array $ids) : string
    {
        return $this->shopLink() . "&products=" . join(",", $ids);
    }

    private function shopLinkWithUsualOrder(string $orderHash) : string
    {
        return $this->shopLink() . "&order=" . urlencode($orderHash);
    }

    private function shopLinkWithCheckout() : string
    {
        return $this->shopLink() . "&checkout=true";
    }

    private function shopLink() : string
    {
        $shopLink = $this->config->secret("cta.shop_link");
        return rtrim($shopLink, "/") . "/?phonenumber=" . urlencode($this->messagePayload["phone_number"]) . "&shop_id=" . shop_id;
    }

    /**
     * Send a first welcome message and continue with CTA url.
     * @return array{sent: bool, error?: string}
     */
    private function reply(): array
    {
        // add/update client
        $upsertResult = $this->clientService->upsertClient(
        [
            "phonenumber" => $this->messagePayload["phone_number"],
            "full_name" => $this->messagePayload["full_name"],
            "date_added" => date('Y-m-d H:i:s'),
        ]);

        //register conversation messages to the transcript
        $this->transcripts->append(
            $this->messagePayload["phone_number"],
            $this->messagePayload["message"],
            'in',
            $this->messagePayload["message_type"],
        );

        //create/update the conversation
        $this->conversrationService->upsertConversation($this->messagePayload["phone_number"]);

        // The shopper is answering our own location request - save it and
        // carry on, rather than asking them for it again below.
        if ($this->messagePayload["message_type"] === "location") {
            $result = $this->upsertClientLocation();

            $message = $result === null ? 
                "We could not retrieve your location. Please try again later!" 
                    : "Your location has been updated!";

            return $this->sendText($message);
        }

        //  || ($upsertResult["record"]["street"] ?? null) === null
        if ($upsertResult["action"] === "inserted" ) {
            $welcome = $this->welcomeCTA();

            if($welcome["sent"] !== true){
                return  ["sent" => false];
            }

            return $this->sendLocationRequest();
        }

        // return $this->welcomeCTA();
        return $this->marvinReply();
    }

    /**
     * Persist the lat/long carried by an inbound WhatsApp location message
     * against the client record. No-op if the payload had no usable location.
     */
    private function upsertClientLocation(): ?array
    {
        $location = $this->messagePayload["location"];

        if ($location === null) {
            return null;
        }

        return $this->clientService->upsertClient(
            [
                "phonenumber" => $this->messagePayload["phone_number"],
                "latitude" => $location["latitude"],
                "longitude" => $location["longitude"]
            ]);
    }

    private function sendLocationRequest() : array
    {
        try{
            $response = $this->gateway->sendLocationRequest($this->messagePayload["phone_number"], null);
            return ["sent" => true];
        }catch(Exception $ex){
              $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $this->messagePayload["phone_number"],
                'message_type' => "",
                'error' => $ex->getMessage(),
            ]);

            return ['sent' => false, 'error' => $ex->getMessage()];
        }
    }

    
    private function shopInfo() : array
    {
        if( $this->shop === [] || $this->shop === null){
            return [];
        }
        return [
            "name" => $this->shop["name"],
            "country" => $this->shop["country"],
            "city" => $this->shop["city"],
            "zip" => $this->shop["zip"],
            "street" => $this->shop["street"],
        ];
    }


    /**
     * Pull the gateway conversation id out of the inbound envelope, if present.
     *
     * @param array<string, mixed> $body
     */
    private function conversationId(array $body): ?int
    {
        $data = $body['data'] ?? null;
        $id = is_array($data) && isset($data['conversation']) && is_array($data['conversation'])
            ? ($data['conversation']['id'] ?? null)
            : null;

        return is_numeric($id) ? (int) $id : null;
    }

    /*
    *   Currently uses shop number because the prompt requires shop information to be built.
    */
    public function health(Request $request, string $shopPhone) : Response
    {
        if(trim($shopPhone) === ""){
            throw new ValidationException(["shop phone" => "shop phone is required!"]);
        }

        $this->messagePayload["shop_phone_number"] = $shopPhone;

        $this->findShop();

        if($this->shop === null || $this->shop === []){
            Response::error(404,["shop" => "No shop found!"]);
        }

        $checkResults = $this->marvin->selfCheck($this->shop);

        return Response::ok(["data" => $checkResults]);
    }

    private function extractClientName(array $body) : ?string
    {
        if(!isset($body["data"]["contact"])){
            return null;
        }

        $contact = $body["data"]["contact"];

        $fullName = "";

        if(isset($contact["firstName"]) && trim($contact["firstName"]) !== ""){
            $fullName = $contact["firstName"];
        }

        if(isset($contact["lastName"]) && trim($contact["lastName"]) !== ""){
            $fullName .= " " . $contact["lastName"];
        }

        if(trim($fullName) === "" && isset($contact["displayName"]) && trim($contact["displayName"]) !== ""){
            $fullName = $contact["displayName"];
        }

        return trim($fullName) !== "" ? $fullName : null;
    }

    private function extractMessage(array $body): string
    {
        $message = '';

        // get text message from the body, depending on the message type
        if(isset($body['data']["message"]["content"]["text"])){
            $message = $body['data']["message"]["content"]["text"];
        // if the message is interactive reply, get the text from the interactive reply
        }elseif (isset($body['data']["message"]["content"]["interactive"]["reply"]["text"])){
            $message = trim((string) ($body['data']["message"]["content"]["interactive"]["reply"]["text"] ?? ''));
        }else{
            $message = trim((string)($body['data']["text"] ?? ''));
        }

        return $message;
    }

    /**
     * Pull the lat/long out of an inbound WhatsApp location message.
     *
     * @param array<string, mixed> $body
     * @return array{latitude: string, longitude: string}|null
     */
    private function extractLocation(array $body): ?array
    {
        $location = $body['data']['message']['content']['location'] ?? null;

        if (!is_array($location) || !isset($location['latitude'], $location['longitude'])) {
            return null;
        }

        return [
            'latitude' => (string) $location['latitude'],
            'longitude' => (string) $location['longitude'],
        ];
    }

    private function getPayloadData(array $body) : array
    {
        // $this->logger->info("inbound message data", $body);

        $this->messagePayload["account"] = trim((string) ($body['phonenumber'] ?? ''));
        $this->messagePayload["message_type"] = trim((string) ($body['message_type'] ?? ''));
        $this->messagePayload["phone_number"] = trim((string) ($body['sender_phone'] ?? ''));
        $this->messagePayload["full_name"] = $this->extractClientName($body);
        $this->messagePayload["message"] = $this->extractMessage($body);
        $this->messagePayload["location"] = $this->extractLocation($body);
        $this->messagePayload["conversation_id"] = $this->conversationId($body);
        $this->messagePayload["shop_phone_number"] = trim((string) ($body['phonenumber'] ?? ''));

        $errors = [];

        if ($this->messagePayload["phone_number"] === '') {
            $errors['sender_phone'] = 'The sender phonenumber was not received!';
        }

        if ($this->messagePayload["shop_phone_number"] === '') {
            $errors['shop_phone_number'] = 'The shop phonenumber was not received!';
        }

        // if ($this->messagePayload["message"] === '') {
        //     $errors['message'] = 'The message is required';
        // }

        $this->logger->info('whatsapp: inbound message received', [
            'account' => $this->messagePayload["account"],
            'sender' => $this->messagePayload["phone_number"] ,
            'message_type' => $this->messagePayload["message_type"],
            'message' => $this->messagePayload["message"],
            'message_id' => $body['message_id'] ?? null,
            'provider' => $body['data_provider'] ?? null,
        ]);


        return $errors;
    }

    private function getButtonsForReturningUser() : array
    {
        return [
            [
                "id" => "campaigntype-1",
                "type" => "reply",
                "title" => "The usual"
            ],
            [
                "id" => "campaigntype-2",
                "type" => "reply",
                "title" => "Something else"
            ],
            [
                "id" => "campaigntype-3",
                "type" => "reply",
                "title" => "Today's promo"
            ],

        ];
    }
}