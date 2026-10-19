<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Services\MenuService;
use Pmsrapi\V2\Support\Logger;
use RedisException;
use RuntimeException;

/**
 * The item a shopper is building over several messages (a product with
 * option groups), kept in Redis per shop and phone until it is added.
 *
 * add_to_order writes it: required groups are asked first, all at once, then
 * each optional group is offered once; the product is only added when both
 * are done. The draft remembers the choices so far, so every answer only
 * needs the new options, and which optional groups were already answered.
 *
 * Redis down: available() is false and add_to_order adds as soon as the
 * required choices are made, without offering the optional groups (it cannot
 * remember which were offered). Never throws.
 *
 * Draft shape:
 *   product_id  int
 *   quantity    int
 *   option_ids  list<string>  chosen so far
 *   answered    list<string>  optional group ids answered or declined
 *   offered     ?string       optional group id asked about last
 *   reminded    bool          the shopper was told at checkout that it is not
 *                             finished; reset by every new choice
 *
 * Cleared when it is added, discarded, or the order is checked out (chat or
 * web); otherwise it expires after marvin.draft_ttl seconds (default 15 minutes).
 */
final class ItemDraft
{
    /** A half-built item is forgotten after this long without an answer, unless marvin.draft_ttl says otherwise. */
    private const int TTL = 900;

    private ?bool $available = null;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly MenuService $menuService,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /** Whether a draft can be kept between messages: Redis configured AND answering. Checked once per request. */
    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        if ($this->key('') === null || !$this->redis->isEnabled()) {
            return $this->available = false;
        }

        try {
            $this->redis->connection()->ping();

            return $this->available = true;
        } catch (RedisException | RuntimeException $e) {
            $this->logger->warning('marvin: item drafts unavailable, adding without optional groups', ['error' => $e->getMessage()]);

            return $this->available = false;
        }
    }

    /** @return array{product_id: int, quantity: int, option_ids: list<string>, answered: list<string>, offered: ?string, reminded: bool}|null */
    public function get(string $phone): ?array
    {
        $key = $this->key($phone);
        if ($key === null || !$this->redis->isEnabled()) {
            return null;
        }

        try {
            $raw = $this->redis->connection()->get($key);
        } catch (RedisException | RuntimeException $e) {
            $this->logger->warning('marvin: item draft not readable', ['error' => $e->getMessage()]);

            return null;
        }

        $draft = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($draft) || !$this->menuService->has((int) ($draft['product_id'] ?? 0))) {
            return null;
        }

        return [
            'product_id' => (int) $draft['product_id'],
            'quantity'   => max(1, (int) ($draft['quantity'] ?? 1)),
            'option_ids' => array_values(array_map('strval', (array) ($draft['option_ids'] ?? []))),
            'answered'   => array_values(array_map('strval', (array) ($draft['answered'] ?? []))),
            'offered'    => isset($draft['offered']) ? (string) $draft['offered'] : null,
            'reminded'   => (bool) ($draft['reminded'] ?? false),
        ];
    }

    /** @param array{product_id: int, quantity: int, option_ids: list<string>, answered: list<string>, offered: ?string, reminded?: bool} $draft */
    public function save(string $phone, array $draft): void
    {
        $key = $this->key($phone);
        if ($key === null || !$this->redis->isEnabled()) {
            return;
        }

        try {
            $ttl = max(60, (int) $this->config->secret('marvin.draft_ttl', self::TTL));
            $this->redis->connection()->setex($key, $ttl, (string) json_encode($draft));
        } catch (RedisException | RuntimeException $e) {
            $this->logger->warning('marvin: item draft not saved', ['error' => $e->getMessage()]);
        }
    }

    public function clear(string $phone): void
    {
        $key = $this->key($phone);
        if ($key === null || !$this->redis->isEnabled()) {
            return;
        }

        try {
            $this->redis->connection()->del($key);
        } catch (RedisException | RuntimeException $e) {
            $this->logger->warning('marvin: item draft not cleared', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The draft in words, for TypeSafe's state and Marvin's note: the item,
     * what is chosen and what it is waiting for. Null when there is none.
     *
     * @return array{item: string, product_id: int, chosen: list<string>, waiting_for: list<string>}|null
     */
    public function summary(string $phone): ?array
    {
        $draft = $this->get($phone);
        if ($draft === null) {
            return null;
        }

        $resolved = $this->menuService->resolveOptions($draft['product_id'], $draft['option_ids']);
        $waiting = array_column($resolved['missing'], 'label');
        if ($waiting === [] && $draft['offered'] !== null) {
            foreach ((array) ($this->menuService->product($draft['product_id'])['config_groups'] ?? []) as $group) {
                if (is_array($group) && (string) ($group['id'] ?? '') === $draft['offered']) {
                    $waiting[] = (string) ($group['label'] ?? $draft['offered']) . ' (optional)';
                }
            }
        }

        return [
            'item'        => (string) $this->menuService->name($draft['product_id']),
            'product_id'  => $draft['product_id'],
            'chosen'      => array_column($resolved['config'], 'item_description'),
            'waiting_for' => $waiting,
        ];
    }

    private function key(string $phone): ?string
    {
        return defined('shop_id') && is_numeric(shop_id) ? 'marvin:draft:' . (int) shop_id . ':' . $phone : null;
    }
}
