<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;
use RedisException;
use RuntimeException;

final class ShopBackground
{
    /** Cap on what Marvin reads, in characters (~3,000 tokens). */
    private const int MAX_CHARS = 12000;

    private const int TIMEOUT = 8;

    public function __construct(
        private readonly Config $config,
        private readonly RedisClient $redis,
        private readonly Logger $logger,
    ) {}

    /**
     * Every configured page, from the secret config's "web_sources" map.
     *
     * @return array<int,string> shop id => URL
     */
    public function sources(): array
    {
        $sources = $this->config->secret('web_sources', []);

        if (!is_array($sources)) {
            return [];
        }

        $out = [];
        foreach ($sources as $shopId => $url) {
            if (is_numeric($shopId) && is_string($url) && trim($url) !== '') {
                $out[(int) $shopId] = trim($url);
            }
        }

        return $out;
    }

    /** Whether the current shop has a page configured. */
    public function isConfigured(): bool
    {
        return defined('shop_id') && is_numeric(shop_id) && isset($this->sources()[(int) shop_id]);
    }

    /**
     * The current shop's page text, as last stored by the refresh script
     * (ShopBackgroundRefresh.php). Read-only: Marvin never fetches the page
     * himself, so an empty cache simply means there is no information.
     *
     * @throws ApiException when nothing is cached for this shop
     */
    public function text(): string
    {
        if (!defined('shop_id') || !is_numeric(shop_id)) {
            throw new ApiException('No shop in context.');
        }

        $text = $this->cacheGet(self::key((int) shop_id));

        if ($text === null) {
            throw new ApiException('No shop background cached for shop ' . shop_id . '.');
        }

        return $text;
    }

    /**
     * Fetch one shop's page and store its text. Only a successful fetch
     * overwrites the cache, so a failed refresh keeps the last good text.
     *
     * @throws ApiException when the page cannot be fetched, has no text, or cannot be stored
     */
    public function refresh(int $shopId, string $url): int
    {
        $text = $this->toText($this->fetch($url));

        if ($text === '') {
            throw new ApiException("Web source has no readable text: {$url}");
        }

        if (!$this->redis->isEnabled()) {
            throw new ApiException('Redis is not configured, nowhere to store the shop background.');
        }

        try {
            // No expiry: the refresh script replaces it, and a page that stops
            // loading must not wipe what Marvin already has.
            $this->redis->connection()->set(self::key($shopId), $text);
        } catch (RedisException | RuntimeException $e) {
            throw new ApiException('Could not store shop background: ' . $e->getMessage());
        }

        return mb_strlen($text);
    }

    private static function key(int $shopId): string
    {
        return 'marvin:shop_background:' . $shopId;
    }

    private function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'OrderLemon-Marvin/1.0',
        ]);

        $html   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if (!is_string($html) || $status >= 400) {
            throw new ApiException("Could not fetch web source {$url} (http {$status}) {$error}");
        }

        return $html;
    }

    /** The page's readable text: no scripts, styles, menus or markup. */
    private function toText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|nav|header|footer|form)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|p|div|li|h[1-6]|tr)\b[^>]*>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\s*\n\s*/', "\n", $text) ?? $text);

        return mb_substr($text, 0, self::MAX_CHARS);
    }

    private function cacheGet(string $key): ?string
    {
        if (!$this->redis->isEnabled()) {
            return null;
        }

        try {
            $value = $this->redis->connection()->get($key);

            return is_string($value) && $value !== '' ? $value : null;
        } catch (RedisException | RuntimeException $e) {
            $this->logger->warning('marvin.shop_background cache read failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
