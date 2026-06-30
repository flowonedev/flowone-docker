-- 193: Store the sent email's Message-ID with each tracking record.
--
-- Read-receipt / link-click notifications previously had no reliable way to
-- reopen the underlying email: the only stored hint was the subject, so the
-- frontend string-matched page 1 of Sent (and failed for old emails or
-- duplicate subjects). Persisting the Message-ID lets the backend resolve the
-- exact IMAP message via HEADER search across folders.
--
-- The column is nullable: rows created before this migration keep working via
-- the subject-search fallback in TrackingService::locateEmail(). The index is
-- mostly for diagnostics/future tooling (lookups are by tracking_id) but is
-- cheap to keep.

ALTER TABLE email_tracking
    ADD COLUMN IF NOT EXISTS message_id VARCHAR(255) DEFAULT NULL AFTER subject;

ALTER TABLE email_tracking
    ADD INDEX IF NOT EXISTS idx_message_id (message_id);
