<?php

declare(strict_types=1);

namespace Plugins\Shop\Services;

use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Support\Logger;

/**
 * Looks up shop records from the nizu universe service's `shops` table.
 *
 * nizu (a v1 service) owns tenant/shop identity; this resolves it over the
 * wire the same way {@see \Plugins\Whatsapp\Gateway\WhatsappGateway::shopToken()}
 * already does: a `select_row` call via {@see ServiceClient} (nizu's
 * function_map entry must carry "version": 1). select_row's `data` envelope
 * is `{ values: { row: {...} }, table_last_update }`, and `where` is a raw
 * SQL fragment in v1's contract — every value here is either cast to int or
 * reduced to digits before being interpolated, so nothing client-controlled
 * reaches the fragment unescaped.
 */
final class ShopService
{
    private const string TABLE = 'shops';
    private const string FIELDS = 'id,company_id,phonenumber,gateway_token,enabled';

    /** @var array<string, array<string, mixed>|null> */
    private array $byPhoneCache = [];

    /** @var array<int, array<string, mixed>|null> */
    private array $byIdCache = [];

    public function __construct(
        private readonly ServiceClient $serviceClient,
        private readonly Logger $logger,
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

        return $this->byPhoneCache[$digits] = $this->fetch("`enabled`=1 AND `phonenumber`='{$digits}'");
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $shopId): ?array
    {
        if (array_key_exists($shopId, $this->byIdCache)) {
            return $this->byIdCache[$shopId];
        }

        return $this->byIdCache[$shopId] = $this->fetch("`enabled`=1 AND `id`={$shopId}");
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $where): ?array
    {
        try {
            $result = $this->serviceClient->call('select_row', [
                'table' => self::TABLE,
                'fields' => self::FIELDS,
                'where' => $where,
            ]);
        } catch (ServiceException $e) {
            $this->logger->warning('shop: could not fetch shop from nizu', [
                'where' => $where,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $row = $result['values']['row'] ?? null;

        return is_array($row) && $row !== [] ? $row : null;
    }
}
