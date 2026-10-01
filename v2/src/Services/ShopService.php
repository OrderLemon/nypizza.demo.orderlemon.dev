<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Helpers\JsonHelper;
use Pmsrapi\V2\Support\Logger;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Core\Config;

class ShopService
{
    /** calendar_{shop}.event_type values (1 is business hours, unused here). */
    public const int PICKUP_EVENT_TYPE = 2;
    public const int DELIVERY_EVENT_TYPE = 3;

    private const array LOGISTICS_EVENT_TYPES = [
        CartService::PICKUP_LOGISTIC_TYPE   => self::PICKUP_EVENT_TYPE,
        CartService::DELIVERY_LOGISTIC_TYPE => self::DELIVERY_EVENT_TYPE,
    ];

    /**
     * How far ahead to look for the next window a shopper can still order
     * for. Only a search bound: how many days ahead a pre-order may be placed
     * is enforced by the front end, not here.
     */
    private const int LOOKAHEAD_DAYS = 15;

    private const string MOMENT_FORMAT = 'l Y-m-d H:i';

    /** Cache for current(). `false` means "not fetched yet" — a real miss is `null`. */
    private array|null|false $shop = false;

    /** @var array<int, list<array<string, mixed>>> calendar rows by event type, loaded once per request */
    private array $calendar = [];

    function __construct(
        private readonly Repository $repo,
        protected Config $config,
    ){}

    public function getByPhone(string $phoneNumber) : ?array
    {
        $digits = preg_replace('/[^0-9]/', '', $phoneNumber) ?? '';

        if ($digits === '') {
            return null;
        }

        return $this->repo->selectRow("shops", ["phonenumber" => $digits, "enabled" => 1]);
    }

    public function find(int $shopId): ?array
    {
        return $this->repo->selectRow("shops", ["id" => $shopId, "enabled" => 1]);
    }

    public function getShopInfo(array $shop) : array
    {
        return [
            'id' => $shop['id'],
            'name' => $shop['name'],
            'company_id' => $shop['company_id'],
            'phonenumber' => $shop['phonenumber'],
            'enabled' => $shop['enabled'],
            "min_pickup_minutes" => 20,
            "min_delivery_minutes" => 45,
            "currency" => "EUR",
            "default_vat_rate" => 0.06,
            "delivery_fee" => 2.5,
            "free_delivery_threshold" => 25
        ];
    }

    /** The shop of the request's `shop_id`, fetched once and cached for the rest of it. */
    public function current(): ?array
    {
        if ($this->shop !== false) {
            return $this->shop;
        }

        return $this->shop = $this->find($this->currentShopId());
    }

    public function name(): string
    {
        return (string) ($this->current()["name"] ?? "");
    }

    public function address(): string
    {
        $shop = $this->current();

        if ($shop === null) {
            return "";
        }

        $parts = array_filter(
            [$shop["street"] ?? null, $shop["zip"] ?? null, $shop["city"] ?? null, $shop["country"] ?? null],
            static fn(?string $part): bool => $part !== null && trim($part) !== "",
        );

        return implode(", ", $parts);
    }
    
    public function getCalendarByEvent(int $eventType) : array
    {
        $table = $this->calendarTable();
        
        return $this->repo->selectRows($table, ["event_type" => $eventType]);
    }
    
    private function calendarTable() : string
    {
        return "calendar_" . $this->currentShopId();
    }
    
    public function shopBrief() : array
    {
        if( $this->current() === [] || $this->current() === null){
            return [];
        }
        return [
            "name" => $this->current()["name"],
            "country" => $this->current()["country"],
            "city" => $this->current()["city"],
            "zip" => $this->current()["zip"],
            "street" => $this->current()["street"],
        ];
    }
    

    public function getLogisticHours() : array
    {
        $weekly = fn(int $eventType): array => array_values(array_map(
            static fn(array $row): array => [
                "day_of_week" => (int) $row["day_of_week"],
                "start"       => $row["start_time"],
                "end"         => $row["end_time"],
                "last_order"  => $row["last_order_time"] ?? $row["end_time"],
            ],
            array_filter(
                $this->calendarRows($eventType),
                static fn(array $row): bool => $row["day_of_week"] !== null
                    && (int) ($row["enabled"] ?? 1) === 1,
            ),
        ));

        return [$weekly(self::PICKUP_EVENT_TYPE), $weekly(self::DELIVERY_EVENT_TYPE)];
    }

    /**
     * The last time an order can be placed on $day's date, per logistics type
     * ("H:i"), or null when that type is closed that day. With split shifts
     * it is the latest window's.
     *
     * @return array<string, ?string> keyed by CartService::LOGISTIC_LABELS
     */
    public function lastOrderTimes(\DateTimeImmutable $day) : array
    {
        $times = [];

        foreach (self::LOGISTICS_EVENT_TYPES as $logistics => $eventType) {
            $windows = $this->windowsOn($eventType, $day->setTime(0, 0));

            $times[CartService::LOGISTIC_LABELS[$logistics]] = $windows === []
                ? null
                : max(array_column($windows, 'cutoff'))->format('H:i');
        }

        return $times;
    }


    public function canOpenCart(?int $logistics, \DateTimeImmutable $now) : bool
    {
        $eventTypes = $logistics === null
            ? self::LOGISTICS_EVENT_TYPES
            : array_intersect_key(self::LOGISTICS_EVENT_TYPES, [$logistics => true]);

        foreach ($eventTypes as $eventType) {
            if ($this->nextOrderableWindow($eventType, $now) !== null) {
                return true;
            }
        }

        return false;
    }


    public function momentProblem(int $logistics, \DateTimeImmutable $moment, \DateTimeImmutable $now) : ?string
    {
        // Minute precision: "asap" is exactly $now, and a typed "HH:MM" has no seconds.
        if ($moment->format('Y-m-d H:i') < $now->format('Y-m-d H:i')) {
            return 'date_in_past';
        }

        $eventType = self::LOGISTICS_EVENT_TYPES[$logistics] ?? null;

        if ($eventType === null) {
            return 'invalid_logistics_type';
        }

        $day = $moment->setTime(0, 0);

        // The day before too, for a window that closes after midnight.
        $containing = array_filter(
            [...$this->windowsOn($eventType, $day->modify('-1 day')), ...$this->windowsOn($eventType, $day)],
            static fn(array $w): bool => $w['start'] <= $moment && $moment <= $w['end'],
        );

        if ($containing === []) {
            return 'outside_hours';
        }

        foreach ($containing as $window) {
            if ($now < $window['cutoff']) {
                return null;
            }
        }

        return 'cutoff_passed';
    }

    /**
     * Facts for Marvin and for a refused cart or moment: per logistics type, whether
     * today can still be ordered for, today's windows, and the first window a
     * shopper can still order for (today or a pre-order). Times are already
     * worked out so nothing downstream has to read calendar rows.
     *
     */
    public function availability(\DateTimeImmutable $now) : array
    {
        $today = $now->setTime(0, 0);

        $result = [
            'now'        => $now->format(self::MOMENT_FORMAT),
            'shop_phone' => $this->current()['phonenumber'] ?? null,
        ];

        foreach (self::LOGISTICS_EVENT_TYPES as $logistics => $eventType) {
            $windows = $this->windowsOn($eventType, $today);

            $result[CartService::LOGISTIC_LABELS[$logistics]] = [
                'orderable_today' => array_filter(
                    $windows,
                    static fn(array $w): bool => $w['end'] > $now && $w['cutoff'] > $now,
                ) !== [],
                'today' => array_map(static fn(array $w): array => [
                    'from'       => $w['start']->format('H:i'),
                    'until'      => $w['end']->format('H:i'),
                    'last_order' => $w['cutoff']->format('H:i'),
                ], $windows),
                'next' => $this->formatWindow($this->nextOrderableWindow($eventType, $now)),
            ];
        }

        return $result;
    }

    private function nextOrderableWindow(int $eventType, \DateTimeImmutable $now) : ?array
    {
        $today = $now->setTime(0, 0);

        // From yesterday, whose window may still be open past midnight.
        for ($offset = -1; $offset <= self::LOOKAHEAD_DAYS; $offset++) {
            foreach ($this->windowsOn($eventType, $today->modify(sprintf('%+d day', $offset))) as $window) {
                if ($window['end'] > $now && $window['cutoff'] > $now) {
                    return $window;
                }
            }
        }

        return null;
    }


    private function windowsOn(int $eventType, \DateTimeImmutable $day) : array
    {
        // day_of_week is 0 = Monday … 6 = Sunday; 'N' is 1 = Monday … 7 = Sunday.
        $weekday = (int) $day->format('N') - 1;

        $dayRows = array_filter(
            $this->calendarRows($eventType),
            static fn(array $row): bool => $row['day_of_week'] !== null && (int) $row['day_of_week'] === $weekday,
        );

        $windows = [];

        foreach ($dayRows as $row) {
            if ((int) ($row['enabled'] ?? 1) !== 1 || empty($row['start_time']) || empty($row['end_time'])) {
                continue;
            }

            $start = $this->atTime($day, $row['start_time']);

            // An end before the start closes after midnight.
            $end = $this->atTime($day, $row['end_time']);
            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }

            // No last_order_time means orders run until closing.
            $cutoff = empty($row['last_order_time']) ? $end : $this->atTime($day, $row['last_order_time']);
            if ($cutoff < $start) {
                $cutoff = $cutoff->modify('+1 day');
            }

            $windows[] = ['start' => $start, 'end' => $end, 'cutoff' => min($cutoff, $end)];
        }

        usort($windows, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        return $windows;
    }

    /** @param ?array{start: \DateTimeImmutable, end: \DateTimeImmutable, cutoff: \DateTimeImmutable} $window */
    private function formatWindow(?array $window) : ?array
    {
        if ($window === null) {
            return null;
        }

        return [
            'from'       => $window['start']->format(self::MOMENT_FORMAT),
            'until'      => $window['end']->format(self::MOMENT_FORMAT),
            'last_order' => $window['cutoff']->format(self::MOMENT_FORMAT),
        ];
    }

    private function atTime(\DateTimeImmutable $day, string $time) : \DateTimeImmutable
    {
        [$hour, $minute, $second] = array_map('intval', explode(':', $time) + [0, 0, 0]);

        return $day->setTime($hour, $minute, $second);
    }

    private function calendarRows(int $eventType) : array
    {
        return $this->calendar[$eventType] ??= $this->getCalendarByEvent($eventType);
    }

    private function currentShopId(): int
    {
        if (!defined("shop_id") || !is_numeric(shop_id)) {
            throw new ValidationException(["shop id" => "Shop Id must be a numeric value!"]);
        }

        return (int) shop_id;
    }
}

?>