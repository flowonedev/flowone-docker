-- Migration 184: Contacts address book (CardDAV-ready)
--
-- A real per-user address book (distinct from the lightweight
-- `email_contacts` autocomplete cache). Backs the Contacts feature in
-- the webmail UI and is the import target for migrations from cPanel /
-- Google / Outlook (VCF / CSV uploaded via the Panel migration tooling
-- and pushed to /api/internal/dav-import).
--
-- Design notes:
--   - Structured columns power search / list / edit in the UI.
--   - Multi-valued fields (emails, phones, addresses) are stored as JSON
--     so we don't need child tables for a feature that is read whole.
--   - `vcard` keeps the raw vCard we imported/generated so CardDAV export
--     is loss-free (X- params, extra fields we don't model survive).
--   - `(addressbook_id, uid)` is unique so re-running an import is
--     idempotent (update-in-place instead of duplicating).
--
-- Tables are also created at runtime by AddressBookService::ensureTablesExist()
-- so the feature is self-healing even if migrations haven't been run.

CREATE TABLE IF NOT EXISTS addressbooks (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_email      VARCHAR(255) NOT NULL,
    name            VARCHAR(255) NOT NULL DEFAULT 'Contacts',
    description     VARCHAR(512) DEFAULT NULL,
    color           VARCHAR(7) DEFAULT '#3b82f6',
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    ctag            VARCHAR(64) DEFAULT NULL COMMENT 'Sync token for CardDAV',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_email (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    addressbook_id  INT NOT NULL,
    user_email      VARCHAR(255) NOT NULL COMMENT 'Denormalized owner for fast per-user queries',
    uid             VARCHAR(255) NOT NULL COMMENT 'vCard UID (stable across syncs)',
    etag            VARCHAR(64) DEFAULT NULL COMMENT 'Version tag for CardDAV',

    full_name       VARCHAR(512) DEFAULT NULL,
    first_name      VARCHAR(255) DEFAULT NULL,
    last_name       VARCHAR(255) DEFAULT NULL,
    nickname        VARCHAR(255) DEFAULT NULL,
    organization    VARCHAR(512) DEFAULT NULL,
    job_title       VARCHAR(255) DEFAULT NULL,

    emails          TEXT COMMENT 'JSON: [{type,value}]',
    phones          TEXT COMMENT 'JSON: [{type,value}]',
    addresses       TEXT COMMENT 'JSON: [{type,street,city,region,postal,country}]',
    urls            TEXT COMMENT 'JSON: [{type,value}]',

    birthday        DATE DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    photo           MEDIUMTEXT DEFAULT NULL COMMENT 'data: URL or remote URL',

    is_favorite     TINYINT(1) NOT NULL DEFAULT 0,
    vcard           MEDIUMTEXT DEFAULT NULL COMMENT 'Raw vCard for loss-free CardDAV export',

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_addressbook (addressbook_id),
    INDEX idx_user_email (user_email),
    INDEX idx_full_name (full_name(191)),
    UNIQUE KEY unique_book_uid (addressbook_id, uid),
    FOREIGN KEY (addressbook_id) REFERENCES addressbooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
