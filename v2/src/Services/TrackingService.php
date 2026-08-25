<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Orders\OrderStatus;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Services\OrderQueryService;

/**
 * Delivery tracking for the demo.
 *
 * Design: SEED ONCE, DERIVE ON READ.
 *
 *  - seed()    is called when an order is created. It appends an immutable plan
 *              (store, destination, polyline, courier, prep/travel durations,
 *              dispatch clock) to the `order_tracking` mockup.
 *  - resolve() is called on every "Where is my order?". It derives status, ETA
 *              and the courier's position from `now - placed_ts` by interpolating
 *              along the polyline. Nothing is written.
 *
 * Because position is derived rather than stored, the marker moves on its own as
 * the demo runs, no cron/worker is needed, and replaying an order reproduces the
 * same journey exactly.
 * 
 * The order's OWN status always outranks the clock: a cancelled order never
 * shows a moving courier, however much time has passed.
 */
final class TrackingService extends JsonService
{
    public const MOCKUP        = 'order_tracking';
    public const ORDERS_MOCKUP = 'orders';

    function __construct(
        protected Logger $logger,
        protected Config $config,
        private readonly OrderQueryService $orders,
    ) {
        parent::__construct($logger, $config);
    }

    public function enabled(): bool
    {
        // return (bool) ($this->config->secret('tracking.enabled') ?? true);
        return true;  // Always on for the demo.
    }

    // --------------------------------------------------------------- seeding

    /**
     * Create the plan for a freshly created order. Idempotent by default: an
     * existing plan is returned untouched.
     *
     * @param array $order needs `id`; uses `client_phone`, `ordered_time`, `items`
     */
    public function seed(array $order, bool $force = false): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $orderId = $order['id'] ?? null;
        if ($orderId === null) {
            $this->logger->warning('tracking.seed skipped: order has no id');

            return null;
        }

        if (!$force) {
            $existing = $this->findPlan((int) $orderId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $plan = $this->buildPlan($order);

        try {
            $this->addItems([$plan], self::MOCKUP);
        } catch (ApiException $e) {
            // Tracking must never break an order.
            $this->logger->error('tracking.seed failed: ' . $e->getMessage());

            return null;
        }

        $this->logger->info(sprintf(
            'tracking.seeded order=%s route=%s prep=%ds travel=%ds',
            $orderId,
            $plan['route_key'],
            $plan['timeline']['prep_seconds'],
            $plan['timeline']['travel_seconds']
        ));

        return $plan;
    }

    /**
     * Build the immutable plan. The ONLY randomised values in the whole system
     * are prep_seconds and travel_seconds; everything else is preset or derived.
     */
    private function buildPlan(array $order): array
    {
        $orderId = (int) $order['id'];
        $phone   = (string) ($order['client_phone'] ?? '');
        $seed    = $phone !== '' ? $phone : 'order:' . $orderId;

        $route   = RouteLibrary::pick($seed);
        $courier = RouteLibrary::pickCourier($seed);

        $placedAt = $this->parseTime($order['ordered_time'] ?? null) ?? time();

        $startedAt = time();   // the delivery clock starts when tracking starts

        return [
            'order_id'     => $orderId,
            'client_phone' => $phone,
            'route_key'    => $route['key'],
            'store'        => $route['store'],
            'destination'  => $route['destination'],
            'courier'      => $courier,
            'timeline' => [
                'ordered_at'     => date('Y-m-d H:i:s', $placedAt),
                'ordered_ts'     => $placedAt,
                'started_at'     => date('Y-m-d H:i:s', $startedAt),
                'started_ts'     => $startedAt,
                'prep_seconds'   => $this->randomPrepSeconds($this->itemCount($order)),
                'travel_seconds' => $this->jitter((int) $route['travel_seconds']),
            ],
            // Snapshot the scale so config changes never distort in-flight orders.
            'time_scale'   => $this->timeScale(),
            'route'        => $route['route'],
            'distance_m'   => (int) round(RouteLibrary::length($route['route'])),
            'seeded_at'    => date('Y-m-d H:i:s'),
        ];
    }

    // --------------------------------------------------------------- reading

    /**
     * Derive the live view of an order's delivery.
     *
     * Lazily seeds when the order has no plan yet, so "Where is my order?" works
     * for the mockup orders that predate the tracking file, and for any order
     * created through a path that bypasses the seeding hook.
     */
    public function resolve(int $orderId): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $order  = $this->findOrder($orderId);
        $stored = OrderStatus::fromMixed($order['status'] ?? null);

        $plan = $this->findPlan($orderId);

        if ($plan === null) {
            if ($order === null) {
                return null;
            }
            $plan = $this->seed($order);
        }

        return $plan === null ? null : $this->project($plan, $stored);
    }

    /**
     * Restart the clock so the same order can be demoed again. Appends a new
     * plan carrying the original route, courier and durations.
     */
    public function reset(int $orderId): ?array
    {
        $plan = $this->findPlan($orderId);
        if ($plan === null) {
            return null;
        }

        $now = time();

        $plan['timeline']['ordered_at'] = date('Y-m-d H:i:s', $now);
        $plan['timeline']['ordered_ts'] = $now;
        $plan['timeline']['started_at'] = date('Y-m-d H:i:s', $now);
        $plan['timeline']['started_ts'] = $now;
        $plan['time_scale']            = $this->timeScale();
        $plan['seeded_at']             = date('Y-m-d H:i:s', $now);
        $plan['reset_of']              = $plan['id'] ?? null;  // id assigned by addItems()
        unset($plan['id']);

        $this->addItems([$plan], self::MOCKUP);

        $order = $this->findOrder($orderId);

        return $this->project($plan, OrderStatus::fromMixed($order['status'] ?? null));
    }

    /** Latest plan for an order, or null. Scans from the end so reset() wins. */
    public function findPlan(int $orderId): ?array
    {
        return $this->findBy(self::MOCKUP, 'order_id', $orderId, reverse: true);
    }

    /** The order itself, for status checks and lazy seeding. */
    public function findOrder(int $orderId): ?array
    {
        return $this->orders->getById($orderId);
    }

    private function findBy(string $mockup, string $key, int $value, bool $reverse = false): ?array
    {
        try {
            $records = $this->load($mockup);
        } catch (ApiException $e) {
            $this->logger->error("tracking.load {$mockup} failed: " . $e->getMessage());

            return null;
        }

        foreach ($reverse ? array_reverse($records) : $records as $record) {
            if (is_array($record) && (int) ($record[$key] ?? 0) === $value) {
                return $record;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ derivation

    /**
     * The whole derivation. Everything the client sees comes out of one clock —
     * unless the order's stored status overrides it.
     */
    private function project(array $plan, OrderStatus $stored): array
    {
        $scale  = max(0.01, (float) ($plan['time_scale'] ?? 1.0));
        $prep   = (int) $plan['timeline']['prep_seconds'];
        $travel = max(1, (int) $plan['timeline']['travel_seconds']);
        $total  = $prep + $travel;

        // Elapsed measured in *delivery-time* seconds: real seconds since the
        // order was placed, multiplied by the demo scale.
        $startedTs = (int) (
            $plan['timeline']['started_ts']
            ?? $plan['timeline']['placed_ts']
            ?? time()
        );

        $elapsed = max(0.0, (time() - $startedTs) * $scale);

        if (!$stored->isTrackable()) {
            // Cancelled: no courier, no ETA, no movement. The clock is irrelevant.
            $status    = $stored;
            $progress  = 0.0;
            $remaining = 0;
        } elseif ($stored->isTerminal()) {
            // Already marked delivered upstream — honour it regardless of the clock.
            $status    = $stored;
            $progress  = 1.0;
            $remaining = 0;
        } elseif ($elapsed < $prep) {
            $status    = OrderStatus::Preparing;
            $progress  = 0.0;
            $remaining = (int) round($total - $elapsed);
        } elseif ($elapsed < $total) {
            $status    = OrderStatus::OutForDelivery;
            $progress  = ($elapsed - $prep) / $travel;
            $remaining = (int) round($total - $elapsed);
        } else {
            $status    = OrderStatus::Delivered;
            $progress  = 1.0;
            $remaining = 0;
        }

        $remaining = max(0, $remaining);
        $current   = $this->pointAt($plan['route'], $progress);
        $courier   = $plan['courier'];

        // eta_clock is real wall-clock arrival, so it agrees with the moving
        // marker even when time_scale compresses the demo.
        $etaAt = time() + (int) round($remaining / $scale);

        $this->updateOrderStatus((int) $plan['order_id'], $status);

        $view = [
            'order_id'     => (int) $plan['order_id'],
            'status'       => $status->value,
            'status_label' => $status->label($courier['name'] ?? null),
            'terminal'     => $status->isTerminal(),
            'trackable'    => $status->isTrackable(),
            'en_route'     => $status->isEnRoute(),
            'progress'     => round($progress, 4),
            'eta_minutes'  => $remaining > 0 ? max(1, (int) ceil($remaining / 60)) : 0,
            'eta_seconds'  => $remaining,
            'eta_clock'    => $remaining > 0 ? date('H:i', $etaAt) : null,
            'courier'      => $status->isTrackable() ? $courier : null,
            'store'        => $plan['store'],
            'destination'  => $plan['destination'],
            'current'      => ['lat' => $current[0], 'lng' => $current[1]],
            'route'        => array_map(
                static fn(array $p): array => ['lat' => (float) $p[0], 'lng' => (float) $p[1]],
                $plan['route']
            ),
            'distance_m'   => $plan['distance_m'] ?? null,
            'remaining_m'  => (int) round((int) ($plan['distance_m'] ?? 0) * (1 - $progress)),
            'ordered_at' => $plan['timeline']['ordered_at'] ?? $plan['timeline']['ordered_at'] ?? null,
            'started_at' => $plan['timeline']['started_at'] ?? $plan['timeline']['started_at'] ?? null,
            'time_scale'   => $scale,
        ];

        // One ready-to-send WhatsApp line, so the demo reads identically whether
        // the AI forwards it verbatim or paraphrases it.
        $view['message'] = $this->message($status, $view);

        // $this->logger->error(sprintf(
        //     'tracking.project order=%s status=%s progress=%.2f eta=%dmin/%ds coordinates=(%.6f,%.6f)',
        //     $plan['order_id'],
        //     $status->value,
        //     $progress,
        //     $view['eta_minutes'],
        //     $view['eta_seconds'],
        //     $view['current']['lat'],
        //     $view['current']['lng']
        // ));
        return $view;
    }

    public function updateOrderStatus(int $id, OrderStatus $status): void
    {
        $orders = $this->load("orders");

        foreach ($orders as &$order) {
            if ($order["id"] === $id) {
                $order["status"] = $status->value;
            }
        }

        $encoded = json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if(!$encoded){
            throw new ApiException("Error encoding data for insertion! Mockup: orders");
        }

        
        if(!file_put_contents($this->jsonPath("orders"), $encoded)){
            throw new ApiException("Error inserting data into json! Mockup: orders");
        }
    }

    /**
     * Walk the polyline by cumulative segment length and linearly interpolate
     * inside the segment the courier is currently on.
     *
     * @param list<array{0:float,1:float}> $route
     * @return array{0:float,1:float}
     */
    private function pointAt(array $route, float $progress): array
    {
        $n = count($route);
        if ($n === 0) {
            return [0.0, 0.0];
        }
        if ($n === 1 || $progress <= 0.0) {
            return [(float) $route[0][0], (float) $route[0][1]];
        }
        if ($progress >= 1.0) {
            return [(float) $route[$n - 1][0], (float) $route[$n - 1][1]];
        }

        $lengths = [];
        $total   = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $d         = RouteLibrary::distance(
                (float) $route[$i][0],
                (float) $route[$i][1],
                (float) $route[$i + 1][0],
                (float) $route[$i + 1][1]
            );
            $lengths[] = $d;
            $total    += $d;
        }

        if ($total <= 0.0) {
            return [(float) $route[0][0], (float) $route[0][1]];
        }

        $target = $total * $progress;
        $walked = 0.0;

        foreach ($lengths as $i => $len) {
            if ($walked + $len >= $target) {
                $t = $len > 0.0 ? ($target - $walked) / $len : 0.0;

                return [
                    round((float) $route[$i][0] + ((float) $route[$i + 1][0] - (float) $route[$i][0]) * $t, 6),
                    round((float) $route[$i][1] + ((float) $route[$i + 1][1] - (float) $route[$i][1]) * $t, 6),
                ];
            }
            $walked += $len;
        }

        return [(float) $route[$n - 1][0], (float) $route[$n - 1][1]];
    }

    private function message(OrderStatus $status, array $view): string
    {
        return match ($status) {
            OrderStatus::Cancelled => 'This order was cancelled. Let me know if you would like to order again.',
            OrderStatus::Delivered => 'Your order has been delivered — enjoy your pizza! 🍕',
            OrderStatus::OutForDelivery => sprintf(
                '%s is %d minutes away from your location — arriving around %s by %s.',
                $view['courier']['name'],
                $view['eta_minutes'],
                $view['eta_clock'],
                $view['courier']['vehicle']
            ),
            default => sprintf(
                'Your order is being prepared at %s. Estimated delivery in about %d minutes (around %s).',
                $view['store']['name'],
                $view['eta_minutes'],
                $view['eta_clock']
            ),
        };
    }

    // ---------------------------------------------------------------- helpers

    private function timeScale(): float
    {
        return max(0.01, (float) ($this->config->secret('tracking.time_scale') ?? 1.0));
    }

    private function itemCount(array $order): int
    {
        $items = $order['items'] ?? [];
        if (!is_array($items)) {
            return 1;
        }

        $count = 0;
        foreach ($items as $item) {
            // Skip discount / adjustment lines — they have no real product.
            if (!is_array($item) || (int) ($item['product_id'] ?? 0) === 0) {
                continue;
            }
            $count += max(1, (int) ($item['quantity'] ?? 1));
        }

        return max(1, $count);
    }

    /** Prep time grows a little with basket size, then jitters. */
    private function randomPrepSeconds(int $items): int
    {
        $min = (int) ($this->config->secret('tracking.prep_seconds.min') ?? 360);
        $max = (int) ($this->config->secret('tracking.prep_seconds.max') ?? 720);
        $max = max($min, $max);

        return random_int($min, $max) + min(300, ($items - 1) * 45);
    }

    /** ±jitter% around the route's nominal travel time. */
    private function jitter(int $seconds): int
    {
        $pct = (float) ($this->config->secret('tracking.travel_jitter') ?? 0.25);
        if ($pct <= 0.0) {
            return $seconds;
        }

        $delta = (int) round($seconds * $pct);

        return max(60, $seconds + random_int(-$delta, $delta));
    }

    private function parseTime(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : $ts;
    }
}