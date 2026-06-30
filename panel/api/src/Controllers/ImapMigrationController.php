<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * IMAP Migration Controller
 * 
 * Handles email migrations from external servers using imapsync.
 * Supports single account and batch migrations with progress tracking.
 */
class ImapMigrationController extends BaseController
{
    private string $logDir = '/var/log/imapsync';
    private string $serverHostname;

    public function __construct($container)
    {
        parent::__construct($container);
        $this->serverHostname = gethostname() ?: 'mail.devcon1.hu';
        
        // Ensure log directory exists
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        
        // Auto-create table if it doesn't exist
        $this->ensureTableExists();
    }

    /**
     * Locate the imapsync binary.
     *
     * The web SAPI (LiteSpeed/lsphp) often runs with a minimal PATH that omits
     * /usr/local/bin, where imapsync is installed by default — so a bare
     * `which imapsync` returns nothing even though it's present (and works fine
     * from a CLI shell). We therefore try `which` first, then fall back to the
     * common absolute install locations. Returns the absolute path or null.
     */
    public static function findImapsync(): ?string
    {
        $which = trim((string) shell_exec('which imapsync 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }
        foreach (['/usr/local/bin/imapsync', '/usr/bin/imapsync', '/bin/imapsync', '/opt/imapsync/imapsync'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Locate a CLI php binary to launch the background runner.
     *
     * IMPORTANT: under LiteSpeed the running SAPI binary (PHP_BINARY) is lsphp,
     * which is NOT the CLI SAPI — launching the runner with it would trip the
     * runner's `php_sapi_name() === 'cli'` guard and exit immediately. So we
     * explicitly find a real CLI php: PATH first, then standard locations and
     * LiteSpeed's bundled lsphp CLI binaries, and only fall back to PHP_BINARY.
     */
    private static function findPhpCli(): string
    {
        $which = trim((string) shell_exec('command -v php 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }
        $candidates = ['/usr/bin/php', '/usr/local/bin/php'];
        foreach (glob('/usr/local/lsws/lsphp*/bin/php') ?: [] as $p) {
            $candidates[] = $p;
        }
        foreach ($candidates as $cand) {
            if (is_executable($cand)) {
                return $cand;
            }
        }
        return PHP_BINARY ?: 'php';
    }

    /**
     * Ensure the imap_migrations table exists (auto-migration)
     */
    private function ensureTableExists(): void
    {
        try {
            $db = $this->container->getDatabase();
            
            // Check if table exists
            $stmt = $db->query("SHOW TABLES LIKE 'imap_migrations'");
            if ($stmt->rowCount() > 0) {
                $this->ensureColumnsExist($db);
                return; // Table already exists
            }

            // Create the table
            $db->exec("
                CREATE TABLE IF NOT EXISTS imap_migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type ENUM('single', 'batch') NOT NULL DEFAULT 'single',
                    source_host VARCHAR(255) NOT NULL,
                    source_port INT DEFAULT 993,
                    source_ssl BOOLEAN DEFAULT TRUE,
                    dest_host VARCHAR(255) NOT NULL,
                    dest_port INT DEFAULT 993,
                    dest_ssl BOOLEAN DEFAULT TRUE,
                    accounts JSON NOT NULL COMMENT 'Array of {email, source_password, dest_email, dest_password}',
                    total_accounts INT DEFAULT 1,
                    completed_accounts INT DEFAULT 0,
                    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
                    progress INT DEFAULT 0 COMMENT 'Overall progress percentage',
                    current_account VARCHAR(255) COMMENT 'Currently migrating account',
                    pid INT COMMENT 'Process ID of running imapsync',
                    log_file VARCHAR(512) COMMENT 'Path to log file',
                    error_message TEXT,
                    total_messages INT DEFAULT 0 COMMENT 'Messages present on source (denominator)',
                    transferred_messages INT DEFAULT 0 COMMENT 'Messages copied across all runs',
                    transferred_bytes BIGINT DEFAULT 0 COMMENT 'Bytes copied in the last run',
                    verified TINYINT(1) DEFAULT 0 COMMENT 'Source/destination counts matched',
                    migration_mode ENUM('initial','delta','final','sweep') NOT NULL DEFAULT 'initial' COMMENT 'Delta-sync phase',
                    schedule_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto periodic delta sync on',
                    delta_interval_minutes INT NOT NULL DEFAULT 360 COMMENT 'Minutes between delta syncs',
                    next_run_at DATETIME NULL COMMENT 'When the next delta is due',
                    last_delta_at DATETIME NULL COMMENT 'Last scheduled delta dispatch',
                    sweep_at DATETIME NULL COMMENT 'One-off post-cutover sweep time',
                    started_at DATETIME,
                    completed_at DATETIME,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_status (status),
                    INDEX idx_created_by (created_by)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Exception $e) {
            debug_log('Failed to create imap_migrations table: ' . $e->getMessage());
        }
    }

    /**
     * Idempotently add columns introduced after the table's first creation.
     * Safe to call on every request — each ADD COLUMN is guarded by a
     * SHOW COLUMNS check so it never errors on an up-to-date schema.
     */
    private function ensureColumnsExist(\PDO $db): void
    {
        $columns = [
            'total_messages'        => "ADD COLUMN total_messages INT DEFAULT 0",
            'transferred_messages'  => "ADD COLUMN transferred_messages INT DEFAULT 0",
            'transferred_bytes'     => "ADD COLUMN transferred_bytes BIGINT DEFAULT 0",
            'verified'              => "ADD COLUMN verified TINYINT(1) DEFAULT 0",
            'migration_mode'        => "ADD COLUMN migration_mode ENUM('initial','delta','final','sweep') NOT NULL DEFAULT 'initial'",
            // Delta-sync scheduler state (run-due-migrations.php dispatcher).
            'schedule_enabled'      => "ADD COLUMN schedule_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto periodic delta sync on'",
            'delta_interval_minutes'=> "ADD COLUMN delta_interval_minutes INT NOT NULL DEFAULT 360 COMMENT 'Minutes between delta syncs'",
            'next_run_at'           => "ADD COLUMN next_run_at DATETIME NULL COMMENT 'When the next delta is due'",
            'last_delta_at'         => "ADD COLUMN last_delta_at DATETIME NULL COMMENT 'Last scheduled delta dispatch'",
            'sweep_at'              => "ADD COLUMN sweep_at DATETIME NULL COMMENT 'One-off post-cutover sweep time'",
        ];

        foreach ($columns as $name => $ddl) {
            try {
                $check = $db->prepare("SHOW COLUMNS FROM imap_migrations LIKE ?");
                $check->execute([$name]);
                if ($check->rowCount() === 0) {
                    $db->exec("ALTER TABLE imap_migrations {$ddl}");
                }
            } catch (\Exception $e) {
                debug_log("Failed to add imap_migrations column {$name}: " . $e->getMessage());
            }
        }

        // migration_mode predates the 'sweep' phase — widen the ENUM in place
        // when an older table still has the 3-value definition.
        try {
            $col = $db->query("SHOW COLUMNS FROM imap_migrations LIKE 'migration_mode'")->fetch(\PDO::FETCH_ASSOC);
            if ($col && stripos($col['Type'] ?? '', "'sweep'") === false) {
                $db->exec("ALTER TABLE imap_migrations MODIFY COLUMN migration_mode ENUM('initial','delta','final','sweep') NOT NULL DEFAULT 'initial'");
            }
        } catch (\Exception $e) {
            debug_log('Failed to widen migration_mode enum: ' . $e->getMessage());
        }
    }

    /**
     * Get list of migrations with optional status filter
     */
    public function list(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();
        $status = $request->getQuery('status');
        // LIMIT can't be a bound parameter when PDO emulation is off (MySQL
        // rejects a string-typed LIMIT), so clamp to a safe int and inline it.
        $limit = max(1, min((int) ($request->getQuery('limit') ?? 50), 100));

        $sql = "SELECT id, type, source_host, dest_host, total_accounts, completed_accounts, 
                       status, progress, current_account, error_message,
                       total_messages, transferred_messages, transferred_bytes, verified, migration_mode,
                       schedule_enabled, delta_interval_minutes, next_run_at, last_delta_at, sweep_at,
                       started_at, completed_at, created_at
                FROM imap_migrations";
        $params = [];

        if ($status) {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT {$limit}";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $migrations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            debug_log('imap_migrations list query failed: ' . $e->getMessage());
            // Most likely an older table predating newer columns — surface an
            // empty list rather than a 500 so the Migration tab still loads.
            $migrations = [];
        }

        return Response::success([
            'migrations' => $migrations,
            'server_hostname' => $this->serverHostname,
        ], 'Success');
    }

    /**
     * Get single migration details
     */
    public function show(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = $request->getParam('id');
        $db = $this->container->getDatabase();

        $stmt = $db->prepare("SELECT * FROM imap_migrations WHERE id = ?");
        $stmt->execute([$id]);
        $migration = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$migration) {
            return Response::error('Migration not found', 404);
        }

        // Decode accounts JSON
        $migration['accounts'] = json_decode($migration['accounts'], true) ?? [];

        // Mask passwords in response (support both schemas)
        foreach ($migration['accounts'] as &$account) {
            foreach (['source_password', 'dest_password', 'old_password', 'new_password'] as $secret) {
                if (isset($account[$secret])) {
                    $account[$secret] = '********';
                }
            }
        }
        unset($account);

        return Response::success($migration, 'Success');
    }

    /**
     * Start a new migration job
     */
    public function start(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $data = $request->getBody();

        // Validate required fields
        if (empty($data['source_host'])) {
            return Response::error('Source host is required');
        }
        if (empty($data['accounts']) || !is_array($data['accounts'])) {
            return Response::error('Accounts array is required');
        }

        // Validate each account
        foreach ($data['accounts'] as $i => $account) {
            if (empty($account['email']) || empty($account['source_password'])) {
                return Response::error("Account #{$i}: email and source_password are required");
            }
            if (!filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
                return Response::error("Account #{$i}: invalid email format");
            }
        }

        // Check if imapsync is installed (searches PATH + common absolute paths).
        if (self::findImapsync() === null) {
            return Response::error('imapsync is not installed on this server. Install with: apt install imapsync');
        }

        $db = $this->container->getDatabase();
        $user = $this->getCurrentUser();
        $createdBy = $user->sub ?? $user->id ?? null;

        $type = count($data['accounts']) > 1 ? 'batch' : 'single';
        $sourceHost = trim($data['source_host']);
        $sourcePort = (int) ($data['source_port'] ?? 993);
        $sourceSsl = (bool) ($data['source_ssl'] ?? true);
        $destHost = trim($data['dest_host'] ?? $this->serverHostname);
        $destPort = (int) ($data['dest_port'] ?? 993);
        $destSsl = (bool) ($data['dest_ssl'] ?? true);
        // Delta-sync phase: 'initial' (default), 'delta' (periodic top-up),
        // or 'final' (cutover). All phases run the same non-destructive
        // imapsync; the label is for the admin's tracking only.
        $migrationMode = in_array($data['migration_mode'] ?? 'initial', ['initial', 'delta', 'final'], true)
            ? $data['migration_mode']
            : 'initial';

        // Prepare accounts with destination details
        $accounts = [];
        foreach ($data['accounts'] as $account) {
            $accounts[] = [
                'email' => strtolower(trim($account['email'])),
                'source_password' => $account['source_password'],
                'dest_email' => strtolower(trim($account['dest_email'] ?? $account['email'])),
                'dest_password' => $account['dest_password'] ?? $account['source_password'],
                'status' => 'pending',
                'progress' => 0,
                'error' => null,
            ];
        }

        // Create migration record
        $logFile = $this->logDir . '/migration_' . date('Y-m-d_His') . '_' . uniqid() . '.log';
        
        $stmt = $db->prepare("
            INSERT INTO imap_migrations 
            (type, source_host, source_port, source_ssl, dest_host, dest_port, dest_ssl, 
             accounts, total_accounts, status, log_file, migration_mode, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
        ");
        $stmt->execute([
            $type,
            $sourceHost,
            $sourcePort,
            $sourceSsl ? 1 : 0,
            $destHost,
            $destPort,
            $destSsl ? 1 : 0,
            json_encode($accounts),
            count($accounts),
            $logFile,
            $migrationMode,
            $createdBy,
        ]);

        $migrationId = $db->lastInsertId();

        // Start the migration process in background
        $this->startMigrationProcess($migrationId);

        $this->logAction('imap_migration.start', 'mail', 'success', [
            'migration_id' => $migrationId,
            'type' => $type,
            'mode' => $migrationMode,
            'source_host' => $sourceHost,
            'accounts_count' => count($accounts),
        ]);

        return Response::success([
            'id' => $migrationId,
            'type' => $type,
            'migration_mode' => $migrationMode,
            'accounts_count' => count($accounts),
            'status' => 'pending',
        ], 'Migration job created');
    }

    /**
     * Preflight: validate source + destination credentials/connectivity for
     * each account WITHOUT moving any mail. Runs `imapsync --justlogin`, which
     * logs into host1 and host2 then exits. We parse the per-host "success
     * login" lines so the UI can show which side (source/dest) is good.
     */
    public function preflight(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $data = $request->getBody();

        if (empty($data['source_host'])) {
            return Response::error('Source host is required');
        }
        if (empty($data['accounts']) || !is_array($data['accounts'])) {
            return Response::error('Accounts array is required');
        }
        if (count($data['accounts']) > 500) {
            return Response::error('Too many accounts in one batch (max 500)');
        }

        $imapsync = self::findImapsync();
        if ($imapsync === null) {
            return Response::error('imapsync is not installed on this server. Install with: apt install imapsync');
        }

        $sourceHost = trim($data['source_host']);
        $sourcePort = (int) ($data['source_port'] ?? 993);
        $sourceSsl  = (bool) ($data['source_ssl'] ?? true);
        $destHost   = trim($data['dest_host'] ?? $this->serverHostname);
        $destPort   = (int) ($data['dest_port'] ?? 993);
        $destSsl    = (bool) ($data['dest_ssl'] ?? true);

        // Resolve an optional `timeout` wrapper so a dead host can't hang the
        // request beyond a hard ceiling (imapsync's own --timeout is per-IMAP-op).
        $timeoutBin = trim((string) shell_exec('command -v timeout 2>/dev/null'));

        $results = [];
        $allOk = true;

        foreach ($data['accounts'] as $i => $account) {
            $email = strtolower(trim($account['email'] ?? ''));
            $sourcePassword = (string) ($account['source_password'] ?? '');
            $destEmail = strtolower(trim($account['dest_email'] ?? $account['email'] ?? ''));
            $destPassword = (string) ($account['dest_password'] ?? $account['source_password'] ?? '');

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results[] = ['email' => $email, 'ok' => false, 'source_ok' => false, 'dest_ok' => false, 'error' => 'Invalid email'];
                $allOk = false;
                continue;
            }
            if ($sourcePassword === '') {
                $results[] = ['email' => $email, 'ok' => false, 'source_ok' => false, 'dest_ok' => false, 'error' => 'Source password is required'];
                $allOk = false;
                continue;
            }

            $args = [
                escapeshellarg($imapsync),
                '--justlogin',
                '--nolog',
                '--no-modulesversion',
                '--noreleasecheck',
                '--timeout 20',
                '--host1 ' . escapeshellarg($sourceHost),
                '--port1 ' . $sourcePort,
                $sourceSsl ? '--ssl1' : '',
                '--user1 ' . escapeshellarg($email),
                '--password1 ' . escapeshellarg($sourcePassword),
                '--host2 ' . escapeshellarg($destHost),
                '--port2 ' . $destPort,
                $destSsl ? '--ssl2' : '',
                '--user2 ' . escapeshellarg($destEmail),
                '--password2 ' . escapeshellarg($destPassword),
            ];

            $cmd = implode(' ', array_filter($args));
            if ($timeoutBin !== '') {
                $cmd = escapeshellarg($timeoutBin) . ' 35 ' . $cmd;
            }
            $cmd .= ' 2>&1';

            $out = (string) shell_exec($cmd);

            $sourceOk = (bool) preg_match('/Host1:\s*success login/i', $out);
            $destOk   = (bool) preg_match('/Host2:\s*success login/i', $out);

            $error = null;
            if (!$sourceOk || !$destOk) {
                $allOk = false;
                $lines = preg_split('/\r?\n/', $out) ?: [];
                $errLines = array_values(array_filter($lines, static function ($l) {
                    return preg_match('/(error|fail|refus|denied|timed?\s*out|can\'?t|couldn|unable|invalid|authenticat|no route|connection)/i', $l);
                }));
                $error = trim(implode(' | ', array_slice($errLines, 0, 4)));
                if ($error === '') {
                    $error = 'Login failed — check host, port, SSL, username and password.';
                }
            }

            $results[] = [
                'email'      => $email,
                'dest_email' => $destEmail,
                'ok'         => $sourceOk && $destOk,
                'source_ok'  => $sourceOk,
                'dest_ok'    => $destOk,
                'error'      => $error,
            ];
        }

        $this->logAction('imap_migration.preflight', 'mail', $allOk ? 'success' : 'warning', [
            'accounts_count' => count($results),
            'all_ok' => $allOk,
        ]);

        return Response::success([
            'all_ok' => $allOk,
            'results' => $results,
        ], $allOk ? 'All connections succeeded' : 'Some connections failed');
    }

    /**
     * Get migration status and progress
     */
    public function status(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = $request->getParam('id');
        $db = $this->container->getDatabase();

        $stmt = $db->prepare("
            SELECT id, type, source_host, dest_host, total_accounts, completed_accounts,
                   status, progress, current_account, error_message, log_file,
                   total_messages, transferred_messages, transferred_bytes, verified, migration_mode,
                   schedule_enabled, delta_interval_minutes, next_run_at, last_delta_at, sweep_at,
                   started_at, completed_at, created_at
            FROM imap_migrations WHERE id = ?
        ");
        $stmt->execute([$id]);
        $migration = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$migration) {
            return Response::error('Migration not found', 404);
        }

        // Check if process is still running
        if ($migration['status'] === 'running') {
            $this->updateMigrationProgress($id);
            
            // Re-fetch after update
            $stmt->execute([$id]);
            $migration = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return Response::success($migration, 'Success');
    }

    /**
     * Get migration logs
     */
    public function logs(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = $request->getParam('id');
        $tail = min((int) ($request->getQuery('tail') ?? 100), 1000);
        $since = (int) ($request->getQuery('since') ?? 0); // Byte offset for incremental fetching
        
        $db = $this->container->getDatabase();
        $stmt = $db->prepare("
            SELECT log_file, status, progress, current_account, accounts,
                   total_accounts, completed_accounts,
                   total_messages, transferred_messages, transferred_bytes, verified
            FROM imap_migrations WHERE id = ?
        ");
        $stmt->execute([$id]);
        $migration = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$migration) {
            return Response::error('Migration not found', 404);
        }

        $logFile = $migration['log_file'];
        $logs = '';
        $fileSize = 0;
        $newOffset = $since;

        if ($logFile && file_exists($logFile)) {
            $fileSize = filesize($logFile);
            
            if ($since > 0 && $since < $fileSize) {
                // Incremental fetch - get new content since offset
                $handle = fopen($logFile, 'r');
                fseek($handle, $since);
                $logs = fread($handle, $fileSize - $since);
                fclose($handle);
                $newOffset = $fileSize;
            } else if ($since === 0) {
                // Initial fetch - get last N lines
                $logs = shell_exec("tail -n {$tail} " . escapeshellarg($logFile) . " 2>/dev/null") ?? '';
                $newOffset = $fileSize;
            }
        }
        
        // Parse accounts for per-account status
        $accounts = json_decode($migration['accounts'], true) ?? [];
        $accountsSummary = array_map(function($acc) {
            return [
                'email' => $acc['email'],
                'status' => $acc['status'] ?? 'pending',
                'progress' => $acc['progress'] ?? 0,
                // Absolute counts/sizes for the 3-column progress view
                // (admins want "23,412 / 45,891 emails", not just a %).
                'messages_total' => $acc['messages_total'] ?? 0,
                'messages_done' => $acc['messages_done'] ?? 0,
                'messages_transferred' => $acc['messages_transferred'] ?? 0,
                'bytes_transferred' => $acc['bytes_transferred'] ?? 0,
                'dest_messages' => $acc['dest_messages'] ?? null,
                'verified' => $acc['verified'] ?? false,
                'error' => $acc['error'] ?? null,
            ];
        }, $accounts);

        return Response::success([
            'log_file' => $logFile,
            'logs' => $logs,
            'offset' => $newOffset,
            'file_size' => $fileSize,
            'status' => $migration['status'],
            'progress' => $migration['progress'],
            'current_account' => $migration['current_account'],
            'accounts' => $accountsSummary,
            // Aggregate counters so the progress modal can show absolute
            // numbers live (not just a %) and update them while polling.
            'total_accounts' => (int) $migration['total_accounts'],
            'completed_accounts' => (int) $migration['completed_accounts'],
            'total_messages' => (int) $migration['total_messages'],
            'transferred_messages' => (int) $migration['transferred_messages'],
            'transferred_bytes' => (int) $migration['transferred_bytes'],
            'verified' => (bool) $migration['verified'],
        ], 'Success');
    }

    /**
     * Cancel a running migration
     */
    public function cancel(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = $request->getParam('id');
        $db = $this->container->getDatabase();

        $stmt = $db->prepare("SELECT id, status, pid FROM imap_migrations WHERE id = ?");
        $stmt->execute([$id]);
        $migration = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$migration) {
            return Response::error('Migration not found', 404);
        }

        if (!in_array($migration['status'], ['pending', 'running'])) {
            return Response::error('Migration cannot be cancelled (already ' . $migration['status'] . ')');
        }

        // Kill the process if running
        if ($migration['pid']) {
            posix_kill($migration['pid'], SIGTERM);
            sleep(1);
            // Force kill if still running
            if (posix_kill($migration['pid'], 0)) {
                posix_kill($migration['pid'], SIGKILL);
            }
        }

        $stmt = $db->prepare("
            UPDATE imap_migrations 
            SET status = 'cancelled', completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        $this->logAction('imap_migration.cancel', 'mail', 'success', ['migration_id' => $id]);

        return Response::success(['id' => $id, 'status' => 'cancelled'], 'Migration cancelled');
    }

    /**
     * Delete a migration record
     */
    public function delete(Request $request): Response
    {
        // requireAdmin (was requireSuperAdmin): cleaning up terminal migration
        // rows is a routine maintenance action for the hosting owner, matching
        // the permission level of cancel(). Running migrations are still
        // protected below.
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = $request->getParam('id');
        $db = $this->container->getDatabase();

        $stmt = $db->prepare("SELECT id, status, log_file FROM imap_migrations WHERE id = ?");
        $stmt->execute([$id]);
        $migration = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$migration) {
            return Response::error('Migration not found', 404);
        }

        if ($migration['status'] === 'running') {
            return Response::error('Cannot delete a running migration. Cancel it first.');
        }

        // Delete log file
        if ($migration['log_file'] && file_exists($migration['log_file'])) {
            @unlink($migration['log_file']);
        }

        $stmt = $db->prepare("DELETE FROM imap_migrations WHERE id = ?");
        $stmt->execute([$id]);

        $this->logAction('imap_migration.delete', 'mail', 'success', ['migration_id' => $id]);

        return Response::success(['id' => $id], 'Migration deleted');
    }

    /**
     * Get active migrations count (for dashboard)
     */
    public function active(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $db = $this->container->getDatabase();
        
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(status = 'running') as running,
                SUM(status = 'pending') as pending,
                SUM(status = 'completed') as completed,
                SUM(status = 'failed') as failed
            FROM imap_migrations
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $counts = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Get currently running migrations
        $stmt = $db->query("
            SELECT id, source_host, current_account, progress 
            FROM imap_migrations 
            WHERE status = 'running'
            LIMIT 5
        ");
        $running = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return Response::success([
            'counts' => $counts,
            'running' => $running,
            'server_hostname' => $this->serverHostname,
        ], 'Success');
    }

    /**
     * Configure the periodic delta-sync schedule for a migration.
     * Body: { enabled: bool, interval_minutes?: int }
     */
    public function schedule(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        $data = $request->getBody();
        $db = $this->container->getDatabase();

        $migration = $this->fetchMigration($db, $id);
        if (!$migration) {
            return Response::error('Migration not found', 404);
        }

        $enabled = !empty($data['enabled']);
        $interval = (int) ($data['interval_minutes'] ?? $migration['delta_interval_minutes'] ?? 360);
        $interval = max(30, min($interval, 10080)); // 30 min .. 1 week

        if ($enabled) {
            $db->prepare("
                UPDATE imap_migrations
                SET schedule_enabled = 1, delta_interval_minutes = ?, next_run_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                WHERE id = ?
            ")->execute([$interval, $interval, $id]);
        } else {
            $db->prepare("UPDATE imap_migrations SET schedule_enabled = 0, next_run_at = NULL WHERE id = ?")
                ->execute([$id]);
        }

        $this->logAction('imap_migration.schedule', 'mail', 'success', [
            'migration_id' => $id, 'enabled' => $enabled, 'interval_minutes' => $interval,
        ]);

        return Response::success($this->scheduleState($db, $id), $enabled ? 'Auto delta sync enabled' : 'Auto delta sync disabled');
    }

    /**
     * Trigger an immediate run in a chosen phase.
     * Body: { mode?: 'delta'|'final'|'sweep' } (default 'delta')
     */
    public function runNow(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        $data = $request->getBody();
        $mode = in_array($data['mode'] ?? 'delta', ['delta', 'final', 'sweep'], true) ? $data['mode'] : 'delta';
        $db = $this->container->getDatabase();

        $migration = $this->fetchMigration($db, $id);
        if (!$migration) {
            return Response::error('Migration not found', 404);
        }
        if (in_array($migration['status'], ['running', 'pending'], true)) {
            return Response::error('A run is already in progress for this migration');
        }
        if (self::findImapsync() === null) {
            return Response::error('imapsync is not installed on this server');
        }

        $this->dispatchRun($db, $id, $mode);

        $this->logAction('imap_migration.run', 'mail', 'success', ['migration_id' => $id, 'mode' => $mode]);

        return Response::success(['id' => $id, 'mode' => $mode, 'status' => 'pending'], ucfirst($mode) . ' sync started');
    }

    /**
     * Cutover: run a final sync now and arm a one-off post-cutover sweep so any
     * mail that landed on the source during DNS propagation is still pulled in.
     * Body: { sweep_after_hours?: int (default 48, 0 = none), run_final_now?: bool (default true) }
     */
    public function finalize(Request $request): Response
    {
        $roleCheck = $this->requireAdmin();
        if ($roleCheck) return $roleCheck;

        $id = (int) $request->getParam('id');
        $data = $request->getBody();
        $db = $this->container->getDatabase();

        $migration = $this->fetchMigration($db, $id);
        if (!$migration) {
            return Response::error('Migration not found', 404);
        }

        $sweepHours = (int) ($data['sweep_after_hours'] ?? 48);
        $sweepHours = max(0, min($sweepHours, 720)); // up to 30 days
        $runFinalNow = $data['run_final_now'] ?? true;

        // Stop periodic delta; arm the one-off sweep (0 hours = no sweep).
        if ($sweepHours > 0) {
            $db->prepare("
                UPDATE imap_migrations
                SET schedule_enabled = 0, next_run_at = NULL, sweep_at = DATE_ADD(NOW(), INTERVAL ? HOUR)
                WHERE id = ?
            ")->execute([$sweepHours, $id]);
        } else {
            $db->prepare("UPDATE imap_migrations SET schedule_enabled = 0, next_run_at = NULL, sweep_at = NULL WHERE id = ?")
                ->execute([$id]);
        }

        $message = 'Cutover schedule updated';
        if ($runFinalNow && !in_array($migration['status'], ['running', 'pending'], true)) {
            if (self::findImapsync() === null) {
                return Response::error('imapsync is not installed on this server');
            }
            $this->dispatchRun($db, $id, 'final');
            $message = 'Final cutover sync started';
        }

        $this->logAction('imap_migration.finalize', 'mail', 'success', [
            'migration_id' => $id, 'sweep_after_hours' => $sweepHours, 'ran_final' => (bool) $runFinalNow,
        ]);

        return Response::success($this->scheduleState($db, $id), $message);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function fetchMigration(\PDO $db, int $id): ?array
    {
        $stmt = $db->prepare("SELECT * FROM imap_migrations WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Compact schedule/phase state for the frontend after a schedule change. */
    private function scheduleState(\PDO $db, int $id): array
    {
        $stmt = $db->prepare("
            SELECT id, status, migration_mode, schedule_enabled, delta_interval_minutes,
                   next_run_at, last_delta_at, sweep_at
            FROM imap_migrations WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Reset a finished migration row to 'pending' in the given phase and kick
     * off the background runner. Shared by runNow()/finalize() and mirrors the
     * scheduler dispatcher so manual and automatic runs behave identically.
     */
    private function dispatchRun(\PDO $db, int $id, string $mode): void
    {
        $db->prepare("
            UPDATE imap_migrations
            SET status = 'pending', migration_mode = ?, progress = 0,
                current_account = NULL, error_message = NULL, pid = NULL
            WHERE id = ?
        ")->execute([$mode, $id]);

        $this->startMigrationProcess($id);
    }

    /**
     * Start migration process in background.
     *
     * The runner lives at api/scripts/run-imap-migration.php and ships with
     * the repo. It is the single source of truth for the imapsync invocation
     * (non-destructive flags) and for parsing counts/bytes/validation, so we
     * never regenerate it here — a stale embedded copy would silently drift
     * from the canonical schema.
     */
    private function startMigrationProcess(int $migrationId): void
    {
        $scriptPath = dirname(__DIR__, 2) . '/scripts/run-imap-migration.php';
        $runnerLog = $this->logDir . '/runner.log';

        $mark = function (string $msg) use ($runnerLog) {
            @file_put_contents($runnerLog, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
        };

        if (!file_exists($scriptPath)) {
            $mark("Runner script missing at {$scriptPath}; cannot start migration {$migrationId}");
            return;
        }

        $php = self::findPhpCli();
        $inner = sprintf('%s %s %d', escapeshellarg($php), escapeshellarg($scriptPath), $migrationId);

        // Detach from the LiteSpeed/lsphp request worker so the runner survives
        // after this HTTP request returns. Without a new session the child is
        // reaped when the worker finishes and the migration stays "pending".
        $setsid = trim((string) shell_exec('command -v setsid 2>/dev/null'));
        $prefix = ($setsid !== '' && is_executable($setsid)) ? escapeshellarg($setsid) . ' ' : 'nohup ';
        $cmd = sprintf('%s%s < /dev/null >> %s 2>&1 &', $prefix, $inner, escapeshellarg($runnerLog));

        $mark("Launching migration {$migrationId} (php={$php}, setsid=" . ($setsid !== '' ? $setsid : 'none') . ')');

        if (!$this->spawnBackground($cmd)) {
            $mark("ERROR: could not spawn background runner for migration {$migrationId}. " .
                  "exec/proc_open/popen/shell_exec all unavailable (check disable_functions). " .
                  "Run manually on the server: {$inner}");
        }
    }

    /**
     * Spawn a detached background command, trying each process primitive in
     * turn — hardened LiteSpeed PHP pools often disable some of them.
     */
    private function spawnBackground(string $cmd): bool
    {
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        if (function_exists('exec') && !in_array('exec', $disabled, true)) {
            exec($cmd);
            return true;
        }
        if (function_exists('proc_open') && !in_array('proc_open', $disabled, true)) {
            $proc = @proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (is_resource($proc)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($proc);
                return true;
            }
        }
        if (function_exists('popen') && !in_array('popen', $disabled, true)) {
            $handle = @popen($cmd, 'r');
            if (is_resource($handle)) {
                pclose($handle);
                return true;
            }
        }
        if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
            @shell_exec($cmd);
            return true;
        }
        return false;
    }

    /**
     * Update migration progress by checking log file
     */
    private function updateMigrationProgress(int $id): void
    {
        $db = $this->container->getDatabase();
        
        $stmt = $db->prepare("SELECT pid, status FROM imap_migrations WHERE id = ?");
        $stmt->execute([$id]);
        $migration = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$migration || $migration['status'] !== 'running') {
            return;
        }

        // Check if process is still running
        if ($migration['pid'] && !posix_kill($migration['pid'], 0)) {
            // Process died unexpectedly
            $db->prepare("
                UPDATE imap_migrations 
                SET status = 'failed', error_message = 'Process terminated unexpectedly', completed_at = NOW()
                WHERE id = ? AND status = 'running'
            ")->execute([$id]);
        }
    }
}

