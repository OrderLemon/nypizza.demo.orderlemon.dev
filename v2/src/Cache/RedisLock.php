<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cache;

use Pmsrapi\V2\Support\Logger;
use RedisException;

/**
 * Short-lived distributed mutex backed by Redis `SET NX EX`, for serializing
 * concurrent writes against the same logical resource (e.g. two requests
 * syncing the same cart at once).
 *
 * Degrades like every other Redis-backed helper here: when Redis is down,
 * acquire() succeeds immediately instead of blocking the request, so a cache
 * outage does not become a full outage.
 */
final class RedisLock
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly Logger $logger,
    ) {}

    /**
     * Attempts to acquire the lock, retrying every $retryDelayMs until
     * $timeoutMs elapses.
     *
     * @return bool true once acquired (or immediately if Redis is
     *              unavailable), false if $timeoutMs is exceeded first
     */
    public function acquire(string $key, int $ttlSeconds = 5, int $timeoutMs = 2000, int $retryDelayMs = 50): bool
    {
        if (!$this->redis->isEnabled()) {
            return true;
        }

        $lockKey = $this->lockKey($key);
        $deadline = microtime(true) + ($timeoutMs / 1000);

        try {
            $redis = $this->redis->connection();

            do {
                if ($redis->set($lockKey, '1', ['NX', 'EX' => $ttlSeconds])) {
                    return true;
                }

                usleep($retryDelayMs * 1000);
            } while (microtime(true) < $deadline);

            return false;
        } catch (RedisException $e) {
            $this->logger->warning('RedisLock degraded to no-op', ['error' => $e->getMessage(), 'key' => $key]);
            return true;
        }
    }

    public function release(string $key): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }

        try {
            $this->redis->connection()->del($this->lockKey($key));
        } catch (RedisException $e) {
            $this->logger->warning('RedisLock release failed', ['error' => $e->getMessage(), 'key' => $key]);
        }
    }

    private function lockKey(string $key): string
    {
        return 'lock:' . $key;
    }
}
