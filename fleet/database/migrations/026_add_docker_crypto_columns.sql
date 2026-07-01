-- Migration: persist the non-regenerable crypto for the Docker (compose) deploy.
--
-- Phase D (native->docker). The native installer generates these keys ON the box
-- (openssl in install.sh) and never reports them back. The Docker deploy renders
-- a per-host .env from Fleet, so Fleet must own these secrets and reuse them on
-- every re-provision -- regenerating IMAP_ENCRYPTION_KEY bricks every stored
-- password; rotating the JWT pair logs everyone out. Stored encrypted (except the
-- JWT PUBLIC key, which is not secret) and reused idempotently by
-- TemplateService::generateServerVariables() / persistDockerSecrets().
--
-- On a MIGRATED box these are overwritten by the real keys from the snapshot
-- (migration/restore.sh); on a FRESH box Fleet generates + persists them here.

ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS imap_encryption_key_encrypted TEXT NULL AFTER meili_search_key,
    ADD COLUMN IF NOT EXISTS ai_encryption_key_encrypted   TEXT NULL AFTER imap_encryption_key_encrypted,
    ADD COLUMN IF NOT EXISTS sso_server_key_encrypted       TEXT NULL AFTER ai_encryption_key_encrypted,
    ADD COLUMN IF NOT EXISTS jwt_private_key_encrypted      TEXT NULL AFTER sso_server_key_encrypted,
    ADD COLUMN IF NOT EXISTS jwt_public_key                 TEXT NULL AFTER jwt_private_key_encrypted;
