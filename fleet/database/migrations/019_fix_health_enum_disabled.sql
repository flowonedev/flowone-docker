-- Migration 019: Add 'disabled' to service status ENUM columns
-- The fleet agent sends 'disabled' for inactive services (systemctl exit code 4)
-- but the ENUM only had running/stopped/error/unknown, causing INSERT failures

ALTER TABLE server_health MODIFY COLUMN openlitespeed_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN mariadb_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN postfix_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN dovecot_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN fail2ban_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN firewalld_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN fleet_agent_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN redis_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN meilisearch_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN spamassassin_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN collab_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
ALTER TABLE server_health MODIFY COLUMN mailsync_status ENUM('running', 'stopped', 'error', 'unknown', 'disabled') DEFAULT 'unknown';
