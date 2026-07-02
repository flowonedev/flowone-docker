<?php
/**
 * DNS Action Handler
 * 
 * Manages PowerDNS zones and records.
 * Now uses our panel's database (dns_domains, dns_records tables).
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\NsDefaults;
use VpsAdmin\Agent\Lib\Validator;

class DnsAction extends BaseAction
{
    private ?\PDO $pdo = null;

    public function getNamespace(): string
    {
        return 'dns';
    }

    public function getMethods(): array
    {
        return ['status', 'stats', 'zones', 'zone', 'createZone', 'deleteZone', 'records', 
                'addRecord', 'updateRecord', 'deleteRecord', 'syncZone', 'syncAll', 'fixIssues',
                'getNsConfig', 'setNsConfig'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['createZone', 'deleteZone', 'addRecord', 'updateRecord', 'deleteRecord']);
    }

    /**
     * Path to NS configuration file (canonical constant lives in NsDefaults)
     */
    private const NS_CONFIG_FILE = NsDefaults::CONFIG_FILE;

    /**
     * Get nameserver configuration
     * Returns configured NS1 and NS2 hostnames. The config file wins; when it
     * is absent the defaults derive from this box's own base domain
     * (ns1.<base>/ns2.<base>) — see NsDefaults.
     */
    private function getNsConfiguration(): array
    {
        return NsDefaults::load();
    }

    /**
     * Save nameserver configuration
     */
    private function saveNsConfiguration(array $config): bool
    {
        $derived = NsDefaults::derived();
        $data = [
            'enabled' => $config['enabled'] ?? true,
            'ns1' => $config['ns1'] ?? $derived['ns1'],
            'ns2' => $config['ns2'] ?? $derived['ns2'],
        ];

        return file_put_contents(self::NS_CONFIG_FILE, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Get nameserver configuration (API action)
     */
    protected function actionGetNsConfig(array $params, string $actor): array
    {
        $config = $this->getNsConfiguration();
        return $this->success($config);
    }

    /**
     * Set nameserver configuration (API action)
     */
    protected function actionSetNsConfig(array $params, string $actor): array
    {
        $enabled = $params['enabled'] ?? true;
        $ns1 = $params['ns1'] ?? null;
        $ns2 = $params['ns2'] ?? null;

        // Only validate NS hostnames if DNS management is enabled
        if ($enabled) {
            if (!$ns1 || !$ns2) {
                return $this->error('Both ns1 and ns2 are required when DNS management is enabled');
            }

            // Validate hostnames
            if (!Validator::domain($ns1) || !Validator::domain($ns2)) {
                return $this->error('Invalid nameserver hostname');
            }
        }

        $config = [
            'enabled' => (bool)$enabled,
            'ns1' => $ns1 ?: '',
            'ns2' => $ns2 ?: '',
        ];

        if ($this->saveNsConfiguration($config)) {
            $this->logger->info('Nameserver configuration updated', $config);
            return $this->success($config, 'Nameserver configuration saved');
        }

        return $this->error('Failed to save nameserver configuration');
    }

    /**
     * Get database connection (our panel's database)
     * Includes connection health check to handle stale connections in long-running daemon
     */
    private function getConnection(): \PDO
    {
        // Check if existing connection is still alive
        if ($this->pdo !== null) {
            try {
                // Ping the connection to verify it's still valid
                $this->pdo->query('SELECT 1');
                return $this->pdo;
            } catch (\PDOException $e) {
                // Connection is dead (MySQL server has gone away, etc.)
                // Reset and reconnect
                $this->pdo = null;
                $this->logger->warning('DNS database connection was stale, reconnecting', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Read panel config
        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';
        
        if (!file_exists($configFile)) {
            throw new \Exception('Panel config file not found');
        }

        $config = require $configFile;
        if (file_exists($localConfigFile)) {
            $localConfig = require $localConfigFile;
            $config = array_replace_recursive($config, $localConfig);
        }

        $dbConfig = $config['database'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $dbConfig['host'] ?? 'localhost',
            $dbConfig['port'] ?? 3306,
            $dbConfig['name'] ?? '',
            $dbConfig['charset'] ?? 'utf8mb4'
        );

        $this->pdo = new \PDO(
            $dsn,
            $dbConfig['user'] ?? '',
            $dbConfig['password'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]
        );

        return $this->pdo;
    }

    /**
     * Get PowerDNS status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        $result = $this->execCommand('systemctl', ['is-active', 'pdns']);
        $running = trim($result['output']) === 'active';

        // Get zone count from database
        $zoneCount = 0;
        try {
            $pdo = $this->getConnection();
            $stmt = $pdo->query("SELECT COUNT(*) FROM dns_domains");
            $zoneCount = (int)$stmt->fetchColumn();
        } catch (\Exception $e) {
            // Database might not be accessible
        }

        return $this->success([
            'running' => $running,
            'zone_count' => $zoneCount,
        ]);
    }

    /**
     * Get comprehensive DNS server stats
     * Includes nameserver info, zone count, last sync time
     */
    protected function actionStats(array $params, string $actor): array
    {
        // Get nameserver info from config
        $nsConfig = $this->getNsConfiguration();
        
        $ns1 = [
            'hostname' => $nsConfig['ns1'],
            'ip' => null,
            'responding' => false,
        ];
        $ns2 = [
            'hostname' => $nsConfig['ns2'],
            'ip' => null,
            'responding' => false,
        ];

        // Try to resolve NS1 IP
        $ip = @gethostbyname($ns1['hostname']);
        if ($ip !== $ns1['hostname']) {
            $ns1['ip'] = $ip;
        }

        // Try to resolve NS2 IP
        $ip = @gethostbyname($ns2['hostname']);
        if ($ip !== $ns2['hostname']) {
            $ns2['ip'] = $ip;
        }

        // Check if NS1 is responding (dig a test query)
        $output = [];
        exec("dig @{$ns1['hostname']} +short +time=2 {$ns1['hostname']} 2>/dev/null", $output);
        $ns1['responding'] = !empty($output);

        // Check if NS2 is responding
        $output = [];
        exec("dig @{$ns2['hostname']} +short +time=2 {$ns2['hostname']} 2>/dev/null", $output);
        $ns2['responding'] = !empty($output);

        // Get zone count from database
        $zoneCount = 0;
        $lastSync = null;
        $lastSyncRelative = null;
        
        try {
            $pdo = $this->getConnection();
            
            // Zone count
            $stmt = $pdo->query("SELECT COUNT(*) FROM dns_domains");
            $zoneCount = (int)$stmt->fetchColumn();
            
            // Check for stored last sync time
            $lastSyncFile = '/var/www/vps-admin/.dns_last_sync';
            if (file_exists($lastSyncFile)) {
                $lastSyncTimestamp = (int)file_get_contents($lastSyncFile);
                if ($lastSyncTimestamp > 0) {
                    $lastSyncDate = new \DateTime();
                    $lastSyncDate->setTimestamp($lastSyncTimestamp);
                    $lastSync = $lastSyncDate->format('Y-m-d H:i:s');
                    
                    $now = new \DateTime();
                    $diff = $now->diff($lastSyncDate);
                    
                    if ($diff->days == 0 && $diff->h == 0 && $diff->i < 5) {
                        $lastSyncRelative = 'Just now';
                    } elseif ($diff->days == 0 && $diff->h == 0) {
                        $lastSyncRelative = $diff->i . ' min ago';
                    } elseif ($diff->days == 0) {
                        $lastSyncRelative = $diff->h . 'h ' . $diff->i . 'm ago';
                    } elseif ($diff->days == 1) {
                        $lastSyncRelative = 'Yesterday';
                    } else {
                        $lastSyncRelative = $diff->days . ' days ago';
                    }
                }
            }
        } catch (\Exception $e) {
            // Database might not be accessible
        }

        return $this->success([
            'ns1' => $ns1,
            'ns2' => $ns2,
            'zone_count' => $zoneCount,
            'last_sync' => $lastSync,
            'last_sync_relative' => $lastSyncRelative,
        ]);
    }

    /**
     * List all zones from database
     */
    protected function actionZones(array $params, string $actor): array
    {
        try {
            $pdo = $this->getConnection();
            
            $stmt = $pdo->query("SELECT id, name FROM dns_domains ORDER BY name");
            $zones = [];

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                // Get record count for this zone
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM dns_records WHERE domain_id = ?");
                $countStmt->execute([$row['id']]);
                $recordCount = (int)$countStmt->fetchColumn();

                $zones[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'type' => 'NATIVE',
                    'record_count' => $recordCount,
                ];
            }

            return $this->success(['zones' => $zones]);
        } catch (\Exception $e) {
            return $this->error('Failed to list zones: ' . $e->getMessage());
        }
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
        
        if (!Validator::domain($name)) {
            return $this->error('Invalid zone name');
        }

        try {
            $pdo = $this->getConnection();
            
            $stmt = $pdo->prepare("SELECT id, name FROM dns_domains WHERE name = ?");
            $stmt->execute([$name]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                return $this->error("Zone not found: {$name}");
            }

            // Get records
            $stmt = $pdo->prepare("SELECT id, name, type, content, ttl, prio, disabled 
                                   FROM dns_records WHERE domain_id = ? ORDER BY type, name");
            $stmt->execute([$zone['id']]);
            $zone['records'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return $this->success(['zone' => $zone]);
        } catch (\Exception $e) {
            return $this->error('Failed to get zone: ' . $e->getMessage());
        }
    }

    /**
     * Create a new zone
     */
    protected function actionCreateZone(array $params, string $actor): array
    {
        // Check if DNS management is enabled
        $nsConfig = $this->getNsConfiguration();
        if (!($nsConfig['enabled'] ?? true)) {
            return $this->success([
                'skipped' => true,
                'message' => 'DNS management is disabled - using external DNS provider',
            ], 'DNS zone creation skipped (external DNS)');
        }

        if (!isset($params['name'])) {
            return $this->error('Zone name is required');
        }

        $name = $params['name'];
        
        if (!Validator::domain($name)) {
            return $this->error('Invalid zone name');
        }

        try {
            $pdo = $this->getConnection();

            // Check if zone already exists
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                return $this->error("Zone already exists: {$name}");
            }

            // Create zone
            $stmt = $pdo->prepare("INSERT INTO dns_domains (name, type) VALUES (?, 'NATIVE')");
            $stmt->execute([$name]);
            $zoneId = $pdo->lastInsertId();

            // Get configured nameservers (use panel NS config as default)
            $nsConfig = $this->getNsConfiguration();
            
            // Create default records
            $ns1 = $params['ns1'] ?? $nsConfig['ns1'];
            $ns2 = $params['ns2'] ?? $nsConfig['ns2'];
            $admin = str_replace('@', '.', $params['admin'] ?? 'admin@' . $name);
            $ip = $params['ip'] ?? null;
            $serial = date('Ymd') . '01';

            // SOA record
            $soaContent = "{$ns1}. {$admin}. {$serial} 10800 3600 604800 3600";
            $stmt = $pdo->prepare("INSERT INTO dns_records (domain_id, name, type, content, ttl) VALUES (?, ?, 'SOA', ?, 3600)");
            $stmt->execute([$zoneId, $name, $soaContent]);

            // NS records
            $stmt = $pdo->prepare("INSERT INTO dns_records (domain_id, name, type, content, ttl) VALUES (?, ?, 'NS', ?, 3600)");
            $stmt->execute([$zoneId, $name, $ns1]);
            $stmt->execute([$zoneId, $name, $ns2]);

            // A record if IP provided
            if ($ip && Validator::ipv4($ip)) {
                $stmt = $pdo->prepare("INSERT INTO dns_records (domain_id, name, type, content, ttl) VALUES (?, ?, 'A', ?, 3600)");
                $stmt->execute([$zoneId, $name, $ip]);
                
                // www CNAME
                $stmt = $pdo->prepare("INSERT INTO dns_records (domain_id, name, type, content, ttl) VALUES (?, ?, 'CNAME', ?, 3600)");
                $stmt->execute([$zoneId, 'www.' . $name, $name]);
            }

            // Notify PowerDNS
            $this->execCommand('pdns_control', ['notify', $name]);

            return $this->success([
                'zone' => $name,
                'id' => $zoneId,
            ], "Zone {$name} created");
        } catch (\Exception $e) {
            return $this->error('Failed to create zone: ' . $e->getMessage());
        }
    }

    /**
     * Delete a zone
     */
    protected function actionDeleteZone(array $params, string $actor): array
    {
        if (!isset($params['name'])) {
            return $this->error('Zone name is required');
        }

        $name = $params['name'];
        
        if (!Validator::domain($name)) {
            return $this->error('Invalid zone name');
        }

        try {
            $pdo = $this->getConnection();

            // Get zone
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$name]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                return $this->error("Zone not found: {$name}");
            }

            // Backup records before deletion
            $stmt = $pdo->prepare("SELECT * FROM dns_records WHERE domain_id = ?");
            $stmt->execute([$zone['id']]);
            $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->logger->info("Backing up zone records before deletion", [
                'zone' => $name,
                'records' => $records,
            ]);

            // Delete records (cascade should handle this, but be explicit)
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ?");
            $stmt->execute([$zone['id']]);

            // Delete zone
            $stmt = $pdo->prepare("DELETE FROM dns_domains WHERE id = ?");
            $stmt->execute([$zone['id']]);

            return $this->success([
                'zone' => $name,
                'records_deleted' => count($records),
            ], "Zone {$name} deleted");
        } catch (\Exception $e) {
            return $this->error('Failed to delete zone: ' . $e->getMessage());
        }
    }

    /**
     * Get records for a zone
     * If zone not found, checks for parent zone and returns subdomain records
     */
    protected function actionRecords(array $params, string $actor): array
    {
        if (!isset($params['zone'])) {
            return $this->error('Zone name is required');
        }

        $zoneName = $params['zone'];
        $filterType = $params['type'] ?? null;
        
        if (!Validator::domain($zoneName)) {
            return $this->error('Invalid zone name');
        }

        try {
            $pdo = $this->getConnection();

            // Get zone ID
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$zoneName]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            // If zone not found, check if this is a subdomain and look in parent zone
            $isSubdomain = false;
            $parentZoneName = null;
            
            if (!$zone) {
                // Try to find parent zone
                $parentZoneName = $this->findParentZone($pdo, $zoneName);
                
                if ($parentZoneName) {
                    $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
                    $stmt->execute([$parentZoneName]);
                    $zone = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $isSubdomain = true;
                }
                
                if (!$zone) {
                    return $this->error("Zone not found: {$zoneName}");
                }
            }

            // Get records
            $sql = "SELECT id, name, type, content, ttl, prio, disabled 
                    FROM dns_records WHERE domain_id = ?";
            $queryParams = [$zone['id']];

            // If subdomain, filter to only records for this subdomain
            if ($isSubdomain) {
                $sql .= " AND (name = ? OR name LIKE ?)";
                $queryParams[] = $zoneName;
                $queryParams[] = '%.' . $zoneName;
            }

            if ($filterType) {
                $sql .= " AND type = ?";
                $queryParams[] = strtoupper($filterType);
            }

            $sql .= " ORDER BY type, name";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($queryParams);
            $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return $this->success([
                'zone' => $zoneName,
                'parent_zone' => $isSubdomain ? $parentZoneName : null,
                'is_subdomain' => $isSubdomain,
                'records' => $records,
            ]);
        } catch (\Exception $e) {
            return $this->error('Failed to get records: ' . $e->getMessage());
        }
    }

    /**
     * Add a DNS record
     */
    protected function actionAddRecord(array $params, string $actor): array
    {
        $required = ['zone', 'name', 'type', 'content'];
        foreach ($required as $field) {
            if (!isset($params[$field])) {
                return $this->error("{$field} is required");
            }
        }

        $zoneName = $params['zone'];
        $name = $params['name'];
        $type = strtoupper($params['type']);
        $content = $params['content'];
        $ttl = $params['ttl'] ?? 3600;
        $prio = $params['prio'] ?? 0;

        if (!Validator::domain($zoneName)) {
            return $this->error('Invalid zone name');
        }

        if (!Validator::dnsRecordType($type)) {
            return $this->error('Invalid record type');
        }

        try {
            $pdo = $this->getConnection();

            // Get zone ID
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$zoneName]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zone) {
                return $this->error("Zone not found: {$zoneName}");
            }

            // Ensure name ends with zone or is the zone itself
            if ($name !== $zoneName && !str_ends_with($name, '.' . $zoneName)) {
                $name = $name . '.' . $zoneName;
            }

            // Insert record
            $stmt = $pdo->prepare("INSERT INTO dns_records (domain_id, name, type, content, ttl, prio) 
                                   VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$zone['id'], $name, $type, $content, $ttl, $prio]);
            $recordId = $pdo->lastInsertId();

            // Update SOA serial
            $this->updateSerial($pdo, $zone['id'], $zoneName);

            // Notify PowerDNS
            $this->execCommand('pdns_control', ['notify', $zoneName]);

            return $this->success([
                'id' => $recordId,
                'zone' => $zoneName,
                'name' => $name,
                'type' => $type,
                'content' => $content,
            ], "Record added to {$zoneName}");
        } catch (\Exception $e) {
            return $this->error('Failed to add record: ' . $e->getMessage());
        }
    }

    /**
     * Update a DNS record
     */
    protected function actionUpdateRecord(array $params, string $actor): array
    {
        if (!isset($params['id'])) {
            return $this->error('Record ID is required');
        }

        $id = (int)$params['id'];

        try {
            $pdo = $this->getConnection();

            // Get existing record
            $stmt = $pdo->prepare("SELECT r.*, d.name as zone_name FROM dns_records r 
                                   JOIN dns_domains d ON r.domain_id = d.id WHERE r.id = ?");
            $stmt->execute([$id]);
            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$record) {
                return $this->error("Record not found: {$id}");
            }

            // Build update
            $updates = [];
            $updateParams = [];

            if (isset($params['content'])) {
                $updates[] = 'content = ?';
                $updateParams[] = $params['content'];
            }

            if (isset($params['ttl'])) {
                $updates[] = 'ttl = ?';
                $updateParams[] = (int)$params['ttl'];
            }

            if (isset($params['prio'])) {
                $updates[] = 'prio = ?';
                $updateParams[] = (int)$params['prio'];
            }

            if (isset($params['disabled'])) {
                $updates[] = 'disabled = ?';
                $updateParams[] = $params['disabled'] ? 1 : 0;
            }

            if (empty($updates)) {
                return $this->error('No fields to update');
            }

            $updateParams[] = $id;
            $stmt = $pdo->prepare("UPDATE dns_records SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmt->execute($updateParams);

            // Update SOA serial
            $this->updateSerial($pdo, $record['domain_id'], $record['zone_name']);

            // Notify PowerDNS
            $this->execCommand('pdns_control', ['notify', $record['zone_name']]);

            return $this->success([
                'id' => $id,
                'zone' => $record['zone_name'],
            ], "Record updated");
        } catch (\Exception $e) {
            return $this->error('Failed to update record: ' . $e->getMessage());
        }
    }

    /**
     * Delete a DNS record
     */
    protected function actionDeleteRecord(array $params, string $actor): array
    {
        if (!isset($params['id'])) {
            return $this->error('Record ID is required');
        }

        $id = (int)$params['id'];

        try {
            $pdo = $this->getConnection();

            // Get record info
            $stmt = $pdo->prepare("SELECT r.*, d.name as zone_name FROM dns_records r 
                                   JOIN dns_domains d ON r.domain_id = d.id WHERE r.id = ?");
            $stmt->execute([$id]);
            $record = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$record) {
                return $this->error("Record not found: {$id}");
            }

            // Don't allow deleting SOA
            if ($record['type'] === 'SOA') {
                return $this->error('Cannot delete SOA record');
            }

            // Log before deletion
            $this->logger->info("Deleting DNS record", ['record' => $record]);

            // Delete
            $stmt = $pdo->prepare("DELETE FROM dns_records WHERE id = ?");
            $stmt->execute([$id]);

            // Update SOA serial
            $this->updateSerial($pdo, $record['domain_id'], $record['zone_name']);

            // Notify PowerDNS
            $this->execCommand('pdns_control', ['notify', $record['zone_name']]);

            return $this->success([
                'id' => $id,
                'zone' => $record['zone_name'],
                'type' => $record['type'],
                'name' => $record['name'],
            ], "Record deleted");
        } catch (\Exception $e) {
            return $this->error('Failed to delete record: ' . $e->getMessage());
        }
    }

    /**
     * Update SOA serial
     */
    private function updateSerial(\PDO $pdo, int $zoneId, string $zone): void
    {
        $stmt = $pdo->prepare("SELECT id, content FROM dns_records WHERE domain_id = ? AND type = 'SOA'");
        $stmt->execute([$zoneId]);
        $soa = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($soa) {
            $parts = explode(' ', $soa['content']);
            $currentSerial = $parts[2] ?? 0;
            $today = date('Ymd');
            
            if (substr($currentSerial, 0, 8) === $today) {
                $newSerial = $currentSerial + 1;
            } else {
                $newSerial = $today . '01';
            }

            $parts[2] = $newSerial;
            $newContent = implode(' ', $parts);

            $stmt = $pdo->prepare("UPDATE dns_records SET content = ? WHERE id = ?");
            $stmt->execute([$newContent, $soa['id']]);
        }
    }

    /**
     * Validate record content based on type
     */
    private function validateRecordContent(string $type, string $content): bool
    {
        switch ($type) {
            case 'A':
                return Validator::ipv4($content);
            case 'AAAA':
                return Validator::ipv6($content);
            case 'CNAME':
            case 'NS':
            case 'PTR':
                return !empty($content);
            case 'MX':
                return !empty($content);
            case 'TXT':
                return !empty($content);
            case 'SRV':
                return preg_match('/^\d+\s+\d+\s+\d+\s+\S+$/', $content);
            case 'CAA':
                return preg_match('/^\d+\s+(issue|issuewild|iodef)\s+/', $content);
            default:
                return true;
        }
    }

    /**
     * Find parent zone for a subdomain
     * e.g., for "robert.devcon1.hu" returns "devcon1.hu" if that zone exists
     */
    private function findParentZone(\PDO $pdo, string $domain): ?string
    {
        $parts = explode('.', $domain);
        
        // Need at least 3 parts to be a subdomain (sub.domain.tld)
        if (count($parts) < 3) {
            return null;
        }
        
        // Try progressively shorter parent domains
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $parentDomain = implode('.', array_slice($parts, $i));
            
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$parentDomain]);
            $zone = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($zone) {
                return $parentDomain;
            }
        }
        
        return null;
    }

    /**
     * Sync DNS zone to all nameservers
     * Bumps serial and sends NOTIFY to slaves
     */
    protected function actionSyncZone(array $params, string $actor): array
    {
        if (!isset($params['zone'])) {
            return $this->error('Zone name is required');
        }

        $zone = $params['zone'];
        
        // For subdomains, find the parent zone
        $pdo = $this->getConnection();
        $parentZone = $this->findParentZone($pdo, $zone);
        $targetZone = $parentZone ?: $zone;

        // Check zone exists
        $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
        $stmt->execute([$targetZone]);
        if (!$stmt->fetch()) {
            return $this->error("Zone '{$targetZone}' not found");
        }

        $results = [];

        // 1. Increment serial using pdnsutil
        $output = [];
        $exitCode = 0;
        exec("pdnsutil increase-serial {$targetZone} 2>&1", $output, $exitCode);
        $results['serial_bumped'] = ($exitCode === 0);
        $results['serial_output'] = implode("\n", $output);

        // 2. Send NOTIFY to all slaves
        $output = [];
        exec("pdns_control notify {$targetZone} 2>&1", $output, $exitCode);
        $results['notify_sent'] = ($exitCode === 0);
        $results['notify_output'] = implode("\n", $output);

        // 3. Verify sync by checking both nameservers
        sleep(2); // Wait a moment for sync
        
        $nsResults = [];
        $nsConfig = $this->getNsConfiguration();
        $nsServers = [$nsConfig['ns1'], $nsConfig['ns2']];
        
        foreach ($nsServers as $ns) {
            $output = [];
            exec("dig @{$ns} {$zone} +short 2>/dev/null", $output);
            $nsResults[$ns] = [
                'responds' => !empty($output),
                'result' => implode(', ', $output),
            ];
        }
        $results['nameservers'] = $nsResults;

        // Check if both nameservers respond
        $allSynced = true;
        foreach ($nsResults as $ns => $result) {
            if (!$result['responds']) {
                $allSynced = false;
            }
        }
        $results['all_synced'] = $allSynced;

        // Update last sync timestamp
        if ($allSynced) {
            $lastSyncFile = '/var/www/vps-admin/.dns_last_sync';
            file_put_contents($lastSyncFile, time());
        }

        return $this->success($results, $allSynced ? 'DNS zone synced successfully' : 'Sync initiated but some nameservers may not have updated yet');
    }

    /**
     * Sync ALL DNS zones to slave nameservers
     */
    protected function actionSyncAll(array $params, string $actor): array
    {
        try {
            $pdo = $this->getConnection();
            
            // Get all zones
            $stmt = $pdo->query("SELECT id, name FROM dns_domains ORDER BY name");
            $zones = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            if (empty($zones)) {
                return $this->success([
                    'zones_synced' => 0,
                    'message' => 'No zones to sync',
                ], 'No zones found');
            }

            $synced = 0;
            $failed = [];

            foreach ($zones as $zone) {
                $zoneName = $zone['name'];
                
                // Increment serial
                $output = [];
                $exitCode = 0;
                exec("pdnsutil increase-serial {$zoneName} 2>&1", $output, $exitCode);
                
                if ($exitCode !== 0) {
                    $failed[] = $zoneName;
                    continue;
                }

                // Send NOTIFY
                exec("pdns_control notify {$zoneName} 2>&1", $output, $exitCode);
                
                if ($exitCode === 0) {
                    $synced++;
                } else {
                    $failed[] = $zoneName;
                }
            }

            // Record last sync time persistently
            $lastSync = date('Y-m-d H:i:s');
            $lastSyncFile = '/var/www/vps-admin/.dns_last_sync';
            file_put_contents($lastSyncFile, time());

            return $this->success([
                'zones_synced' => $synced,
                'zones_failed' => $failed,
                'total_zones' => count($zones),
                'last_sync' => $lastSync,
            ], "Synced {$synced} of " . count($zones) . " zones");

        } catch (\Exception $e) {
            return $this->error('Failed to sync zones: ' . $e->getMessage());
        }
    }

    /**
     * Check and fix DNS issues for a zone
     * mode=check: only detect issues, don't fix
     * mode=fix: actually apply the fixes
     * 
     * Fixes:
     * - Old _domainkey.domain records with 't=y; o=~;' format
     * - Wrong SOA nameserver (not matching configured NS)
     * - Weak DMARC policy (p=none -> p=quarantine)
     * - Orphan _dmarc.mail.* and _domainkey.mail.* records
     */
    protected function actionFixIssues(array $params, string $actor): array
    {
        $zone = $params['zone'] ?? null;
        $mode = $params['mode'] ?? 'check'; // 'check' or 'fix'
        
        if (!$zone) {
            return $this->error('Zone name is required');
        }

        $applyFixes = ($mode === 'fix');
        
        // Get configured nameservers
        $nsConfig = $this->getNsConfiguration();

        try {
            $pdo = $this->getConnection();
            
            // Get zone ID - check for exact match first
            $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
            $stmt->execute([$zone]);
            $zoneData = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // If not found, check if this is a subdomain within a parent zone
            $isSubdomain = false;
            $parentZoneName = null;
            
            if (!$zoneData) {
                $parentZoneName = $this->findParentZone($pdo, $zone);
                
                if ($parentZoneName) {
                    $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ?");
                    $stmt->execute([$parentZoneName]);
                    $zoneData = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $isSubdomain = true;
                }
            }
            
            if (!$zoneData) {
                return $this->error("Zone {$zone} not found");
            }
            
            $zoneId = $zoneData['id'];
            $actualZoneName = $parentZoneName ?: $zone;
            $issues = [];
            $fixed = [];
            
            // 1. Check for old _domainkey.domain records with 't=y; o=~;' format
            // For subdomains, filter to only records matching the subdomain
            if ($isSubdomain) {
                $stmt = $pdo->prepare("SELECT id, name, content FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'TXT'");
                $stmt->execute([$zoneId, '_domainkey.' . $zone]);
            } else {
                $stmt = $pdo->prepare("SELECT id, name, content FROM dns_records WHERE domain_id = ? AND name LIKE '_domainkey.%' AND name NOT LIKE '%._domainkey.%' AND type = 'TXT'");
                $stmt->execute([$zoneId]);
            }
            $oldDomainkeys = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($oldDomainkeys as $record) {
                if (strpos($record['content'], 't=y') !== false || strpos($record['content'], 'o=~') !== false) {
                    $issues[] = "Old format _domainkey record: {$record['name']}";
                    
                    if ($applyFixes) {
                        $delStmt = $pdo->prepare("DELETE FROM dns_records WHERE id = ?");
                        $delStmt->execute([$record['id']]);
                        $fixed[] = "Deleted old _domainkey record: {$record['name']}";
                    }
                }
            }
            
            // 2. Check SOA nameserver (only for main zones, not subdomains)
            if (!$isSubdomain) {
                $stmt = $pdo->prepare("SELECT id, content FROM dns_records WHERE domain_id = ? AND type = 'SOA'");
                $stmt->execute([$zoneId]);
                $soa = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($soa) {
                    $soaParts = preg_split('/\s+/', $soa['content']);
                    $currentNs = $soaParts[0] ?? '';
                    
                    // Check if SOA doesn't use the configured NS1 (normalize with/without trailing dot)
                    $expectedNs1 = rtrim($nsConfig['ns1'], '.');
                    $actualNs = rtrim($currentNs, '.');
                    
                    if (strtolower($actualNs) !== strtolower($expectedNs1)) {
                        $issues[] = "Wrong SOA nameserver: {$currentNs} (should be {$nsConfig['ns1']})";
                        
                        if ($applyFixes) {
                            $serial = $soaParts[2] ?? date('Ymd') . '01';
                            $newSerial = max((int)$serial + 1, (int)(date('Ymd') . '01'));
                            $newSoa = "{$nsConfig['ns1']} admin.{$zone} {$newSerial} 10800 3600 604800 3600";
                            
                            $updateStmt = $pdo->prepare("UPDATE dns_records SET content = ? WHERE id = ?");
                            $updateStmt->execute([$newSoa, $soa['id']]);
                            $fixed[] = "Fixed SOA nameserver to {$nsConfig['ns1']}";
                        }
                    }
                }
            }
            
            // 3. Check DMARC policy
            if ($isSubdomain) {
                $stmt = $pdo->prepare("SELECT id, name, content FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'TXT'");
                $stmt->execute([$zoneId, '_dmarc.' . $zone]);
            } else {
                $stmt = $pdo->prepare("SELECT id, name, content FROM dns_records WHERE domain_id = ? AND name LIKE '_dmarc.%' AND type = 'TXT'");
                $stmt->execute([$zoneId]);
            }
            $dmarcRecords = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($dmarcRecords as $record) {
                // Skip _dmarc.mail.* records (will be deleted below)
                if (strpos($record['name'], '_dmarc.mail.') === 0) {
                    continue;
                }
                
                // Check for weak policy (p=none or p=quarantine) or missing strict alignment
                $needsUpgrade = false;
                $reason = '';
                
                if (strpos($record['content'], 'p=none') !== false) {
                    $needsUpgrade = true;
                    $reason = 'Weak DMARC policy (p=none)';
                } elseif (strpos($record['content'], 'p=quarantine') !== false) {
                    $needsUpgrade = true;
                    $reason = 'DMARC policy not strict (p=quarantine instead of p=reject)';
                } elseif (strpos($record['content'], 'adkim=s') === false || strpos($record['content'], 'aspf=s') === false) {
                    $needsUpgrade = true;
                    $reason = 'DMARC missing strict alignment (adkim=s; aspf=s)';
                }
                
                if ($needsUpgrade) {
                    $issues[] = "{$reason}: {$record['name']}";
                    
                    if ($applyFixes) {
                        $dmarcDomain = preg_replace('/^_dmarc\./', '', $record['name']);
                        $newDmarc = "v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@{$dmarcDomain}; fo=1";
                        
                        $updateStmt = $pdo->prepare("UPDATE dns_records SET content = ? WHERE id = ?");
                        $updateStmt->execute([$newDmarc, $record['id']]);
                        $fixed[] = "Upgraded DMARC to strict policy (p=reject; adkim=s; aspf=s): {$record['name']}";
                    }
                }
            }
            
            // 4. Check SPF records for weak policy (~all instead of -all)
            if ($isSubdomain) {
                $stmt = $pdo->prepare("SELECT id, name, content FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'TXT' AND content LIKE 'v=spf1%'");
                $stmt->execute([$zoneId, $zone]);
            } else {
                $stmt = $pdo->prepare("SELECT id, name, content FROM dns_records WHERE domain_id = ? AND type = 'TXT' AND content LIKE 'v=spf1%'");
                $stmt->execute([$zoneId]);
            }
            $spfRecords = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($spfRecords as $record) {
                // Check for soft fail (~all) instead of hard fail (-all)
                if (strpos($record['content'], '~all') !== false) {
                    $issues[] = "Weak SPF policy (~all instead of -all): {$record['name']}";
                    
                    if ($applyFixes) {
                        $newSpf = str_replace('~all', '-all', $record['content']);
                        
                        $updateStmt = $pdo->prepare("UPDATE dns_records SET content = ? WHERE id = ?");
                        $updateStmt->execute([$newSpf, $record['id']]);
                        $fixed[] = "Upgraded SPF to hard fail (-all): {$record['name']}";
                    }
                }
            }
            
            // 5. Check for orphan _dmarc.mail.* records
            if ($isSubdomain) {
                $stmt = $pdo->prepare("SELECT id, name FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'TXT'");
                $stmt->execute([$zoneId, '_dmarc.mail.' . $zone]);
            } else {
                $stmt = $pdo->prepare("SELECT id, name FROM dns_records WHERE domain_id = ? AND name LIKE '_dmarc.mail.%' AND type = 'TXT'");
                $stmt->execute([$zoneId]);
            }
            $orphanDmarc = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($orphanDmarc as $record) {
                $issues[] = "Orphan DMARC record: {$record['name']}";
                
                if ($applyFixes) {
                    $delStmt = $pdo->prepare("DELETE FROM dns_records WHERE id = ?");
                    $delStmt->execute([$record['id']]);
                    $fixed[] = "Deleted orphan DMARC: {$record['name']}";
                }
            }
            
            // 6. Check for orphan _domainkey.mail.* records
            if ($isSubdomain) {
                $stmt = $pdo->prepare("SELECT id, name FROM dns_records WHERE domain_id = ? AND name = ? AND type = 'TXT'");
                $stmt->execute([$zoneId, '_domainkey.mail.' . $zone]);
            } else {
                $stmt = $pdo->prepare("SELECT id, name FROM dns_records WHERE domain_id = ? AND name LIKE '_domainkey.mail.%' AND type = 'TXT'");
                $stmt->execute([$zoneId]);
            }
            $orphanDomainkey = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($orphanDomainkey as $record) {
                $issues[] = "Orphan _domainkey record: {$record['name']}";
                
                if ($applyFixes) {
                    $delStmt = $pdo->prepare("DELETE FROM dns_records WHERE id = ?");
                    $delStmt->execute([$record['id']]);
                    $fixed[] = "Deleted orphan _domainkey: {$record['name']}";
                }
            }
            
            // If we made changes, sync the zone (use actual zone name for subdomains)
            if (!empty($fixed)) {
                $this->syncZoneToNameservers($actualZoneName);
            }
            
            $message = $applyFixes 
                ? (count($fixed) > 0 ? "Fixed " . count($fixed) . " issue(s)" : "No issues to fix")
                : (count($issues) > 0 ? "Found " . count($issues) . " issue(s)" : "No DNS issues found");
            
            return $this->success([
                'zone' => $zone,
                'parent_zone' => $isSubdomain ? $parentZoneName : null,
                'is_subdomain' => $isSubdomain,
                'mode' => $mode,
                'issues_found' => count($issues),
                'issues_fixed' => count($fixed),
                'issues' => $issues,
                'fixed' => $fixed,
            ], $message);

        } catch (\Exception $e) {
            return $this->error('Failed to check/fix DNS issues: ' . $e->getMessage());
        }
    }

    /**
     * Sync zone to nameservers (helper method)
     */
    private function syncZoneToNameservers(string $zone): void
    {
        try {
            // Increment serial
            exec("pdnsutil increase-serial {$zone} 2>&1", $output, $exitCode);
            
            // Send NOTIFY to slaves
            exec("pdns_control notify {$zone} 2>&1", $output, $exitCode);
        } catch (\Exception $e) {
            $this->logger->warning("Failed to sync zone {$zone} after fixing issues: " . $e->getMessage());
        }
    }
}

