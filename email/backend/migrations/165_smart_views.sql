-- ─────────────────────────────────────────────────────────────────────────────
-- 165_smart_views.sql
-- User-defined Smart Views (saved searches) for the email mailbox.
--
-- Why both `query` AND `filters_json`?
--   * `query`        = canonical search-syntax string (what the IMAP search
--                      endpoint accepts today, e.g. `is:unread has:attachment`).
--                      This is what we actually execute. Single source of truth
--                      for execution.
--   * `filters_json` = structured form of the same query (whitelist-validated
--                      by FiltersNormalizer). Used to (a) render filter chips
--                      in the UI, (b) re-open the visual filter builder with
--                      the saved selections, (c) migrate shape in future
--                      without re-parsing the string.
--   * `schema_version` lets us bump filters_json shape in-place and write a
--     normalizer that upgrades older rows on read.
--
-- Tenancy: keyed by `email` (lowercased) to match every other per-user table
-- in the schema (webmail_labels, webmail_signatures, etc.). NO user_id.
--
-- Reordering is two-pass (negate positions in a tx, then write final values)
-- so we don't trip the UNIQUE(email, position) index mid-swap.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS webmail_smart_views (
    id              INT UNSIGNED        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255)        NOT NULL,
    name            VARCHAR(64)         NOT NULL,
    icon            VARCHAR(32)         NOT NULL DEFAULT 'filter_alt',
    color           VARCHAR(16)         NOT NULL DEFAULT 'primary',
    query           TEXT                NOT NULL,
    filters_json    JSON                DEFAULT NULL,
    schema_version  TINYINT UNSIGNED    NOT NULL DEFAULT 1,
    scope           ENUM('folder','all','accounts') NOT NULL DEFAULT 'all',
    position        INT                 NOT NULL DEFAULT 0,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_position (email, position),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
