-- Scheduled Emails - Send later / schedule send feature
-- Stores emails that should be sent at a future date/time
-- Processed by cron every minute: process-scheduled-emails.php

CREATE TABLE IF NOT EXISTS scheduled_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id VARCHAR(36) NOT NULL UNIQUE,  -- UUID for frontend reference
    user_email VARCHAR(255) NOT NULL,
    
    -- Email content (full payload stored as JSON for flexibility)
    email_payload JSON NOT NULL,
    
    -- Schedule info
    scheduled_at TIMESTAMP NOT NULL,           -- When to send
    timezone VARCHAR(64) DEFAULT 'UTC',        -- User's timezone
    
    -- Status: pending, sending, sent, failed, cancelled
    status ENUM('pending', 'sending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
    
    -- Error tracking
    error_message TEXT DEFAULT NULL,
    attempts INT UNSIGNED DEFAULT 0,
    max_attempts INT UNSIGNED DEFAULT 3,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    
    -- Indexes
    INDEX idx_user_status (user_email, status),
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_schedule_id (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

