-- Add billing/invoicing fields to clients table
-- These are used by the Billing integration to populate invoice data from client records

ALTER TABLE clients
    ADD COLUMN billing_name VARCHAR(255) DEFAULT NULL COMMENT 'Company name for invoices (if different from display_name)' AFTER display_name,
    ADD COLUMN billing_address VARCHAR(500) DEFAULT NULL COMMENT 'Billing street address' AFTER billing_name,
    ADD COLUMN billing_city VARCHAR(100) DEFAULT NULL COMMENT 'Billing city' AFTER billing_address,
    ADD COLUMN billing_zip VARCHAR(20) DEFAULT NULL COMMENT 'Billing postal/zip code' AFTER billing_city,
    ADD COLUMN billing_country VARCHAR(100) DEFAULT 'HU' COMMENT 'Billing country code' AFTER billing_zip,
    ADD COLUMN billing_tax_id VARCHAR(50) DEFAULT NULL COMMENT 'Tax number / VAT ID' AFTER billing_country;

