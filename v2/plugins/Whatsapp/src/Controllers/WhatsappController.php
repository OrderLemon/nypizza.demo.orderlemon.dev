<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Controllers;

use Plugins\Whatsapp\AI\Marvin;
use Plugins\Whatsapp\AI\MarvinTool;
use Plugins\Whatsapp\Gateway\WhatsappGateway;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Services\ClientService;
use Pmsrapi\V2\Services\ConversationService;
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
    private string $conversationJsonPath  = "";
    private string $clientPhone  = "";
    private string $clientName  = "";

    public function __construct(
        private readonly WhatsappGateway $gateway,
        private readonly ClientService $clientService,
        private readonly ConversationService $conversrationService,
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly Marvin $marvin,
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
        $action = trim((string) ($body['a'] ?? ''));
        $account = trim((string) ($body['phonenumber'] ?? ''));
        $messageType = trim((string) ($body['message_type'] ?? ''));

        $message = $this->extractMessage($body);

        $this->clientPhone = trim((string) ($body['sender_phone'] ?? ''));
        $this->clientName = $this->extractClientName($body);

        $errors = [];
        if ($action !== 'incoming') {
            $errors['a'] = 'Only the "incoming" action is handled here';
        }
        if ($account === '') {
            $errors['phonenumber'] = 'The receiving account phonenumber is required';
        }
        if ($this->clientPhone === '') {
            $errors['sender_phone'] = 'The sender phonenumber is required';
        }
        if ($message === '') {
            $errors['message'] = 'The message is required';
        }
        if ($errors !== []) {
            $this->logger->error("whatsatpp: inbound message errors", $errors);
            throw new ValidationException($errors);
        }

        $this->logger->info('whatsapp: inbound message received', [
            'account' => $account,
            'sender' => $this->clientPhone ,
            'message_type' => $messageType,
            'message' => $message,
            'message_id' => $body['message_id'] ?? null,
            'provider' => $body['data_provider'] ?? null,
        ]);

        $conversationId = $this->conversationId($body);
        
        $reply = $this->reply($message, $messageType, $conversationId);
        
        
        return Response::ok([
            'received' => true,
            'account' => $account,
            'sender' => $this->clientPhone,
            'message_type' => $messageType,
            'reply' => $reply,
        ]);
    }

    /**
     * Ask Claude, send the answer, log it as an outbound turn.
     *
     * The inbound message is already in the conversation file by the time we
     * get here (reply() calls registerMessage first), so the file IS the
     * request history. Nothing else to assemble.
     *
     * @return array{sent: bool, error?: string}
     */
    private function marvinReply(string $sender, string $messageType, ?int $conversationId) : array
    {

        $reply = $this->marvin->reply($this->loadConversations());

        return match ($reply["type"] ?? '') {
            'text' => $this->sendMarvinText($sender, $reply["message"], $messageType, $conversationId),
            MarvinTool::TrackOrder->value => $this->sendTrackingLocation($sender, $reply, $messageType, $conversationId),
            MarvinTool::GreetWithUsual->value => $this->greetWithUsual($sender, $reply, $messageType, $conversationId),
            MarvinTool::GetUsualForUser->value => $this->ctaWithUsualOrder($sender, $reply, $messageType, $conversationId),
            MarvinTool::FilterProducts->value => $this->ctaWithProducts($sender, $reply, $messageType, $conversationId),
            MarvinTool::CheckoutOrder->value => $this->ctaWithCheckout($sender, $reply, $messageType, $conversationId),
            MarvinTool::AddToOrder->value => $this->draftStatus($sender, $reply, $messageType, $conversationId),
            MarvinTool::RemoveFromOrder->value => $this->draftStatus($sender, $reply, $messageType, $conversationId),
            default => ['sent' => false, 'error' => "Marvin returned an unknown reply type: " . ($reply["type"] ?? '')],
        };
    }

    private function draftStatus(string $sender, array $reply, string $messageType, ?int $conversationId) : array
    {
        try {
            if( !isset($reply["message"])){
                throw new ApiException("Marvin did not return a message or order history to send!");
            }

            $this->gateway->sendLink(
                $sender,
                $reply["message"],
                "OPEN",
                $this->shopLink(),
                "To get your menu always click here",
                null,
                $conversationId);
            
            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->registerMessage($reply["message"], 'text', 'out', MarvinTool::GetUsualForUser->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $sender,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function ctaWithCheckout(string $sender, array $reply, string $messageType, ?int $conversationId) : array
    {
        if( !isset($reply["checkout"]["reference"]) || !isset($reply["checkout"]["draft"])){
            $this->logger->error("marvin.checkout", ["checkout" => "missing reference or draft from the checkout"]);
            return $this->sendMarvinText($sender, "Something went wrong while proceeding to checkout!", $messageType, $conversationId);
        }

        try {

            $this->gateway->sendLink(
                $sender,
                $reply["message"],
                "OPEN",
                $this->shopLinkWithDraft($reply["checkout"]["reference"]),
                "To get your menu always click here",
                null,
                $conversationId);
            
            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->registerMessage($reply["message"], 'text', 'out', MarvinTool::GetUsualForUser->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $sender,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function ctaWithProducts(string $sender, array $reply, string $messageType, ?int $conversationId) : array
    {
        if( !isset($reply["message"])){
            throw new ApiException("Marvin did not return a message or order history to send!");
        }

        if(!isset($reply["product_ids"]) || !is_array($reply["product_ids"])
                || count($reply["product_ids"]) < 1 ){
            //respond with the basic CTA link
            return $this->sendMarvinText($sender, $reply["message"], $messageType, $conversationId);
        }

        try {

            $this->gateway->sendLink(
                $sender,
                $reply["message"],
                "OPEN",
                $this->shopLinkWithProducts($reply["product_ids"]),
                "To get your menu always click here",
                null,
                $conversationId);
            
            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->registerMessage($reply["message"], 'text', 'out', MarvinTool::GetUsualForUser->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $sender,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function ctaWithUsualOrder(string $sender, array $reply, string $messageType, ?int $conversationId) : array
    {
        if( !isset($reply["message"]) || !isset($reply["order"])){
            throw new ApiException("Marvin did not return a message or order history to send!");
        }

        //no order history, just send the text message
        //TO DO: needs fix, marvin should not return a message with no order, but if it does, we just send the text
        if(is_array($reply["order"]) && count($reply["order"]) === 0){
            return $this->sendMarvinText($sender, $reply["message"], $messageType, $conversationId);
        }

        try {

            $this->gateway->sendLink(
                $sender,
                $reply["message"],
                "OPEN",
                $this->shopLinkWithUsualOrder($reply["order"]["hash"]),
                "To get your menu always click here",
                null,
                $conversationId);
            
            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->registerMessage($reply["message"], 'text', 'out', MarvinTool::GetUsualForUser->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $sender,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function greetWithUsual(string $sender, array $reply, string $messageType, ?int $conversationId) : array
    {
        if( !isset($reply["message"]) || !isset($reply["order_history"])){
            throw new ApiException("Marvin did not return a message or order history to send!");
        }

        //no order history, just send the text message
        //TO DO: needs fix, marvin should not return a message with no order, but if it does, we just send the text
        if(is_array($reply["order_history"]) && count($reply["order_history"]) === 0){
            return $this->sendMarvinText($sender, $reply["message"], $messageType, $conversationId);
        }

        try {
            $this->gateway->sendButtons(
                $sender,
                $reply["message"],
                $this->getButtonsForReturningUser(),
                $conversationId);
            
            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->registerMessage($reply["message"], 'text', 'out', MarvinTool::GreetWithUsual->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $sender,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendTrackingLocation(string $sender, array $reply, string $messageType, ?int $conversationId) : array
    {
        try {
            
            $this->sendMarvinText($sender, $reply["message"], $messageType, $conversationId);
        
            $this->gateway->sendLocation(
                $sender,
                $reply["tracking"]["current"]["lat"],
                $reply["tracking"]["current"]["lng"],
                "",
                "",
                $conversationId);

            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->registerMessage($reply["message"], 'text', 'out', MarvinTool::TrackOrder->value);

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $sender,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendMarvinText(string $sender, string $message, string $messageType, ?int $conversationId) : array
    {

        try {
            $this->gateway->sendLink(
                $sender,
                $message,
                "OPEN",
                $this->shopLink(),
                "To get your menu always click here",
                null,
                $conversationId);

            // Log Marvin's own turn, or he will not see his previous answers on
            // the next message and the thread loses all context.
            $this->registerMessage($message, 'text', 'out');

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->error('whatsapp: outbound reply failed', [
                'sender' => $sender,
                'message_type' => $messageType,
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
    private function welcomeCTA(string $sender, string $messageType, ?int $conversationId): array
    {
        $greeting = "Hi, Welcome to Dominos Pizza!";

        try {
            $this->gateway->sendLink(
                $sender,
                $greeting,
                "OPEN",
                $this->shopLink(),
                "To get your menu always click here",
                $this->headerImage(),
                $conversationId);

            // Record the greeting so Marvin knows the shopper was already
            // welcomed and does not greet them a second time.
            $this->registerMessage($greeting, 'text', 'out');

            return ['sent' => true];
        } catch (ApiException $e) {
            $this->logger->warning('whatsapp: outbound reply failed', [
                'sender' => $sender,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function headerImage() : ?string
    {
        return $this->config->secret("cta.header.image");
    }

    private function shopLinkWithDraft(string $reference) : string
    {
        return $this->shopLink() . "&reference=" . $reference;
    }

    private function shopLinkWithProducts(array $ids) : string
    {
        return $this->shopLink() . "&products=" . join(",", $ids);
    }

    private function shopLinkWithUsualOrder(string $orderHash) : string
    {
        return $this->shopLink() . "&order=" . urlencode($orderHash);
    }

    private function shopLink() : string
    {
        $shopLink = $this->config->secret("cta.shop_link");
        return rtrim($shopLink, "/") . "/?phone=" . urlencode($this->clientPhone);
    }

    /**
     * Send a first welcome message and continue with CTA url.
     * @return array{sent: bool, error?: string}
     */
    private function reply($senderMessage, string $messageType, ?int $conversationId): array
    {
        $isNewClient = $this->clientService->isNewClient($this->safePhone());

        $this->registerMessage($senderMessage, $messageType);
        
        //create/update the conversation
        $this->conversrationService->upsertConversation($this->clientPhone);

        if($isNewClient){
            $this->clientService->upsertClient($this->clientPhone, $this->clientName);
            return ["sent" => true];
            // return $this->welcomeCTA($this->clientPhone, $messageType, $conversationId);
        }
            
        return ["sent" => true];
        // return $this->marvinReply($this->clientPhone, $messageType, $conversationId);
    }

    
    private function loadConversations() : array
    {
        $convDir = $this->config->secret("local_resources.conversations.path");

        if($convDir === null || trim((string) $convDir) === ""){
            throw new ApiException("Path to conversation directory is invalid!");
        }
   
        if(!file_exists($convDir)){
            if(!mkdir($convDir, 0770, true)){
                throw new ApiException("Could not create conversations directory!");
            }
        }

        $this->conversationJsonPath = $convDir . DIRECTORY_SEPARATOR . $this->safePhone() . ".json";

        if(!file_exists($this->conversationJsonPath)){
            if(!touch($this->conversationJsonPath)){
                throw new ApiException("Could not create conversation json!");
            }
        }

        $cts = file_get_contents($this->conversationJsonPath);

        if($cts === false || trim($cts) === ''){
            return [];
        }

        $decoded = json_decode($cts, true);

        if($decoded === null){
            throw new ApiException("Conversation file is malformed!");
        }
        
        return $decoded;
    }

    /**
     * The sender phone as a safe filename.
     *
     * senderPhone comes straight off an inbound HTTP body, and it is being
     * concatenated into a filesystem path — without this, a crafted
     * sender_phone of "../../config/secret" writes outside the directory.
     */
    private function safePhone() : string
    {
        $safe = preg_replace('/[^0-9]/', '', $this->clientPhone);

        if($safe === null || $safe === ''){
            throw new ApiException("Sender phonenumber is not a usable identifier!");
        }

        return $safe;
    }

    private function registerMessage(mixed $message, string $messageType, string $direction = "in", ?string $source_tool = null) : bool|int
    {
        $conversation = $this->loadConversations();

        // A file that exists but has no data.messages yet (or got truncated)
        // would make count() throw on PHP 8, taking the whole request with it.
        if(!isset($conversation["data"]["messages"]) || !is_array($conversation["data"]["messages"])){
            $conversation = ["phone" => $this->clientPhone, "data" => ["total" => 0, "messages" => []]];
        }

        $totalMessages = count($conversation["data"]["messages"]);

        $message = [ "id" => $totalMessages + 1,
            "direction" => $direction,
            "message" => $message,
            "message_type" => $messageType,
            "at" => date('c'),
        ];

           
        if( $direction === "out" && $source_tool !== null){
            $message["source_tool"] = $source_tool;
        }

        $conversation["data"]["messages"][] = $message;

        $conversation["data"]["total"] = $totalMessages + 1;

        $cts = json_encode($conversation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return file_put_contents($this->conversationJsonPath, $cts, LOCK_EX);
    }

    private function initClientConversation() : bool|int
    {
        $data = ["phone" => $this->clientPhone, "data" => ["total" => 0, "messages" =>[]]];

        return file_put_contents($this->conversationJsonPath, json_encode($data), LOCK_EX);
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

    public function health(Request $request) : Response
    {
        $checkResults = $this->marvin->selfCheck();

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
            $message = trim((string) ($body['data']["text"] ?? ''));
        }

        return $message;
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