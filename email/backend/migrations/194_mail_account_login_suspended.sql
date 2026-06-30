-- 194: Per-mailbox login suspension (login-only, keeps receiving mail).
--
-- The panel needs a "Suspend" button that blocks a user from logging in via
-- IMAP/POP3/SMTP-AUTH and webmail (Outlook included) WITHOUT bouncing their
-- incoming mail. The existing `status` column cannot do this: Dovecot's
-- user_query and Postfix's virtual_mailbox lookup both require status='active'
-- for delivery, so flipping status away from 'active' would also stop inbound
-- mail (and is additionally entangled with the OOO/'vacation' status).
--
-- Instead we add a dedicated `login_suspended` flag that is wired ONLY into
-- Dovecot's password_query (`... AND login_suspended = 0`). Delivery keeps
-- working because status stays 'active'; only authentication is refused, so a
-- suspended user is locked out everywhere while their mailbox keeps filling up
-- and is waiting for them when they are resumed.
--
-- All columns are additive and default to the "not suspended" state, so
-- existing rows are unaffected.

ALTER TABLE mail_accounts
    ADD COLUMN IF NOT EXISTS login_suspended TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

ALTER TABLE mail_accounts
    ADD COLUMN IF NOT EXISTS suspended_at TIMESTAMP NULL DEFAULT NULL AFTER login_suspended;

ALTER TABLE mail_accounts
    ADD COLUMN IF NOT EXISTS suspended_reason VARCHAR(255) DEFAULT NULL AFTER suspended_at;

ALTER TABLE mail_accounts
    ADD INDEX IF NOT EXISTS idx_login_suspended (login_suspended);
