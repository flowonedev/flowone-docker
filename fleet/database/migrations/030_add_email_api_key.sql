-- Migration: persist the Email App <-> Panel API key per server.
--
-- TemplateService::generateServerVariables() minted a FRESH random
-- EMAIL_API_KEY on every call. The Docker provision renders the email .env
-- (PANEL_API_KEY) in one service instance and the panel-front deploy passes
-- --email-api-key to install.sh from ANOTHER instance, so the email app and
-- the panel ended up with two different keys — every Panel API call from the
-- email app (audit ingest, addons, storage config) answered 401, and the
-- panel's rate limiter escalated that to 429. Persist the key once and reuse
-- it forever, like the other stable docker secrets (migration 026).

ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS email_api_key_encrypted TEXT NULL AFTER sso_server_key_encrypted;
