-- Out of Office Auto-Reply Tracking
-- Tracks which senders have already received an auto-reply to prevent duplicates
-- Auto-replies are limited to once per sender per OOO period

-- ============================================
-- AUTO REPLY TRACKING
-- ============================================
CREATE TABLE IF NOT EXISTS ooo_auto_reply_tracking (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL COMMENT 'The user who has OOO enabled',
    sender_email VARCHAR(255) NOT NULL COMMENT 'The sender who received the auto-reply',
    
    -- Original email reference
    original_message_id VARCHAR(255) DEFAULT NULL COMMENT 'Message-ID of the email that triggered reply',
    original_subject VARCHAR(500) DEFAULT NULL COMMENT 'Subject of original email',
    
    -- Auto-reply tracking
    replied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When auto-reply was sent',
    reply_message_id VARCHAR(255) DEFAULT NULL COMMENT 'Message-ID of the auto-reply sent',
    
    -- OOO period tracking (to reset when new OOO period starts)
    ooo_period_start DATETIME NOT NULL COMMENT 'Start of the OOO period when reply was sent',
    
    -- Indexes for fast lookup
    UNIQUE KEY idx_user_sender_period (user_email, sender_email, ooo_period_start),
    INDEX idx_user_email (user_email),
    INDEX idx_replied_at (replied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUTO REPLY LOG - For debugging/audit
-- ============================================
CREATE TABLE IF NOT EXISTS ooo_auto_reply_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    
    -- Event type: sent, skipped_duplicate, skipped_no_reply, skipped_outside_schedule, failed
    event_type VARCHAR(50) NOT NULL,
    
    -- Details
    sender_email VARCHAR(255) DEFAULT NULL,
    original_subject VARCHAR(500) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_time (user_email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

