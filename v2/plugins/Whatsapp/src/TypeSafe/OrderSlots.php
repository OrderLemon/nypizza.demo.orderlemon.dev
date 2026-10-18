<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\TypeSafe;

use Pmsrapi\V2\Services\CartService;

/**
 * Turns what TypeSafe read into tool input, for the cases code runs itself:
 *
 *   change_logistics  {logistics_type, date: "asap"}
 *
 * Items, quantities and exact times are read by Marvin instead, with only the
 * tools the branch allows.
 */
final class OrderSlots
{
    /**
     * change_logistics input when the message says pickup or delivery AND
     * "as soon as possible". Anything else (a clock time, another day) needs
     * the actual time, which Marvin reads.
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
}
