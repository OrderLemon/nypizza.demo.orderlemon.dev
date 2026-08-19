<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Helpers\JsonHelper;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Core\Config;

class ConversationService
{
    function __construct(
        private readonly Repository $repo,
        protected Config $config,
    ){}

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
    public function upsertConversation(string $phone): ?array
    {
        $table = $this->conversationsTable();
        $existing = $this->getByPhone($phone);

        if ($existing === null) {
            $id = $this->repo->insertRow($table, [
                'phonenumber' => $phone,
                'start_time' => date('Y-m-d H:i:s'),
                'step' => 0,
            ]);

            return $this->repo->selectRow($table, ['id' => $id]);
        }

        $this->repo->updateById($table, (int) $existing['id'], [
            'step_ts' => date('Y-m-d H:i:s'),
            'order_id' => null,
        ]);

        return $this->repo->selectRow($table, ['id' => $existing['id']]);
    }

    private function conversationsTable(): string
    {
        $shopId = $this->config->secret('company.shop_id');

        if (!is_numeric($shopId)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return 'conversations_' . (int) $shopId;
    }
}

?>