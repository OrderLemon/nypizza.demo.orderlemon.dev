<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Core\Config;

final class ShopLinkService
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function shopLinkWithProducts(string $phone, ?string $language, array $ids): string
    {
        return $this->shopLink($phone, $language) . "&products=" . implode(",", $ids);
    }

    public function shopLinkWithUsualOrder(string $phone, ?string $language, string $orderHash): string
    {
        return $this->shopLink($phone, $language) . "&order=" . urlencode($orderHash);
    }

    public function shopLinkWithCheckout(string $phone, ?string $language): string
    {
        return $this->shopLink($phone, $language) . "&checkout=true";
    }

    public function shopLink(string $phone, ?string $language): string
    {
        $shopLink = (string) $this->config->secret("cta.shop_link", "");

        return rtrim($shopLink, "/") . "/?phonenumber="
            . urlencode($phone)
            . "&shop_id=" . shop_id
            . "&language=" . urlencode((string) $language);
    }
}
