<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

/**
 * Works out the campaign discount lines a cart earns, following cart.ms's
 * bundle rules (add_promo_bundle_v2). Pure: reads the menu's campaigns and the
 * cart rows it is handed, never writes — {@see CartService::applyCampaigns()}
 * swaps the result into order_items_active_{shop}.
 *
 * - Product lines are never touched or tagged; a discount is linked to the
 *   product it takes money off only through its line's discount_product_id.
 * - Campaigns are tried one after another in the order the menu lists them.
 *   Each one is applied as many times as the units still free can fill all
 *   of its combo_groups, taking the cheapest matching units first. Units a
 *   campaign took are gone for the campaigns after it.
 * - A set costs the campaign's `price`; its discount is what the set's units
 *   cost on their lines minus that price. A set that isn't cheaper that way
 *   gets no discount (its units are still used up, as in cart.ms).
 * - Each set's discount is spread evenly, in whole cents, over its units, and
 *   written as discount lines per product — product_id 0, the campaign_id,
 *   discount_product_id, labelled "Discount - {campaign name}".
 */
final class CampaignDiscountService
{
    public const int DISCOUNT_PRODUCT_ID = 0;
    public const int DISCOUNT_CATEGORY_ID = 0;

    /** cart.ms prefixes this (translated) to the campaign name. */
    private const string DISCOUNT_LABEL = 'Discount';

    public function __construct(
        private readonly MenuService $menu,
    ) {}

    public static function isDiscountLine(array $line): bool
    {
        return isset($line['product_id'], $line['campaign_id'])
            && (int) $line['product_id'] === self::DISCOUNT_PRODUCT_ID;
    }

    /** One campaign's discount, on one product, at one amount per unit. */
    public static function discountKey(array $line): string
    {
        return implode('|', [
            (int) $line['campaign_id'],
            (int) ($line['discount_product_id'] ?? 0),
            (int) round((float) $line['unit_price'] * 100),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $lines the cart's flat order_items rows
     * @return array<string, array{campaign_id: int, discount_product_id: int, item_description: string, unit_price: float, quantity: int, vat_percentage: int}>
     *         the discount lines the cart should have, keyed by {@see discountKey()}; unit_price is negative
     */
    public function discountsFor(array $lines): array
    {
        $pool = $this->unitPool($lines);
        $discounts = [];

        if ($pool === []) {
            return [];
        }

        foreach ($this->usableCampaigns() as $campaign) {
            $campaignId = (int) $campaign['id'];

            while (($taken = $this->takeOnce($pool, $campaign['combo_groups'])) !== null) {
                foreach (array_keys($taken) as $index) {
                    unset($pool[$index]);
                }

                $discount = round(array_sum(array_column($taken, 'price')) - (float) $campaign['price'], 2);

                if ($discount <= 0) {
                    continue;
                }

                foreach ($this->spread($discount, $taken) as [$unit, $cents]) {
                    $line = [
                        'campaign_id'         => $campaignId,
                        'discount_product_id' => $unit['product_id'],
                        'item_description'    => self::DISCOUNT_LABEL . ' - ' . (string) ($campaign['name'] ?? $campaignId),
                        'unit_price'          => -$cents / 100,
                        'quantity'            => 0,
                        'vat_percentage'      => $unit['vat'],
                    ];

                    $key = self::discountKey($line);
                    $discounts[$key] ??= $line;
                    $discounts[$key]['quantity']++;
                }
            }
        }

        return $discounts;
    }

    /**
     * One entry per unit of every top-level product line. Configs ride on
     * their host (a config's price is not part of the campaign), and
     * synthetic lines (delivery fee, discounts) are left out.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array{product_id: int, keys: list<string>, price: float, vat: int}>
     */
    private function unitPool(array $lines): array
    {
        $pool = [];

        foreach ($lines as $line) {
            if ((int) ($line['parent_id'] ?? 0) !== 0 || (int) ($line['product_id'] ?? 0) <= 0) {
                continue;
            }

            $unit = [
                'product_id' => (int) $line['product_id'],
                'keys'       => $this->keysOf((int) $line['product_id']),
                'price'      => round((float) $line['unit_price'], 2),
                'vat'        => CartService::normalizeVatPercentage($line['vat_percentage'] ?? 0),
            ];

            for ($i = 0; $i < (int) $line['quantity']; $i++) {
                $pool[] = $unit;
            }
        }

        return $pool;
    }

    /**
     * Picks one set's worth of units out of $pool without removing them: for
     * each group, its `count` cheapest matching units not already picked for
     * an earlier group.
     *
     * @param array<int, array{product_id: int, keys: list<string>, price: float, vat: int}> $pool
     * @param list<array{count: int, ids: list<string>}> $groups
     * @return array<int, array{product_id: int, keys: list<string>, price: float, vat: int}>|null
     *         the picked units keyed by pool index, or null if a group can't be filled
     */
    private function takeOnce(array $pool, array $groups): ?array
    {
        $taken = [];

        foreach ($groups as $group) {
            $candidates = array_filter(
                $pool,
                static fn(array $unit, int $index): bool => !isset($taken[$index])
                    && array_intersect($unit['keys'], $group['ids']) !== [],
                ARRAY_FILTER_USE_BOTH,
            );

            if (count($candidates) < $group['count']) {
                return null;
            }

            uasort($candidates, static fn(array $a, array $b): int => $a['price'] <=> $b['price']);

            $taken += array_slice($candidates, 0, $group['count'], preserve_keys: true);
        }

        return $taken;
    }

    /**
     * Splits one set's discount evenly over its units, in whole cents; the
     * cents that don't divide go one each to the priciest units.
     *
     * @param array<int, array{product_id: int, price: float, vat: int}> $taken
     * @return list<array{0: array{product_id: int, price: float, vat: int}, 1: int}> each unit with its discount in cents
     */
    private function spread(float $discount, array $taken): array
    {
        $units = array_values($taken);
        usort($units, static fn(array $a, array $b): int => $b['price'] <=> $a['price']);

        $cents = (int) round($discount * 100);
        $base = intdiv($cents, count($units));
        $extra = $cents % count($units);
        $out = [];

        foreach ($units as $i => $unit) {
            $out[] = [$unit, $base + ($i < $extra ? 1 : 0)];
        }

        return $out;
    }

    /**
     * Active campaigns with a usable price and combo_groups, normalized, in
     * menu order.
     *
     * @return list<array<string, mixed>>
     */
    private function usableCampaigns(): array
    {
        $campaigns = [];

        foreach ($this->menu->campaigns() as $campaign) {
            if (!isset($campaign['id']) || !is_numeric($campaign['price'] ?? null)) {
                continue;
            }

            $groups = $this->groupsOf($campaign);

            if ($groups === []) {
                continue;
            }

            $campaigns[] = [...$campaign, 'combo_groups' => $groups];
        }

        return $campaigns;
    }

    /** @return list<array{count: int, ids: list<string>}> empty if any group is unusable */
    private function groupsOf(array $campaign): array
    {
        $groups = [];

        foreach ((array) ($campaign['combo_groups'] ?? []) as $group) {
            $ids = is_array($group) ? array_map('strval', (array) ($group['ids'] ?? [])) : [];

            if ($ids === []) {
                return [];
            }

            $groups[] = ['count' => max(1, (int) ($group['count'] ?? 1)), 'ids' => $ids];
        }

        return $groups;
    }

    /** @return list<string> the product's numeric id and, if it has one, its slug */
    private function keysOf(int $productId): array
    {
        $keys = [(string) $productId];
        $slug = $this->menu->product($productId)['slug'] ?? null;

        if (is_string($slug) && $slug !== '') {
            $keys[] = $slug;
        }

        return $keys;
    }
}
