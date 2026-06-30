-- News Reader: cache full extracted article content per item.
-- The RSS feed usually only gives a 1-2 sentence summary. We use a
-- server-side article extractor (Readability-style) to fetch the full
-- article text from the publisher and cache it here so subsequent reads
-- are instant.
--
-- Columns:
--   full_content_html   sanitized HTML of the extracted article body
--   full_extracted_at   when extraction last succeeded (NULL = not tried yet)
--   full_extract_status 'ok' | 'failed' | NULL
--   full_extract_error  human-readable failure reason (debugging)

ALTER TABLE news_reader_items
    ADD COLUMN full_content_html LONGTEXT NULL AFTER content_text,
    ADD COLUMN full_extracted_at DATETIME NULL AFTER full_content_html,
    ADD COLUMN full_extract_status VARCHAR(16) NULL AFTER full_extracted_at,
    ADD COLUMN full_extract_error VARCHAR(512) NULL AFTER full_extract_status;
