<?php
declare(strict_types=1);

namespace App\Services;

final class GeoapifyPlaces
{
    public const RADIUS = 1000;
    public const CATEGORIES = [
        'all' => 'catering.restaurant,catering.cafe,commercial,catering.bar,catering.pub,catering.biergarten,entertainment,tourism.sights,leisure.park,healthcare.pharmacy',
        'food' => 'catering.restaurant',
        'cafe' => 'catering.cafe',
        'shop' => 'commercial',
        'bar' => 'catering.bar,catering.pub,catering.biergarten',
        'entertainment' => 'entertainment',
        'sights' => 'tourism.sights',
        'park' => 'leisure.park',
        'pharmacy' => 'healthcare.pharmacy',
        'supermarket' => 'commercial.supermarket',
    ];
    private const CATEGORY_LABELS = [
        'catering.cafe' => 'Quán nước', 'catering.restaurant' => 'Quán ăn',
        'catering.bar' => 'Bar & pub', 'catering.pub' => 'Bar & pub', 'catering.biergarten' => 'Bar & pub',
        'commercial.supermarket' => 'Siêu thị', 'healthcare.pharmacy' => 'Nhà thuốc',
        'leisure.park' => 'Công viên', 'tourism.sights' => 'Tham quan',
        'entertainment' => 'Giải trí', 'commercial' => 'Cửa hàng',
    ];

    public function nearby(float $latitude, float $longitude, string $category): array
    {
        if (!isset(self::CATEGORIES[$category]) || !is_finite($latitude) || !is_finite($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180) {
            throw new \InvalidArgumentException('Invalid nearby place search.');
        }
        $config = require dirname(__DIR__, 2).'/config/places.php';
        if ($config['api_key'] === '') {
            throw new \RuntimeException('Geoapify is not configured.');
        }
        $point = $longitude.','.$latitude;
        $params = [
            'categories' => self::CATEGORIES[$category], 'filter' => 'circle:'.$point.','.self::RADIUS,
            'bias' => 'proximity:'.$point, 'limit' => 40, 'lang' => 'vi', 'apiKey' => $config['api_key'],
        ];
        // Short cache shared by visitors: repeated views of a listing do not spend API credits.
        $cache = sys_get_temp_dir().'/home2home-places-v2-'.hash('sha256', json_encode($params)).'.json';
        if (is_file($cache) && filemtime($cache) > time() - 300) {
            $items = json_decode((string) file_get_contents($cache), true);
            if (is_array($items)) { return $items; }
        }
        $request = curl_init('https://api.geoapify.com/v2/places?'.http_build_query($params));
        if ($request === false) { throw new \RuntimeException('Places request could not start.'); }
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'Home2Home/1.0',
        ]);
        // Windows PHP builds using OpenSSL may have no bundled CA file. Keep TLS
        // verification enabled and use the operating system's trusted certificates.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt_array($request, [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]);
        }
        $response = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        if (!is_string($response) || $status !== 200) { throw new \RuntimeException('Places provider unavailable.'); }
        $body = json_decode($response, true);
        if (!is_array($body) || !isset($body['features']) || !is_array($body['features'])) {
            throw new \RuntimeException('Invalid places response.');
        }
        $items = $this->items($body['features'], $latitude, $longitude);
        // A cache write failure must not turn a successful search into an error.
        @file_put_contents($cache, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        return $items;
    }

    private function items(array $features, float $latitude, float $longitude): array
    {
        $items = [];
        foreach (array_slice($features, 0, 40) as $feature) {
            $properties = $feature['properties'] ?? [];
            $coordinates = $feature['geometry']['coordinates'] ?? [];
            $lat = $properties['lat'] ?? $coordinates[1] ?? null;
            $lng = $properties['lon'] ?? $coordinates[0] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) { continue; }
            $lat = (float) $lat; $lng = (float) $lng;
            $a = sin(deg2rad($lat - $latitude) / 2) ** 2
                + cos(deg2rad($latitude)) * cos(deg2rad($lat)) * sin(deg2rad($lng - $longitude) / 2) ** 2;
            $distance = (int) round(6371000 * 2 * asin(sqrt(min(1, max(0, $a)))));
            if ($distance > self::RADIUS) { continue; }
            $categories = is_array($properties['categories'] ?? null) ? $properties['categories'] : [];
            $label = 'Địa điểm';
            foreach (self::CATEGORY_LABELS as $prefix => $categoryLabel) {
                foreach ($categories as $name) {
                    if (is_string($name) && ($name === $prefix || str_starts_with($name, $prefix.'.'))) {
                        $label = $categoryLabel;
                        break 2;
                    }
                }
            }
            $id = is_string($properties['place_id'] ?? null) ? $properties['place_id'] : $lat.','.$lng;
            $title = is_string($properties['name'] ?? null) && $properties['name'] !== '' ? $properties['name'] : $label;
            $items[$id] = [
                'id' => $id, 'service' => true, 'title' => $title, 'category_label' => $label,
                'latitude' => $lat, 'longitude' => $lng, 'distance' => $distance,
                'address' => is_string($properties['address_line2'] ?? null) ? $properties['address_line2'] : '',
                'opening_hours' => is_string($properties['datasource']['raw']['opening_hours'] ?? null) ? $properties['datasource']['raw']['opening_hours'] : '',
                'url' => 'https://www.google.com/maps/search/?api=1&query='.$lat.','.$lng,
            ];
        }
        $items = array_values($items);
        usort($items, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
        return $items;
    }
}
