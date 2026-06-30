-- Add email tracking triggers to CRM automation rules
ALTER TABLE crm_automation_rules MODIFY trigger_type ENUM(
    'deal_stage_idle','deal_stage_changed','client_health_low',
    'invoice_overdue','no_contact_days','deal_won','deal_lost',
    'task_changed','board_closed','moodboard_ready',
    'time_spent_reached','colleague_sick_status',
    'drive_folder_permission_changed',
    'email_opened','email_link_clicked'
) NOT NULL;

-- Widen target_id to support string-based composite keys (email tracking uses tracking_id:recipient)
ALTER TABLE crm_automation_log MODIFY target_id VARCHAR(255) NOT NULL;
