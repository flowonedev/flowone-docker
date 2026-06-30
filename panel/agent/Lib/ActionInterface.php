<?php
/**
 * Action Interface
 * 
 * All agent actions must implement this interface.
 * Ensures consistent structure for all system operations.
 */

namespace VpsAdmin\Agent\Lib;

interface ActionInterface
{
    /**
     * Get the action namespace (e.g., 'service', 'vhost', 'ssl')
     */
    public function getNamespace(): string;

    /**
     * Get available methods for this action
     */
    public function getMethods(): array;

    /**
     * Execute an action method
     * 
     * @param string $method Method name to execute
     * @param array $params Parameters for the method
     * @param string $actor The user/system performing the action
     * @return array Result with 'success', 'data', and optionally 'error'
     */
    public function execute(string $method, array $params, string $actor): array;

    /**
     * Check if a method requires a backup before execution
     */
    public function requiresBackup(string $method): bool;
}

