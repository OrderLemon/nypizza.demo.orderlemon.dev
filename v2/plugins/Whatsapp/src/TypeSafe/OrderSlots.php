<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Pmsrapi\V2\Services\CartService;
use Pmsrapi\V2\Services\MenuService;

/**
 * Turns what TypeSafe read from an order message into tool input:
 *
 *   add_to_order      {product_id, quantity, option_ids}
 *   change_logistics  {logistics_type, date: "asap"}
 *   filter_products   {product_ids: [one product]}
 *   remove_from_order {line_id, keep?}, one per basket line
 *
 * TypeSafe answers in option labels per config group ("Size" => "Large (40cm)");
 * here they are matched to the chosen product's own option ids. A label the
 * product does not offer is left out. Required choices that are still missing
 * are not guessed: add_to_order answers "needs_options" and Marvin asks.
 */
final class OrderSlots
{
    public function __construct(
        private readonly MenuService $menu,
    ) {}

    /**
     * add_to_order input when TypeSafe is confident about one menu item, else null.
     *
     * @return array{product_id: int, quantity: int, option_ids: list<string>}|null
     */
    public function addToOrder(Decision $decision): ?array
    {
        if ($decision->item !== Decision::ITEM_ONE || $decision->productId === null
            || !$decision->isConfident($decision->itemConfidence)) {
            return null;
        }

        return [
            'product_id' => $decision->productId,
            'quantity'   => $decision->quantity ?? 1,
            'option_ids' => $this->optionIds($decision->productId, $decision),
        ];
    }

    /**
     * change_logistics input when the message says pickup or delivery AND
     * "as soon as possible". A later time needs the actual clock time, which
     * TypeSafe does not extract: Marvin asks or reads it himself.
     *
     * @return array{logistics_type: int, date: string}|null
     */
    public function changeLogistics(Decision $decision): ?array
    {
        $type = match ($decision->logistics) {
            'pickup'   => CartService::PICKUP_LOGISTIC_TYPE,
            'delivery' => CartService::DELIVERY_LOGISTIC_TYPE,
            default    => null,
        };

        return $type !== null && $decision->orderTime === 'asap'
            ? ['logistics_type' => $type, 'date' => 'asap']
            : null;
    }

    /**
     * filter_products input when TypeSafe is confident about one menu item.
     * Vague requests ("something with meat") stay with Marvin, who picks the
     * products from the menu himself.
     *
     * @return array{product_ids: list<int>}|null
     */
    public function filterProducts(Decision $decision): ?array
    {
        return $decision->item === Decision::ITEM_ONE && $decision->productId !== null
            && $decision->isConfident($decision->itemConfidence)
            ? ['product_ids' => [$decision->productId]]
            : null;
    }

    /**
     * remove_from_order input for every basket line TypeSafe says is to be
     * taken out. Without a number, or with at least as many as the line has,
     * the whole line goes; otherwise "keep" is what is left.
     *
     * @return list<array{line_id: int, keep?: int}>
     */
    public function removeLines(Decision $decision): array
    {
        $inputs = [];
        foreach ($decision->removeLines as $line) {
            $input = ['line_id' => $line['line_id']];
            if ($line['remove'] !== null && $line['remove'] < $line['quantity']) {
                $input['keep'] = $line['quantity'] - $line['remove'];
            }
            $inputs[] = $input;
        }

        return $inputs;
    }

    /**
     * What TypeSafe read that the tool call does not carry, for Marvin's note:
     * ingredient changes, pickup/delivery and time, options for other items.
     */
    public function extras(Decision $decision, bool $logisticsHandled = false): string
    {
        $extras = [];
        if ($decision->wantsModification()) {
            $extras[] = 'They also asked to change ingredients of the item; the basket cannot store that, so tell them honestly what is possible.';
        }
        if (!$logisticsHandled && ($decision->logistics !== null || $decision->orderTime !== null)) {
            $extras[] = sprintf(
                'They also mentioned %s%s; handle that with change_logistics once you know both the type and the time.',
                $decision->logistics ?? 'a time',
                $decision->orderTime !== null && $decision->logistics !== null ? ', ' . str_replace('_', ' ', $decision->orderTime) : '',
            );
        }

        return implode(' ', $extras);
    }

    /**
     * Option labels TypeSafe chose, matched to this product's option ids.
     *
     * @return list<string>
     */
    private function optionIds(int $productId, Decision $decision): array
    {
        $ids = [];
        foreach ((array) ($this->menu->product($productId)['config_groups'] ?? []) as $group) {
            $chosen = $decision->option(trim((string) ($group['label'] ?? '')));
            if ($chosen === null) {
                continue;
            }
            foreach ((array) ($group['options'] ?? []) as $option) {
                if (trim((string) ($option['label'] ?? '')) === $chosen && isset($option['id'])) {
                    $ids[] = (string) $option['id'];
                    break;
                }
            }
        }

        return $ids;
    }
}
