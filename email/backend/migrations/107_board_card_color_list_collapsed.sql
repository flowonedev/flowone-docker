-- Add card_color column to cards (full background color, separate from cover_color which is the top strip)
ALTER TABLE webmail_board_cards ADD COLUMN card_color VARCHAR(7) DEFAULT NULL AFTER cover_image_id;

-- Add collapsed column to lists (allows collapsing lists like Trello)
ALTER TABLE webmail_board_lists ADD COLUMN collapsed TINYINT(1) NOT NULL DEFAULT 0 AFTER archived;
