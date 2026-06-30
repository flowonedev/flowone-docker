-- News Reader addon: feeds, subscriptions, items, per-user read state
-- Next migration after 156_weather_cache.sql

CREATE TABLE IF NOT EXISTS news_reader_feeds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feed_url VARCHAR(2048) NOT NULL COMMENT 'Original URL as entered or redirected final URL',
    canonical_feed_url VARCHAR(2048) NOT NULL,
    canonical_url_hash CHAR(40) NOT NULL COMMENT 'SHA1 of normalized canonical URL for uniqueness',
    feed_type ENUM('rss','atom','unknown') NOT NULL DEFAULT 'unknown',
    title VARCHAR(512) DEFAULT NULL,
    site_url VARCHAR(2048) DEFAULT NULL,
    favicon_url VARCHAR(2048) DEFAULT NULL,
    description TEXT,
    last_fetched_at DATETIME NULL,
    last_etag VARCHAR(255) NULL,
    last_modified VARCHAR(255) NULL,
    fetch_error_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_fetch_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_canonical_hash (canonical_url_hash),
    KEY idx_feed_url_prefix (feed_url(191)),
    KEY idx_last_fetched (last_fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_reader_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    feed_id BIGINT UNSIGNED NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    category VARCHAR(64) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_feed (user_email, feed_id),
    KEY idx_user (user_email),
    KEY idx_user_enabled (user_email, is_enabled),
    CONSTRAINT fk_news_sub_feed FOREIGN KEY (feed_id) REFERENCES news_reader_feeds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_reader_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feed_id BIGINT UNSIGNED NOT NULL,
    guid VARCHAR(512) DEFAULT NULL COMMENT 'RSS guid or Atom id when present',
    item_hash CHAR(40) NOT NULL COMMENT 'SHA1(link + title + published_at)',
    title VARCHAR(1024) NOT NULL DEFAULT '',
    link VARCHAR(2048) NOT NULL DEFAULT '',
    summary TEXT COMMENT 'Short HTML excerpt, sanitized',
    content_html MEDIUMTEXT NULL COMMENT 'Full HTML body when available, sanitized',
    content_text MEDIUMTEXT NULL COMMENT 'Plaintext for preview/search',
    image_url VARCHAR(2048) DEFAULT NULL,
    author VARCHAR(255) DEFAULT NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feed_hash (feed_id, item_hash),
    KEY idx_feed_guid (feed_id, guid(255)),
    KEY idx_feed_published (feed_id, published_at DESC),
    KEY idx_created (created_at),
    KEY idx_published (published_at DESC),
    CONSTRAINT fk_news_item_feed FOREIGN KEY (feed_id) REFERENCES news_reader_feeds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_reader_reads (
    user_email VARCHAR(255) NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_email, item_id),
    KEY idx_user_read (user_email, read_at DESC),
    KEY idx_item (item_id),
    CONSTRAINT fk_news_read_item FOREIGN KEY (item_id) REFERENCES news_reader_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
