<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

/**
 * Static catalogue of demo delivery routes (Netherlands + Belgium).
 *
 * Each entry is a store, a destination and a hand-traced polyline between them
 * that roughly follows real streets — a straight line across the Amsterdam canal
 * ring reads as fake immediately in a demo.
 *
 * Selection is deterministic on the client phone number, so the same shopper
 * always "lives" at the same address across re-runs of the demo.
 */
final class RouteLibrary
{
    /**
     * @return list<array{
     *   key:string,
     *   store:array{name:string,lat:float,lng:float},
     *   destination:array{label:string,lat:float,lng:float},
     *   route:list<array{0:float,1:float}>,
     *   travel_seconds:int
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'key'   => 'ams-vondelpark',
                'store' => ['name' => "Domino's Amsterdam Centrum", 'lat' => 52.3702, 'lng' => 4.8952],
                'destination' => ['label' => 'Vondelstraat 12, Amsterdam', 'lat' => 52.3609, 'lng' => 4.8721],
                'route' => [
                    [52.3702, 4.8952], [52.3684, 4.8908], [52.3665, 4.8861],
                    [52.3646, 4.8818], [52.3628, 4.8776], [52.3616, 4.8742],
                    [52.3609, 4.8721],
                ],
                'travel_seconds' => 720,
            ],
            [
                'key'   => 'ams-de-pijp',
                'store' => ['name' => "Domino's Amsterdam Zuid", 'lat' => 52.3468, 'lng' => 4.8836],
                'destination' => ['label' => 'Gerard Doustraat 45, Amsterdam', 'lat' => 52.3563, 'lng' => 4.8912],
                'route' => [
                    [52.3468, 4.8836], [52.3491, 4.8852], [52.3514, 4.8869],
                    [52.3536, 4.8888], [52.3552, 4.8903], [52.3563, 4.8912],
                ],
                'travel_seconds' => 540,
            ],
            [
                'key'   => 'rotterdam-kralingen',
                'store' => ['name' => "Domino's Rotterdam Blaak", 'lat' => 51.9200, 'lng' => 4.4870],
                'destination' => ['label' => 'Oudedijk 88, Rotterdam', 'lat' => 51.9280, 'lng' => 4.5220],
                'route' => [
                    [51.9200, 4.4870], [51.9218, 4.4938], [51.9236, 4.5006],
                    [51.9251, 4.5075], [51.9264, 4.5143], [51.9274, 4.5190],
                    [51.9280, 4.5220],
                ],
                'travel_seconds' => 840,
            ],
            [
                'key'   => 'utrecht-lombok',
                'store' => ['name' => "Domino's Utrecht Centrum", 'lat' => 52.0907, 'lng' => 5.1214],
                'destination' => ['label' => 'Kanaalstraat 137, Utrecht', 'lat' => 52.0898, 'lng' => 5.0975],
                'route' => [
                    [52.0907, 5.1214], [52.0912, 5.1160], [52.0915, 5.1103],
                    [52.0910, 5.1048], [52.0903, 5.1005], [52.0898, 5.0975],
                ],
                'travel_seconds' => 600,
            ],
            [
                'key'   => 'antwerpen-zurenborg',
                'store' => ['name' => "Domino's Antwerpen Centraal", 'lat' => 51.2172, 'lng' => 4.4210],
                'destination' => ['label' => 'Dageraadplaats 7, Antwerpen', 'lat' => 51.2058, 'lng' => 4.4342],
                'route' => [
                    [51.2172, 4.4210], [51.2148, 4.4243], [51.2124, 4.4272],
                    [51.2100, 4.4299], [51.2078, 4.4322], [51.2058, 4.4342],
                ],
                'travel_seconds' => 660,
            ],
            [
                'key'   => 'brussel-ixelles',
                'store' => ['name' => "Domino's Bruxelles Sainte-Catherine", 'lat' => 50.8510, 'lng' => 4.3470],
                'destination' => ['label' => 'Rue du Bailli 22, Ixelles', 'lat' => 50.8290, 'lng' => 4.3690],
                'route' => [
                    [50.8510, 4.3470], [50.8474, 4.3512], [50.8438, 4.3556],
                    [50.8399, 4.3598], [50.8358, 4.3634], [50.8322, 4.3665],
                    [50.8290, 4.3690],
                ],
                'travel_seconds' => 1080,
            ],
            [
                'key'   => 'gent-sint-pieters',
                'store' => ['name' => "Domino's Gent Korenmarkt", 'lat' => 51.0543, 'lng' => 3.7212],
                'destination' => ['label' => 'Kortrijksesteenweg 210, Gent', 'lat' => 51.0378, 'lng' => 3.7108],
                'route' => [
                    [51.0543, 3.7212], [51.0511, 3.7196], [51.0478, 3.7178],
                    [51.0444, 3.7156], [51.0410, 3.7132], [51.0378, 3.7108],
                ],
                'travel_seconds' => 780,
            ],
        ];
    }

    /** Couriers paired with a vehicle, picked deterministically alongside the route. */
    public static function couriers(): array
    {
        return [
            ['name' => 'Sven',   'vehicle' => 'scooter', 'phone' => '+31 6 1234 5678'],
            ['name' => 'Fatima', 'vehicle' => 'e-bike',  'phone' => '+31 6 2345 6789'],
            ['name' => 'Joris',  'vehicle' => 'scooter', 'phone' => '+31 6 3456 7890'],
            ['name' => 'Lotte',  'vehicle' => 'e-bike',  'phone' => '+32 47 123 45 67'],
            ['name' => 'Mehdi',  'vehicle' => 'car',     'phone' => '+32 47 234 56 78'],
        ];
    }

    /**
     * Deterministic pick: the same phone number always maps to the same route.
     * Falls back to the order id when no phone is present.
     */
    public static function pick(string $seed): array
    {
        $routes = self::all();
        $n      = (int) hexdec(substr(md5($seed), 0, 8));

        return $routes[$n % count($routes)];
    }

    public static function pickCourier(string $seed): array
    {
        $couriers = self::couriers();
        $n        = (int) hexdec(substr(md5('courier:' . $seed), 0, 8));

        return $couriers[$n % count($couriers)];
    }

    /** Great-circle distance in metres. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r  = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);

        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Cumulative length of a polyline, in metres. */
    public static function length(array $route): float
    {
        $total = 0.0;
        $n     = count($route);

        for ($i = 0; $i < $n - 1; $i++) {
            $total += self::distance(
                (float) $route[$i][0],
                (float) $route[$i][1],
                (float) $route[$i + 1][0],
                (float) $route[$i + 1][1]
            );
        }

        return $total;
    }
}