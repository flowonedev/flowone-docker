-- CRM Pro Tables
-- Migration: All crm_ tables for the CRM Pro addon (invoicing, deals, tags, reminders, call log, meeting notes)
-- These tables are always created (even when addon is disabled) so data persists across toggle cycles.

-- =========================================================================
-- Phase 5: Invoicing System
-- =========================================================================

-- Invoices generated for clients
CREATE TABLE IF NOT EXISTS crm_invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL COMMENT 'Owner of this invoice',
    invoice_number VARCHAR(50) NOT NULL COMMENT 'Auto: INV-2026-001',
    status ENUM('draft', 'sent', 'viewed', 'partial', 'paid', 'overdue', 'cancelled', 'refunded') DEFAULT 'draft',
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    -- Amounts
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(5,2) DEFAULT 0 COMMENT 'Percentage',
    tax_amount DECIMAL(15,2) DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    total DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'HUF',
    -- Payment
    paid_amount DECIMAL(15,2) DEFAULT 0,
    paid_at DATETIME DEFAULT NULL,
    payment_method VARCHAR(50) DEFAULT NULL COMMENT 'bank_transfer, cash, card, paypal',
    payment_reference VARCHAR(255) DEFAULT NULL,
    -- Config
    notes TEXT DEFAULT NULL COMMENT 'Shown on invoice',
    internal_notes TEXT DEFAULT NULL COMMENT 'Not shown to client',
    is_recurring TINYINT(1) DEFAULT 0,
    recurrence_interval ENUM('weekly', 'monthly', 'quarterly', 'yearly') DEFAULT NULL,
    recurrence_end_date DATE DEFAULT NULL,
    parent_invoice_id INT UNSIGNED DEFAULT NULL COMMENT 'For recurring chain',
    -- Integration
    portal_document_id INT UNSIGNED DEFAULT NULL COMMENT 'Link to portal doc for delivery',
    drive_file_id INT DEFAULT NULL COMMENT 'Generated PDF stored in Drive',
    board_card_id INT DEFAULT NULL COMMENT 'Linked board milestone',
    -- Tracking
    sent_at DATETIME DEFAULT NULL,
    viewed_at DATETIME DEFAULT NULL,
    reminder_count INT DEFAULT 0,
    last_reminder_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_invoice_number (user_email, invoice_number),
    INDEX idx_client (client_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX idx_recurring (is_recurring, recurrence_interval),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Line items on invoices
CREATE TABLE IF NOT EXISTS crm_invoice_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 1,
    unit VARCHAR(50) DEFAULT NULL COMMENT 'hours, pieces, months, etc.',
    unit_price DECIMAL(15,2) NOT NULL,
    tax_rate DECIMAL(5,2) DEFAULT NULL COMMENT 'Per-item override',
    total DECIMAL(15,2) NOT NULL,
    sort_order INT DEFAULT 0,
    board_card_id INT DEFAULT NULL COMMENT 'Linked to board card/milestone',

    INDEX idx_invoice (invoice_id),
    FOREIGN KEY (invoice_id) REFERENCES crm_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment records (supports partial payments)
CREATE TABLE IF NOT EXISTS crm_invoice_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    reference VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    recorded_by VARCHAR(255) NOT NULL COMMENT 'User who recorded the payment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_invoice (invoice_id),
    INDEX idx_date (payment_date),
    FOREIGN KEY (invoice_id) REFERENCES crm_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expense tracking per client
CREATE TABLE IF NOT EXISTS crm_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'HUF',
    expense_date DATE NOT NULL,
    category VARCHAR(100) DEFAULT NULL COMMENT 'software, hosting, travel, etc.',
    receipt_drive_file_id INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_client (client_id),
    INDEX idx_date (expense_date),
    INDEX idx_user (user_email),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Phase 6: Pipeline, Tags, Custom Fields, Reminders, Call Log, Meeting Notes
-- =========================================================================

-- Sales pipeline / deal tracking
CREATE TABLE IF NOT EXISTS crm_deals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT DEFAULT NULL,
    pipeline_stage ENUM('lead', 'contacted', 'proposal', 'negotiation', 'won', 'lost') DEFAULT 'lead',
    expected_value DECIMAL(15,2) DEFAULT NULL,
    currency VARCHAR(3) DEFAULT 'HUF',
    probability INT DEFAULT 50 COMMENT '0-100 percent',
    expected_close_date DATE DEFAULT NULL,
    actual_close_date DATE DEFAULT NULL,
    lost_reason TEXT DEFAULT NULL,
    contact_id INT UNSIGNED DEFAULT NULL COMMENT 'Primary contact for this deal',
    assigned_to VARCHAR(255) DEFAULT NULL COMMENT 'Team member email',
    board_id INT DEFAULT NULL COMMENT 'Linked board',
    invoice_id INT UNSIGNED DEFAULT NULL COMMENT 'Linked invoice when won',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_client (client_id),
    INDEX idx_stage (pipeline_stage),
    INDEX idx_assigned (assigned_to),
    INDEX idx_close_date (expected_close_date),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags for clients
CREATE TABLE IF NOT EXISTS crm_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL COMMENT 'Tag belongs to this user workspace',
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#6366f1' COMMENT 'Hex color',
    tag_group VARCHAR(100) DEFAULT NULL COMMENT 'industry, priority, source, etc.',

    UNIQUE KEY unique_tag (user_email, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tag assignments (many-to-many between clients and tags)
CREATE TABLE IF NOT EXISTS crm_tag_assignments (
    client_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (client_id, tag_id),
    INDEX idx_tag (tag_id),
    INDEX idx_client (client_id),
    FOREIGN KEY (tag_id) REFERENCES crm_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom field definitions (user-defined fields for clients)
CREATE TABLE IF NOT EXISTS crm_custom_field_definitions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    field_type ENUM('text', 'number', 'date', 'select', 'url', 'email', 'phone') NOT NULL,
    field_options JSON DEFAULT NULL COMMENT 'For select type: ["Option A", "Option B"]',
    is_required TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,

    UNIQUE KEY unique_field (user_email, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom field values per client
CREATE TABLE IF NOT EXISTS crm_custom_field_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NOT NULL,
    field_value TEXT DEFAULT NULL,

    UNIQUE KEY unique_value (client_id, field_id),
    INDEX idx_client (client_id),
    INDEX idx_field (field_id),
    FOREIGN KEY (field_id) REFERENCES crm_custom_field_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Follow-up reminders
CREATE TABLE IF NOT EXISTS crm_reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT DEFAULT NULL,
    remind_at DATETIME NOT NULL,
    is_completed TINYINT(1) DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    is_recurring TINYINT(1) DEFAULT 0,
    recurrence_interval ENUM('daily', 'weekly', 'biweekly', 'monthly') DEFAULT NULL,
    contact_id INT UNSIGNED DEFAULT NULL COMMENT 'Specific contact to follow up with',
    deal_id INT UNSIGNED DEFAULT NULL COMMENT 'Linked deal',
    notification_sent TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user_remind (user_email, remind_at),
    INDEX idx_client (client_id),
    INDEX idx_pending (is_completed, remind_at),
    INDEX idx_deal (deal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Call log (phone calls, not video calls)
CREATE TABLE IF NOT EXISTS crm_call_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    contact_id INT UNSIGNED DEFAULT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    duration_minutes INT DEFAULT NULL,
    outcome ENUM('connected', 'no_answer', 'voicemail', 'busy', 'callback_requested') DEFAULT 'connected',
    notes TEXT DEFAULT NULL,
    follow_up_reminder_id INT UNSIGNED DEFAULT NULL COMMENT 'Auto-created reminder',
    call_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_client (client_id),
    INDEX idx_user (user_email),
    INDEX idx_date (call_date),
    INDEX idx_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Meeting notes
CREATE TABLE IF NOT EXISTS crm_meeting_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    title VARCHAR(500) NOT NULL,
    content TEXT DEFAULT NULL,
    meeting_date DATETIME NOT NULL,
    attendees JSON DEFAULT NULL COMMENT '["email1", "email2"]',
    action_items JSON DEFAULT NULL COMMENT '[{"text": "...", "assignee": "...", "done": false}]',
    calendar_event_id INT DEFAULT NULL COMMENT 'Linked calendar event',
    portal_call_id INT UNSIGNED DEFAULT NULL COMMENT 'Linked portal call',
    deal_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_client (client_id),
    INDEX idx_date (meeting_date),
    INDEX idx_user (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

