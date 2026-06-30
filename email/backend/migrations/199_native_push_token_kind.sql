-- 199_native_push_token_kind.sql
-- Distinguish FCM tokens from APNs VoIP (PushKit) tokens for the same device.
--
-- The iOS Chat app now registers TWO tokens per install: the regular FCM token
-- (alerts) and a separate PushKit VoIP token used to wake the app and present
-- the native CallKit full-screen call screen. A VoIP token is APNs-only and
-- would be rejected by FCM, so it MUST be stored and routed separately.
--
-- token_kind: 'fcm' (default, existing behavior) | 'voip' (PushKit VoIP token).
-- The per-device unique key widens to include token_kind so one device can hold
-- both an fcm row and a voip row. Existing rows backfill to 'fcm' via the
-- column default. Additive + idempotent (safe to re-run).

ALTER TABLE native_push_tokens
    ADD COLUMN IF NOT EXISTS token_kind VARCHAR(16) NOT NULL DEFAULT 'fcm' AFTER token;

-- Widen the per-device uniqueness to (user, app, device, kind). Drop the old
-- key first (best-effort: absent on fresh installs created with the new schema).
ALTER TABLE native_push_tokens DROP INDEX IF EXISTS unique_device;

ALTER TABLE native_push_tokens
    ADD UNIQUE KEY IF NOT EXISTS unique_device_kind (user_email, app_id, device_id, token_kind);

ALTER TABLE native_push_tokens
    ADD INDEX IF NOT EXISTS idx_user_kind (user_email, token_kind);
