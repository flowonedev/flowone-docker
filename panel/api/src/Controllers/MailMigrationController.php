<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Mail Migration Controller
 * 
 * Handles safe migration from CyberPanel mail tables to our own.
 * 
 * Migration Phases:
 * 1. not_started - Initial state
 * 2. syncing     - Copying data from CyberPanel to our tables
 * 3. dual_write  - Writing to both databases (testing phase)
 * 4. switched    - Using our tables, CyberPanel as backup
 * 5. completed   - Full migration done, CyberPanel dependency removed
 */
class MailMigrationController extends BaseController
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
        $stmt = $db->query("SELECT * FROM mail_migration_status WHERE id = 1");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Get counts from our tables
        $ourCounts = [
            'domains' => (int) $db->query("SELECT COUNT(*) FROM mail_domains")->fetchColumn(),
            'accounts' => (int) $db->query("SELECT COUNT(*) FROM mail_accounts")->fetchColumn(),
            'forwards' => (int) $db->query("SELECT COUNT(*) FROM mail_forwards")->fetchColumn(),
        ];

        // Get counts from CyberPanel (if available)
        $cyberCounts = $this->getCyberPanelCounts();

        return Response::success([
            'phase' => $status['migration_phase'] ?? 'not_started',
            'last_sync' => $status['last_sync_at'] ?? null,
            'our_data' => $ourCounts,
            'cyberpanel_data' => $cyberCounts,
            'synced' => [
                'domains' => $status['domains_synced'] ?? 0,
                'accounts' => $status['accounts_synced'] ?? 0,
                'forwards' => $status['forwards_synced'] ?? 0,
            ],
            'config_status' => [
                'postfix_updated' => (bool) ($status['postfix_config_updated'] ?? false),
                'dovecot_updated' => (bool) ($status['dovecot_config_updated'] ?? false),
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
            'domains' => 0,
            'accounts' => 0,
            'forwards' => 0,
            'errors' => [],
        ];

        try {
            $db->beginTransaction();

            // 1. Sync domains from virtual_domains file + e_users
            $results['domains'] = $this->syncDomains($db, $cyberPdo);

            // 2. Sync accounts from e_users
            $results['accounts'] = $this->syncAccounts($db, $cyberPdo);

            // 3. Sync forwards from e_forwardings
            $results['forwards'] = $this->syncForwards($db, $cyberPdo);

            // Update migration status
            $db->exec("
                UPDATE mail_migration_status SET 
                    migration_phase = 'syncing',
                    last_sync_at = NOW(),
                    domains_synced = {$results['domains']},
                    accounts_synced = {$results['accounts']},
                    forwards_synced = {$results['forwards']}
                WHERE id = 1
            ");

            $db->commit();

            $this->logAction('mail.migration.sync', 'mail', 'success', $results);

            return Response::success($results, 'Sync completed successfully');

        } catch (\Exception $e) {
            $db->rollBack();
            $this->logAction('mail.migration.sync', 'mail', 'failed', ['error' => $e->getMessage()]);
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
        $stmt = $db->query("SELECT migration_phase, accounts_synced FROM mail_migration_status WHERE id = 1");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($status['migration_phase'] === 'not_started' || $status['accounts_synced'] == 0) {
            return Response::error('Please run sync first before enabling dual-write');
        }

        $db->exec("UPDATE mail_migration_status SET migration_phase = 'dual_write' WHERE id = 1");

        $this->logAction('mail.migration.dual_write', 'mail', 'success');

        return Response::success([
            'phase' => 'dual_write',
            'message' => 'Dual-write mode enabled. New accounts will be written to both databases.',
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

        // Verify we're in dual_write phase
        $stmt = $db->query("SELECT migration_phase FROM mail_migration_status WHERE id = 1");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!in_array($status['migration_phase'], ['dual_write', 'syncing'])) {
            return Response::error('Please complete sync and dual-write phases first');
        }

        // Generate new Postfix/Dovecot configs (but don't apply yet)
        $configs = $this->generateNewConfigs();

        $db->exec("
            UPDATE mail_migration_status SET 
                migration_phase = 'switched',
                rollback_available = TRUE
            WHERE id = 1
        ");

        $this->logAction('mail.migration.switch', 'mail', 'success');

        return Response::success([
            'phase' => 'switched',
            'postfix_config' => $configs['postfix'],
            'dovecot_config' => $configs['dovecot'],
            'instructions' => [
                '1. Backup current configs: cp /etc/dovecot/dovecot-sql.conf.ext /etc/dovecot/dovecot-sql.conf.ext.bak',
                '2. Apply new configs using the provided templates',
                '3. Test with: doveadm auth test user@domain password',
                '4. If issues: rollback instantly with the rollback endpoint',
            ],
            'rollback_available' => true,
        ], 'Ready to switch. Apply configs and test.');
    }

    /**
     * Rollback to CyberPanel (instant recovery)
     */
    public function rollback(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();

        $db->exec("UPDATE mail_migration_status SET migration_phase = 'syncing' WHERE id = 1");

        $this->logAction('mail.migration.rollback', 'mail', 'success');

        return Response::success([
            'phase' => 'syncing',
            'instructions' => [
                'Restore original configs:',
                'cp /etc/dovecot/dovecot-sql.conf.ext.bak /etc/dovecot/dovecot-sql.conf.ext',
                'systemctl restart dovecot postfix',
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

        // Check accounts match
        $cyberAccounts = $cyberPdo->query("SELECT email FROM e_users ORDER BY email")->fetchAll(\PDO::FETCH_COLUMN);
        $ourAccounts = $db->query("SELECT email FROM mail_accounts ORDER BY email")->fetchAll(\PDO::FETCH_COLUMN);

        $missingInOurs = array_diff($cyberAccounts, $ourAccounts);
        $extraInOurs = array_diff($ourAccounts, $cyberAccounts);

        if (!empty($missingInOurs)) {
            $issues[] = [
                'type' => 'missing_accounts',
                'count' => count($missingInOurs),
                'emails' => array_slice($missingInOurs, 0, 10),
            ];
        }

        if (!empty($extraInOurs)) {
            $issues[] = [
                'type' => 'extra_accounts',
                'count' => count($extraInOurs),
                'emails' => array_slice($extraInOurs, 0, 10),
            ];
        }

        // Check forwards match
        $cyberForwards = $cyberPdo->query("SELECT source, destination FROM e_forwardings ORDER BY source")->fetchAll(\PDO::FETCH_ASSOC);
        $ourForwards = $db->query("SELECT source_email as source, destination FROM mail_forwards ORDER BY source_email")->fetchAll(\PDO::FETCH_ASSOC);

        $cyberForwardKeys = array_map(fn($f) => $f['source'] . '->' . $f['destination'], $cyberForwards);
        $ourForwardKeys = array_map(fn($f) => $f['source'] . '->' . $f['destination'], $ourForwards);

        $missingForwards = array_diff($cyberForwardKeys, $ourForwardKeys);
        if (!empty($missingForwards)) {
            $issues[] = [
                'type' => 'missing_forwards',
                'count' => count($missingForwards),
                'forwards' => array_slice($missingForwards, 0, 10),
            ];
        }

        return Response::success([
            'is_valid' => empty($issues),
            'cyberpanel_accounts' => count($cyberAccounts),
            'our_accounts' => count($ourAccounts),
            'cyberpanel_forwards' => count($cyberForwards),
            'our_forwards' => count($ourForwards),
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
        $stmt = $db->query("SELECT migration_phase FROM mail_migration_status WHERE id = 1");
        $status = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($status['migration_phase'] !== 'switched') {
            return Response::error('Must be in switched phase to complete migration');
        }

        $db->exec("
            UPDATE mail_migration_status SET 
                migration_phase = 'completed',
                rollback_available = FALSE
            WHERE id = 1
        ");

        $this->logAction('mail.migration.complete', 'mail', 'success');

        return Response::success([
            'phase' => 'completed',
            'message' => 'Migration completed. CyberPanel mail tables are no longer used.',
        ], 'Migration completed');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function ensureTables(): void
    {
        $db = $this->container->getDatabase();

        // Mail domains
        $db->exec("
            CREATE TABLE IF NOT EXISTS mail_domains (
                id INT AUTO_INCREMENT PRIMARY KEY,
                domain VARCHAR(255) NOT NULL UNIQUE,
                dkim_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                dkim_selector VARCHAR(64) DEFAULT 'default',
                dkim_private_key TEXT,
                dkim_public_key TEXT,
                spf_record VARCHAR(512),
                dmarc_record VARCHAR(512),
                catch_all_email VARCHAR(255),
                max_accounts INT DEFAULT 100,
                max_quota_mb INT DEFAULT 5120,
                status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Mail accounts
        $db->exec("
            CREATE TABLE IF NOT EXISTS mail_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                domain VARCHAR(255) NOT NULL,
                username VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                quota_mb INT DEFAULT 5120,
                disk_usage_kb BIGINT DEFAULT 0,
                maildir_path VARCHAR(512),
                status ENUM('active', 'suspended', 'vacation') NOT NULL DEFAULT 'active',
                vacation_message TEXT,
                vacation_subject VARCHAR(255),
                last_login TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_domain (domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Mail forwards
        $db->exec("
            CREATE TABLE IF NOT EXISTS mail_forwards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_email VARCHAR(255) NOT NULL,
                source_domain VARCHAR(255) NOT NULL,
                destination VARCHAR(512) NOT NULL,
                keep_copy BOOLEAN NOT NULL DEFAULT FALSE,
                status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_source (source_email),
                INDEX idx_domain (source_domain),
                UNIQUE KEY unique_forward (source_email, destination)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Migration status
        $db->exec("
            CREATE TABLE IF NOT EXISTS mail_migration_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration_phase ENUM('not_started', 'syncing', 'dual_write', 'switched', 'completed') NOT NULL DEFAULT 'not_started',
                last_sync_at TIMESTAMP NULL,
                accounts_synced INT DEFAULT 0,
                forwards_synced INT DEFAULT 0,
                domains_synced INT DEFAULT 0,
                postfix_config_updated BOOLEAN DEFAULT FALSE,
                dovecot_config_updated BOOLEAN DEFAULT FALSE,
                rollback_available BOOLEAN DEFAULT TRUE,
                notes TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Ensure initial status row
        $db->exec("INSERT IGNORE INTO mail_migration_status (id, migration_phase) VALUES (1, 'not_started')");
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
            return ['domains' => 0, 'accounts' => 0, 'forwards' => 0, 'error' => 'Cannot connect'];
        }

        try {
            return [
                'domains' => (int) $cyberPdo->query("SELECT COUNT(DISTINCT emailOwner_id) FROM e_users")->fetchColumn(),
                'accounts' => (int) $cyberPdo->query("SELECT COUNT(*) FROM e_users")->fetchColumn(),
                'forwards' => (int) $cyberPdo->query("SELECT COUNT(*) FROM e_forwardings")->fetchColumn(),
            ];
        } catch (\Exception $e) {
            return ['domains' => 0, 'accounts' => 0, 'forwards' => 0, 'error' => $e->getMessage()];
        }
    }

    private function syncDomains(\PDO $db, \PDO $cyberPdo): int
    {
        // Get unique domains from e_users
        $stmt = $cyberPdo->query("SELECT DISTINCT emailOwner_id as domain FROM e_users WHERE emailOwner_id IS NOT NULL AND emailOwner_id != ''");
        $domains = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $count = 0;
        $insertStmt = $db->prepare("
            INSERT IGNORE INTO mail_domains (domain, status) 
            VALUES (?, 'active')
        ");

        foreach ($domains as $domain) {
            $insertStmt->execute([$domain]);
            if ($insertStmt->rowCount() > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function syncAccounts(\PDO $db, \PDO $cyberPdo): int
    {
        // Get all accounts from e_users
        $stmt = $cyberPdo->query("
            SELECT email, password, mail, emailOwner_id as domain, DiskUsage 
            FROM e_users 
            WHERE email IS NOT NULL AND email != ''
        ");

        $count = 0;
        $insertStmt = $db->prepare("
            INSERT INTO mail_accounts (email, domain, username, password_hash, maildir_path, disk_usage_kb, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
            ON DUPLICATE KEY UPDATE 
                password_hash = VALUES(password_hash),
                maildir_path = VALUES(maildir_path),
                disk_usage_kb = VALUES(disk_usage_kb)
        ");

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $email = $row['email'];
            $domain = $row['domain'];
            $username = explode('@', $email)[0];
            $passwordHash = $row['password'] ?? '';
            $maildirPath = $row['mail'] ?? "{$domain}/{$username}/";
            $diskUsage = (int) ($row['DiskUsage'] ?? 0);

            // Ensure domain exists
            $db->exec("INSERT IGNORE INTO mail_domains (domain) VALUES (" . $db->quote($domain) . ")");

            $insertStmt->execute([
                $email,
                $domain,
                $username,
                $passwordHash,
                $maildirPath,
                $diskUsage,
            ]);
            $count++;
        }

        return $count;
    }

    private function syncForwards(\PDO $db, \PDO $cyberPdo): int
    {
        $stmt = $cyberPdo->query("SELECT source, destination FROM e_forwardings");

        $count = 0;
        $insertStmt = $db->prepare("
            INSERT IGNORE INTO mail_forwards (source_email, source_domain, destination, status)
            VALUES (?, ?, ?, 'active')
        ");

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $source = $row['source'];
            $destination = $row['destination'];
            $domain = explode('@', $source)[1] ?? '';

            if ($domain) {
                $insertStmt->execute([$source, $domain, $destination]);
                if ($insertStmt->rowCount() > 0) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function generateNewConfigs(): array
    {
        $config = $this->container->getConfig('database');

        $postfixConfig = <<<SQL
# /etc/postfix/mysql-virtual_mailbox_maps.cf
# Point this to our database instead of CyberPanel

user = {$config['user']}
password = {$config['password']}
hosts = {$config['host']}
dbname = {$config['name']}
query = SELECT maildir_path FROM mail_accounts WHERE email='%s' AND status='active'
SQL;

        $dovecotConfig = <<<SQL
# /etc/dovecot/dovecot-sql.conf.ext
# Point this to our database instead of CyberPanel

driver = mysql
connect = host={$config['host']} dbname={$config['name']} user={$config['user']} password={$config['password']}

default_pass_scheme = SHA512-CRYPT

password_query = SELECT email as user, password_hash as password \\
  FROM mail_accounts WHERE email='%u' AND status='active'

user_query = SELECT '/home/vmail/%d/%n' as home, \\
  'maildir:/home/vmail/%d/%n' as mail, \\
  5000 AS uid, 5000 AS gid, \\
  CASE WHEN quota_mb > 0 THEN CONCAT('*:bytes=', quota_mb * 1048576) ELSE NULL END AS quota_rule \\
  FROM mail_accounts WHERE email='%u' AND status='active'
SQL;

        return [
            'postfix' => $postfixConfig,
            'dovecot' => $dovecotConfig,
        ];
    }
}

