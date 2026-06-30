-- Multi-tab safe refresh token rotation
-- When Tab 1 rotates the refresh token, Tab 2 still holds the old one.
-- Without a grace period, Tab 2's refresh attempt would be treated as a
-- replay attack and kill the session (logging out BOTH tabs).
--
-- Fix: store the previous refresh token hash + rotation timestamp.
-- Accept the previous hash within a 2-minute grace window.
ALTER TABLE webmail_sessions ADD COLUMN IF NOT EXISTS previous_refresh_token_hash VARCHAR(128) DEFAULT NULL AFTER refresh_token_hash;
ALTER TABLE webmail_sessions ADD COLUMN IF NOT EXISTS refresh_rotated_at DATETIME DEFAULT NULL AFTER previous_refresh_token_hash;

