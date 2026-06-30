-- Migration: Enhance boardpro_email_rules with card creation settings
-- Adds: title template, type detection, body-to-checklist, auto-link, auto-attach, run tracking

-- Card title template with variable support e.g. "FEEDBACK - {type}"
ALTER TABLE boardpro_email_rules
    ADD COLUMN card_title_template VARCHAR(255) DEFAULT '' AFTER auto_assign_to;

-- Type detection: JSON array of {label, keywords} for {type} variable in title
ALTER TABLE boardpro_email_rules
    ADD COLUMN type_categories JSON DEFAULT NULL AFTER card_title_template;

-- Default type when no category keywords match
ALTER TABLE boardpro_email_rules
    ADD COLUMN type_default VARCHAR(50) DEFAULT 'General' AFTER type_categories;

-- How to handle email body: none, description, checklist, both
ALTER TABLE boardpro_email_rules
    ADD COLUMN body_handling VARCHAR(20) DEFAULT 'none' AFTER type_default;

-- Checklist name when body_handling is checklist or both
ALTER TABLE boardpro_email_rules
    ADD COLUMN checklist_title VARCHAR(100) DEFAULT '' AFTER body_handling;

-- Auto-link the triggering email to the created card
ALTER TABLE boardpro_email_rules
    ADD COLUMN auto_link_email TINYINT(1) DEFAULT 1 AFTER checklist_title;

-- Auto-attach email screenshots/files to the created card
ALTER TABLE boardpro_email_rules
    ADD COLUMN auto_attach_files TINYINT(1) DEFAULT 1 AFTER auto_link_email;

-- Track how many times this rule has fired
ALTER TABLE boardpro_email_rules
    ADD COLUMN run_count INT UNSIGNED DEFAULT 0 AFTER auto_attach_files;

-- Track when this rule last fired
ALTER TABLE boardpro_email_rules
    ADD COLUMN last_run_at DATETIME DEFAULT NULL AFTER run_count;
