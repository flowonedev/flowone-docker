-- Migration: Add is_local column to servers table
-- Version: 004

ALTER TABLE servers ADD COLUMN is_local TINYINT(1) DEFAULT 0 AFTER ssh_password_encrypted;
ALTER TABLE servers ADD COLUMN key_path VARCHAR(255) DEFAULT NULL AFTER is_local;

-- Update existing servers with 127.0.0.1 to be marked as local
UPDATE servers SET is_local = 1 WHERE ip_address = '127.0.0.1' OR ip_address = 'localhost';

