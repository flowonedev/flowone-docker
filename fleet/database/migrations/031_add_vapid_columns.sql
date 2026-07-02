-- Migration: persist per-server VAPID keys (web push).
--
-- ComposeEnvRenderer emits VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY into the email
-- .env, but nothing ever generated them — every fresh Docker box shipped with
-- empty values and the frontend warned "[Push] VAPID key not configured".
-- ServerSecretGenerator now mints a P-256 pair on first provision; it must be
-- persisted and reused forever because push subscriptions are cryptographically
-- bound to the public key (rotating it orphans every subscribed browser).
-- Public key is not secret; private key is stored encrypted like the rest.

ALTER TABLE servers
    ADD COLUMN IF NOT EXISTS vapid_public_key            TEXT NULL AFTER jwt_public_key,
    ADD COLUMN IF NOT EXISTS vapid_private_key_encrypted TEXT NULL AFTER vapid_public_key;
