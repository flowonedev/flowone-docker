<?php
/**
 * Fleet Manager Agent Configuration
 */

return [
    // Fleet Panel configuration (for heartbeat)
    'panel' => [
        'url' => getenv('FLEET_PANEL_URL') ?: 'https://fleet.devcon1.hu',
        'agent_token' => getenv('FLEET_AGENT_TOKEN') ?: '',  // Set in environment or replace
    ],

    // Socket configuration
    'socket' => [
        'path' => '/run/fleet-manager/agent.sock',
        'permissions' => 0660,
        'group' => 'www-data',  // Allow www-data to connect
    ],
    
    // Paths
    'paths' => [
        'base' => '/var/www/vps-fleet',
        'token_file' => '/var/www/vps-fleet/var/agent.token',
        'log_file' => '/var/log/fleet-manager/agent.log',
    ],
    
    // Security
    'security' => [
        'require_auth_token' => true,
    ],
    
    // Logging
    'logging' => [
        'level' => 'info',  // debug, info, warning, error
        'max_size' => 10 * 1024 * 1024,  // 10MB
        'max_files' => 5,
    ],
    
    // Extraction settings
    'extraction' => [
        'max_file_size' => 5 * 1024 * 1024,  // 5MB per file
        'timeout' => 300,  // 5 minutes
    ],

    // Heartbeat settings
    'heartbeat' => [
        'interval' => 30,  // seconds
        'timeout' => 30,   // API timeout
    ],
];

