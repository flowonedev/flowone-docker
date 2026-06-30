-- Add new service status columns to server_health for services added to deployment
-- OpenDKIM, OpenDMARC, ClamAV, PowerDNS, coTURN, LiveKit, stunnel

ALTER TABLE server_health
    ADD COLUMN IF NOT EXISTS opendkim_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER spamassassin_status,
    ADD COLUMN IF NOT EXISTS opendmarc_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER opendkim_status,
    ADD COLUMN IF NOT EXISTS clamav_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER opendmarc_status,
    ADD COLUMN IF NOT EXISTS pdns_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER clamav_status,
    ADD COLUMN IF NOT EXISTS coturn_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER pdns_status,
    ADD COLUMN IF NOT EXISTS livekit_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER coturn_status,
    ADD COLUMN IF NOT EXISTS stunnel_status ENUM('running', 'stopped', 'error', 'unknown') DEFAULT 'unknown' AFTER livekit_status;
