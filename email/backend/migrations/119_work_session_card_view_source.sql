-- Add 'card_view' to the source ENUM on projecthub_work_sessions
ALTER TABLE projecthub_work_sessions
    MODIFY COLUMN source ENUM('manual','drive_edit','board_view','timer','card_view') DEFAULT 'manual';
