-- Add email_attachment to source_type ENUM
-- Required for attachment indexing feature

ALTER TABLE universal_search_index
MODIFY COLUMN source_type ENUM('email', 'email_attachment', 'calendar_event', 'drive_file', 'drive_folder', 'board', 'card', 'checklist_item', 'todo', 'client', 'contact', 'collab_doc') NOT NULL;

