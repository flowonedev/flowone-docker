<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * DNS Migration Controller
 * 
 * Handles safe migration from CyberPanel's PowerDNS tables to our own.
 * 
 * Migration Phases:
 * 1. not_started - Initial state
 * 2. syncing     - Copying data from CyberPanel to our tables
 * 3. dual_write  - Writing to both databases (testing phase)
 * 4. switched    - Using our tables, CyberPanel as backup
 * 5. completed   - Full migration done, CyberPanel dependency removed
 */
class DnsMigrationController extends BaseController
{
    private ?\PDO $cyberPdo = null;

    /**
     * Get migration status
     */
    public function status(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $this->ensureTables();
        $db = $this->container->getDatabase();

        // Get migration status
        $stmt = $db->query("SELECT * FROM dns_migration_status WHERE id = 1");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Get counts from our tables
        $ourCounts = [
            'zones' => (int) $db->query("SELECT COUNT(*) FROM dns_domains")->fetchColumn(),
            'records' => (int) $db->query("SELECT COUNT(*) FROM dns_records")->fetchColumn(),
        ];

        // Get counts from CyberPanel (if available)
        $cyberCounts = $this->getCyberPanelCounts();

        return Response::success([
            'phase' => $status['migration_phase'] ?? 'not_started',
            'last_sync' => $status['last_sync_at'] ?? null,
            'our_data' => $ourCounts,
            'cyberpanel_data' => $cyberCounts,
            'synced' => [
                'zones' => $status['zones_synced'] ?? 0,
                'records' => $status['records_synced'] ?? 0,
            ],
            'config_status' => [
                'pdns_updated' => (bool) ($status['pdns_config_updated'] ?? false),
            ],
            'rollback_available' => (bool) ($status['rollback_available'] ?? true),
        ], 'Success');
    }

    /**
     * Phase 1: Sync data from CyberPanel to our tables
     */
    public function sync(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $this->ensureTables();
        $db = $this->container->getDatabase();
        $cyberPdo = $this->getCyberPanelDb();

        if (!$cyberPdo) {
            return Response::error('Cannot connect to CyberPanel database');
        }

        $results = [
            'zones' => 0,
            'records' => 0,
            'errors' => [],
        ];

        try {
            $db->beginTransaction();

            // 1. Sync zones (domains table in PowerDNS)
            $results['zones'] = $this->syncZones($db, $cyberPdo);

            // 2. Sync records
            $results['records'] = $this->syncRecords($db, $cyberPdo);

            // Update migration status
            $db->exec("
                UPDATE dns_migration_status SET 
                    migration_phase = 'syncing',
                    last_sync_at = NOW(),
                    zones_synced = {$results['zones']},
                    records_synced = {$results['records']}
                WHERE id = 1
            ");

            $db->commit();

            $this->logAction('dns.migration.sync', 'dns', 'success', $results);

            return Response::success($results, 'Sync completed successfully');

        } catch (\Exception $e) {
            $db->rollBack();
            $this->logAction('dns.migration.sync', 'dns', 'failed', ['error' => $e->getMessage()]);
            return Response::error('Sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Phase 2: Enable dual-write mode
     */
    public function enableDualWrite(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();

        // First verify sync is complete
        $stmt = $db->query("SELECT migration_phase, zones_synced FROM dns_migration_status WHERE id = 1");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($status['migration_phase'] === 'not_started' || $status['zones_synced'] == 0) {
            return Response::error('Please run sync first before enabling dual-write');
        }

        $db->exec("UPDATE dns_migration_status SET migration_phase = 'dual_write' WHERE id = 1");

        $this->logAction('dns.migration.dual_write', 'dns', 'success');

        return Response::success([
            'phase' => 'dual_write',
            'message' => 'Dual-write mode enabled. New DNS records will be written to both databases.',
        ], 'Dual-write mode enabled');
    }

    /**
     * Phase 3: Switch to our tables (with instant rollback capability)
     */
    public function switch(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();
        $config = $this->container->getConfig('database');

        // Verify we're in dual_write phase
        $stmt = $db->query("SELECT migration_phase FROM dns_migration_status WHERE id = 1");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!in_array($status['migration_phase'], ['dual_write', 'syncing'])) {
            return Response::error('Please complete sync and dual-write phases first');
        }

        // Generate new PowerDNS config
        $pdnsConfig = $this->generatePdnsConfig($config);

        $db->exec("
            UPDATE dns_migration_status SET 
                migration_phase = 'switched',
                rollback_available = TRUE
            WHERE id = 1
        ");

        $this->logAction('dns.migration.switch', 'dns', 'success');

        return Response::success([
            'phase' => 'switched',
            'pdns_config' => $pdnsConfig,
            'instructions' => [
                '1. Backup current config: cp /etc/powerdns/pdns.d/gmysql.conf /etc/powerdns/pdns.d/gmysql.conf.bak',
                '2. Apply new config using the provided template',
                '3. Test with: dig @localhost yourdomain.com',
                '4. Restart PowerDNS: systemctl restart pdns',
                '5. If issues: rollback instantly with the rollback endpoint',
            ],
            'rollback_available' => true,
        ], 'Ready to switch. Apply config and test.');
    }

    /**
     * Rollback to CyberPanel (instant recovery)
     */
    public function rollback(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();

        $db->exec("UPDATE dns_migration_status SET migration_phase = 'syncing' WHERE id = 1");

        $this->logAction('dns.migration.rollback', 'dns', 'success');

        return Response::success([
            'phase' => 'syncing',
            'instructions' => [
                'Restore original config:',
                'cp /etc/powerdns/pdns.d/gmysql.conf.bak /etc/powerdns/pdns.d/gmysql.conf',
                'systemctl restart pdns',
            ],
        ], 'Rolled back to CyberPanel mode');
    }

    /**
     * Verify data integrity between both databases
     */
    public function verify(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();
        $cyberPdo = $this->getCyberPanelDb();

        if (!$cyberPdo) {
            return Response::error('Cannot connect to CyberPanel database');
        }

        $issues = [];

        // Check zones match
        $cyberZones = $cyberPdo->query("SELECT name FROM domains ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $ourZones = $db->query("SELECT name FROM dns_domains ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);

        $missingInOurs = array_diff($cyberZones, $ourZones);
        $extraInOurs = array_diff($ourZones, $cyberZones);

        if (!empty($missingInOurs)) {
            $issues[] = [
                'type' => 'missing_zones',
                'count' => count($missingInOurs),
                'zones' => array_slice($missingInOurs, 0, 10),
            ];
        }

        if (!empty($extraInOurs)) {
            $issues[] = [
                'type' => 'extra_zones',
                'count' => count($extraInOurs),
                'zones' => array_slice($extraInOurs, 0, 10),
            ];
        }

        // Check record counts per zone
        $cyberRecordCount = (int) $cyberPdo->query("SELECT COUNT(*) FROM records")->fetchColumn();
        $ourRecordCount = (int) $db->query("SELECT COUNT(*) FROM dns_records")->fetchColumn();

        if (abs($cyberRecordCount - $ourRecordCount) > 5) {
            $issues[] = [
                'type' => 'record_count_mismatch',
                'cyberpanel' => $cyberRecordCount,
                'ours' => $ourRecordCount,
                'diff' => $cyberRecordCount - $ourRecordCount,
            ];
        }

        return Response::success([
            'is_valid' => empty($issues),
            'cyberpanel_zones' => count($cyberZones),
            'our_zones' => count($ourZones),
            'cyberpanel_records' => $cyberRecordCount,
            'our_records' => $ourRecordCount,
            'issues' => $issues,
        ], empty($issues) ? 'Data is in sync' : 'Issues found');
    }

    /**
     * Complete migration (remove CyberPanel dependency)
     */
    public function complete(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();

        // Verify we're in switched phase and stable
        $stmt = $db->query("SELECT migration_phase FROM dns_migration_status WHERE id = 1");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($status['migration_phase'] !== 'switched') {
            return Response::error('Must be in switched phase to complete migration');
        }

        $db->exec("
            UPDATE dns_migration_status SET 
                migration_phase = 'completed',
                rollback_available = FALSE
            WHERE id = 1
        ");

        $this->logAction('dns.migration.complete', 'dns', 'success');

        return Response::success([
            'phase' => 'completed',
            'message' => 'Migration completed. CyberPanel DNS tables are no longer used.',
        ], 'Migration completed');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function ensureTables(): void
    {
        $db = $this->container->getDatabase();

        // DNS Domains (Zones)
        $db->exec("
            CREATE TABLE IF NOT EXISTS dns_domains (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                master VARCHAR(128) DEFAULT NULL,
                last_check INT DEFAULT NULL,
                type VARCHAR(6) NOT NULL DEFAULT 'NATIVE',
                notified_serial INT DEFAULT NULL,
                account VARCHAR(40) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // DNS Records
        $db->exec("
            CREATE TABLE IF NOT EXISTS dns_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                domain_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(10) NOT NULL,
                content TEXT NOT NULL,
                ttl INT DEFAULT 3600,
                prio INT DEFAULT 0,
                disabled BOOLEAN DEFAULT FALSE,
                ordername VARCHAR(255) DEFAULT NULL,
                auth BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_domain_id (domain_id),
                INDEX idx_name_type (name, type),
                INDEX idx_ordername (ordername)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Migration status
        $db->exec("
            CREATE TABLE IF NOT EXISTS dns_migration_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration_phase ENUM('not_started', 'syncing', 'dual_write', 'switched', 'completed') NOT NULL DEFAULT 'not_started',
                last_sync_at TIMESTAMP NULL,
                zones_synced INT DEFAULT 0,
                records_synced INT DEFAULT 0,
                pdns_config_updated BOOLEAN DEFAULT FALSE,
                rollback_available BOOLEAN DEFAULT TRUE,
                notes TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Ensure initial status row
        $db->exec("INSERT IGNORE INTO dns_migration_status (id, migration_phase) VALUES (1, 'not_started')");
    }

    private function getCyberPanelDb(): ?\PDO
    {
        if ($this->cyberPdo !== null) {
            return $this->cyberPdo;
        }

        try {
            $this->cyberPdo = new \PDO(
                "mysql:host=127.0.0.1;port=3306;dbname=cyberpanel",
                'cyberpanel',
                '9toSK7oSA50TjV',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            return $this->cyberPdo;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getCyberPanelCounts(): array
    {
        $cyberPdo = $this->getCyberPanelDb();
        if (!$cyberPdo) {
            return ['zones' => 0, 'records' => 0, 'error' => 'Cannot connect'];
        }

        try {
            return [
                'zones' => (int) $cyberPdo->query("SELECT COUNT(*) FROM domains")->fetchColumn(),
                'records' => (int) $cyberPdo->query("SELECT COUNT(*) FROM records")->fetchColumn(),
            ];
        } catch (\Exception $e) {
            return ['zones' => 0, 'records' => 0, 'error' => $e->getMessage()];
        }
    }

    private function syncZones(\PDO $db, \PDO $cyberPdo): int
    {
        // Get all zones from CyberPanel's PowerDNS
        $stmt = $cyberPdo->query("SELECT id, name, master, last_check, type, notified_serial, account FROM domains");

        $count = 0;
        $insertStmt = $db->prepare("
            INSERT INTO dns_domains (id, name, master, last_check, type, notified_serial, account) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                master = VALUES(master),
                last_check = VALUES(last_check),
                type = VALUES(type),
                notified_serial = VALUES(notified_serial),
                account = VALUES(account)
        ");

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $insertStmt->execute([
                $row['id'],
                $row['name'],
                $row['master'],
                $row['last_check'],
                $row['type'] ?? 'NATIVE',
                $row['notified_serial'],
                $row['account'],
            ]);
            $count++;
        }

        return $count;
    }

    private function syncRecords(\PDO $db, \PDO $cyberPdo): int
    {
        // Get all records from CyberPanel's PowerDNS
        $stmt = $cyberPdo->query("SELECT id, domain_id, name, type, content, ttl, prio, disabled, ordername, auth FROM records");

        $count = 0;
        $insertStmt = $db->prepare("
            INSERT INTO dns_records (id, domain_id, name, type, content, ttl, prio, disabled, ordername, auth)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                type = VALUES(type),
                content = VALUES(content),
                ttl = VALUES(ttl),
                prio = VALUES(prio),
                disabled = VALUES(disabled),
                ordername = VALUES(ordername),
                auth = VALUES(auth)
        ");

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $insertStmt->execute([
                $row['id'],
                $row['domain_id'],
                $row['name'],
                $row['type'],
                $row['content'],
                $row['ttl'] ?? 3600,
                $row['prio'] ?? 0,
                $row['disabled'] ?? 0,
                $row['ordername'],
                $row['auth'] ?? 1,
            ]);
            $count++;
        }

        return $count;
    }

    private function generatePdnsConfig(array $config): string
    {
        return <<<CONFIG
# /etc/powerdns/pdns.d/gmysql.conf
# Point this to our database instead of CyberPanel

launch=gmysql

gmysql-host=127.0.0.1
gmysql-port=3306
gmysql-dbname={$config['name']}
gmysql-user={$config['user']}
gmysql-password={$config['password']}

# Use our table names
gmysql-dnssec=no

# Custom queries for our table structure
gmysql-basic-query=SELECT content, ttl, prio, type, domain_id, disabled, name, auth FROM dns_records WHERE disabled=0 AND type=? AND name=?
gmysql-id-query=SELECT content, ttl, prio, type, domain_id, disabled, name, auth FROM dns_records WHERE disabled=0 AND id=?
gmysql-any-query=SELECT content, ttl, prio, type, domain_id, disabled, name, auth FROM dns_records WHERE disabled=0 AND name=?
gmysql-any-id-query=SELECT content, ttl, prio, type, domain_id, disabled, name, auth FROM dns_records WHERE disabled=0 AND domain_id=?
gmysql-list-query=SELECT content, ttl, prio, type, domain_id, disabled, name, auth FROM dns_records WHERE disabled=0 AND domain_id=?
gmysql-list-subzone-query=SELECT content, ttl, prio, type, domain_id, disabled, name, auth FROM dns_records WHERE disabled=0 AND domain_id=? AND (name=? OR name LIKE ?)

gmysql-master-zone-query=SELECT master FROM dns_domains WHERE name=? AND type='SLAVE'
gmysql-info-zone-query=SELECT id, name, master, last_check, notified_serial, type FROM dns_domains WHERE name=?
gmysql-info-all-slaves-query=SELECT id, name, master, last_check FROM dns_domains WHERE type='SLAVE'
gmysql-supermaster-query=SELECT account FROM supermasters WHERE ip=? AND nameserver=?
gmysql-supermaster-name-to-ips=SELECT ip, account FROM supermasters WHERE nameserver=? AND account=?

gmysql-insert-zone-query=INSERT INTO dns_domains (name, type, master, account) VALUES (?, ?, ?, ?)
gmysql-insert-record-query=INSERT INTO dns_records (content, ttl, prio, type, domain_id, disabled, name, auth) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
gmysql-update-serial-query=UPDATE dns_domains SET notified_serial=? WHERE id=?
gmysql-update-lastcheck-query=UPDATE dns_domains SET last_check=? WHERE id=?
gmysql-info-all-master-query=SELECT id, name, master, last_check, notified_serial, type FROM dns_domains WHERE type='MASTER'
gmysql-delete-zone-query=DELETE FROM dns_domains WHERE name=?
CONFIG;
    }
}

