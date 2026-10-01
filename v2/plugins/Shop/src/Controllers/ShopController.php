<?php

declare(strict_types=1);

namespace Plugins\Shop\Controllers;

use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Services\ShopService;

final class ShopController
{
    public function __construct(
        private readonly ShopService $shops,
    ) {}

    public function show(string $shopId): Response
    {
        if (!ctype_digit($shopId)) {
            throw new ValidationException(['shop_id' => 'Shop id must be numeric']);
        }

        $shop = $this->shops->find((int) $shopId);

        if ($shop === null) {
            return Response::error(404, ['not found' => 'No shop with that id']);
        }

        return Response::ok(["shop" => $this->shops->getShopInfo($shop)]);
    }

    public function byPhone(string $phoneNumber): Response
    {
        if (trim($phoneNumber) === '') {
            throw new ValidationException(['phonenumber' => 'Phone number is required']);
        }

        $shop = $this->shops->getByPhone($phoneNumber);

        if ($shop === null) {
            return Response::error(404, ['not found' => 'No shop for that phone number']);
        }

        return Response::ok(["shop" => $this->shops->getShopInfo($shop)]);
    }

}
