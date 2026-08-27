<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Orders;

/**
 * The order statuses.
 *
 * Covers the full lifecycle from placement to doorstep. `Preparing` and
 * `OutForDelivery` are derived by TrackingService from the delivery clock rather
 * than stored on the order — see Services\TrackingService.
 */
enum OrderStatus: string
{
    case Ordered        = 'ordered';
    case Done        = 'done';
    case Shipped = 'shipped';
    case Delivered      = 'delivered';
    case Cancelled      = 'cancelled';

    /**
     * Customer-facing copy, in one place. WhatsApp is the only consumer today,
     * but keeping it on the enum means the web front end can't drift from it.
     */
    public function label(?string $courier = null): string
    {
        return match ($this) {
            self::Ordered        => 'We have your order',
            self::Shipped => $courier !== null
                ? "{$courier} is on the way with your order"
                : 'Your order is on the way',
            self::Delivered      => 'Delivered — enjoy!',
            self::Cancelled      => 'This order was cancelled',
        };
    }

    /** Nothing will change from here on: stop polling, stop animating. */
    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled || $this == self::Done;
    }

    /** Whether a courier position/ETA is meaningful for this status. */
    public function isTrackable(): bool
    {
        return $this !== self::Cancelled && $this !== self::Delivered && $this !== self::Done;
    }

    /** Whether the courier has left the store. */
    public function isEnRoute(): bool
    {
        return $this === self::Shipped;
    }

    /** Tolerant parse for mockup data: unknown/absent values fall back to Ordered. */
    public static function fromMixed(mixed $value, self $default = self::Ordered): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value)
            ? (self::tryFrom($value) ?? $default)
            : $default;
    }
}