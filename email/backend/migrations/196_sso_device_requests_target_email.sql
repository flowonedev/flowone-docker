-- Migration 196: Target a device-authorization request at a specific account.
--
-- Adds target_email so a device that wants to sign in can name the account it
-- is targeting (the user types their email on the new device). Already-signed-in
-- sessions for that account then DISCOVER the pending request by polling
-- /sso/device/pending and pop an approval modal automatically -- no QR scan
-- required. The QR / verify_url path still works as a fallback.
--
-- Security: target_email only narrows discovery to the right account's sessions;
-- approval still requires picking the correct match_number out of three, is
-- attempt-capped, and binds to the approver's authenticated identity.

ALTER TABLE sso_device_requests
    ADD COLUMN target_email VARCHAR(255) DEFAULT NULL AFTER user_email,
    ADD INDEX idx_target_pending (target_email, status, expires_at);
