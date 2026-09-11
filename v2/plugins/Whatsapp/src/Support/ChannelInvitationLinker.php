<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Support;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;
use RuntimeException;

final class ChannelInvitationLinker
{
    private const int DEFAULT_TTL_SECONDS = 14 * 24 * 60 * 60;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    public function buildLink(int $shopId, string $phone, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): ?string
    {
        $signingSecret = (string) $this->config->secret('channel_follow.signing_secret', '');
        $invitationsPath = (string) $this->config->secret('channel_follow.chanel_invitations', '');
        $invitationUri = (string) $this->config->secret('channel_follow.invitation_uri', '');

        if ($signingSecret === '' || $invitationsPath === '' || $invitationUri === '') {
            $this->logger->error(
                'channel_follow: missing signing_secret/chanel_invitations/invitation_uri in secret config',
            );

            return null;
        }

        $expiresAt = time() + $ttlSeconds;
        $signature = hash_hmac('sha256', "{$shopId}:{$phone}:{$expiresAt}", $signingSecret);

        try {
            $this->recordInvite($invitationsPath, $shopId, $phone);
        } catch (RuntimeException $e) {
            $this->logger->error('channel_follow: could not write invitations ledger', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return rtrim($invitationUri, '/') . '/?shop_id=' . $shopId
            . '&phone=' . urlencode($phone)
            . '&exp=' . $expiresAt
            . '&sig=' . $signature;
    }

    private function recordInvite(string $path, int $shopId, string $phone): void
    {
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException("Could not open invitations ledger: {$path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("Could not lock invitations ledger: {$path}");
            }

            $contents = stream_get_contents($handle);
            $decoded = $contents !== false && $contents !== '' ? json_decode($contents, true) : [];
            $ledger = is_array($decoded) ? $decoded : [];

            $key = "{$shopId}:{$phone}";
            $ledger[$key] = [
                'shop_id' => $shopId,
                'phone' => $phone,
                'invited_at' => date('c'),
                'clicked_at' => $ledger[$key]['clicked_at'] ?? null,
            ];

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
