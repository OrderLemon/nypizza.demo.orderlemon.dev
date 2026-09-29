<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\AI;

use Pmsrapi\V2\Services\MenuService;

/**
 * Finds the active campaigns a basket is close to, so Marvin can upsell one in
 * the same reply he confirms an add with: "two cakes in — add a third and the
 * cake trio is €16.50 instead of €19.25".
 *
 * A campaign's combo_groups say what it takes: each group needs `count` items
 * whose product is listed in `ids`. Ids are product slugs (as campaigns.json
 * writes them) or numeric product ids — both are accepted, so either catalogue
 * style works.
 *
 * Only campaigns the just-added product belongs to are returned. That keeps the
 * nudge relevant ("you added an appelpunt, here's the appelpunt deal") and stops
 * Marvin from pitching every campaign on every add.
 *
 * Pure: reads the menu, never the cart store, never a price the model supplied.
 * The cart does NOT apply campaign prices (CartService reports savings as 0), so
 * the result is a hint for Marvin to phrase, never a discount on the basket.
 */
final class CampaignNudge
{
    /** Further away than this and it isn't a nudge, it's a sales pitch. */
    private const int MAX_MISSING = 2;

    public function __construct(
        private readonly MenuService $menuService,
    ) {}

    /**
     * @param list<array<string,mixed>> $cartItems raw cart rows (configs have a parent_id)
     * @return list<array{campaign_id: mixed, name: string, price: mixed, old_price: mixed,
     *                    description: string, qualifies: bool, missing: list<array{label: string, add: int}>}>
     */
    public function near(array $cartItems, int $addedProductId): array
    {
        $inCart = $this->quantitiesByKey($cartItems);
        $addedKeys = $this->keysOf($addedProductId);

        $nudges = [];

        foreach ($this->menuService->campaigns() as $campaign) {
            $groups = is_array($campaign['combo_groups'] ?? null) ? $campaign['combo_groups'] : [];

            if ($groups === [] || !$this->mentionsAny($groups, $addedKeys)) {
                continue;
            }

            $missing = [];
            $missingTotal = 0;

            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $need = max(1, (int) ($group['count'] ?? 1));
                $have = 0;
                foreach ((array) ($group['ids'] ?? []) as $id) {
                    $have += $inCart[(string) $id] ?? 0;
                }

                $short = max(0, $need - $have);
                if ($short > 0) {
                    $missing[] = [
                        'label'       => (string) ($group['label'] ?? $group['id'] ?? ''),
                        'add'         => $short,
                        'product_ids' => $this->productIdsFor((array) ($group['ids'] ?? [])),
                    ];
                    $missingTotal += $short;
                }
            }

            if ($missingTotal > self::MAX_MISSING) {
                continue;
            }

            $nudges[] = [
                'campaign_id' => $campaign['id'] ?? null,
                'name'        => (string) ($campaign['name'] ?? ''),
                'price'       => $campaign['price'] ?? null,
                'old_price'   => $campaign['old_price'] ?? null,
                'description' => (string) ($campaign['short_description'] ?? $campaign['description'] ?? ''),
                'qualifies'   => $missingTotal === 0,
                'missing'     => $missing,
            ];
        }

        return $nudges;
    }

    /**
     * Top-level lines only (configs ride on their host), summed per product and
     * filed under both its numeric id and its slug.
     *
     * @param list<array<string,mixed>> $cartItems
     * @return array<string,int>
     */
    private function quantitiesByKey(array $cartItems): array
    {
        $out = [];

        foreach ($cartItems as $line) {
            if (!is_array($line) || (int) ($line['parent_id'] ?? 0) !== 0) {
                continue;
            }

            $quantity = (int) ($line['quantity'] ?? 0);
            foreach ($this->keysOf((int) ($line['product_id'] ?? 0)) as $key) {
                $out[$key] = ($out[$key] ?? 0) + $quantity;
            }
        }

        return $out;
    }

    /**
     * Campaign ids (slugs or numeric) resolved to the numeric product ids Marvin
     * passes to add_to_order — his prompt carries ids, not slugs.
     *
     * @param list<mixed> $ids
     * @return list<int>
     */
    private function productIdsFor(array $ids): array
    {
        $wanted = array_map('strval', $ids);
        $out = [];

        foreach ($this->menuService->index() as $productId => $product) {
            if (in_array((string) $productId, $wanted, true)
                || in_array((string) ($product['slug'] ?? ''), $wanted, true)) {
                $out[] = (int) $productId;
            }
        }

        return $out;
    }

    /** @return list<string> the product's numeric id and, if it has one, its slug */
    private function keysOf(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $keys = [(string) $productId];
        $slug = $this->menuService->product($productId)['slug'] ?? null;
        if (is_string($slug) && $slug !== '') {
            $keys[] = $slug;
        }

        return $keys;
    }

    /**
     * @param list<mixed> $groups
     * @param list<string> $keys
     */
    private function mentionsAny(array $groups, array $keys): bool
    {
        foreach ($groups as $group) {
            $ids = array_map('strval', is_array($group) ? (array) ($group['ids'] ?? []) : []);
            if (array_intersect($ids, $keys) !== []) {
                return true;
            }
        }

        return false;
    }
}
