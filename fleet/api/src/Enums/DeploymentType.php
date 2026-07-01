<?php

namespace FleetManager\Api\Enums;

/**
 * Deployment type constants for server provisioning
 */
class DeploymentType
{
    /** Full server provisioning - install everything from scratch */
    public const FULL_PROVISION = 'full_provision';
    
    /** Config only - apply configuration templates without installing packages */
    public const CONFIG_ONLY = 'config_only';
    
    /** Packages + config - install packages and apply configs, but skip app deployment */
    public const PACKAGES_CONFIG = 'packages_config';

    // NOTE: PANEL_UPDATE ('panel_update') and EMAIL_UPDATE ('email_update') were
    // retired in the native->docker migration (Phase D). They were dead stubs
    // (handleOtherDeployment() only inserted a pending row, nothing executed).
    // Real per-app updates flow through APP_UPDATE -> deployAppUpdate(); the
    // Docker deploy updates a single service with `docker compose pull/up`.
    // The DB `deployments.type` enum still lists the old strings for historical
    // rows; they are simply no longer offered or handled.

    /** Update Fleet Agent only */
    public const AGENT_UPDATE = 'agent_update';
    
    /** Apply configuration changes */
    public const CONFIG_UPDATE = 'config_update';
    
    /** Renew SSL certificates */
    public const SSL_RENEW = 'ssl_renew';

    /** App Update - update application code only, preserve configs */
    public const APP_UPDATE = 'app_update';

    /**
     * Docker Provision - deploy the whole per-server stack via Docker Compose
     * (Phase D). Renders the per-host .env, ships docker-compose.yml, pulls the
     * pre-built images and brings the stack up, obtains SSL + seeds a default
     * login mailbox. Runs IN PARALLEL with native FULL_PROVISION during cutover.
     */
    public const DOCKER_PROVISION = 'docker_provision';

    /** Wipe server - remove installed software */
    public const WIPE = 'wipe';

    /**
     * Get all deployment types
     */
    public static function all(): array
    {
        return [
            self::FULL_PROVISION,
            self::CONFIG_ONLY,
            self::PACKAGES_CONFIG,
            self::AGENT_UPDATE,
            self::CONFIG_UPDATE,
            self::SSL_RENEW,
            self::APP_UPDATE,
            self::DOCKER_PROVISION,
            self::WIPE,
        ];
    }

    /**
     * Get deployment type label for display
     */
    public static function label(string $type): string
    {
        return match ($type) {
            self::FULL_PROVISION => 'Full Provision',
            self::CONFIG_ONLY => 'Config Only',
            self::PACKAGES_CONFIG => 'Packages + Config',
            self::AGENT_UPDATE => 'Agent Update',
            self::CONFIG_UPDATE => 'Config Update',
            self::SSL_RENEW => 'SSL Renewal',
            self::APP_UPDATE => 'App Update (Code Only)',
            self::DOCKER_PROVISION => 'Docker Provision',
            self::WIPE => 'Wipe Server',
            default => $type,
        };
    }

    /**
     * Get deployment type description
     */
    public static function description(string $type): string
    {
        return match ($type) {
            self::FULL_PROVISION => 'Complete server setup: install all packages, apply configs, deploy apps, setup SSL',
            self::CONFIG_ONLY => 'Apply configuration templates only, restart affected services',
            self::PACKAGES_CONFIG => 'Install required packages and apply configurations (no app deployment)',
            self::AGENT_UPDATE => 'Update the Fleet Agent to the latest version',
            self::CONFIG_UPDATE => 'Push configuration changes to the server',
            self::SSL_RENEW => 'Renew SSL certificates for all domains',
            self::APP_UPDATE => 'Update application code without touching server configs (preserves config.local.php, .env)',
            self::DOCKER_PROVISION => 'Deploy the full stack via Docker Compose: render per-host .env, pull images, bring the stack up, obtain SSL and seed a default login mailbox',
            self::WIPE => 'Remove all installed software and configs from the server',
            default => '',
        };
    }

    /**
     * Check if deployment type requires a blueprint
     */
    public static function requiresBlueprint(string $type): bool
    {
        return in_array($type, [
            self::FULL_PROVISION,
            self::CONFIG_ONLY,
            self::PACKAGES_CONFIG,
        ]);
    }

    /**
     * Check if deployment type requires server to be active
     */
    public static function requiresActiveServer(string $type): bool
    {
        return in_array($type, [
            self::CONFIG_ONLY,
            self::PACKAGES_CONFIG,
            self::AGENT_UPDATE,
            self::CONFIG_UPDATE,
            self::SSL_RENEW,
            self::APP_UPDATE,
            self::WIPE,
        ]);
    }

    /**
     * Get apps that can be updated with APP_UPDATE
     */
    public static function getUpdatableApps(): array
    {
        return [
            'panel' => [
                'id' => 'panel',
                'label' => 'VPS Admin Panel',
                'description' => 'Admin panel PHP/API files',
                'icon' => 'dashboard',
            ],
            'email' => [
                'id' => 'email',
                'label' => 'MailFlow Email App',
                'description' => 'Email app frontend and backend',
                'icon' => 'mail',
            ],
            'agent' => [
                'id' => 'agent',
                'label' => 'Fleet Agent',
                'description' => 'Fleet Manager agent daemon',
                'icon' => 'smart_toy',
            ],
        ];
    }
}

