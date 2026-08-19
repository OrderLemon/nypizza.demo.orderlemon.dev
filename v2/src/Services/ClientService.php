<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Helpers\JsonHelper;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Core\Config;

class ClientService
{
    function __construct(
        private readonly Repository $repo,
        protected Config $config,
    ){}

    /**
     * @return array<string, mixed>|null
     */
    public function getByPhone(string $phoneNumber): ?array
    {
        return $this->repo->selectRow($this->clientsTable(), [
            'phonenumber' => $phoneNumber,
        ]);
    }

    public function isNewClient(string $phoneNumber): bool
    {
        return $this->getByPhone($phoneNumber) === null;
    }

    /**
     * @return array<string, mixed>|null the upserted client record
     */
    public function upsertClient(string $phone, string $name): ?array
    {
        // date_added must not be in updateColumns: it's a "first seen" stamp,
        // not a "last seen" one, so an existing client's date must stay put.
        $result = $this->repo->upsert(
            $this->clientsTable(),
            [
                'phonenumber' => $phone,
                'full_name' => $name,
                'date_added' => date('Y-m-d H:i:s'),
            ],
            updateColumns: ['full_name'],
        );

        return $result['record'];
    }

    private function clientsTable(): string
    {
        $shopId = $this->config->secret('company.shop_id');

        if (!is_numeric($shopId)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return 'clients_' . (int) $shopId;
    }
}

?>