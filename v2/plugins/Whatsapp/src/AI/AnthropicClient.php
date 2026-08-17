<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;

/**
 * Transport for POST https://api.anthropic.com/v1/messages.
 *
 * All settings come from the secret JSON via Config, under the "marvin" block:
 *
 *   "marvin": {
 *     "config":  "/home/marvin.json",
 *     "prompts": "/home/marvin.v1.txt",
 *     "api_key": "sk-ant-...",
 *     "model":   "claude-opus-4-8",
 *     "max_tokens": 1024,
 *     "effort":  "low",
 *     "timeout": 30,
 *     "max_retries": 2
 *   }
 *
 * A generic "anthropic" block is honoured as a fallback for every key except
 * the paths, so if you add a second AI feature later you can move the shared
 * credential there without touching this class.
 *
 * The API is stateless: every call carries the full system prompt and the full
 * message history, and there is no session to resume. Prompt caching is what
 * makes that affordable — see Marvin::systemBlocks().
 */
final class AnthropicClient
{
    private const ENDPOINT            = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION         = '2023-06-01';
    private const BETA_EXTENDED_CACHE = 'extended-cache-ttl-2025-04-11';
    private const BETA_EFFORT         = 'effort-2025-11-24';

    /** @var list<int> */
    private const RETRYABLE = [408, 429, 500, 502, 503, 504, 529];

    private readonly string $apiKey;
    private readonly string $model;
    private readonly int $maxTokens;
    private readonly int $timeout;
    private readonly ?string $effort;
    private readonly int $maxRetries;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
        $this->apiKey    = (string) $this->setting('api_key', '');
        $this->model     = (string) $this->setting('model', 'claude-opus-4-8');
        $this->maxTokens = (int) $this->setting('max_tokens', 1024);
        $this->timeout   = (int) $this->setting('timeout', 30);
        $this->maxRetries = (int) $this->setting('max_retries', 2);

        // Opus 4.8 defaults internally to high effort, which costs seconds on a
        // reply a shopper is waiting for. Setting marvin.effort to "low" trades
        // some reasoning depth for latency.
        //
        // Opt-in by default: this rides a beta header, and a request carrying an
        // unsupported parameter is rejected outright rather than ignored. If you
        // ever see "effort: Extra inputs are not permitted" or
        // "output_config: Extra inputs are not permitted", set marvin.effort to
        // null and move on — the cost is latency, not correctness.
        $effort = $this->setting('effort', null);
        $this->effort = is_string($effort) && $effort !== '' ? $effort : null;

        if (trim($this->apiKey) === '') {
            throw new ApiException(
                'Missing API key: set marvin.api_key (or anthropic.api_key) in the secret config.'
            );
        }
    }

    /** marvin.<key>, falling back to anthropic.<key>, then the default. */
    private function setting(string $key, mixed $default): mixed
    {
        return $this->config->secret(
            "marvin.{$key}",
            $this->config->secret("anthropic.{$key}", $default)
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Send one turn and return the decoded response body.
     *
     * @param list<array<string,mixed>> $messages full history, oldest first
     * @param list<array<string,mixed>> $system   system blocks; cache_control
     *                                            belongs on the last one
     * @param list<array<string,mixed>> $tools    tool definitions; part of the
     *                                            cached prefix, ahead of $system
     * @return array<string,mixed>
     *
     * @throws ApiException on a non-retryable failure
     */
    public function messages(array $messages, array $system, array $tools = []): array
    {
        if ($messages === []) {
            throw new ApiException('cannot call anthropic with an empty messages array');
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'system'     => array_values($system),
            'messages'   => array_values($messages),
        ];

        // The cached prefix is ordered tools, then system, then messages —
        // regardless of the order these keys appear in the JSON. So Marvin's
        // breakpoint at the end of the system block covers the tools as well,
        // and the tools array has to be byte-stable for the same reason the menu
        // does. See MarvinTools::definitions().
        if ($tools !== []) {
            $payload['tools'] = array_values($tools);
        }

        if ($this->effort !== null) {
            // Nested under output_config — a top-level "effort" key is rejected.
            $payload['output_config'] = ['effort' => $this->effort];
        }

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $attempt = 0;

        while (true) {
            $attempt++;
            $started = microtime(true);

            [$raw, $status, $curlError, $retryAfter] = $this->post($json);

            $elapsed = microtime(true) - $started;

            if ($raw === null) {
                if ($attempt > $this->maxRetries) {
                    throw new ApiException("anthropic unreachable: {$curlError}");
                }
                $this->logger->warning('anthropic: transport failure, retrying', [
                    'attempt' => $attempt,
                    'error'   => $curlError,
                ]);
                $this->backoff($attempt, null);
                continue;
            }

            $body = json_decode($raw, true);
            if (!is_array($body)) {
                throw new ApiException("anthropic returned non-JSON (http {$status})");
            }

            if ($status >= 400) {
                $type    = (string) ($body['error']['type'] ?? 'api_error');
                $message = (string) ($body['error']['message'] ?? "http {$status}");

                if (in_array($status, self::RETRYABLE, true) && $attempt <= $this->maxRetries) {
                    $this->logger->warning('anthropic: retryable error', [
                        'attempt' => $attempt,
                        'status'  => $status,
                        'type'    => $type,
                    ]);
                    $this->backoff($attempt, $retryAfter);
                    continue;
                }

                throw new ApiException("anthropic {$type}: {$message}", $status);
            }

            $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

            // Watch cache_write against cache_read. In steady state read should
            // dominate and write should be ~0 outside the first call per TTL
            // window. Write staying high means something dynamic crept above
            // the cache breakpoint.
            //
            // With tools in play there are several requests per shopper message,
            // so stop_reason is the only way to tell a tool round trip from the
            // final answer.
            $this->logger->info('anthropic: ok', [
                'model'       => $body['model'] ?? $this->model,
                'stop_reason' => $body['stop_reason'] ?? null,
                'tools'       => count($tools),
                'in'          => $usage['input_tokens'] ?? 0,
                'out'         => $usage['output_tokens'] ?? 0,
                'cache_write' => $usage['cache_creation_input_tokens'] ?? 0,
                'cache_read'  => $usage['cache_read_input_tokens'] ?? 0,
                'seconds'     => round($elapsed, 2),
            ]);

            return $body;
        }
    }

    /**
     * @return array{0: string|null, 1: int, 2: string, 3: int|null}
     */
    private function post(string $json): array
    {
        $retryAfter = null;

        // Multiple betas go in one comma-separated header. Only advertise the
        // effort beta when actually sending output_config, so a request without
        // it is never gated on an unrelated preview feature.
        $betas = [self::BETA_EXTENDED_CACHE];
        if ($this->effort !== null) {
            $betas[] = self::BETA_EFFORT;
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'anthropic-beta: ' . implode(',', $betas),
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $value = trim(substr($line, 12));
                    if (ctype_digit($value)) {
                        $retryAfter = (int) $value;
                    }
                }

                return strlen($line);
            },
        ]);

        $raw    = curl_exec($ch);
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        return [$raw === false ? null : (string) $raw, $status, $error, $retryAfter];
    }

    private function backoff(int $attempt, ?int $retryAfter): void
    {
        if ($retryAfter !== null) {
            usleep(min($retryAfter, 20) * 1_000_000);

            return;
        }

        $seconds = min(6.0, 0.5 * (2 ** ($attempt - 1))) + (random_int(0, 250) / 1000);
        usleep((int) ($seconds * 1_000_000));
    }
}