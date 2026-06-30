-- Email Queue System for Bulk Mailings
-- Handles rate-limited sending (100/hour, 500/day) with campaign tracking

-- ============================================
-- EMAIL CAMPAIGNS - Groups emails from same send
-- ============================================
CREATE TABLE IF NOT EXISTS email_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(36) NOT NULL UNIQUE,  -- UUID for external reference
    user_email VARCHAR(255) NOT NULL,
    
    -- Email content
    subject VARCHAR(500) NOT NULL,
    body_html LONGTEXT,
    body_text TEXT,
    from_name VARCHAR(255) DEFAULT NULL,
    attachments JSON DEFAULT NULL,  -- Array of attachment info
    
    -- Reply/forward context
    in_reply_to VARCHAR(255) DEFAULT NULL,
    `references` TEXT DEFAULT NULL,
    
    -- Tracking options
    track_read TINYINT(1) DEFAULT 1,
    
    -- Progress tracking
    total_recipients INT UNSIGNED DEFAULT 0,
    sent_count INT UNSIGNED DEFAULT 0,
    failed_count INT UNSIGNED DEFAULT 0,
    
    -- Status: pending, processing, completed, paused, cancelled
    status ENUM('pending', 'processing', 'completed', 'paused', 'cancelled') DEFAULT 'pending',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    
    -- Indexes
    INDEX idx_user_status (user_email, status),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EMAIL QUEUE - Individual emails to send
-- ============================================
CREATE TABLE IF NOT EXISTS email_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(36) NOT NULL,
    
    -- Recipient info
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255) DEFAULT NULL,
    recipient_type ENUM('to', 'cc', 'bcc') DEFAULT 'to',
    
    -- Status: pending, sending, sent, failed, rate_limited
    status ENUM('pending', 'sending', 'sent', 'failed', 'rate_limited') DEFAULT 'pending',
    
    -- Retry tracking
    attempts INT UNSIGNED DEFAULT 0,
    max_attempts INT UNSIGNED DEFAULT 3,
    
    -- Scheduling
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    
    -- Error tracking
    error_message TEXT DEFAULT NULL,
    last_attempt_at TIMESTAMP NULL,
    
    -- Indexes
    INDEX idx_campaign (campaign_id),
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_recipient (recipient_email),
    
    -- Foreign key (soft - campaign_id references email_campaigns.campaign_id)
    CONSTRAINT fk_queue_campaign FOREIGN KEY (campaign_id) 
        REFERENCES email_campaigns(campaign_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- EMAIL RATE LIMITS - Per-user rate tracking
-- ============================================
CREATE TABLE IF NOT EXISTS email_rate_limits (
    user_email VARCHAR(255) PRIMARY KEY,
    
    -- Hourly limit tracking (100/hour)
    hourly_count INT UNSIGNED DEFAULT 0,
    hourly_reset_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Daily limit tracking (500/day)
    daily_count INT UNSIGNED DEFAULT 0,
    daily_reset_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Last activity
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_hourly_reset (hourly_reset_at),
    INDEX idx_daily_reset (daily_reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CAMPAIGN ACTIVITY LOG - For detailed tracking
-- ============================================
CREATE TABLE IF NOT EXISTS email_campaign_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id VARCHAR(36) NOT NULL,
    
    -- Event type: queued, sent, failed, paused, resumed, completed
    event_type VARCHAR(50) NOT NULL,
    
    -- Event details
    recipient_email VARCHAR(255) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_campaign_time (campaign_id, created_at),
    
    CONSTRAINT fk_log_campaign FOREIGN KEY (campaign_id) 
        REFERENCES email_campaigns(campaign_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

