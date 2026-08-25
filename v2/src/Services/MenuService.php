<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Support\Logger;

/**
 * The menu, indexed for lookups.
 *
 * Marvin reads the menu as prose in his prompt. This reads the same marvin.json
 * as a data structure, so PHP can answer the questions Marvin must never answer
 * himself: does this product exist, is that a real option for it, and what does
 * the line cost.
 *
 * PRICES ARE COMPUTED HERE, NEVER PASSED BY THE MODEL. base price + the
 * priceDelta of each chosen option, times quantity. A model-supplied price is a
 * model-supplied invoice, and a wrong total on a confirmation screen is the one
 * bug in this feature that actually costs money.
 */
final class MenuService
{
    /** The mockups that make up a generated menu.json, and the order they're assembled in. */
    private const MENU_STRUCTURE = ["categories", "products", "campaigns"];

    /** @var array<int,array<string,mixed>>|null product id => product */
    private ?array $index = null;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ProductsService $productsService,
        private readonly CategoryService $categoryService,
        private readonly CampaignService $campaignService,
    ) {
    }

    // ---------------------------------------------------------------- lookups

    public function has(int $productId): bool
    {
        return isset($this->index()[$productId]);
    }

    /** @return array<string,mixed>|null */
    public function product(int $productId): ?array
    {
        return $this->index()[$productId] ?? null;
    }

    /**
     * Keep only ids that exist. Marvin picks products from the prompt text, so a
     * hallucinated or mistyped id has to be caught before it reaches a link or a
     * priced line.
     *
     * @param list<mixed> $ids
     * @return array{valid: list<int>, dropped: list<int>}
     */
    public function sift(array $ids): array
    {
        $valid = $dropped = [];

        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }

            $id = (int) $id;

            if ($this->has($id)) {
                if (!in_array($id, $valid, true)) {
                    $valid[] = $id;
                }
            } elseif (!in_array($id, $dropped, true)) {
                $dropped[] = $id;
            }
        }

        if ($dropped !== []) {
            $this->logger->warning('menu: dropped unknown product ids', ['ids' => $dropped]);
        }

        return ['valid' => $valid, 'dropped' => $dropped];
    }

    /** Deals carry combo_groups — slot counts and category constraints. */
    public function isDeal(int $productId): bool
    {
        $product = $this->product($productId);

        return is_array($product['combo_groups'] ?? null) && $product['combo_groups'] !== [];
    }

    // ---------------------------------------------------------------- options

    /**
     * Option groups this product requires a choice from, and the choices
     * available. Shaped for handing straight to Marvin so he can ask the
     * question — the menu prose does not spell out which groups are mandatory.
     *
     * @return list<array{group_id:string,label:string,options:list<array{option_id:string,label:string,price_delta:float}>}>
     */
    public function requiredGroups(int $productId): array
    {
        $out = [];

        foreach ($this->groups($productId) as $group) {
            if (($group['required'] ?? false) !== true) {
                continue;
            }

            $out[] = [
                'group_id' => (string) $group['id'],
                'label'    => (string) ($group['label'] ?? $group['id']),
                'options'  => array_map(
                    static fn(array $o): array => [
                        'option_id'   => (string) $o['id'],
                        'label'       => (string) ($o['label'] ?? $o['id']),
                        'price_delta' => (float) ($o['priceDelta'] ?? 0),
                    ],
                    array_values(array_filter((array) ($group['options'] ?? []), 'is_array'))
                ),
            ];
        }

        return $out;
    }

    /**
     * Resolve bare option ids against a product, working out which group each
     * belongs to. Option ids are unique within a product, so Marvin can pass
     * ["medium", "classic", "mushrooms"] and never has to know the group names.
     *
     * @param list<mixed> $optionIds
     * @return array{
     *   config: list<array{group_id:string,option_id:string,product_id:int,item_description:string,quantity:int,unit_price:float}>,
     *   unknown: list<string>,
     *   missing: list<array<string,mixed>>,
     *   delta: float
     * }
     */
    public function resolveOptions(int $productId, array $optionIds): array
    {
        $groups   = $this->groups($productId);
        $wanted   = array_values(array_unique(array_map('strval', array_filter($optionIds, 'is_scalar'))));
        $config   = [];
        $unknown  = $wanted;
        $delta    = 0.0;
        $chosenIn = [];

        foreach ($groups as $group) {
            $groupId = (string) $group['id'];
            $single  = ($group['type'] ?? 'single') === 'single';

            foreach ((array) ($group['options'] ?? []) as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $optionId = (string) ($option['id'] ?? '');
                if ($optionId === '' || !in_array($optionId, $wanted, true)) {
                    continue;
                }

                // A single-choice group takes the first match and ignores the rest,
                // so "medium" and "large" together cannot both be charged.
                if ($single && isset($chosenIn[$groupId])) {
                    $unknown = array_values(array_diff($unknown, [$optionId]));
                    continue;
                }

                $price  = (float) ($option['priceDelta'] ?? 0);
                $delta += $price;

                $config[] = [
                    'group_id'         => $groupId,
                    'option_id'        => $optionId,
                    'product_id'       => (int) ($option['product_id'] ?? 0),
                    'item_description' => (string) ($option['label'] ?? $optionId),
                    'quantity'         => 1,
                    'unit_price'       => $price,
                ];

                $chosenIn[$groupId] = true;
                $unknown            = array_values(array_diff($unknown, [$optionId]));
            }
        }

        // Required groups with nothing chosen — Marvin has a question to ask.
        $missing = [];
        foreach ($this->requiredGroups($productId) as $group) {
            if (!isset($chosenIn[$group['group_id']])) {
                $missing[] = $group;
            }
        }

        return [
            'config'  => $config,
            'unknown' => $unknown,
            'missing' => $missing,
            'delta'   => round($delta, 2),
        ];
    }

    // ----------------------------------------------------------------- pricing

    public function basePrice(int $productId): float
    {
        return round((float) ($this->product($productId)['price'] ?? 0), 2);
    }

    public function name(int $productId): string
    {
        return (string) ($this->product($productId)['name'] ?? 'item');
    }

    /** (base + option deltas) × quantity. */
    public function lineTotal(int $productId, int $quantity, float $optionDelta): float
    {
        return round(($this->basePrice($productId) + $optionDelta) * max(1, $quantity), 2);
    }

    // ------------------------------------------------------------------ index

    /** @return array<int,array<string,mixed>> */
    public function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        if(!defined("shop_id") || !is_numeric(shop_id)){
            throw new ApiException("shop id must be a numeric value!");
        }

        $path = $this->config->secret('marvin.config');

        if (!is_string($path) || trim($path) === '') {
            throw new ApiException('marvin.config is not set in the secret config.');
        }

        $path = str_replace("{{shop_id}}", (string) shop_id, $path);

        $decoded = $this->readMenu($path);

        // No menu.json yet, or one with nothing in it — build it from the
        // shop's mockups (categories + products + campaigns) so Marvin and
        // the rest of the app never have to special-case a missing file.
        if (!$this->hasCategories($decoded)) {
            $decoded = $this->buildMenu();
            $this->writeMenu($path, $decoded);
        }

        // Same shapes Marvin::menu() accepts: a bare list, or { "menu": ... },
        // where the menu may itself be { "categories": [...] }.
        $menu = array_is_list($decoded) ? $decoded : ($decoded['menu'] ?? []);
        if (is_array($menu) && isset($menu['categories'])) {
            $menu = $menu['categories'];
        }

        $index = [];
        $this->walk(is_array($menu) ? $menu : [], $index);

        if ($index === []) {
            $this->logger->error('menu: indexed zero products', ['path' => $path]);
        }

        return $this->index = $index;
    }

    /**
     * Decode menu.json, or an empty array when it doesn't exist yet or is
     * blank. A file that exists but contains invalid JSON is a real error,
     * not "empty" — that still throws.
     *
     * @return array<mixed>
     */
    private function readMenu(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ApiException("Cannot read marvin.config file: {$path}");
        }

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiException("marvin.config is not valid JSON: {$path}");
        }

        return $decoded;
    }

    /** @param array<mixed> $decoded */
    private function hasCategories(array $decoded): bool
    {
        $menu = array_is_list($decoded) ? $decoded : ($decoded['menu'] ?? []);
        if (is_array($menu) && isset($menu['categories'])) {
            $menu = $menu['categories'];
        }

        return is_array($menu) && $menu !== [];
    }

    /**
     * Assemble menu.json's shape from the shop's mockups: categories nested
     * with their products (via ProductsService::groupByCategory), and
     * campaigns alongside them, untouched — campaigns keep their own id space
     * and are never merged into the product index.
     *
     * @return array{success: bool, menu: array{categories: list<array<string,mixed>>, campaigns: list<array<string,mixed>>}}
     */
    private function buildMenu(): array
    {
        $mockups = [
            'categories' => $this->categoryService,
            'products'   => $this->productsService,
            'campaigns'  => $this->campaignService,
        ];

        $loaded = [];
        foreach (self::MENU_STRUCTURE as $mockup) {
            $loaded[$mockup] = $mockups[$mockup]->load($mockup);
        }

        return [
            'success' => true,
            'menu' => [
                'categories' => $this->productsService->groupByCategory($loaded['products'], $loaded['categories']),
                'campaigns'  => $loaded['campaigns'],
            ],
        ];
    }

    /** @param array<mixed> $menu */
    private function writeMenu(string $path, array $menu): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ApiException("Cannot create menu directory: {$dir}");
        }

        $encoded = json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($path, $encoded) === false) {
            throw new ApiException("Cannot write menu.json file: {$path}");
        }

        $this->logger->info('menu: generated menu.json from mockups', ['path' => $path]);
    }

    /**
     * Categories nest via "sub-category" and carry "products" at each level.
     *
     * @param array<mixed> $nodes
     * @param array<int,array<string,mixed>> $index
     */
    private function walk(array $nodes, array &$index): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            // A product node, not a category.
            if (isset($node['id'], $node['name']) && !isset($node['products'], $node['sub-category'])) {
                $index[(int) $node['id']] = $node;
                continue;
            }

            foreach ((array) ($node['products'] ?? []) as $product) {
                if (is_array($product) && isset($product['id'])) {
                    $index[(int) $product['id']] = $product;
                }
            }

            $this->walk((array) ($node['sub-category'] ?? []), $index);
            $this->walk((array) ($node['subcategory'] ?? []), $index);
        }
    }

    /** @return list<array<string,mixed>> */
    private function groups(int $productId): array
    {
        $groups = $this->product($productId)['config_groups'] ?? null;

        return is_array($groups)
            ? array_values(array_filter($groups, static fn($g): bool => is_array($g) && isset($g['id'])))
            : [];
    }
}