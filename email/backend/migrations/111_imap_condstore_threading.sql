-- Migration 111: IMAP CONDSTORE support + fallback threading
-- Adds highest_modseq for CONDSTORE incremental flag sync
-- Adds normalized_subject for subject-based thread grouping fallback

-- CONDSTORE: store HIGHESTMODSEQ per folder for incremental flag sync
ALTER TABLE webmail_folder_index ADD COLUMN highest_modseq BIGINT DEFAULT 0;

-- Fallback threading: normalized_subject for grouping when References/In-Reply-To are missing
ALTER TABLE webmail_conversations ADD COLUMN normalized_subject VARCHAR(512) DEFAULT NULL;
ALTER TABLE webmail_conversations ADD INDEX idx_norm_subject (user_email, folder, normalized_subject);

-- Backfill normalized_subject for existing conversations
UPDATE webmail_conversations
SET normalized_subject = LOWER(TRIM(
    REGEXP_REPLACE(subject, '^(\\s*(Re|Fwd|FW|RE|Fw|re|fwd|fw)\\s*:\\s*)+', '')
))
WHERE subject IS NOT NULL AND normalized_subject IS NULL;
