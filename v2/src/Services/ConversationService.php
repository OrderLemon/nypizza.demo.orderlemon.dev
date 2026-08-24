<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Helpers\JsonHelper;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Services\OrderQueryService;

class ConversationService
{
    function __construct(
        private readonly Repository $repo,
        protected Config $config,
        private readonly OrderQueryService $orders,
    ){}

    /**
     * Every row currently in the live conversations table.
     *
     * @param int|null $shopId defaults to the configured company.shop_id
     */
    public function activeConversations(?int $shopId = null): array
    {
        return $this->repo->selectRows($this->conversationsTable($shopId));
    }

    /**
     * @param array<string, mixed> $conversation
     * @param int|null             $shopId defaults to the configured company.shop_id
     */
    public function archive(array $conversation, ?int $shopId = null): void
    {
        $this->repo->insertRow($this->archiveTable($shopId), $conversation);
        $this->repo->deleteById($this->conversationsTable($shopId), (int) $conversation['id']);

        $orderId = $conversation['order_id'] ?? null;

        if ($orderId !== null) {
            $this->orders->archiveOrder((int) $orderId, $shopId);
        }
    }

    /**
     * The most recent conversation row for this phone (there's no unique
     * constraint on phonenumber — a phone can have many rows over time).
     *
     * @return array<string, mixed>|null
     */
    public function getByPhone(string $phoneNumber): ?array
    {
        return $this->repo->selectRows(
            $this->conversationsTable(),
            ['phonenumber' => $phoneNumber],
            orderBy: 'id:desc',
            limit: 1,
        )[0] ?? null;
    }

    public function isNewConversation(string $phoneNumber): bool
    {
        return $this->getByPhone($phoneNumber) === null;
    }

    /**
     * There's no unique key on phonenumber in conversations_{shop_id}, so this
     * can't be a real INSERT ... ON DUPLICATE KEY UPDATE like ClientsService's
     * upsert — instead it looks up the latest row for the phone in app code
     * and updates it, or starts a fresh one when there isn't one.
     *
     * @return array<string, mixed>|null the upserted conversation record
     */
    public function upsertConversation(string $phone, array $data = []): ?array
    {
        //new conversation
        if($data === []){
            $data = [
                'phonenumber' => $phone,
                'start_time' => date('Y-m-d H:i:s'),
                'step' => 0,
            ];
        }

        $table = $this->conversationsTable();
        $existing = $this->getByPhone($phone);

        if ($existing === null) {
            $id = $this->repo->insertRow($table, $data);

            return $this->repo->selectRow($table, ['id' => $id]);
        }

        $this->repo->updateById($table, (int) $existing['id'], $data);

        return $this->repo->selectRow($table, ['id' => $existing['id']]);
    }

    private function conversationsTable(?int $shopId = null): string
    {
        return 'conversations_' . ($shopId ?? $this->shopId());
    }

    private function archiveTable(?int $shopId = null): string
    {
        return 'conversations_archive_' . ($shopId ?? $this->shopId());
    }

    private function shopId(): int
    {
        $shopId = $this->config->secret('company.shop_id');

        if (!is_numeric($shopId)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return (int) $shopId;
    }
}

?>