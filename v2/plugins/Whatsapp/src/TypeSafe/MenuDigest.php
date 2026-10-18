<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Services\JsonService;
use Pmsrapi\V2\Services\MenuService;

/**
 * The current shop's menu trimmed to what TypeSafe needs to *recognise* an
 * item: name, a short "about", and aliases. Prices, stock, specs, tags and long
 * descriptions are left out; Marvin still has them for his replies.
 *
 * Options are keyed "p{product id}", so a menu_item answer maps straight back
 * to a product with productId(). Size, base and other config options are not
 * repeated per item: configGroups() lists each group once.
 *
 * Built from MenuService on first use and kept for the request; the menu is
 * already loaded there, so this is cheap and never stale.
 */
final class MenuDigest
{
    /** "About" text is cut to this many characters; enough to tell items apart. */
    private const int ABOUT_MAX = 120;

    /** @var array<int, array{name: string, about: string, deal: bool}>|null product id => item */
    private ?array $items = null;

    public function __construct(
        private readonly MenuService $menuService,
        private readonly JsonService $json,
    ) {}

    /**
     * The menu's categories for "show me the menu": one line per top-level
     * category with the sub-categories that have products, e.g.
     * "Bowls: Fish, Meat, Vegan". Categories without products are left out.
     * '' when the shop has no category data.
     */
    public function categoryOverview(): string
    {
        try {
            $categories = $this->json->load('categories');
        } catch (ApiException) {
            return '';
        }
        $categories = array_values(array_filter(array_is_list($categories) ? $categories : ($categories['categories'] ?? []), 'is_array'));

        $counts = [];
        foreach ($this->menuService->index() as $product) {
            $id = (int) ($product['category_id'] ?? 0);
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        $lines = [];
        foreach ($categories as $top) {
            if (($top['parent_id'] ?? null) !== null) {
                continue;
            }
            $children = array_values(array_map(
                static fn(array $c): string => (string) $c['name'],
                array_filter($categories, static fn(array $c): bool => (int) ($c['parent_id'] ?? 0) === (int) $top['id'] && isset($counts[(int) $c['id']])),
            ));
            if ($children === [] && !isset($counts[(int) $top['id']])) {
                continue;
            }
            $lines[] = (string) $top['name'] . ($children !== [] ? ': ' . implode(', ', $children) : '');
        }

        return implode('; ', $lines);
    }

    /**
     * Choice criteria for "which menu item": option key => description.
     *
     * @param array<string, list<string>> $aliases product name => other names
     *                                              shoppers use (from flow.json)
     * @return array<string, string>
     */
    public function choiceOptions(array $aliases = []): array
    {
        $options = [];
        foreach ($this->items() as $id => $item) {
            $text = $item['name'];
            if ($item['about'] !== '') {
                $text .= ': ' . $item['about'];
            }
            if ($item['deal']) {
                $text .= ' (deal)';
            }
            $also = $aliases[$item['name']] ?? [];
            if ($also !== []) {
                $text .= '. Also called: ' . implode(', ', $also);
            }
            $options["p{$id}"] = $text;
        }

        return $options;
    }

    /**
     * Name, price and a few words for these products, as JSON, for a note
     * that asks the shopper which one they mean. '' when none are known.
     *
     * @param list<int> $productIds
     */
    public function facts(array $productIds): string
    {
        $items = $this->items();
        $facts = [];
        foreach ($productIds as $id) {
            if (isset($items[$id])) {
                $facts[] = ['name' => $items[$id]['name'], 'price' => $this->menuService->basePrice($id), 'about' => $items[$id]['about']];
            }
        }

        return $facts === [] ? '' : (string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** "p11" -> 11, or null when the key is not a product on this menu. */
    public function productId(string $option): ?int
    {
        if (preg_match('/^p(\d+)$/', $option, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];

        return isset($this->items()[$id]) ? $id : null;
    }

    public function name(int $productId): ?string
    {
        return $this->items()[$productId]['name'] ?? null;
    }

    /**
     * Every config group on the menu, once, with its option labels.
     * For a pizza shop: ["Size" => ["Small (25cm)", ...], "Base" => [...]].
     *
     * @return array<string, list<string>>
     */
    public function configGroups(): array
    {
        $groups = [];
        foreach ($this->menuService->index() as $product) {
            foreach ((array) ($product['config_groups'] ?? []) as $group) {
                $label = trim((string) ($group['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                foreach ((array) ($group['options'] ?? []) as $option) {
                    $optionLabel = trim((string) ($option['label'] ?? ''));
                    if ($optionLabel !== '' && !in_array($optionLabel, $groups[$label] ?? [], true)) {
                        $groups[$label][] = $optionLabel;
                    }
                }
            }
        }

        return $groups;
    }

    /** @return array<int, array{name: string, about: string, deal: bool}> */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $items = [];
        foreach ($this->menuService->index() as $id => $product) {
            $name = trim((string) ($product['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $items[(int) $id] = [
                'name'  => $name,
                'about' => $this->about($product),
                'deal'  => $this->menuService->isDeal((int) $id),
            ];
        }

        return $this->items = $items;
    }

    /** @param array<string, mixed> $product */
    private function about(array $product): string
    {
        $text = (string) ($product['short_description'] ?? '');
        if (trim($text) === '') {
            $text = (string) ($product['description'] ?? '');
        }
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > self::ABOUT_MAX
            ? rtrim(mb_substr($text, 0, self::ABOUT_MAX - 1)) . '…'
            : $text;
    }
}
