-- Track the target server's Linux distro + version (e.g. "Ubuntu 24.04.1 LTS").
-- Surfaced on the dashboard so operators can see the OS before/while deploying
-- and avoid installing onto an unsupported distro.
ALTER TABLE servers
    ADD COLUMN os_info VARCHAR(100) NULL AFTER email_app_version;
