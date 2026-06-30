-- Add 'skipped_unsubscribed' to email_queue status ENUM
-- Required for the unsubscribe-check feature added to campaign processing
ALTER TABLE email_queue
    MODIFY COLUMN status ENUM('pending', 'sending', 'sent', 'failed', 'rate_limited', 'skipped_unsubscribed') DEFAULT 'pending';
