<?php

declare(strict_types=1);

namespace Plugins\Shop\Controllers;

use Plugins\Shop\Services\ShopService;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Response;

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

        return Response::ok(["shop" => $this->present($shop)]);
    }

    public function byPhone(string $phoneNumber): Response
    {
        if (trim($phoneNumber) === '') {
            throw new ValidationException(['phonenumber' => 'Phone number is required']);
        }

        $shop = $this->shops->findByPhone($phoneNumber);

        if ($shop === null) {
            return Response::error(404, ['not found' => 'No shop for that phone number']);
        }

        return Response::ok(["shop" => $this->present($shop)]);
    }

    /**
     * @param array<string, mixed> $shop
     * @return array<string, mixed>
     */
    private function present(array $shop): array
    {
        return [
            'id' => $shop['id'],
            'name' => $shop['name'],
            'company_id' => $shop['company_id'],
            'phonenumber' => $shop['phonenumber'],
            'enabled' => $shop['enabled'],
            "min_pickup_minutes" => 20,
            "min_delivery_minutes" => 45,
            "currency" => "EUR",
            "default_vat_rate" => 0.06,
            "delivery_fee" => 2.5,
            "free_delivery_threshold" => 25
        ];
    }
}
