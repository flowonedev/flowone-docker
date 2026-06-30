-- Mood Board Presentation Mode
-- Migration 060: Add presentation/slide fields to frames for Prezi-style presenting

-- 1. Add slide_order to mood_board_items (NULL = not a presentation frame, integer = slide position)
ALTER TABLE mood_board_items
    ADD COLUMN IF NOT EXISTS slide_order INT DEFAULT NULL COMMENT 'Presentation slide order (NULL = not a slide)' AFTER z_index;

-- 2. Add transition type for slide transitions
ALTER TABLE mood_board_items
    ADD COLUMN IF NOT EXISTS transition_type ENUM('fly','fade','instant') DEFAULT 'fly' COMMENT 'Transition animation between slides' AFTER slide_order;

-- 3. Add presenter notes (speaker notes visible only to presenter)
ALTER TABLE mood_board_items
    ADD COLUMN IF NOT EXISTS presenter_notes TEXT DEFAULT NULL COMMENT 'Speaker notes for presentation mode' AFTER transition_type;

-- 4. Index for quick lookup of presentation frames ordered by slide_order
ALTER TABLE mood_board_items
    ADD INDEX IF NOT EXISTS idx_slide_order (board_id, slide_order);

