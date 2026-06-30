-- Billing Integration Tables
-- Migration: Add external billing provider support (Billingo, Szamlazz.hu) and billing settings

-- =========================================================================
-- Add billing provider columns to crm_invoices
-- =========================================================================

ALTER TABLE crm_invoices
    ADD COLUMN billing_provider ENUM('billingo', 'szamlazz', 'manual') DEFAULT 'manual' AFTER board_card_id,
    ADD COLUMN external_invoice_id VARCHAR(100) DEFAULT NULL COMMENT 'ID on external billing platform' AFTER billing_provider,
    ADD COLUMN external_invoice_url VARCHAR(500) DEFAULT NULL COMMENT 'Link to view on billing platform' AFTER external_invoice_id,
    ADD COLUMN external_pdf_url VARCHAR(500) DEFAULT NULL COMMENT 'Direct link to PDF on platform' AFTER external_invoice_url,
    ADD INDEX idx_external (billing_provider, external_invoice_id);

-- =========================================================================
-- Billing settings per user
-- =========================================================================

CREATE TABLE IF NOT EXISTS crm_billing_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    
    -- Provider config
    provider ENUM('billingo', 'szamlazz', 'none') DEFAULT 'none',
    api_key VARCHAR(500) DEFAULT NULL COMMENT 'Encrypted API key for the billing platform',
    
    -- Billingo-specific
    billingo_block_id INT DEFAULT NULL COMMENT 'Billingo invoice block ID',
    
    -- Szamlazz-specific
    szamlazz_agent_key VARCHAR(255) DEFAULT NULL COMMENT 'Szamlazz.hu agent key',
    
    -- Company details (used in invoice generation)
    company_name VARCHAR(255) DEFAULT NULL,
    company_address TEXT DEFAULT NULL,
    company_tax_number VARCHAR(50) DEFAULT NULL,
    company_eu_tax_number VARCHAR(50) DEFAULT NULL COMMENT 'EU VAT number',
    company_bank_account VARCHAR(100) DEFAULT NULL,
    company_bank_name VARCHAR(100) DEFAULT NULL,
    company_email VARCHAR(255) DEFAULT NULL,
    company_phone VARCHAR(50) DEFAULT NULL,
    company_logo_drive_file_id INT DEFAULT NULL,
    
    -- Defaults
    default_currency VARCHAR(3) DEFAULT 'HUF',
    default_tax_rate DECIMAL(5,2) DEFAULT 27.00 COMMENT 'Hungarian VAT default',
    default_payment_terms_days INT DEFAULT 8,
    default_payment_method ENUM('bank_transfer', 'cash', 'card', 'paypal', 'other') DEFAULT 'bank_transfer',
    default_language ENUM('hu', 'en', 'de') DEFAULT 'hu',
    
    -- Auto-save to Drive
    auto_save_to_drive TINYINT(1) DEFAULT 1 COMMENT 'Auto-save generated invoices to Drive',
    drive_invoices_folder_id INT DEFAULT NULL COMMENT 'Drive folder ID for Invoices system folder',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

