-- Migration 086: Expanded Automation Engine + Board/MoodBoard state columns
-- Adds new trigger types, action types, target types, and missing board states

-- 1. Expand trigger_type ENUM on crm_automation_rules
ALTER TABLE crm_automation_rules MODIFY COLUMN trigger_type ENUM(
    'deal_stage_idle',
    'deal_stage_changed',
    'client_health_low',
    'invoice_overdue',
    'no_contact_days',
    'deal_won',
    'deal_lost',
    'task_changed',
    'board_closed',
    'moodboard_ready',
    'time_threshold_reached',
    'colleague_status_changed',
    'drive_folder_permission_changed'
) NOT NULL;

-- 2. Expand action_type ENUM
ALTER TABLE crm_automation_rules MODIFY COLUMN action_type ENUM(
    'create_reminder',
    'send_email',
    'create_invoice_draft',
    'move_deal_stage',
    'notify_user',
    'start_sequence',
    'assign_task',
    'send_chat_message',
    'reassign_deals'
) NOT NULL;

-- 3. Expand target_type in log table
ALTER TABLE crm_automation_log MODIFY COLUMN target_type ENUM(
    'deal',
    'client',
    'invoice',
    'task',
    'board',
    'moodboard',
    'colleague',
    'drive_folder'
) NOT NULL;

-- 4. Add is_closed + closed_at to webmail_boards
ALTER TABLE webmail_boards
    ADD COLUMN is_closed TINYINT(1) DEFAULT 0 AFTER archived,
    ADD COLUMN closed_at DATETIME DEFAULT NULL AFTER is_closed,
    ADD COLUMN closed_by VARCHAR(255) DEFAULT NULL AFTER closed_at;

-- 5. Add is_ready + ready_at to mood_boards
ALTER TABLE mood_boards
    ADD COLUMN is_ready TINYINT(1) DEFAULT 0 AFTER archived,
    ADD COLUMN ready_at DATETIME DEFAULT NULL AFTER is_ready,
    ADD COLUMN marked_ready_by VARCHAR(255) DEFAULT NULL AFTER ready_at;

