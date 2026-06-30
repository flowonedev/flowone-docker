-- =====================================================
-- EMAIL CONTENT TEMPLATES (Blocks)
-- Migration: 039_email_templates.sql
--
-- Stores reusable email content blocks/templates
-- that can be inserted into email body during compose.
-- Templates can be personal or shared with the team.
-- =====================================================

CREATE TABLE IF NOT EXISTS email_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Owner
    created_by VARCHAR(255) NOT NULL COMMENT 'Email of the creator',
    organization_domain VARCHAR(255) NOT NULL COMMENT 'Domain for team sharing',
    
    -- Template info
    name VARCHAR(200) NOT NULL COMMENT 'Template display name',
    description VARCHAR(500) DEFAULT NULL COMMENT 'Optional description',
    category VARCHAR(50) NOT NULL DEFAULT 'custom' COMMENT 'Category: text, media, layout, cta, custom',
    icon VARCHAR(50) DEFAULT 'dashboard_customize' COMMENT 'Google Material Symbol icon name',
    
    -- Content
    html_content MEDIUMTEXT NOT NULL COMMENT 'HTML content of the template block',
    thumbnail TEXT DEFAULT NULL COMMENT 'Base64 thumbnail preview or CSS gradient',
    
    -- Sharing
    is_shared TINYINT(1) DEFAULT 0 COMMENT 'Shared with entire organization',
    
    -- Ordering
    sort_order INT DEFAULT 0,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_creator (created_by),
    INDEX idx_org (organization_domain),
    INDEX idx_shared (organization_domain, is_shared),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

