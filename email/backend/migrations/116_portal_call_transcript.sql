ALTER TABLE portal_calls
ADD COLUMN chat_transcript LONGTEXT NULL AFTER notes,
ADD COLUMN transcript_sent_at DATETIME NULL AFTER chat_transcript;
