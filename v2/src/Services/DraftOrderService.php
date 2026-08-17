<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;
use Pmsrapi\V2\Helpers\CustomerHelper;

use Pmsrapi\V2\Exception\ApiException;

/**
 * The basket Marvin assembles in conversation, before the shopper completes it
 * on the web.
 *
 * APPEND-ONLY, like tracking. JsonService cannot update, so every change appends
 * a complete snapshot of the draft and reads take the LAST record for that phone.
 * Undo and an audit trail come free; the file grows by one record per tap, which
 * is fine at demo volume.
 *
 * Nothing here trusts the model with a price. Marvin passes a product id, a
 * quantity and bare option ids; MenuService resolves and prices them. He also
 * cannot pass a phone — it comes from the gateway envelope.
 */
final class DraftOrderService extends JsonService
{
    public const MOCKUP = 'order_drafts';

    /** A draft older than this is stale — a Tuesday basket must not reappear on Friday. */
    private const TTL_SECONDS = 7200;

    private const MAX_LINES    = 20;
    private const MAX_QUANTITY = 20;

    public function __construct(
        private readonly MenuService $menu,
        \Pmsrapi\V2\Support\Logger $logger,
        \Pmsrapi\V2\Core\Config $config,
    ) {
        parent::__construct($logger, $config);
    }

    // ---------------------------------------------------------------- reading

    /**
     * The live draft for this phone, or null when there is none, it was cleared,
     * checked out, or it has gone stale.
     *
     * @return array<string,mixed>|null
     */
    public function current(string $phone): ?array
    {
        $draft = $this->latest($phone);

        if ($draft === null) {
            return null;
        }

        if (($draft['status'] ?? 'open') !== 'open') {
            return null;
        }

        $age = time() - (int) ($draft['updated_ts'] ?? 0);
        if ($age > self::TTL_SECONDS) {
            $this->logger->info('draft: expired', ['age_seconds' => $age]);

            return null;
        }

        return $draft;
    }

    /** The most recent record for this phone whatever its status. */
    private function latest(string $phone): ?array
    {
        try {
            $records = $this->load(self::MOCKUP);
        } catch (ApiException $e) {
            $this->logger->error('draft: cannot load: ' . $e->getMessage());

            return null;
        }

        foreach (array_reverse($records) as $record) {
            if (is_array($record) && CustomerHelper::samePhone((string) ($record['phone'] ?? ''), $phone)) {
                return $record;
            }
        }

        return null;
    }

    /** Find a draft by its checkout reference — the front end's only lookup. */
    public function byReference(string $reference): ?array
    {
        if (trim($reference) === '') {
            return null;
        }

        try {
            $records = $this->load(self::MOCKUP);
        } catch (ApiException $e) {
            $this->logger->error('draft: cannot load: ' . $e->getMessage());

            return null;
        }

        foreach (array_reverse($records) as $record) {
            if (is_array($record) && ($record['reference'] ?? null) === $reference) {
                return $record;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- writing

    /**
     * Add a line. Returns the outcome rather than throwing, so Marvin can be told
     * what to ask next.
     *
     * @param list<mixed> $optionIds bare option ids, e.g. ["medium","classic","mushrooms"]
     * @return array<string,mixed>
     */
    public function add(string $phone, int $productId, int $quantity, array $optionIds): array
    {
        if (!$this->menu->has($productId)) {
            return ['ok' => false, 'reason' => 'unknown_product', 'product_id' => $productId];
        }

        // Deals have combo slots with category constraints — a different problem
        // from a configurable product, and out of scope for conversation.
        if ($this->menu->isDeal($productId)) {
            return [
                'ok'     => false,
                'reason' => 'deal_not_supported',
                'name'   => $this->menu->name($productId),
            ];
        }

        $resolved = $this->menu->resolveOptions($productId, $optionIds);

        // A required choice is missing: tell Marvin what to ask, change nothing.
        if ($resolved['missing'] !== []) {
            return [
                'ok'         => false,
                'reason'     => 'needs_options',
                'product_id' => $productId,
                'name'       => $this->menu->name($productId),
                'needs'      => $resolved['missing'],
            ];
        }

        $quantity = max(1, min(self::MAX_QUANTITY, $quantity));

        $draft    = $this->current($phone) ?? $this->blank($phone);

        if (count($draft['items']) >= self::MAX_LINES) {
            return ['ok' => false, 'reason' => 'basket_full'];
        }

        $line = [
            'line_id'          => $this->nextLineId($draft),
            'product_id'       => $productId,
            'item_description' => $this->menu->name($productId),
            'quantity'         => $quantity,
            'unit_price'       => $this->menu->basePrice($productId),
            'config'           => $resolved['config'],
            'line_total'       => $this->menu->lineTotal($productId, $quantity, $resolved['delta']),
        ];

        $draft['items'][] = $line;

        $saved = $this->commit($draft);

        return [
            'ok'      => true,
            'added'   => $line['item_description'],
            'unknown_options' => $resolved['unknown'],
            'draft'   => $this->summary($saved),
        ];
    }

    /** @return array<string,mixed> */
    public function remove(string $phone, int $lineId): array
    {
        $draft = $this->current($phone);

        if ($draft === null) {
            return ['ok' => false, 'reason' => 'no_draft'];
        }

        $before = count($draft['items']);

        $draft['items'] = array_values(array_filter(
            $draft['items'],
            static fn(array $line): bool => (int) $line['line_id'] !== $lineId
        ));

        if (count($draft['items']) === $before) {
            return ['ok' => false, 'reason' => 'no_such_line', 'draft' => $this->summary($draft)];
        }

        return ['ok' => true, 'draft' => $this->summary($this->commit($draft))];
    }

    /** @return array<string,mixed> */
    public function clear(string $phone): array
    {
        $drafts = $this->load(self::MOCKUP);

        $draft = array_filter($drafts, fn($dr) : bool => CustomerHelper::samePhone($dr["phone"], $phone) );
        
        if ($draft === null || count($draft) === 0) {
            return ['ok' => true, 'cleared' => false];
        }
        
        $keys = array_keys($draft) ?? null;

        if($keys === null){
            return ['ok' => true, 'cleared' => false];
        }

        foreach($keys as $key){
            unset($drafts[$key]);
        }

        if(!$this->save($drafts, self::MOCKUP)){
            return ['ok' => true, 'cleared' => false];
        }

        return ['ok' => true, 'cleared' => true];
    }

    /**
     * Close the draft and mint the reference the front end fetches it by.
     *
     * A reference, not an encoded basket: it is random, so there is nothing in it
     * to tamper with, and the items are only ever read from our own file.
     *
     * @return array<string,mixed>
     */
    public function checkout(string $phone): array
    {
        $draft = $this->current($phone);

        if ($draft === null || $draft['items'] === []) {
            return ['ok' => false, 'reason' => 'empty_basket'];
        }

        $draft['status']         = 'checked_out';
        $draft['reference']      = bin2hex(random_bytes(8));
        $draft['checked_out_at'] = date('Y-m-d H:i:s');

        $saved = $this->commit($draft);

        return [
            'ok'        => true,
            'reference' => $saved['reference'],
            'total'     => $saved['total'],
            'draft'     => $this->summary($saved),
        ];
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function blank(string $phone): array
    {
        return [
            'phone'  => $phone,
            'items'  => [],
            'status' => 'open',
            'total'  => 0.0,
        ];
    }

    /** Recompute the total and append the snapshot. */
    private function commit(array $draft): array
    {
        $draft['total'] = round(array_sum(array_map(
            static fn(array $line): float => (float) $line['line_total'],
            $draft['items']
        )), 2);

        $draft['updated_at'] = date('Y-m-d H:i:s');
        $draft['updated_ts'] = time();


        if( !isset($draft["id"])){
            $this->addItems([$draft], self::MOCKUP);
        }else{
            $this->replaceItem($draft["id"], $draft, self::MOCKUP);
        }

        return $draft;
    }

    private function nextLineId(array $draft): int
    {
        $ids = array_map(static fn(array $l): int => (int) $l['line_id'], $draft['items']);

        return $ids === [] ? 1 : max($ids) + 1;
    }

    /**
     * What Marvin sees. Line ids and totals, so he can read the basket back and
     * remove things by reference — not the full config arrays, which are cost
     * without signal for writing a sentence.
     *
     * @return array<string,mixed>
     */
    private function summary(array $draft): array
    {
        return [
            'items' => array_map(
                static fn(array $line): array => [
                    'line_id'  => $line['line_id'],
                    'name'     => $line['item_description'],
                    'quantity' => $line['quantity'],
                    'options'  => array_map(
                        static fn(array $o): string => (string) $o['item_description'],
                        $line['config']
                    ),
                    'total'    => $line['line_total'],
                ],
                $draft['items']
            ),
            'count' => count($draft['items']),
            'total' => $draft['total'],
        ];
    }

}