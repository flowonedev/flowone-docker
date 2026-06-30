-- =====================================================
-- CALL HISTORY: Add 'declined' status
-- Migration: 067_call_history_declined_status.sql
--
-- Adds 'declined' to the status ENUM so rejected calls
-- can be saved and displayed as chat system messages.
-- =====================================================

ALTER TABLE call_history 
    MODIFY status ENUM('completed', 'missed', 'rejected', 'no_answer', 'cancelled', 'declined') 
    NOT NULL DEFAULT 'completed';

