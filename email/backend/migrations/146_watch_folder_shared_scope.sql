-- Migration 146: Add local_watch source to work sessions

ALTER TABLE projecthub_work_sessions
    MODIFY COLUMN source ENUM('manual','drive_edit','board_view','timer','card_view','website_work','portal_call','calendar_event','local_watch') DEFAULT 'manual';
