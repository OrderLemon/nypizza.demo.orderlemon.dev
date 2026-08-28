<?php

declare(strict_types=1);

namespace Plugins\Shop\Services;

use Pmsrapi\V2\Database\Repository;

/**
 * Looks up shop records from the local `shops` table.
 */
final class ShopService
{
    private const string TABLE = 'shops';

    /** @var array<string, array<string, mixed>|null> */
    private array $byPhoneCache = [];

    /** @var array<int, array<string, mixed>|null> */
    private array $byIdCache = [];

    public function __construct(
        private readonly Repository $repo,
    ) {}

    /**
     * Find the enabled shop whose account number matches $phone.
     *
     * @return array<string, mixed>|null
     */
    public function findByPhone(string $phone): ?array
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (array_key_exists($digits, $this->byPhoneCache)) {
            return $this->byPhoneCache[$digits];
        }

        return $this->byPhoneCache[$digits] = $this->repo->selectRow(self::TABLE, [
            'phonenumber' => $digits,
            'enabled' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $shopId): ?array
    {
        if (array_key_exists($shopId, $this->byIdCache)) {
            return $this->byIdCache[$shopId];
        }

        return $this->byIdCache[$shopId] = $this->repo->selectRow(self::TABLE, [
            'id' => $shopId,
            'enabled' => 1,
        ]);
    }
}
