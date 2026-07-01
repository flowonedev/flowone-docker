-- Add 'docker_provision' to deployments type ENUM (Phase D: native->docker path).
-- The Docker deploy renders a per-host .env + ships docker-compose.yml, pulls the
-- pre-built images and brings the stack up (DockerProvisioningService), running IN
-- PARALLEL with the native full_provision during cutover. Retired panel_update/
-- email_update stay listed for historical rows.
ALTER TABLE deployments
MODIFY COLUMN type ENUM(
    'full_provision',
    'config_only',
    'packages_config',
    'panel_update',
    'email_update',
    'agent_update',
    'config_update',
    'ssl_renew',
    'wipe',
    'app_update',
    'docker_provision'
) NOT NULL;
