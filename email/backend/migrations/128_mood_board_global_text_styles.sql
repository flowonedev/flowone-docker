-- Add global_text_styles JSON column to mood_boards for reusable typography definitions
ALTER TABLE mood_boards
  ADD COLUMN IF NOT EXISTS global_text_styles JSON DEFAULT NULL
  AFTER design_tokens;
