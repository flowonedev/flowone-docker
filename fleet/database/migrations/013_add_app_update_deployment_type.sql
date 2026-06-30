-- Add 'app_update' to deployments type ENUM
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
    'app_update'
) NOT NULL;

