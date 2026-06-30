-- Add background audio column to mood_boards
-- Stores YouTube URL or audio file path with volume setting for presentation ambient audio
ALTER TABLE mood_boards
    ADD COLUMN IF NOT EXISTS bg_audio JSON DEFAULT NULL COMMENT 'Background audio: {type: youtube|file, url: string, volume: 0-100, loop: bool}';

