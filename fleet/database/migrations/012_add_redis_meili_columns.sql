-- Migration: Add Redis and Meilisearch credential columns to servers table
-- These store encrypted credentials generated during provisioning

ALTER TABLE servers
    ADD COLUMN redis_password_encrypted TEXT NULL AFTER mail_db_password_encrypted,
    ADD COLUMN meili_master_key_encrypted TEXT NULL AFTER redis_password_encrypted,
    ADD COLUMN meili_search_key TEXT NULL AFTER meili_master_key_encrypted;

