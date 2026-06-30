-- Add member_type to board members for guest/external user support
ALTER TABLE webmail_board_members
  ADD COLUMN member_type ENUM('internal','guest') NOT NULL DEFAULT 'internal' AFTER role;
