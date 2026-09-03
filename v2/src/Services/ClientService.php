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
        private readonly Logger $logger,
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
    public function upsertClient(array $data): ?array
    {
        // date_added must not be in updateColumns: it's a "first seen" stamp,
        // not a "last seen" one, so an existing client's date must stay put.
        if(isset($data["latitude"]) && isset($data["longitude"])){
            $address = $this->getClientAddress($data["latitude"], $data["longitude"]);
            $data = [...$data, ...$address];
        }
        $result = $this->repo->upsert(
            $this->clientsTable(),
            $data,
            updateColumns: ['full_name', 'latitude', 'longitude','country','state','city','zip','street','box'],
        );

        return $result;
    }


    public function updateEndClient(string $phone, array $data): ?array
    {
        $result = $this->repo->upsert(
            "clients_data",
            ['phonenumber' => $phone, ...$data],
            updateColumns: array_keys($data),
        );

        return $result;
    }

    /**
     * Saves checkout-supplied address/billing details against this phone's
     * client record, creating it if it doesn't exist yet. Only fields present
     * in $details are written — an incomplete checkout can't blank out
     * previously known details — and anything not a real clients_{shop}
     * column is dropped by Repository's own schema whitelist. date_added is
     * deliberately left out of $details so it's untouched on an update and
     * left to the column's own default on a brand new client.
     *
     * @param array<string, mixed> $details e.g. full_name, business_name,
     *        business_vat, business_tin, country/state/city/zip/street/box
     *        and their billing_* counterparts
     * @return array<string, mixed>|null the upserted client record
     */
    public function upsertFromCheckout(string $phone, array $details): ?array
    {
        //upsert the shop client
        $result = $this->repo->upsert(
            $this->clientsTable(),
            [...$details, 'phonenumber' => $phone],
            ["street","city","country","box","state","zip"]);

        //upsert the global client
        $this->repo->upsert(
            "clients_data",
            [...$details, 'phonenumber' => $phone],
            ["street","city","country","box","state","zip"]);

        return $result['record'];
    }

    private function clientsTable(): string
    {

        if (!defined("shop_id") || !is_numeric(shop_id)) {
            throw new ApiException('Invalid configuration for shop id');
        }

        return 'clients_' . (int) shop_id;
    }

    /**
     * Resolves lat/lng into a postal address via the Google Geocoding API.
     * Returns an empty array (rather than throwing) when the API is
     * unreachable or misconfigured, so a bad lookup never blocks the
     * client upsert that triggered it.
     *
     * @return array<string, string>
     */
    private function getClientAddress(string|int|float $latitude, string|int|float $longitude): array
    {
        $token = (string) $this->config->secret('google_api.location.token', '');
        $urlTemplate = (string) $this->config->secret('google_api.location.url', '');

        if ($token === '' || $urlTemplate === '') {
            $this->logger->error('Missing google_api.location configuration');

            return [];
        }

        $url = str_replace(
            ['${latitude}', '${longitude}', '${token}'],
            [(string) $latitude, (string) $longitude, $token],
            $urlTemplate,
        );

        
        $result = $this->callGeocodeApi($url);

        $this->logger->info("Google api response for: $url", $result ?? []);

        return $result !== null ? $this->parseGeocodeResult($result) : [];
    }

    /**
     * Calls the Google Geocoding API and returns the best-match result, or
     * null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function callGeocodeApi(string $url): ?array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false || $curlError !== '') {
            $this->logger->error("Google geocode API call failed: {$curlError}");

            return null;
        }

        if ($statusCode !== 200) {
            $this->logger->error("Google geocode API returned HTTP {$statusCode}: {$response}");

            return null;
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'OK' || !isset($decoded['results'][0])) {
            $this->logger->error("Google geocode API returned an unexpected response: {$response}");

            return null;
        }

        return $decoded['results'][0];
    }

    /**
     * Maps a Google Geocoding "result" entry's address_components into this
     * service's client column names. Missing components are simply omitted
     * so callers only overwrite what Google actually returned.
     *
     * @param array<string, mixed> $result
     * @return array<string, string>
     */
    private function parseGeocodeResult(array $result): array
    {
        $components = is_array($result['address_components'] ?? null)
            ? $result['address_components']
            : [];

        $findComponent = static function (array $types, string $nameField = 'long_name') use ($components): ?string {
            foreach ($components as $component) {
                if (!is_array($component) || !is_array($component['types'] ?? null)) {
                    continue;
                }

                if (array_intersect($types, $component['types'])) {
                    return (string) ($component[$nameField] ?? '');
                }
            }

            return null;
        };

        $streetNumber = $findComponent(['street_number']);
        $route = $findComponent(['route']);
        $street = trim(($route ?? '') . ($streetNumber !== null ? ' ' . $streetNumber : ''));

        $fields = [
            // 'country' column is varchar(2) — use the ISO short_name (e.g. "RO"), not the full country name.
            'country' => $findComponent(['country'], 'short_name'),
            'state' => $findComponent(['administrative_area_level_1']),
            'city' => $findComponent(['locality', 'postal_town', 'administrative_area_level_2']),
            'zip' => $findComponent(['postal_code']),
            'street' => $street !== '' ? $street : null,
            'box' => $findComponent(['subpremise']),
        ];

        return array_filter(
            $fields,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    public function getOrInsertGlobalClient(string $phoneNumber, array $data): ?array
    {
        $client = $this->upsertGlobalClient($phoneNumber, $data);

        if ($client === null) {
            throw new ServiceException("Failed to upsert global client for phone number: $phoneNumber");
        }

        $client = $client['record'] ?? null;
        
        //shop clients require full_name
        $client["full_name"] = trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
        
        //also insert in the clients_{shop_id} table
        $upsert = $this->upsertClient($client);

        return ["action" => $upsert["action"], "record" => $client];
    }

    public function getGlobalClient(string $phoneNumber): ?array
    {
        return $this->repo->selectRow('clients_data', [
            'phonenumber' => $phoneNumber,
        ]);
    }

    public function insertGlobalClient(string $phoneNumber, array $data): ?array
    {
        $result = $this->repo->upsert('clients_data', [
            'phonenumber' => $phoneNumber,
            ...$data,
        ]);

        return $result;
    }

    public function upsertGlobalClient(string $phoneNumber, array $data): ?array
    {
        $result = $this->repo->upsert('clients_data', [
            'phonenumber' => $phoneNumber,
            ...$data,
        ], updateColumns: array_keys($data));

        return $result;
    }

    public function getClientLanguage(string $phoneNumber): string
    {
        $client = $this->getGlobalClient($phoneNumber);

        return $client['language'] ?? 'en';
    }
}

?>