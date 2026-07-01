-- Add 'docker_update' to deployments type ENUM (Phase D: native->docker path).
-- A Docker Update rolls one or more already-running services to a chosen image
-- tag: re-renders the per-host .env with the tag, then `docker compose pull` +
-- `up -d --no-deps` the selected service(s). Runs on an already-deployed box
-- (the compose equivalent of the retired panel_update/email_update). Old enum
-- strings stay listed for historical rows.
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
    'docker_provision',
    'docker_update'
) NOT NULL;
