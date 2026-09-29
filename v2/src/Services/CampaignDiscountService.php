<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

/**
 * Works out which active campaigns a cart qualifies for: which product units
 * each campaign takes, and what discount line each one earns. Pure: reads the
 * menu's campaigns and the cart rows it is handed, never writes —
 * {@see CartService::applyCampaigns()} owns the reconciliation against
 * order_items_active_{shop}.
 *
 * Discount lines follow cart.ms: product_id DISCOUNT_PRODUCT_ID (0 — the
 * "discount / adjustment line" OrderBasket already skips on reorder), the
 * campaign_id they belong to, and the discount_product_id of the product they
 * take money off. A campaign applies as many times as the cart holds full
 * sets for it, and each application's discount is spread evenly, in whole
 * cents, over the units it took — so the ticket can print every discount
 * under its own product.
 */
final class CampaignDiscountService
{
    public const int DISCOUNT_PRODUCT_ID = 0;
    public const int DISCOUNT_CATEGORY_ID = 0;

    public function __construct(
        private readonly MenuService $menu,
    ) {}

    public static function isDiscountLine(array $line): bool
    {
        return isset($line['product_id'], $line['campaign_id'])
            && (int) $line['product_id'] === self::DISCOUNT_PRODUCT_ID;
    }

    /**
     * What makes two discount lines the same line: one campaign's discount,
     * on one product, at one amount per unit. CartService matches existing
     * rows against the plan by this.
     */
    public static function discountKey(array $line): string
    {
        return implode('|', [
            (int) $line['campaign_id'],
            (int) ($line['discount_product_id'] ?? 0),
            (int) round((float) $line['unit_price'] * 100),
        ]);
    }

    /**
     * Splits one application's discount evenly over the units it took, in
     * whole cents; the cents that don't divide go one each to the first
     * (priciest) units.
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

    public function plan(array $lines): array
    {
        $families = $this->families($lines);
        $pool = $this->unitPool($families);

        if ($pool === []) {
            return ['discounts' => [], 'families' => []];
        }

        $campaigns = $this->usableCampaigns();
        $names = array_column($campaigns, 'name', 'id');
        $discounts = [];

        // Each round applies whichever campaign saves the most on what's left
        // of the pool — the same one again if it still does — until none of
        // them is a saving any more.
        while (true) {
            $best = null;

            foreach ($campaigns as $campaign) {
                $taken = $this->takeOnce($pool, $campaign['combo_groups']);

                if ($taken === null) {
                    continue;
                }

                $discount = round(array_sum(array_column($taken, 'price')) - (float) $campaign['price'], 2);

                if ($discount > 0 && ($best === null || $discount > $best['discount'])) {
                    $best = ['campaign_id' => (int) $campaign['id'], 'taken' => $taken, 'discount' => $discount];
                }
            }

            if ($best === null) {
                break;
            }

            foreach ($best['taken'] as $index => $unit) {
                unset($pool[$index]);
                $families[$unit['family']]['split'][$best['campaign_id']] =
                    ($families[$unit['family']]['split'][$best['campaign_id']] ?? 0) + 1;
                $families[$unit['family']]['split'][0]--;
            }

            $campaignId = $best['campaign_id'];

            foreach ($this->spread($best['discount'], $best['taken']) as [$unit, $cents]) {
                $line = [
                    'campaign_id'         => $campaignId,
                    'discount_product_id' => $unit['product_id'],
                    'item_description'    => (string) ($names[$campaignId] ?? "Campaign {$campaignId}"),
                    'unit_price'          => -$cents / 100,
                    'quantity'            => 0,
                    'vat_percentage'      => $unit['vat'],
                ];

                $key = self::discountKey($line);
                $discounts[$key] ??= $line;
                $discounts[$key]['quantity']++;
            }
        }

        return [
            'discounts' => $discounts,
            'families'  => array_values(array_map(
                static fn(array $family): array => ['line_ids' => $family['line_ids'], 'split' => $family['split']],
                $families,
            )),
        ];
    }

    /**
     * Groups the top-level product lines a campaign may consume into
     * families: same product, same unit price, same configs. Configs ride on
     * their host (a config's price is not part of the campaign), and synthetic
     * lines (delivery fee, discounts) are left out.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, array{line_ids: list<int>, quantity: int, split: array<int, int>, product_id: int, price: float, vat: int}>
     *         keyed by family; "split" starts with every unit untagged (key 0)
     */
    private function families(array $lines): array
    {
        $configsByHost = [];

        foreach ($lines as $line) {
            $parentId = (int) ($line['parent_id'] ?? 0);

            if ($parentId !== 0) {
                $configsByHost[$parentId][] = (int) $line['product_id'];
            }
        }

        $families = [];

        foreach ($lines as $line) {
            if ((int) ($line['parent_id'] ?? 0) !== 0 || (int) ($line['product_id'] ?? 0) <= 0) {
                continue;
            }

            $configs = $configsByHost[(int) $line['id']] ?? [];
            sort($configs);

            $price = round((float) $line['unit_price'], 2);
            $key = implode('|', [(int) $line['product_id'], $price, implode(',', $configs)]);
            $quantity = max(0, (int) $line['quantity']);

            $families[$key] ??= [
                'line_ids'   => [],
                'quantity'   => 0,
                'split'      => [0 => 0],
                'product_id' => (int) $line['product_id'],
                'price'      => $price,
                'vat'        => CartService::normalizeVatPercentage($line['vat_percentage'] ?? 0),
            ];

            $families[$key]['line_ids'][] = (int) $line['id'];
            $families[$key]['quantity'] += $quantity;
            $families[$key]['split'][0] += $quantity;
        }

        return $families;
    }

    /**
     * One entry per unit of every family, tagged with the family it came from.
     *
     * @param array<string, array{quantity: int, product_id: int, price: float, vat: int}> $families
     * @return array<int, array{family: string, product_id: int, keys: list<string>, price: float, vat: int}>
     */
    private function unitPool(array $families): array
    {
        $pool = [];

        foreach ($families as $key => $family) {
            $unit = [
                'family'     => $key,
                'product_id' => $family['product_id'],
                'keys'       => $this->keysOf($family['product_id']),
                'price'  => $family['price'],
                'vat'    => $family['vat'],
            ];

            for ($i = 0; $i < $family['quantity']; $i++) {
                $pool[] = $unit;
            }
        }

        return $pool;
    }

    /**
     * Picks one application's worth of units out of $pool without removing
     * them: for each group, its `count` priciest matching units not already
     * picked for an earlier group.
     *
     * @param array<int, array{keys: list<string>, price: float, vat: int}> $pool
     * @param list<array{count: int, ids: list<string>}> $groups
     * @return array<int, array{keys: list<string>, price: float, vat: int}>|null
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

            uasort($candidates, static fn(array $a, array $b): int => $b['price'] <=> $a['price']);

            $taken += array_slice($candidates, 0, $group['count'], preserve_keys: true);
        }

        return $taken;
    }

    /**
     * Active campaigns with a usable price and combo_groups, normalized.
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
