<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Helpers\JsonHelper;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Core\Config;

class ShopService
{
    function __construct(
        private readonly Repository $repo,
        protected Config $config,
    ){}

    public function getByPhone(string $phoneNumber) : ?array
    {
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber) ?? '';

        if ($digits === '') {
            return null;
        }

        return $this->repo->selectRow("shops", ["phonenumber" => $phoneNumber, "enabled" => 1]);
    }
}

?>