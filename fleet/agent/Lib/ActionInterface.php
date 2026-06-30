<?php

namespace FleetManager\Agent\Lib;

/**
 * Interface for all agent actions
 */
interface ActionInterface
{
    /**
     * Get the namespace for this action (e.g., 'extractor', 'system')
     */
    public function getNamespace(): string;
    
    /**
     * Execute an action method
     * 
     * @param string $method The method to execute
     * @param array $params Parameters for the method
     * @param string $actor Who initiated the action
     * @return array Result with 'success' key
     */
    public function execute(string $method, array $params, string $actor): array;
}

