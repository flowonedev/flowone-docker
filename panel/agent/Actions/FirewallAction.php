<?php
/**
 * Firewall Action Handler
 * 
 * Manages FirewallD zones, services, and rules.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class FirewallAction extends BaseAction
{
    public function getNamespace(): string
    {
        return 'firewall';
    }

    public function getMethods(): array
    {
        return ['status', 'zones', 'zone', 'services', 'ports', 'addService', 'removeService', 
                'addPort', 'removePort', 'richRules', 'addRichRule', 'removeRichRule', 'reload'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['addService', 'removeService', 'addPort', 'removePort', 
                                   'addRichRule', 'removeRichRule']);
    }

    /**
     * Get firewall status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        $result = $this->execCommand('firewall-cmd', ['--state']);
        $running = trim($result['output']) === 'running';

        $defaultZone = '';
        if ($running) {
            $zoneResult = $this->execCommand('firewall-cmd', ['--get-default-zone']);
            $defaultZone = trim($zoneResult['output']);
        }

        return $this->success([
            'running' => $running,
            'default_zone' => $defaultZone,
        ]);
    }

    /**
     * List all zones
     */
    protected function actionZones(array $params, string $actor): array
    {
        $result = $this->execCommand('firewall-cmd', ['--get-zones']);
        
        if (!$result['success']) {
            return $this->error('Failed to get zones: ' . $result['output']);
        }

        $zoneNames = array_filter(explode(' ', trim($result['output'])));
        $zones = [];

        foreach ($zoneNames as $zoneName) {
            $zones[] = $this->getZoneDetails($zoneName);
        }

        return $this->success(['zones' => $zones]);
    }

    /**
     * Get zone details
     */
    protected function actionZone(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Zone name is required');
        }

        $name = $params['name'];
        
        if (!Validator::zoneName($name)) {
            return $this->error('Invalid zone name');
        }

        $details = $this->getZoneDetails($name);
        
        if (!$details) {
            return $this->error("Zone not found: {$name}");
        }

        return $this->success(['zone' => $details]);
    }

    /**
     * List services in a zone
     */
    protected function actionServices(array $params, string $actor): array
    {
        $zone = $params['zone'] ?? null;
        
        $args = ['--list-services'];
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args = ['--zone=' . $zone, '--list-services'];
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if (!$result['success']) {
            return $this->error('Failed to list services: ' . $result['output']);
        }

        $services = array_filter(explode(' ', trim($result['output'])));

        return $this->success([
            'zone' => $zone ?? 'default',
            'services' => $services,
        ]);
    }

    /**
     * List ports in a zone
     */
    protected function actionPorts(array $params, string $actor): array
    {
        $zone = $params['zone'] ?? null;
        
        $args = ['--list-ports'];
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args = ['--zone=' . $zone, '--list-ports'];
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if (!$result['success']) {
            return $this->error('Failed to list ports: ' . $result['output']);
        }

        $portStrings = array_filter(explode(' ', trim($result['output'])));
        $ports = [];

        foreach ($portStrings as $portStr) {
            if (preg_match('/^(\d+)(?:-(\d+))?\/(\w+)$/', $portStr, $m)) {
                $ports[] = [
                    'port' => (int)$m[1],
                    'port_end' => isset($m[2]) ? (int)$m[2] : null,
                    'protocol' => $m[3],
                    'raw' => $portStr,
                ];
            }
        }

        return $this->success([
            'zone' => $zone ?? 'default',
            'ports' => $ports,
        ]);
    }

    /**
     * Add a service to a zone
     */
    protected function actionAddService(array $params, string $actor): array
    {
        if (!isset($params['service'])) {
            return $this->error('Service name is required');
        }

        $service = $params['service'];
        $zone = $params['zone'] ?? null;
        $permanent = $params['permanent'] ?? true;

        $args = ['--add-service=' . $service];
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args[] = '--zone=' . $zone;
        }
        if ($permanent) {
            $args[] = '--permanent';
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if ($result['success']) {
            if ($permanent) {
                $this->execCommand('firewall-cmd', ['--reload']);
            }
            return $this->success([
                'service' => $service,
                'zone' => $zone ?? 'default',
                'permanent' => $permanent,
            ], "Service {$service} added");
        }

        return $this->error("Failed to add service: " . $result['output']);
    }

    /**
     * Remove a service from a zone
     */
    protected function actionRemoveService(array $params, string $actor): array
    {
        if (!isset($params['service'])) {
            return $this->error('Service name is required');
        }

        $service = $params['service'];
        $zone = $params['zone'] ?? null;
        $permanent = $params['permanent'] ?? true;

        $args = ['--remove-service=' . $service];
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args[] = '--zone=' . $zone;
        }
        if ($permanent) {
            $args[] = '--permanent';
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if ($result['success']) {
            if ($permanent) {
                $this->execCommand('firewall-cmd', ['--reload']);
            }
            return $this->success([
                'service' => $service,
                'zone' => $zone ?? 'default',
                'permanent' => $permanent,
            ], "Service {$service} removed");
        }

        return $this->error("Failed to remove service: " . $result['output']);
    }

    /**
     * Add a port to a zone
     */
    protected function actionAddPort(array $params, string $actor): array
    {
        if (!isset($params['port']) || !isset($params['protocol'])) {
            return $this->error('Port and protocol are required');
        }

        $port = (int)$params['port'];
        $protocol = strtolower($params['protocol']);
        $zone = $params['zone'] ?? null;
        $permanent = $params['permanent'] ?? true;

        if (!Validator::port($port)) {
            return $this->error('Invalid port number');
        }

        if (!in_array($protocol, ['tcp', 'udp'])) {
            return $this->error('Protocol must be tcp or udp');
        }

        $portSpec = "{$port}/{$protocol}";
        $args = ['--add-port=' . $portSpec];
        
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args[] = '--zone=' . $zone;
        }
        if ($permanent) {
            $args[] = '--permanent';
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if ($result['success']) {
            if ($permanent) {
                $this->execCommand('firewall-cmd', ['--reload']);
            }
            return $this->success([
                'port' => $port,
                'protocol' => $protocol,
                'zone' => $zone ?? 'default',
                'permanent' => $permanent,
            ], "Port {$portSpec} added");
        }

        return $this->error("Failed to add port: " . $result['output']);
    }

    /**
     * Remove a port from a zone
     */
    protected function actionRemovePort(array $params, string $actor): array
    {
        if (!isset($params['port']) || !isset($params['protocol'])) {
            return $this->error('Port and protocol are required');
        }

        $port = (int)$params['port'];
        $protocol = strtolower($params['protocol']);
        $zone = $params['zone'] ?? null;
        $permanent = $params['permanent'] ?? true;

        if (!Validator::port($port)) {
            return $this->error('Invalid port number');
        }

        $portSpec = "{$port}/{$protocol}";
        $args = ['--remove-port=' . $portSpec];
        
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args[] = '--zone=' . $zone;
        }
        if ($permanent) {
            $args[] = '--permanent';
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if ($result['success']) {
            if ($permanent) {
                $this->execCommand('firewall-cmd', ['--reload']);
            }
            return $this->success([
                'port' => $port,
                'protocol' => $protocol,
                'zone' => $zone ?? 'default',
                'permanent' => $permanent,
            ], "Port {$portSpec} removed");
        }

        return $this->error("Failed to remove port: " . $result['output']);
    }

    /**
     * List rich rules
     */
    protected function actionRichRules(array $params, string $actor): array
    {
        $zone = $params['zone'] ?? null;
        
        $args = ['--list-rich-rules'];
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args = ['--zone=' . $zone, '--list-rich-rules'];
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if (!$result['success']) {
            return $this->error('Failed to list rich rules: ' . $result['output']);
        }

        $rules = array_filter(explode("\n", trim($result['output'])));

        return $this->success([
            'zone' => $zone ?? 'default',
            'rules' => $rules,
        ]);
    }

    /**
     * Add a rich rule
     */
    protected function actionAddRichRule(array $params, string $actor): array
    {
        if (!isset($params['rule'])) {
            return $this->error('Rule is required');
        }

        $rule = $params['rule'];
        $zone = $params['zone'] ?? null;
        $permanent = $params['permanent'] ?? true;

        $args = ['--add-rich-rule=' . $rule];
        
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args[] = '--zone=' . $zone;
        }
        if ($permanent) {
            $args[] = '--permanent';
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if ($result['success']) {
            if ($permanent) {
                $this->execCommand('firewall-cmd', ['--reload']);
            }
            return $this->success([
                'rule' => $rule,
                'zone' => $zone ?? 'default',
                'permanent' => $permanent,
            ], "Rich rule added");
        }

        return $this->error("Failed to add rich rule: " . $result['output']);
    }

    /**
     * Remove a rich rule
     */
    protected function actionRemoveRichRule(array $params, string $actor): array
    {
        if (!isset($params['rule'])) {
            return $this->error('Rule is required');
        }

        $rule = $params['rule'];
        $zone = $params['zone'] ?? null;
        $permanent = $params['permanent'] ?? true;

        $args = ['--remove-rich-rule=' . $rule];
        
        if ($zone) {
            if (!Validator::zoneName($zone)) {
                return $this->error('Invalid zone name');
            }
            $args[] = '--zone=' . $zone;
        }
        if ($permanent) {
            $args[] = '--permanent';
        }

        $result = $this->execCommand('firewall-cmd', $args);
        
        if ($result['success']) {
            if ($permanent) {
                $this->execCommand('firewall-cmd', ['--reload']);
            }
            return $this->success([
                'rule' => $rule,
                'zone' => $zone ?? 'default',
                'permanent' => $permanent,
            ], "Rich rule removed");
        }

        return $this->error("Failed to remove rich rule: " . $result['output']);
    }

    /**
     * Reload firewall
     */
    protected function actionReload(array $params, string $actor): array
    {
        $result = $this->execCommand('firewall-cmd', ['--reload']);
        
        if ($result['success']) {
            return $this->success([], "Firewall reloaded");
        }

        return $this->error("Failed to reload firewall: " . $result['output']);
    }

    /**
     * Get zone details
     */
    private function getZoneDetails(string $name): ?array
    {
        $result = $this->execCommand('firewall-cmd', ['--zone=' . $name, '--list-all']);
        
        if (!$result['success']) {
            return null;
        }

        $details = [
            'name' => $name,
            'active' => false,
            'default' => false,
            'interfaces' => [],
            'sources' => [],
            'services' => [],
            'ports' => [],
            'protocols' => [],
            'masquerade' => false,
            'forward_ports' => [],
            'rich_rules' => [],
        ];

        $output = $result['output'];

        // Check if active
        $details['active'] = strpos($output, '(active)') !== false;

        // Parse interfaces
        if (preg_match('/interfaces:\s*(.+)$/m', $output, $m)) {
            $details['interfaces'] = array_filter(explode(' ', trim($m[1])));
        }

        // Parse sources
        if (preg_match('/sources:\s*(.+)$/m', $output, $m)) {
            $details['sources'] = array_filter(explode(' ', trim($m[1])));
        }

        // Parse services
        if (preg_match('/services:\s*(.+)$/m', $output, $m)) {
            $details['services'] = array_filter(explode(' ', trim($m[1])));
        }

        // Parse ports
        if (preg_match('/ports:\s*(.+)$/m', $output, $m)) {
            $details['ports'] = array_filter(explode(' ', trim($m[1])));
        }

        // Check masquerade
        if (preg_match('/masquerade:\s*(\w+)$/m', $output, $m)) {
            $details['masquerade'] = strtolower(trim($m[1])) === 'yes';
        }

        // Check default zone
        $defaultResult = $this->execCommand('firewall-cmd', ['--get-default-zone']);
        $details['default'] = trim($defaultResult['output']) === $name;

        return $details;
    }
}

