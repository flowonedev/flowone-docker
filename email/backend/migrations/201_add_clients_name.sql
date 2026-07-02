-- Migration 201: add clients.name for fresh installs.
--
-- Code (DriveService, ClientService, addons) selects c.name from clients. On
-- the original server that column exists because the PANEL's schema.sql created
-- the shared `clients` table first (its shape includes `name`). On a fresh
-- Docker box the EMAIL app boots first, so migration 001 wins the CREATE TABLE
-- race and the table has display_name but no `name` — every Drive request then
-- 500s with "Unknown column 'c.name'". Idempotent: no-op where `name` exists.

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS name VARCHAR(255) DEFAULT NULL COMMENT 'Client display name (panel-compatible; falls back to display_name)' AFTER user_email;
