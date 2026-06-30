-- ============================================================
-- STATISZTIKAI ADATGYŰJTŐ RENDSZER — TELJES ADATBÁZIS SÉMA
-- Domain: statisztika.asvanyvizek.hu
-- Charset: utf8mb4 / utf8mb4_hungarian_ci
-- Engine: InnoDB
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================
-- 1. FELHASZNÁLÓK ÉS BIZTONSÁG
-- ============================================================

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role ENUM('admin','reviewer','client') NOT NULL DEFAULT 'client',
    company_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    two_factor_secret VARCHAR(255) NULL,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE user_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    last_active_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_token (token_hash),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE refresh_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    session_id INT UNSIGNED NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_refresh_token (token_hash),
    INDEX idx_refresh_user (user_id),
    CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_refresh_session FOREIGN KEY (session_id) REFERENCES user_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_email (email),
    INDEX idx_attempts_ip (ip_address),
    INDEX idx_attempts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- ============================================================
-- 2. CÉGEK ÉS IDŐSZAKOK
-- ============================================================

CREATE TABLE companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year SMALLINT UNSIGNED NOT NULL,
    quarter TINYINT UNSIGNED NOT NULL,
    status ENUM('draft','open','locked','published') NOT NULL DEFAULT 'draft',
    deadline DATE NULL COMMENT 'Automatikus lezárás határideje',
    snapshot_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period (year, quarter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- FK: users.company_id → companies.id (deferred because companies created after users)
ALTER TABLE users
    ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL;

-- ============================================================
-- 3. KONFIGURÁCIÓ (DINAMIKUS DIMENZIÓK)
-- ============================================================

CREATE TABLE product_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL COMMENT 'I, II, III, IV, V, VI, VII, VIII',
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('pending','approved','disabled') NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pg_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE sub_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_group_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('pending','approved','disabled') NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subcat_pg (product_group_id),
    CONSTRAINT fk_subcat_pg FOREIGN KEY (product_group_id) REFERENCES product_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE packaging_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('pending','approved','disabled') NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE sub_category_packaging (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sub_category_id INT UNSIGNED NOT NULL,
    packaging_type_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_subcat_pkg (sub_category_id, packaging_type_id),
    CONSTRAINT fk_scp_subcat FOREIGN KEY (sub_category_id) REFERENCES sub_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_scp_pkg FOREIGN KEY (packaging_type_id) REFERENCES packaging_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE sizes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL COMMENT 'Megjelenítési cimke, pl. "0,5 liter"',
    value_liters DECIMAL(8,4) NOT NULL COMMENT 'Numerikus érték literben, pl. 0.5000',
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('pending','approved','disabled') NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_size_value (value_liters)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE flow_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NOT NULL COMMENT 'domestic, own_import, export',
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_flow_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE config_snapshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_id INT UNSIGNED NOT NULL,
    snapshot_data JSON NOT NULL COMMENT 'Teljes konfiguráció JSON pillanatkép',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_snapshot_period (period_id),
    CONSTRAINT fk_snapshot_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

ALTER TABLE periods
    ADD CONSTRAINT fk_periods_snapshot FOREIGN KEY (snapshot_id) REFERENCES config_snapshots(id) ON DELETE SET NULL;

-- ============================================================
-- 4. FŐ ADATBEVITEL
-- ============================================================

CREATE TABLE entries_draft (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    sub_category_id INT UNSIGNED NOT NULL,
    packaging_type_id INT UNSIGNED NOT NULL,
    size_id INT UNSIGNED NOT NULL,
    flow_type_id INT UNSIGNED NOT NULL,
    value INT NOT NULL DEFAULT 0 COMMENT 'Darabszám (db)',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_draft_entry (company_id, period_id, sub_category_id, packaging_type_id, size_id, flow_type_id),
    INDEX idx_draft_company_period (company_id, period_id),
    INDEX idx_draft_period (period_id),

    CONSTRAINT fk_draft_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_subcat FOREIGN KEY (sub_category_id) REFERENCES sub_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_pkg FOREIGN KEY (packaging_type_id) REFERENCES packaging_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_size FOREIGN KEY (size_id) REFERENCES sizes(id) ON DELETE CASCADE,
    CONSTRAINT fk_draft_flow FOREIGN KEY (flow_type_id) REFERENCES flow_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE entries_final (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    sub_category_id INT UNSIGNED NOT NULL,
    packaging_type_id INT UNSIGNED NOT NULL,
    size_id INT UNSIGNED NOT NULL,
    flow_type_id INT UNSIGNED NOT NULL,
    value INT NOT NULL COMMENT 'Darabszám (db)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_final_entry (company_id, period_id, sub_category_id, packaging_type_id, size_id, flow_type_id),
    INDEX idx_final_company_period (company_id, period_id),
    INDEX idx_final_period (period_id),

    CONSTRAINT fk_final_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_final_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_final_subcat FOREIGN KEY (sub_category_id) REFERENCES sub_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_final_pkg FOREIGN KEY (packaging_type_id) REFERENCES packaging_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_final_size FOREIGN KEY (size_id) REFERENCES sizes(id) ON DELETE CASCADE,
    CONSTRAINT fk_final_flow FOREIGN KEY (flow_type_id) REFERENCES flow_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE dataset_states (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    status ENUM('draft','submitted','approved','locked','published') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    reviewer_id INT UNSIGNED NULL,
    reviewer_note TEXT NULL,
    submitted_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_dataset_state (company_id, period_id),
    INDEX idx_ds_period (period_id),
    CONSTRAINT fk_ds_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_ds_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_ds_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE import_defaults (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    sub_category_id INT UNSIGNED NOT NULL,
    packaging_type_id INT UNSIGNED NOT NULL,
    size_id INT UNSIGNED NOT NULL,
    value INT NOT NULL DEFAULT 0 COMMENT 'Alapértelmezett "Saját import" darabszám',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_import_default (company_id, sub_category_id, packaging_type_id, size_id),
    CONSTRAINT fk_impdef_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_impdef_subcat FOREIGN KEY (sub_category_id) REFERENCES sub_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_impdef_pkg FOREIGN KEY (packaging_type_id) REFERENCES packaging_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_impdef_size FOREIGN KEY (size_id) REFERENCES sizes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- ============================================================
-- 5. KIEGÉSZÍTŐ ADATOK (ÍZEK, CUKOR, KALÓRIA)
-- ============================================================

CREATE TABLE flavors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE flavor_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Pl. Ízesített ásványvíz, Juice, Nektár, Ital, Jeges tea, Szörp',
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE flavor_entries_draft (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    flavor_id INT UNSIGNED NOT NULL,
    flavor_category_id INT UNSIGNED NOT NULL,
    flow_type_id INT UNSIGNED NOT NULL,
    value DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Érték 1000 literben',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_flavor_draft (company_id, period_id, flavor_id, flavor_category_id, flow_type_id),
    INDEX idx_flav_draft_cp (company_id, period_id),
    CONSTRAINT fk_flavd_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_flavd_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_flavd_flavor FOREIGN KEY (flavor_id) REFERENCES flavors(id) ON DELETE CASCADE,
    CONSTRAINT fk_flavd_cat FOREIGN KEY (flavor_category_id) REFERENCES flavor_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_flavd_flow FOREIGN KEY (flow_type_id) REFERENCES flow_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE flavor_entries_final (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    flavor_id INT UNSIGNED NOT NULL,
    flavor_category_id INT UNSIGNED NOT NULL,
    flow_type_id INT UNSIGNED NOT NULL,
    value DECIMAL(12,3) NOT NULL COMMENT 'Érték 1000 literben',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_flavor_final (company_id, period_id, flavor_id, flavor_category_id, flow_type_id),
    INDEX idx_flav_final_cp (company_id, period_id),
    CONSTRAINT fk_flavf_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_flavf_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_flavf_flavor FOREIGN KEY (flavor_id) REFERENCES flavors(id) ON DELETE CASCADE,
    CONSTRAINT fk_flavf_cat FOREIGN KEY (flavor_category_id) REFERENCES flavor_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_flavf_flow FOREIGN KEY (flow_type_id) REFERENCES flow_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE sugar_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE sugar_entries_draft (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    sugar_type_id INT UNSIGNED NOT NULL,
    product_group_id INT UNSIGNED NOT NULL,
    value DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Érték 1000 literben',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_sugar_draft (company_id, period_id, sugar_type_id, product_group_id),
    INDEX idx_sugar_draft_cp (company_id, period_id),
    CONSTRAINT fk_sugd_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sugd_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_sugd_sugar FOREIGN KEY (sugar_type_id) REFERENCES sugar_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_sugd_pg FOREIGN KEY (product_group_id) REFERENCES product_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE sugar_entries_final (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    sugar_type_id INT UNSIGNED NOT NULL,
    product_group_id INT UNSIGNED NOT NULL,
    value DECIMAL(12,3) NOT NULL COMMENT 'Érték 1000 literben',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_sugar_final (company_id, period_id, sugar_type_id, product_group_id),
    INDEX idx_sugar_final_cp (company_id, period_id),
    CONSTRAINT fk_sugf_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sugf_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_sugf_sugar FOREIGN KEY (sugar_type_id) REFERENCES sugar_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_sugf_pg FOREIGN KEY (product_group_id) REFERENCES product_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE calorie_entries_draft (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    product_group_id INT UNSIGNED NOT NULL,
    avg_kcal DECIMAL(8,2) NOT NULL DEFAULT 0 COMMENT 'Átlagos kcal/100ml',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_cal_draft (company_id, period_id, product_group_id),
    INDEX idx_cal_draft_cp (company_id, period_id),
    CONSTRAINT fk_cald_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_cald_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_cald_pg FOREIGN KEY (product_group_id) REFERENCES product_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE calorie_entries_final (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    period_id INT UNSIGNED NOT NULL,
    product_group_id INT UNSIGNED NOT NULL,
    avg_kcal DECIMAL(8,2) NOT NULL COMMENT 'Átlagos kcal/100ml',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_cal_final (company_id, period_id, product_group_id),
    INDEX idx_cal_final_cp (company_id, period_id),
    CONSTRAINT fk_calf_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_calf_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_calf_pg FOREIGN KEY (product_group_id) REFERENCES product_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- ============================================================
-- 6. API KULCSOK (AUTOMATIZÁLT ADATFELTÖLTÉS)
-- ============================================================

CREATE TABLE api_keys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    key_hash VARCHAR(255) NOT NULL,
    label VARCHAR(255) NULL COMMENT 'Admin által adott leírás',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apikey_company (company_id),
    UNIQUE KEY uq_apikey_hash (key_hash),
    CONSTRAINT fk_apikey_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- ============================================================
-- 7. EMAIL ÉRTESÍTÉSEK
-- ============================================================

CREATE TABLE email_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    company_id INT UNSIGNED NULL,
    type ENUM('publish','deadline_reminder','rejection','other') NOT NULL,
    subject VARCHAR(500) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP NULL,
    is_success TINYINT(1) NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_company (company_id),
    INDEX idx_notif_type (type),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_notif_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- ============================================================
-- 8. NAPLÓZÁS (AUDIT LOG)
-- ============================================================

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL COMMENT 'create, update, delete, submit, approve, reject, login, stb.',
    table_name VARCHAR(100) NULL,
    record_id BIGINT UNSIGNED NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_table (table_name),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
