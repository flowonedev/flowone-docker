-- Migration: Add additional service status columns to server_health
-- Tracks Redis, Meilisearch, SpamAssassin, Collab Server, MailSync Server

ALTER TABLE server_health
    ADD COLUMN redis_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER openvpn_status,
    ADD COLUMN meilisearch_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER redis_status,
    ADD COLUMN spamassassin_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER meilisearch_status,
    ADD COLUMN collab_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER spamassassin_status,
    ADD COLUMN mailsync_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER collab_status;

