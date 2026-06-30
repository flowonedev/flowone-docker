-- Add 'cancelled' status to portal_calls
ALTER TABLE portal_calls 
    MODIFY COLUMN status ENUM('waiting', 'active', 'ended', 'cancelled') DEFAULT 'waiting';
