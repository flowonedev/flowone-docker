-- Add color palette and background effects columns to mood_boards
ALTER TABLE mood_boards
    ADD COLUMN IF NOT EXISTS color_palette JSON DEFAULT NULL COMMENT 'Saved color swatches for the board',
    ADD COLUMN IF NOT EXISTS background_effect JSON DEFAULT NULL COMMENT 'Background effects: grain, blur, gradient, noise';

