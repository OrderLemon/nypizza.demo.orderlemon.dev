<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Gateway;

use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ConfigException;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Support\Logger;

/**
 * Outbound client for the WhatsApp provider gateway.
 *
 * v2 port of the procedural whatsapp_handler.php draft. Every operation is a
 * JSON POST to `{gateway_url}/{endpoint}/` shaped as
 * `{ "a": <action>, ...envelope, "data": {...} }`, where send operations carry
 * `recipient_phonenumber` / `conversation_id` (and, for replies, `reply_to`) in
 * the top-level envelope alongside `data`.
 *
 * Like {@see \Plugins\Whatsapp\Forwarder\WhatsappForwarder}, this talks to an
 * EXTERNAL third-party gateway, not a universe sibling, so it hand-rolls curl —
 * {@see \Pmsrapi\V2\Cluster\ServiceClient} is reserved for inter-service calls.
 * Credentials come from the secret config; the plugin opens no handle of its own.
 *
 * Secret config (dot-path):
 *   whatsapp.gateway_url  base URL, e.g. "https://gw.example.com/v3/"
 *   whatsapp.api_token    bearer token for the gateway
 */
final class WhatsappGateway
{
    /**
     * The shop whose credentials this instance sends with. When set, {@see token()}
     * fetches the bearer from the `cart` universe service instead of static config.
     */
    private ?string $shopId = null;

    /**
     * Per-shop token cache, keyed by shop id, so a request that sends several
     * messages for the same shop calls `shops_info` at most once.
     *
     * @var array<string, string>
     */
    private array $tokenCache = [];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Repository $repo,
    ) {}

    /**
     * Bind this gateway to a shop; subsequent sends authenticate with that
     * shop's WhatsApp token (fetched from the `cart` service). Returns $this
     * for fluent use: `$gateway->forShop($id)->sendText(...)`.
     */
    public function forShop(string $shopId): self
    {
        $this->shopId = $shopId;
        return $this;
    }

    // ---- Account ---------------------------------------------------------

    /** List the templates for a WhatsApp Business Account. @return array<string, mixed> */
    public function getTemplateList(string $wabaId): array
    {
        return $this->request(WhatsappGatewayEndpoint::Account, 'get_template_list', ['waba_id' => $wabaId]);
    }

    /** Retrieve the connected account's data. @return array<string, mixed> */
    public function retrieveData(): array
    {
        return $this->request(WhatsappGatewayEndpoint::Account, 'retrieve_data', []);
    }

    /**
     * Create a template in the gateway.
     *
     * @param array<string, mixed> $template Template definition (name, language, category, components)
     * @return array<string, mixed>
     */
    public function createTemplate(string $wabaId, array $template): array
    {
        return $this->request(
            WhatsappGatewayEndpoint::Account,
            'create_template',
            ['waba_id' => $wabaId, 'template' => $template],
        );
    }

    /** Retrieve the account's WhatsApp provider. @return array<string, mixed> */
    public function retrieveProvider(): array
    {
        return $this->request(WhatsappGatewayEndpoint::Account, 'retrieve_provider', []);
    }

    // ---- Conversation ----------------------------------------------------

    /** Retrieve a single message by its gateway id. @return array<string, mixed> */
    public function getMessage(string $messageId): array
    {
        return $this->request(WhatsappGatewayEndpoint::Conversation, 'get_message', ['message_id' => $messageId]);
    }

    /**
     * Gather one phone number's conversation from a start datetime (Y-m-d H:i:s).
     *
     * @return array<string, mixed>
     */
    public function gatherConversation(string $phonenumber, string $dateStart): array
    {
        return $this->request(
            WhatsappGatewayEndpoint::Conversation,
            'gather_conversation',
            ['phonenumber' => $phonenumber, 'date' => ['start' => $dateStart]],
        );
    }

    /**
     * Gather all conversations from a start datetime (Y-m-d H:i:s).
     *
     * @return array<string, mixed>
     */
    public function gatherConversations(string $dateStart): array
    {
        return $this->request(
            WhatsappGatewayEndpoint::Conversation,
            'gather_conversations',
            ['date' => ['start' => $dateStart]],
        );
    }

    // ---- Send ------------------------------------------------------------

    /** Send a text message. @return array<string, mixed> */
    public function sendText(
        string $recipient,
        string $text,
        bool $disableUrlPreview = false,
        ?int $conversationId = null,
    ): array {
        return $this->send('send_text', $recipient, $conversationId, [
            'text' => $text,
            'disableUrlPreview' => $disableUrlPreview,
        ]);
    }

    /** Send an image by public URL, with an optional caption. @return array<string, mixed> */
    public function sendImage(string $recipient, string $url, ?string $caption = null, ?int $conversationId = null): array
    {
        return $this->send('send_image', $recipient, $conversationId, ['image' => $this->media($url, $caption)]);
    }

    /** Send audio by public URL, with an optional caption. @return array<string, mixed> */
    public function sendAudio(string $recipient, string $url, ?string $caption = null, ?int $conversationId = null): array
    {
        return $this->send('send_audio', $recipient, $conversationId, ['audio' => $this->media($url, $caption)]);
    }

    /** Send a video by public URL, with an optional caption. @return array<string, mixed> */
    public function sendVideo(string $recipient, string $url, ?string $caption = null, ?int $conversationId = null): array
    {
        return $this->send('send_video', $recipient, $conversationId, ['video' => $this->media($url, $caption)]);
    }

    /** Send a file by public URL, with an optional caption. @return array<string, mixed> */
    public function sendFile(string $recipient, string $url, ?string $caption = null, ?int $conversationId = null): array
    {
        return $this->send('send_file', $recipient, $conversationId, ['file' => $this->media($url, $caption)]);
    }

    /** Send a .webp sticker by public URL. @return array<string, mixed> */
    public function sendSticker(string $recipient, string $link, ?int $conversationId = null): array
    {
        return $this->send('send_sticker', $recipient, $conversationId, ['whatsappSticker' => ['link' => $link]]);
    }

    /** Send a location. @return array<string, mixed> */
    public function sendLocation(
        string $recipient,
        float $latitude,
        float $longitude,
        string $name = '',
        string $address = '',
        ?int $conversationId = null,
    ): array {
        return $this->send('send_location', $recipient, $conversationId, [
            'location' => ['lat' => $latitude, 'long' => $longitude, 'name' => $name, 'address' => $address],
        ]);
    }

    /** Send a location. @return array<string, mixed> */
    public function sendLocationRequest(
        string $recipient,
        ?int $conversationId = null,
    ): array {
        return $this->send('send_location_request_message', $recipient, $conversationId, [
                'interactive' => [
                    'type' => "location_request_message",
                    'body' => ["text" => 'Would you like to share your location for a delivery purposes?'],
                    'action' => ['name' => "send_location"],
                ],
        ]);
    }

    /** React to a message with an emoji. @return array<string, mixed> */
    public function sendReaction(
        string $recipient,
        string $emoji,
        string $messageId,
        ?int $conversationId = null,
    ): array {
        return $this->send('send_reaction', $recipient, $conversationId, [
            'reaction' => ['emoji' => $emoji, 'message_id' => $messageId],
        ]);
    }

    /**
     * Send an interactive list message.
     *
     * @param array<int, array<string, mixed>> $sections Each with a title and rows
     * @return array<string, mixed>
     */
    public function sendList(
        string $recipient,
        string $bodyText,
        string $button,
        array $sections,
        ?int $conversationId = null,
    ): array {
        return $this->send('send_list', $recipient, $conversationId, [
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $bodyText],
                'action' => ['button' => $button, 'sections' => $sections],
            ],
        ]);
    }

    /**
     * Send an interactive reply-buttons message.
     *
     * @param array<int, array<string, mixed>> $buttons Each with id, type and title
     * @return array<string, mixed>
     */
    public function sendButtons(
        string $recipient,
        string $bodyText,
        array $buttons,
        ?string $headerText = null,
        ?int $conversationId = null,
    ): array {
        $interactive = [
            'type' => 'button',
            'body' => ['text' => $bodyText],
            'action' => ['buttons' => $buttons],    
        ];

        if ($headerText !== null) {
            $interactive['header'] = ['type' => 'text', 'text' => $headerText];
        }

        return $this->send('send_buttons', $recipient, $conversationId, ['interactive' => $interactive]);
    }

    /** Send an interactive call-to-action URL message. @return array<string, mixed> */
    public function sendLink(
        string $recipient,
        string $bodyText,
        string $actionTitle,
        string $url,
        ?string $footerText = null,
        ?string $headerImageLink = null,
        ?int $conversationId = null,
    ): array {
        $interactive = [
            'body' => ['text' => $bodyText],
            'action' => ['title' => $actionTitle, 'url' => $url],
            'footer' => ["text" => $footerText]
        ];

        if ($headerImageLink !== null) {
            $interactive['header'] = ['image' => ['link' => $headerImageLink], 'type' => 'image'];
        }

        if($footerText !== null){
            $interactive['footer'] = ['text' => $footerText];
                
        }

        return $this->send('send_link', $recipient, $conversationId, ['interactive' => $interactive]);
    }

    /** Send a single product message. @return array<string, mixed> */
    public function sendProduct(
        string $recipient,
        string $catalogId,
        string $productRetailerId,
        ?string $bodyText = null,
        ?string $footerText = null,
        ?int $conversationId = null,
    ): array {
        $interactive = [
            'type' => 'product',
            'action' => ['catalog_id' => $catalogId, 'product_retailer_id' => $productRetailerId],
        ];

        if ($bodyText !== null) {
            $interactive['body'] = ['text' => $bodyText];
        }
        if ($footerText !== null) {
            $interactive['footer'] = ['text' => $footerText];
        }

        return $this->send('send_product', $recipient, $conversationId, ['interactive' => $interactive]);
    }

    /**
     * Send a multi-product list message.
     *
     * @param array<int, array<string, mixed>> $sections Each with a title and product_items
     * @return array<string, mixed>
     */
    public function sendProductList(
        string $recipient,
        string $catalogId,
        array $sections,
        string $bodyText,
        string $headerText,
        ?int $conversationId = null,
    ): array {
        return $this->send('send_product_list', $recipient, $conversationId, [
            'interactive' => [
                'type' => 'product_list',
                'header' => ['type' => 'text', 'text' => $headerText],
                'body' => ['text' => $bodyText],
                'action' => ['catalog_id' => $catalogId, 'sections' => $sections],
            ],
        ]);
    }

    /** Send a text message as a reply to another message. @return array<string, mixed> */
    public function sendReply(
        string $recipient,
        string $text,
        string $replyTo,
        bool $disableUrlPreview = false,
        ?int $conversationId = null,
    ): array {
        return $this->send(
            'send_reply',
            $recipient,
            $conversationId,
            ['text' => $text, 'disableUrlPreview' => $disableUrlPreview],
            ['reply_to' => $replyTo],
        );
    }

    /**
     * Send a template message.
     *
     * @param array<string, mixed>|null $params Header/body/button substitutions
     * @return array<string, mixed>
     */
    public function sendTemplate(
        string $recipient,
        string $namespace,
        string $language,
        string $templateName,
        ?array $params = null,
        ?int $conversationId = null,
    ): array {
        $data = [
            'namespace' => $namespace,
            'language' => $language,
            'template_name' => $templateName,
        ];

        if ($params !== null) {
            $data['params'] = $params;
        }

        return $this->send('send_template', $recipient, $conversationId, $data);
    }

    // ---- Server ----------------------------------------------------------

    /** Retrieve the timestamp of the last message. @return array<string, mixed> */
    public function retrieveLastMessage(): array
    {
        return $this->request(WhatsappGatewayEndpoint::Server, 'retrieve_last_message', []);
    }

    // ---- Internals -------------------------------------------------------

    /**
     * A media block: a `url` plus an optional `caption`.
     *
     * @return array<string, string>
     */
    private function media(string $url, ?string $caption): array
    {
        $media = ['url' => $url];
        if ($caption !== null) {
            $media['caption'] = $caption;
        }
        return $media;
    }

    /**
     * Assemble a Send request: the recipient + conversation envelope is common
     * to every send operation; $extraEnvelope carries per-operation top-level
     * keys (e.g. reply_to).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extraEnvelope
     * @return array<string, mixed>
     */
    private function send(
        string $action,
        string $recipient,
        ?int $conversationId,
        array $data,
        array $extraEnvelope = [],
    ): array {
        $envelope = array_merge([
            'recipient_phonenumber' => $recipient,
            'conversation_id' => $conversationId,
        ], $extraEnvelope);

        return $this->request(WhatsappGatewayEndpoint::Send, $action, $data, $envelope);
    }

    /**
     * Build the `{ a, ...envelope, data }` payload and POST it to the endpoint.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function request(
        WhatsappGatewayEndpoint $endpoint,
        string $action,
        array $data,
        array $envelope = [],
    ): array {
        $payload = array_merge(['a' => $action], $envelope, ['data' => $data]);

        try {
            $response = $this->curl_shell(
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->token(),
                ],
                $payload,
                HttpMethod::POST,
                $this->url($endpoint),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->logger->warning('whatsapp: gateway call failed', [
                'endpoint' => $endpoint->value,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            throw new ServiceException("WhatsApp gateway call failed: {$e->getMessage()}", 502, 'service_error', $e);
        }

        if (!is_array($response)) {
            $this->logger->warning('whatsapp: gateway returned an unexpected response', [
                'endpoint' => $endpoint->value,
                'action' => $action,
            ]);
            throw new ServiceException('WhatsApp gateway returned an unexpected (non-object) response');
        }

        /** @var array<string, mixed> $response */
        return $response;
    }

    private function url(WhatsappGatewayEndpoint $endpoint): string
    {
        $base = $this->config->secret('whatsapp.gateway_url');
        if (!is_string($base) || trim($base) === '') {
            throw new ConfigException('Missing whatsapp.gateway_url in secret config.');
        }
        return rtrim(trim($base), '/') . '/v2/' . $endpoint->value . '/';
    }

    /**
     * Resolve the bearer token for the current send.
     *
     * When the gateway is bound to a shop ({@see forShop()}), the token is the
     * shop's `gateway_token`, read from the nizu service. Otherwise it falls
     * back to the static `whatsapp.api_token` secret (legacy behaviour).
     */
    private function token(): string
    {
        if (defined("shop_id") && is_numeric(shop_id)) {
            return $this->shopToken((string)shop_id);
        }

        $token = $this->config->secret('whatsapp.gateway_token');
        if (!is_string($token) || $token === '') {
            $this->logger->error("Missing whatsapp.gateway_token in secret config.");
            throw new ConfigException('Missing whatsapp.gateway_token in secret config.');
        }

        return $token;
    }

    /**
     * Fetch (and cache) a shop's WhatsApp gateway token from the nizu service.
     *
     * Issues `GET select_row` via {@see ServiceClient} against nizu's `shops`
     * table (`select_row` must be mapped to the `nizu` universe node in the
     * secret config's `function_map`). nizu's own conversation_messages reads
     * the same column: `shops.gateway_token` where the shop is enabled and has
     * a phone number. select_row's `data` envelope is
     * `{ values: { row: {...} }, table_last_update }` and ServiceClient unwraps
     * `data`, so the row is at $result['values']['row'].
     */
    private function shopToken(string $shopId): string
    {
        if (isset($this->tokenCache[$shopId])) {
            return $this->tokenCache[$shopId];
        }

        // `where` is a raw SQL fragment in v1's select_row contract; cast the
        // id to int so nothing client-controlled is interpolated into it.
        $id = (int) $shopId;

        try {
            // nizu is a v1 service; its function_map spec must carry "version": 1.
            $row = $this->repo->selectRow(
                "shops",
                ["id" => shop_id]
            );

        } catch (ServiceException $e) {
            $this->logger->warning('whatsapp: could not fetch shop from nizu', [
                'shop_id' => $shopId,
                'error' => $e->getMessage(),
            ]);
            throw new ConfigException("Could not resolve WhatsApp token for shop '{$shopId}': {$e->getMessage()}");
        }

        $token = is_array($row) ? ($row['gateway_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            throw new ConfigException("Shop '{$shopId}' has no gateway token (or is not accessible).");
        }

        return $this->tokenCache[$shopId] = $token;
    }

    /**
     * Handler function to make an API call using the shell by invoking curl.
     *
     * @param array                         $headers                        `optional,default=['Content-Type: application/json']` The HTTP headers for the request
     * @param ?array                        $body                           `optional,default=null` The request body/payload
     * @param ?HttpMethod                   $method                         `optional,default=null` The HTTP method to use
     * @param ?string                       $url                            `optional,default=null` The URL of the targeted endpoint
     * @param bool                          $async                          `optional,default=false` Whether to fire the request asynchronously
     * @param bool                          $form_data                      `optional,default=false` Whether to send the body as multipart form-data
     * @param bool                          $form_data_url_encode           `optional,default=false` Whether to send the body as URL-encoded form-data
     * @param bool                          $unescape_json                  `optional,default=false` Whether to encode JSON with unescaped slashes and unicode
     *
     * @return mixed                                                        The decoded API response, `null` on an empty response, or `true` for an async dispatch
     *
     * @throws \InvalidArgumentException                                    When $method or $url is null, or $method is not an allowed HTTP method
     * @throws \RuntimeException                                            When the request body cannot be prepared, the API call fails, or the response is not valid JSON
     *
     * @author Mathieu Van daele <mathieu@orderlemon.com>
     */
    private function curl_shell(
         array      $headers              = ['Content-Type: application/json'],
        ?array      $body                 = null,
        ?HttpMethod $method               = null,
        ?string     $url                  = null,
         bool       $async                = false,
         bool       $form_data            = false,
         bool       $form_data_url_encode = false,
         bool       $unescape_json        = false
    ): mixed {
        if (!isset($method) || !isset($url)) {
            throw new \InvalidArgumentException(__FUNCTION__ . '(): Arguments $method and $url must not be null');
        }

        $method = strtoupper($method->value);

        if (in_array("Content-Type: application/json", $headers, true) && ($form_data || $form_data_url_encode)) {
            unset($headers[array_search("Content-Type: application/json", $headers, true)]);
        }
        $command = "curl -sS -k -X $method " . escapeshellarg($url);

        foreach ($headers as $value) {
            $command .= ' --header ' . escapeshellarg($value);
        }

        $tmp_path = null;

        if (isset($body) && is_array($body) && count($body) > 0) {
            if ($form_data) {
                foreach ($body as $key => $value) {
                    $command .= ' -F ' . escapeshellarg("$key=$value");
                }
            } elseif ($form_data_url_encode) {
                foreach ($body as $key => $value) {
                    $command .= ' --data-urlencode ' . escapeshellarg("$key=$value");
                }
            } else {
                $json = json_encode($body, $unescape_json ? JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE : JSON_INVALID_UTF8_SUBSTITUTE);
                if ($json === false) {
                    throw new \RuntimeException(__FUNCTION__ . '(): Failed to JSON-encode the request body');
                }

                $tmp_path = tempnam(sys_get_temp_dir(), 'ol_api_');
                if ($tmp_path === false) {
                    throw new \RuntimeException(__FUNCTION__ . '(): Failed to create a temporary file for the request body');
                }

                if (file_put_contents($tmp_path, $json, LOCK_EX) === false) {
                    unlink($tmp_path);
                    throw new \RuntimeException(__FUNCTION__ . '(): Failed to write the request body to the temporary file');
                }

                $command .= ' --data ' . escapeshellarg("@$tmp_path");
            }
        }

        if ($async) {
            if (isset($tmp_path)) {
                exec("( $command; rm -f " . escapeshellarg($tmp_path) . " ) > /dev/null 2>&1 &");
            } else {
                exec("$command > /dev/null 2>&1 &");
            }

            return true;
        }

        $result = shell_exec($command);

        if (isset($tmp_path) && file_exists($tmp_path) && !unlink($tmp_path)) {
            error_log(__FUNCTION__ . "(): Failed to remove temporary file $tmp_path");
        }

        if ($result === null || $result === false) {
            throw new \RuntimeException(__FUNCTION__ . '(): Failed to execute the request');
        }

        if ($result === "") {
            return null;
        }

        try {
            $result = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(__FUNCTION__ . '(): The API returned a malformed JSON response', 0, $e);
        }

        return $result === false ? null : $result;
    }


}