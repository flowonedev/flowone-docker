<?php

namespace Webmail\Services;

use PDO;
use Webmail\Core\Database;

/**
 * WeatherService
 *
 * Single source of truth for the header weather chip:
 *   1. resolve the user's lat/lon from their IP (30-day per-user cache)
 *   2. round to a 0.1 deg bucket so users in the same city share one cache row
 *   3. fetch Open-Meteo at most once every 15 minutes per bucket
 *
 * External APIs (all free, no key):
 *   - ipapi.co        - IP geolocation
 *   - api.open-meteo.com - current weather, WMO weather code
 *
 * The thundering-herd guard uses an optimistic UPDATE so that when many
 * users hit a stale bucket simultaneously, only the first request triggers
 * an external call; the rest reuse the freshly-updated row.
 */
class WeatherService
{
    private const WEATHER_TTL_SECONDS = 900;    // 15 minutes
    private const GEO_TTL_SECONDS     = 2592000; // 30 days
    private const HTTP_TIMEOUT        = 4;       // seconds, applied to every external call
    private const BUCKET_PRECISION    = 0.1;     // ~11 km square

    // Dev/private-IP fallback: project is HU-targeted
    private const FALLBACK_CITY         = 'Budapest';
    private const FALLBACK_COUNTRY      = 'HU';
    private const FALLBACK_LATITUDE     = 47.4979;
    private const FALLBACK_LONGITUDE    = 19.0402;

    private PDO $db;

    public function __construct(array $config)
    {
        $this->db = Database::getConnection($config);
    }

    /**
     * Resolve weather for a logged-in user.
     * Always returns an array shaped like:
     *   {
     *     city, country_code, latitude, longitude,
     *     weather_code, temperature_c, is_day,
     *     fetched_at, stale: bool, available: bool
     *   }
     */
    public function getForUser(string $userEmail, string $clientIp): array
    {
        $location = $this->resolveUserLocation($userEmail, $clientIp);

        if (!$location || $location['lat_bucket'] === null) {
            return $this->unavailable();
        }

        $weather = $this->getWeatherForBucket(
            (float)$location['lat_bucket'],
            (float)$location['lon_bucket'],
            (float)$location['latitude'],
            (float)$location['longitude']
        );

        return [
            'city'          => $location['city'],
            'country_code'  => $location['country_code'],
            'latitude'      => (float)$location['latitude'],
            'longitude'     => (float)$location['longitude'],
            'weather_code'  => $weather['weather_code'],
            'temperature_c' => $weather['temperature_c'],
            'is_day'        => $weather['is_day'],
            'forecast'      => $this->extractForecast($weather['payload_json'] ?? null),
            'fetched_at'    => $weather['fetched_at'],
            'stale'         => $weather['stale'],
            'available'     => $weather['weather_code'] !== null,
        ];
    }

    /**
     * Pull a compact daily forecast out of the cached Open-Meteo payload.
     * Returns an array of up to 7 days, each: { date, weather_code, max, min }.
     * Empty array if payload is missing or malformed (older cached rows).
     */
    private function extractForecast($payloadJson): array
    {
        if (!$payloadJson) {
            return [];
        }
        $data = is_array($payloadJson) ? $payloadJson : json_decode((string)$payloadJson, true);
        if (!is_array($data) || empty($data['daily'])) {
            return [];
        }

        $daily = $data['daily'];
        $dates = $daily['time']                ?? [];
        $codes = $daily['weather_code']        ?? [];
        $maxes = $daily['temperature_2m_max']  ?? [];
        $mins  = $daily['temperature_2m_min']  ?? [];

        $out = [];
        $n = min(count($dates), count($codes), count($maxes), count($mins), 7);
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'date'         => (string)$dates[$i],
                'weather_code' => is_numeric($codes[$i]) ? (int)$codes[$i] : null,
                'max'          => is_numeric($maxes[$i]) ? round((float)$maxes[$i], 1) : null,
                'min'          => is_numeric($mins[$i])  ? round((float)$mins[$i],  1) : null,
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Location resolution (per user, 30-day TTL)
    // -------------------------------------------------------------------------

    private function resolveUserLocation(string $userEmail, string $clientIp): ?array
    {
        $existing = $this->loadUserLocation($userEmail);

        if ($existing && !$this->isGeoStale($existing['geo_fetched_at'])) {
            return $existing;
        }

        $geo = $this->geocodeIp($clientIp);
        if (!$geo) {
            if ($existing) {
                // External geocode failed; keep using the cached row even if old.
                return $existing;
            }
            $geo = $this->fallbackGeo($clientIp);
        }

        return $this->saveUserLocation($userEmail, $clientIp, $geo);
    }

    private function loadUserLocation(string $userEmail): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT user_email, lat_bucket, lon_bucket, latitude, longitude,
                    city, country_code, resolved_from_ip, geo_fetched_at
             FROM user_locations WHERE user_email = ? LIMIT 1'
        );
        $stmt->execute([strtolower($userEmail)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isGeoStale(?string $fetchedAt): bool
    {
        if (!$fetchedAt) {
            return true;
        }
        $ts = strtotime($fetchedAt);
        return $ts === false || (time() - $ts) > self::GEO_TTL_SECONDS;
    }

    private function saveUserLocation(string $userEmail, string $clientIp, array $geo): array
    {
        $lat = (float)$geo['latitude'];
        $lon = (float)$geo['longitude'];
        $latBucket = $this->bucket($lat);
        $lonBucket = $this->bucket($lon);

        $stmt = $this->db->prepare(
            'INSERT INTO user_locations
                (user_email, lat_bucket, lon_bucket, latitude, longitude,
                 city, country_code, resolved_from_ip, geo_fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                lat_bucket = VALUES(lat_bucket),
                lon_bucket = VALUES(lon_bucket),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                city = VALUES(city),
                country_code = VALUES(country_code),
                resolved_from_ip = VALUES(resolved_from_ip),
                geo_fetched_at = NOW()'
        );
        $stmt->execute([
            strtolower($userEmail),
            $latBucket,
            $lonBucket,
            $lat,
            $lon,
            $geo['city'] ?? null,
            $geo['country_code'] ?? null,
            $clientIp,
        ]);

        return [
            'user_email'       => strtolower($userEmail),
            'lat_bucket'       => $latBucket,
            'lon_bucket'       => $lonBucket,
            'latitude'         => $lat,
            'longitude'        => $lon,
            'city'             => $geo['city'] ?? null,
            'country_code'     => $geo['country_code'] ?? null,
            'resolved_from_ip' => $clientIp,
            'geo_fetched_at'   => date('Y-m-d H:i:s'),
        ];
    }

    // -------------------------------------------------------------------------
    // Weather lookup (shared cache, 15-min TTL, thundering-herd guard)
    // -------------------------------------------------------------------------

    private function getWeatherForBucket(float $latBucket, float $lonBucket, float $lat, float $lon): array
    {
        $cached = $this->loadCacheRow($latBucket, $lonBucket);

        if ($cached && !$this->isWeatherStale($cached['fetched_at'])) {
            return $this->shapeWeather($cached, false);
        }

        // Thundering-herd guard: only the first caller through a stale row gets
        // to "claim" it and call Open-Meteo. Others fall through and reuse the
        // still-warm cached values (returned with stale=true if needed).
        if ($cached && !$this->claimRefresh($latBucket, $lonBucket, $cached['fetched_at'])) {
            return $this->shapeWeather($cached, true);
        }

        $fresh = $this->fetchOpenMeteo($lat, $lon);
        if (!$fresh) {
            if ($cached) {
                return $this->shapeWeather($cached, true);
            }
            return $this->shapeWeather(null, true);
        }

        $this->upsertCacheRow($latBucket, $lonBucket, $fresh);

        return $this->shapeWeather([
            'weather_code'  => $fresh['weather_code'],
            'temperature_c' => $fresh['temperature_c'],
            'is_day'        => $fresh['is_day'],
            'payload_json'  => json_encode($fresh['raw']),
            'fetched_at'    => date('Y-m-d H:i:s'),
        ], false);
    }

    private function loadCacheRow(float $latBucket, float $lonBucket): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT weather_code, temperature_c, is_day, payload_json, fetched_at
             FROM weather_cache
             WHERE lat_bucket = ? AND lon_bucket = ? LIMIT 1'
        );
        $stmt->execute([$latBucket, $lonBucket]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isWeatherStale(?string $fetchedAt): bool
    {
        if (!$fetchedAt) {
            return true;
        }
        $ts = strtotime($fetchedAt);
        return $ts === false || (time() - $ts) > self::WEATHER_TTL_SECONDS;
    }

    /**
     * Try to claim the right to refresh this bucket.
     * Returns true if THIS request should call Open-Meteo, false otherwise.
     *
     * We bump fetched_at by 60 seconds in the future as a soft lock: any
     * concurrent caller landing in the WHERE window (older than 14 min) will
     * see the row appear "fresh enough" briefly and skip the external call.
     * The bump is harmless if our own external call also succeeds because we
     * overwrite fetched_at to NOW() then.
     */
    private function claimRefresh(float $latBucket, float $lonBucket, string $fetchedAt): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE weather_cache
             SET fetched_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
             WHERE lat_bucket = ? AND lon_bucket = ? AND fetched_at = ?'
        );
        $stmt->execute([$latBucket, $lonBucket, $fetchedAt]);
        return $stmt->rowCount() === 1;
    }

    private function upsertCacheRow(float $latBucket, float $lonBucket, array $fresh): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO weather_cache
                (lat_bucket, lon_bucket, weather_code, temperature_c, is_day, payload_json, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                weather_code = VALUES(weather_code),
                temperature_c = VALUES(temperature_c),
                is_day = VALUES(is_day),
                payload_json = VALUES(payload_json),
                fetched_at = NOW()'
        );
        $stmt->execute([
            $latBucket,
            $lonBucket,
            $fresh['weather_code'],
            $fresh['temperature_c'],
            $fresh['is_day'],
            json_encode($fresh['raw']),
        ]);
    }

    private function shapeWeather(?array $row, bool $stale): array
    {
        return [
            'weather_code'  => $row['weather_code'] ?? null,
            'temperature_c' => isset($row['temperature_c']) ? (float)$row['temperature_c'] : null,
            'is_day'        => isset($row['is_day']) ? (int)$row['is_day'] : null,
            'payload_json'  => $row['payload_json'] ?? null,
            'fetched_at'    => $row['fetched_at'] ?? null,
            'stale'         => $stale,
        ];
    }

    // -------------------------------------------------------------------------
    // External calls
    // -------------------------------------------------------------------------

    /**
     * Geocode a client IP via ipapi.co.
     * Returns null on private/loopback IPs and on any network/parse error.
     */
    private function geocodeIp(string $ip): ?array
    {
        if (!$this->isPublicIp($ip)) {
            return null;
        }

        $url = 'https://ipapi.co/' . rawurlencode($ip) . '/json/';
        $body = $this->httpGet($url);
        if (!$body) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || isset($data['error'])) {
            return null;
        }

        $lat = $data['latitude'] ?? null;
        $lon = $data['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return [
            'latitude'     => (float)$lat,
            'longitude'    => (float)$lon,
            'city'         => isset($data['city']) ? (string)$data['city'] : null,
            'country_code' => isset($data['country_code']) ? strtoupper(substr((string)$data['country_code'], 0, 2)) : null,
        ];
    }

    private function fetchOpenMeteo(float $lat, float $lon): ?array
    {
        $query = http_build_query([
            'latitude'      => round($lat, 4),
            'longitude'     => round($lon, 4),
            'current'       => 'temperature_2m,weather_code,is_day',
            'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min',
            'forecast_days' => 7,
            'timezone'      => 'auto',
        ]);

        $url = 'https://api.open-meteo.com/v1/forecast?' . $query;
        $body = $this->httpGet($url);
        if (!$body) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['current'])) {
            return null;
        }

        $cur = $data['current'];
        if (!isset($cur['weather_code'], $cur['temperature_2m'])) {
            return null;
        }

        return [
            'weather_code'  => (int)$cur['weather_code'],
            'temperature_c' => round((float)$cur['temperature_2m'], 1),
            'is_day'        => isset($cur['is_day']) ? (int)$cur['is_day'] : 1,
            'raw'           => $data,
        ];
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_USERAGENT      => 'FlowOne-Weather/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            error_log("WeatherService httpGet failed url=$url code=$code err=$err");
            return null;
        }
        return (string)$body;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function bucket(float $value): float
    {
        return round($value / self::BUCKET_PRECISION) * self::BUCKET_PRECISION;
    }

    private function isPublicIp(string $ip): bool
    {
        return (bool)filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function fallbackGeo(string $ip): array
    {
        error_log('WeatherService: falling back to ' . self::FALLBACK_CITY . " for ip=$ip (private/loopback or geocode failed)");
        return [
            'latitude'     => self::FALLBACK_LATITUDE,
            'longitude'    => self::FALLBACK_LONGITUDE,
            'city'         => self::FALLBACK_CITY,
            'country_code' => self::FALLBACK_COUNTRY,
        ];
    }

    private function unavailable(): array
    {
        return [
            'city'          => null,
            'country_code'  => null,
            'latitude'      => null,
            'longitude'     => null,
            'weather_code'  => null,
            'temperature_c' => null,
            'is_day'        => null,
            'fetched_at'    => null,
            'stale'         => true,
            'available'     => false,
        ];
    }
}
