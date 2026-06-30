-- Phase 3: Git Deployment
-- Run: mysql -u root -p < database/migrate_phase3_git.sql

USE vpsadmin;

-- Git repositories linked to sites
CREATE TABLE IF NOT EXISTS git_deployments (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    repo_url VARCHAR(500) NOT NULL,
    branch VARCHAR(100) DEFAULT 'main',
    deploy_path VARCHAR(500),
    auto_deploy BOOLEAN DEFAULT FALSE,
    webhook_secret VARCHAR(100),
    ssh_key_id INT UNSIGNED,
    last_commit VARCHAR(40),
    last_commit_message TEXT,
    last_deploy_at TIMESTAMP NULL,
    last_deploy_status ENUM('success', 'failed', 'pending') DEFAULT 'pending',
    deploy_script TEXT,
    env_vars JSON,
    pre_deploy_script TEXT,
    post_deploy_script TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_domain (domain),
    INDEX idx_status (last_deploy_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deployment history
CREATE TABLE IF NOT EXISTS git_deploy_history (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    deployment_id INT UNSIGNED NOT NULL,
    commit_hash VARCHAR(40),
    commit_message TEXT,
    commit_author VARCHAR(255),
    status ENUM('started', 'success', 'failed', 'rolled_back') NOT NULL,
    log MEDIUMTEXT,
    duration_seconds INT,
    triggered_by ENUM('manual', 'webhook', 'schedule') DEFAULT 'manual',
    actor VARCHAR(100),
    rollback_from BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deployment_id) REFERENCES git_deployments(id) ON DELETE CASCADE,
    INDEX idx_deployment (deployment_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SSH Keys for Git
CREATE TABLE IF NOT EXISTS git_ssh_keys (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    public_key TEXT NOT NULL,
    private_key_path VARCHAR(500) NOT NULL,
    fingerprint VARCHAR(100),
    key_type VARCHAR(20) DEFAULT 'ed25519',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Phase 3 migration complete: git deployment tables created' AS status;

