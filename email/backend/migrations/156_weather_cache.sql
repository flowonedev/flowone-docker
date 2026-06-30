-- =====================================================
-- Header weather chip: shared cache + per-user location
-- =====================================================
-- weather_cache: ONE row per geographic bucket (0.1° lat/lon ~ 11 km).
--   Multiple users in the same area share this row.
--   Refreshed at most every 15 minutes regardless of user count.
-- user_locations: per-user link into the bucket above.
--   Geocoded once per user (30-day TTL) from their request IP.
-- Idempotent: safe to run on a DB where the tables already exist.

CREATE TABLE IF NOT EXISTS weather_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lat_bucket DECIMAL(5,2) NOT NULL,
    lon_bucket DECIMAL(5,2) NOT NULL,
    weather_code SMALLINT NULL,
    temperature_c DECIMAL(4,1) NULL,
    is_day TINYINT(1) NULL,
    payload_json JSON NULL,
    fetched_at DATETIME NOT NULL,
    UNIQUE KEY uniq_bucket (lat_bucket, lon_bucket),
    INDEX idx_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_locations (
    user_email VARCHAR(255) NOT NULL PRIMARY KEY,
    lat_bucket DECIMAL(5,2) NULL,
    lon_bucket DECIMAL(5,2) NULL,
    latitude DECIMAL(8,5) NULL,
    longitude DECIMAL(8,5) NULL,
    city VARCHAR(120) NULL,
    country_code CHAR(2) NULL,
    resolved_from_ip VARCHAR(45) NULL,
    geo_fetched_at DATETIME NULL,
    INDEX idx_bucket (lat_bucket, lon_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
