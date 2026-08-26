<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Helpers\JsonHelper;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Core\Config;

class ShopService
{
    /** Cache for current(). `false` means "not fetched yet" — a real miss is `null`. */
    private array|null|false $shop = false;

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

    public function find(int $shopId): ?array
    {
        return $this->repo->selectRow("shops", ["id" => $shopId, "enabled" => 1]);
    }

    /** The shop of the request's `shop_id`, fetched once and cached for the rest of it. */
    public function current(): ?array
    {
        if ($this->shop !== false) {
            return $this->shop;
        }

        return $this->shop = $this->find($this->currentShopId());
    }

    public function name(): string
    {
        return (string) ($this->current()["name"] ?? "");
    }

    public function address(): string
    {
        $shop = $this->current();

        if ($shop === null) {
            return "";
        }

        $parts = array_filter(
            [$shop["street"] ?? null, $shop["zip"] ?? null, $shop["city"] ?? null, $shop["country"] ?? null],
            static fn(?string $part): bool => $part !== null && trim($part) !== "",
        );

        return implode(", ", $parts);
    }

    private function currentShopId(): int
    {
        if (!defined("shop_id") || !is_numeric(shop_id)) {
            throw new ValidationException(["shop id" => "Shop Id must be a numeric value!"]);
        }

        return (int) shop_id;
    }
}

?>