-- =====================================================
-- CALL HISTORY
-- Migration: 045_call_history.sql
--
-- Tracks voice/video call history per conversation
-- =====================================================

CREATE TABLE IF NOT EXISTS call_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    call_id VARCHAR(64) NOT NULL COMMENT 'Unique call identifier from signaling',
    conversation_id INT UNSIGNED NOT NULL COMMENT 'Chat conversation this call belongs to',
    initiated_by INT UNSIGNED NOT NULL COMMENT 'Colleague ID who started the call',
    
    -- Call details
    call_type ENUM('voice', 'video') NOT NULL DEFAULT 'voice',
    status ENUM('completed', 'missed', 'rejected', 'no_answer', 'cancelled') NOT NULL DEFAULT 'completed',
    
    -- Timing
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at TIMESTAMP NULL COMMENT 'When the call was answered',
    ended_at TIMESTAMP NULL COMMENT 'When the call ended',
    duration_seconds INT UNSIGNED DEFAULT 0 COMMENT 'Call duration in seconds (after answer)',
    
    -- Participants (JSON array of colleague emails who participated)
    participants JSON DEFAULT NULL,
    
    -- Screen share occurred during the call
    had_screen_share TINYINT(1) DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_call (call_id),
    INDEX idx_conversation (conversation_id, started_at DESC),
    INDEX idx_initiator (initiated_by),
    INDEX idx_status (status),
    INDEX idx_started (started_at DESC),
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

