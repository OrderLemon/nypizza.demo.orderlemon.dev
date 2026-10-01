<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

/**
 * The shop won't take this order at this time: no window is left to open a
 * cart for, or the chosen pickup/delivery moment is outside the shop's hours,
 * past its last order time, or already past. Carries the shop's availability so the caller can tell the
 * shopper when they CAN order instead of just "no".
 */
final class OrderingUnavailableException extends ApiException
{
    /**
     * @param string $reason no_orderable_slot | moment_required | invalid_moment |
     *        date_in_past | invalid_logistics_type | outside_hours | cutoff_passed
     * @param array<string, mixed> $availability see ShopService::availability()
     */
    public function __construct(
        private readonly string $reason,
        private readonly array $availability,
    ) {
        parent::__construct(
            'The shop is not taking this order right now',
            409,
            'ordering_unavailable',
            ['reason' => $reason, 'availability' => $availability],
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function availability(): array
    {
        return $this->availability;
    }
}
