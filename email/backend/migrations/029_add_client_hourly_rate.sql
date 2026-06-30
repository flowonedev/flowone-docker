-- Add hourly_rate column to clients table
-- This allows tracking hourly rate for time tracking financial calculations

ALTER TABLE clients 
ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL COMMENT 'Hourly rate for time tracking calculations'
AFTER payment_terms_days;

