<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use JsonException;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;

/**
 * Transport for POST https://api.typesafe.ai/v1/systemone: send state plus
 * questions, get one typed answer per question back.
 *
 * Settings come from the secret JSON, "typesafe" block:
 *
 *   "typesafe": {
 *     "api_key":     "...",          // or the TYPESAFE_API_KEY environment variable
 *     "model":       "jev-latest",
 *     "timeout":     10,             // seconds per attempt
 *     "max_retries": 2,
 *     "endpoint":    "https://api.typesafe.ai/v1/systemone"   // override for a local fake
 *   }
 *
 * A missing key does not break the service at boot: the WhatsApp controller is
 * built either way, and ask() throws TypeSafeException::notConfigured(), which
 * callers treat like any other TypeSafe failure (fall back to Marvin).
 *
 * Retries are kept short on purpose: this sits in front of a shopper waiting
 * for a reply, so a few hundred ms of backoff, not seconds. 429 and 529 are
 * the documented "retry with backoff" statuses; other 5xx and network errors
 * are retried the same way.
 */
final class TypeSafeClient
{
    private const DEFAULT_ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    /** Bodies at least this large are gzipped; the menu makes most requests qualify. */
    private const COMPRESS_FROM_BYTES = 1024;

    /** TypeSafe rejects choices with more options than this. */
    private const MAX_CHOICE_OPTIONS = 255;

    /** @var list<int> */
    private const RETRYABLE = [408, 429, 500, 502, 503, 504, 529];

    private readonly string $apiKey;
    private readonly string $model;
    private readonly string $endpoint;
    private readonly int $timeout;
    private readonly int $maxRetries;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
        $key = (string) $this->config->secret('typesafe.api_key', '');
        $this->apiKey     = trim($key !== '' ? $key : (string) getenv('TYPESAFE_API_KEY'));
        $this->model      = (string) $this->config->secret('typesafe.model', 'jev-latest');
        $this->endpoint   = (string) $this->config->secret('typesafe.endpoint', self::DEFAULT_ENDPOINT);
        $this->timeout    = max(1, (int) $this->config->secret('typesafe.timeout', 10));
        $this->maxRetries = max(0, (int) $this->config->secret('typesafe.max_retries', 2));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Ask every question about the same state in one request.
     *
     * @param string|array<mixed>                $state     what the questions are about; named
     *                                                       fields can be referenced in
     *                                                       instructions as `field.path`
     * @param array<string, array<string, mixed>> $questions question id => {type, instructions, criteria?}
     *
     * @throws TypeSafeException when TypeSafe is not configured, unreachable,
     *                           rejects the request, or returns something unreadable
     */
    public function ask(string|array $state, array $questions): TypeSafeResult
    {
        if (!$this->isConfigured()) {
            throw TypeSafeException::notConfigured();
        }
        $this->assertQuestions($questions);

        try {
            $json = json_encode(
                ['model' => $this->model, 'state' => $state, 'questions' => $questions],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new TypeSafeException('cannot encode TypeSafe request: ' . $e->getMessage(), 'typesafe_bad_request', previous: $e);
        }

        [$body, $gzip] = $this->compress($json);
        $started = microtime(true);
        $attempt = 0;

        while (true) {
            $attempt++;
            [$raw, $status, $curlError] = $this->post($body, $gzip);

            if ($raw === null) {
                if ($attempt > $this->maxRetries) {
                    throw new TypeSafeException("TypeSafe unreachable: {$curlError}", 'typesafe_unreachable', statusCode: 503);
                }
                $this->logger->warning('typesafe: transport failure, retrying', ['attempt' => $attempt, 'error' => $curlError]);
                $this->backoff($attempt);
                continue;
            }

            $decoded = json_decode($raw, true);

            if ($status >= 400) {
                if (in_array($status, self::RETRYABLE, true) && $attempt <= $this->maxRetries) {
                    $this->logger->warning('typesafe: retryable error', ['attempt' => $attempt, 'status' => $status]);
                    $this->backoff($attempt);
                    continue;
                }

                throw $this->failure($status, is_array($decoded) ? $decoded : []);
            }

            if (!is_array($decoded)) {
                throw new TypeSafeException("TypeSafe returned non-JSON (http {$status})", 'typesafe_bad_response', $status);
            }

            $result = TypeSafeResult::fromResponse($decoded, microtime(true) - $started, $attempt);

            $this->logger->info('typesafe: ok', [
                'model'     => $result->model,
                'questions' => count($questions),
                'in'        => $result->inputTokens,
                'out'       => $result->outputTokens,
                'attempts'  => $attempt,
                'seconds'   => round($result->seconds, 2),
                'bytes'     => strlen($body),
            ]);

            return $result;
        }
    }

    /**
     * Cheap local checks for mistakes TypeSafe would reject anyway, so they
     * show up as a clear error here instead of a 422 later.
     *
     * @param array<string, array<string, mixed>> $questions
     */
    private function assertQuestions(array $questions): void
    {
        if ($questions === []) {
            throw new TypeSafeException('cannot call TypeSafe without questions', 'typesafe_bad_request');
        }

        foreach ($questions as $id => $question) {
            $type = $question['type'] ?? null;
            if (!in_array($type, ['choice', 'noul', 'score'], true)) {
                throw new TypeSafeException("question '{$id}' has no valid type", 'typesafe_bad_request');
            }
            if (($question['instructions'] ?? '') === '' || ($question['instructions'] ?? []) === []) {
                throw new TypeSafeException("question '{$id}' has no instructions", 'typesafe_bad_request');
            }

            $criteria = $question['criteria'] ?? null;
            if ($type === 'choice') {
                if (!is_array($criteria) || $criteria === [] || array_is_list($criteria)) {
                    throw new TypeSafeException("choice '{$id}' needs criteria as option => description", 'typesafe_bad_request');
                }
                if (count($criteria) > self::MAX_CHOICE_OPTIONS) {
                    throw new TypeSafeException(
                        "choice '{$id}' has " . count($criteria) . ' options, max ' . self::MAX_CHOICE_OPTIONS,
                        'typesafe_bad_request',
                    );
                }
            }
            if ($type === 'score' && (!is_array($criteria) || !array_is_list($criteria) || count($criteria) < 2 || count($criteria) > 10)) {
                throw new TypeSafeException("score '{$id}' needs 2 to 10 ordered levels", 'typesafe_bad_request');
            }
        }
    }

    /** @return array{0: string, 1: bool} body to send, and whether it is gzipped */
    private function compress(string $json): array
    {
        if (strlen($json) < self::COMPRESS_FROM_BYTES || !function_exists('gzencode')) {
            return [$json, false];
        }

        $gzipped = gzencode($json, 6);

        return $gzipped === false ? [$json, false] : [$gzipped, true];
    }

    /** @return array{0: string|null, 1: int, 2: string} raw body (null on transport failure), status, curl error */
    private function post(string $body, bool $gzip): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: orderlemon-whatsapp/1.0',
        ];
        if ($gzip) {
            $headers[] = 'Content-Encoding: gzip';
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $this->timeout),
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $raw    = curl_exec($ch);
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        return [$raw === false ? null : (string) $raw, $status, $error];
    }

    /** @param array<string, mixed> $body */
    private function failure(int $status, array $body): TypeSafeException
    {
        $detail  = $body['detail'] ?? null;
        $type    = is_array($detail) ? (string) ($detail['error_type'] ?? '') : '';
        $message = is_array($detail) ? (string) ($detail['message'] ?? '') : (is_string($detail) ? $detail : '');

        $code = match (true) {
            $status === 401          => 'typesafe_unauthorized',
            $status === 429          => 'typesafe_rate_limited',
            $status === 529          => 'typesafe_overloaded',
            $status === 413          => 'typesafe_too_large',
            in_array($status, [400, 415, 422], true) => 'typesafe_rejected',
            default                  => 'typesafe_error',
        };

        // The body of a 422 names the offending field: keep it, it is the useful part.
        $text = trim(($type !== '' ? "{$type}: " : '') . ($message !== '' ? $message : json_encode($body)));

        return new TypeSafeException(
            "TypeSafe http {$status}: {$text}",
            $code,
            $status,
            statusCode: in_array($status, [429, 529], true) ? 503 : 502,
        );
    }

    private function backoff(int $attempt): void
    {
        $seconds = min(1.5, 0.3 * (2 ** ($attempt - 1))) + (random_int(0, 150) / 1000);
        usleep((int) ($seconds * 1_000_000));
    }
}
