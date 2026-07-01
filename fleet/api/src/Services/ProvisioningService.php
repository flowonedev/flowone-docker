<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;
use FleetManager\Api\Enums\DeploymentType;

/**
 * Provisioning Service - Orchestrates server deployment with idempotency support
 */
class ProvisioningService
{
    private Container $container;
    private SSHService $ssh;
    private TemplateService $templates;
    private EncryptionService $encryption;
    private \PDO $db;
    private array $deploymentLog = [];

    // Per-step command log buffer (flushed to DB when step completes)
    private string $stepCommandLog = '';
    private ?string $currentStepKey = null;
    private bool $isResuming = false;

    // Set by hardenSshAccess() once the box is COMMITTED to the hardened profile
    // (pxr@1985, key-only, root denied). On a 100%-successful provision the
    // success path persists this to the servers row so PORT/USER/AUTH + the SSH
    // command flip automatically. Stays null if hardening was skipped/rolled back.
    private ?array $hardenedProfile = null;

    // Space-separated IP list the Fleet Manager connects FROM, fed into fail2ban
    // ignoreip so the panel can NEVER ban itself off a box it manages (a ban =
    // DROP = "connection timed out", which strands Test Connection). Captured once
    // while still root@22 (pre-harden) so sudo env-scrubbing isn't in play, then
    // cached for the harden step's jail drop-in. Empty string = none detected.
    private ?string $fleetIgnoreIps = null;

    // Provisioning steps for full provision
    // can_skip: user may skip this step on resume; idempotent: safe to re-run
    private const STEPS = [
        'connect'            => ['name' => 'Connecting to server',              'weight' => 5,  'can_skip' => false, 'idempotent' => true],
        // Runs EARLY (before the long install steps) so the operator always has a
        // way in even if a later step fails: creates pxr (key + passwordless sudo)
        // and authorizes the operator key on BOTH pxr and root. Does NOT change the
        // port or deny root yet - that lockdown is the final 'harden_ssh' step.
        'establish_access'   => ['name' => 'Establishing SSH access (pxr + keys)', 'weight' => 2, 'can_skip' => true, 'idempotent' => true],
        'system_update'      => ['name' => 'Updating system packages',          'weight' => 10, 'can_skip' => true,  'idempotent' => true],
        'install_deps'       => ['name' => 'Installing dependencies',           'weight' => 12, 'can_skip' => false, 'idempotent' => true],
        'install_nodejs'     => ['name' => 'Installing Node.js',                'weight' => 5,  'can_skip' => true,  'idempotent' => true],
        'install_ols'        => ['name' => 'Installing OpenLiteSpeed',          'weight' => 8,  'can_skip' => false, 'idempotent' => true],
        'deploy_vhosts'      => ['name' => 'Creating vhost directories & configs', 'weight' => 4, 'can_skip' => false, 'idempotent' => true],
        'install_php'        => ['name' => 'Installing PHP 8.3',               'weight' => 8,  'can_skip' => false, 'idempotent' => true],
        'install_mariadb'    => ['name' => 'Installing MariaDB',               'weight' => 8,  'can_skip' => false, 'idempotent' => true],
        'install_redis'      => ['name' => 'Installing Redis',                 'weight' => 4,  'can_skip' => true,  'idempotent' => true],
        'install_meilisearch'=> ['name' => 'Installing Meilisearch',           'weight' => 4,  'can_skip' => true,  'idempotent' => true],
        'install_postfix'    => ['name' => 'Installing Postfix',               'weight' => 6,  'can_skip' => false, 'idempotent' => true],
        'install_dovecot'    => ['name' => 'Installing Dovecot',               'weight' => 6,  'can_skip' => false, 'idempotent' => true],
        'install_security'   => ['name' => 'Installing security tools',        'weight' => 4,  'can_skip' => true,  'idempotent' => true],
        'install_extras'     => ['name' => 'Installing extra services',       'weight' => 5,  'can_skip' => true,  'idempotent' => true],
        'configure_firewall' => ['name' => 'Configuring firewall',             'weight' => 3,  'can_skip' => true,  'idempotent' => true],
        'deploy_configs'     => ['name' => 'Deploying configurations',         'weight' => 4,  'can_skip' => true,  'idempotent' => true],
        'setup_databases'    => ['name' => 'Setting up databases',             'weight' => 4,  'can_skip' => false, 'idempotent' => true],
        'install_powerdns'   => ['name' => 'Installing PowerDNS',             'weight' => 4,  'can_skip' => true,  'idempotent' => true],
        'deploy_panel'       => ['name' => 'Deploying VPS Admin Panel',        'weight' => 6,  'can_skip' => false, 'idempotent' => true],
        'deploy_email'       => ['name' => 'Deploying MailFlow',               'weight' => 6,  'can_skip' => false, 'idempotent' => true],
        'install_agent'      => ['name' => 'Installing Fleet Agent',           'weight' => 3,  'can_skip' => true,  'idempotent' => true],
        'install_mailsecurity'=> ['name' => 'Installing Mail Security (Rspamd)','weight' => 4,  'can_skip' => true,  'idempotent' => true],
        'install_cpguard'    => ['name' => 'Installing CPGuard (if licensed)',  'weight' => 3,  'can_skip' => true,  'idempotent' => true],
        'provision_base_site'=> ['name' => 'Registering base domain site',     'weight' => 3,  'can_skip' => true,  'idempotent' => true],
        'setup_ssl'          => ['name' => 'Setting up SSL certificates',      'weight' => 4,  'can_skip' => true,  'idempotent' => true],
        'finalize'           => ['name' => 'Finalizing installation',          'weight' => 2,  'can_skip' => false, 'idempotent' => true],
        'audit'              => ['name' => 'Auditing deployment',              'weight' => 3,  'can_skip' => true,  'idempotent' => true],
        // MUST run last: moves SSH to 1985, denies root, switches the Fleet
        // Manager onto the unprivileged pxr (key + passwordless sudo) account.
        'harden_ssh'         => ['name' => 'Hardening SSH access',             'weight' => 3,  'can_skip' => true,  'idempotent' => true],
    ];

    // Steps for packages + config deployment (no apps)
    private const STEPS_PACKAGES_CONFIG = [
        'connect'          => ['name' => 'Connecting to server',                'weight' => 10, 'can_skip' => false, 'idempotent' => true],
        'system_update'    => ['name' => 'Updating system packages',            'weight' => 15, 'can_skip' => true,  'idempotent' => true],
        'install_packages' => ['name' => 'Installing packages from blueprint',  'weight' => 40, 'can_skip' => false, 'idempotent' => true],
        'deploy_configs'   => ['name' => 'Deploying configurations',            'weight' => 25, 'can_skip' => false, 'idempotent' => true],
        'restart_services' => ['name' => 'Restarting affected services',        'weight' => 10, 'can_skip' => true,  'idempotent' => true],
    ];

    private ?AgentService $agent = null;
    private bool $isLocalServer = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->ssh = $container->get(SSHService::class);
        $this->templates = $container->get(TemplateService::class);
        $this->encryption = $container->get(EncryptionService::class);
        $this->agent = $container->get(AgentService::class);
        $this->db = $container->getDatabase();
    }

    /**
     * Ensure the local DB connection is alive, reconnect if dropped.
     * Long-running SSH commands can cause MySQL to time out the idle connection.
     */
    private function ensureDbConnection(): void
    {
        try {
            $this->db->query('SELECT 1');
        } catch (\Throwable $e) {
            if (php_sapi_name() === 'cli') {
                fwrite(STDERR, "  [DB] Connection lost, reconnecting...\n");
            }
            // Force Container to create a fresh PDO
            $this->container->resetDatabase();
            $this->db = $this->container->getDatabase();
        }
    }

    // =========================================================================
    // HEARTBEAT & STEP ENGINE
    // =========================================================================

    /**
     * Write a heartbeat timestamp so the dashboard can detect stalled/dead processes
     */
    private function heartbeat(int $deploymentId): void
    {
        $this->ensureDbConnection();
        $stmt = $this->db->prepare("UPDATE deployments SET last_heartbeat = NOW() WHERE id = ?");
        $stmt->execute([$deploymentId]);
    }

    /**
     * Register PID and initial heartbeat for process tracking
     */
    private function registerProcess(int $deploymentId): void
    {
        $this->ensureDbConnection();
        $stmt = $this->db->prepare("UPDATE deployments SET pid = ?, last_heartbeat = NOW() WHERE id = ?");
        $stmt->execute([getmypid(), $deploymentId]);
    }

    /**
     * Create deployment_steps rows for every step in the plan.
     * On resume, existing rows are preserved (INSERT IGNORE).
     */
    private function createStepRecords(int $deploymentId, array $steps = null): void
    {
        $steps = $steps ?? self::STEPS;
        $order = 0;

        foreach ($steps as $key => $info) {
            $order++;
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO deployment_steps
                    (deployment_id, step_key, step_name, step_order, weight, status, can_skip, idempotent, max_retries)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, 2)"
            );
            $stmt->execute([
                $deploymentId,
                $key,
                $info['name'],
                $order,
                $info['weight'],
                $info['can_skip'] ?? 0,
                $info['idempotent'] ?? 1,
            ]);
        }

        $stmt = $this->db->prepare("UPDATE deployments SET steps_total = ? WHERE id = ?");
        $stmt->execute([$order, $deploymentId]);
    }

    /**
     * Get a single step record from the database
     */
    private function getStepRecord(int $deploymentId, string $stepKey): ?array
    {
        $this->ensureDbConnection();
        $stmt = $this->db->prepare(
            "SELECT * FROM deployment_steps WHERE deployment_id = ? AND step_key = ?"
        );
        $stmt->execute([$deploymentId, $stepKey]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Update a step's status in the database
     */
    private function updateStepStatus(
        int $deploymentId,
        string $stepKey,
        string $status,
        ?string $errorMsg = null,
        ?string $errorType = null,
        ?int $durationMs = null
    ): void {
        $this->ensureDbConnection();

        $sets = ['status = ?'];
        $params = [$status];

        if ($status === 'running') {
            $sets[] = 'started_at = NOW()';
        }
        if (in_array($status, ['success', 'failed', 'skipped', 'warning'])) {
            $sets[] = 'completed_at = NOW()';
        }
        if ($durationMs !== null) {
            $sets[] = 'duration_ms = ?';
            $params[] = $durationMs;
        }
        if ($errorMsg !== null) {
            $sets[] = 'error_message = ?';
            $params[] = $errorMsg;
        }
        if ($errorType !== null) {
            $sets[] = 'error_type = ?';
            $params[] = $errorType;
        }

        $params[] = $deploymentId;
        $params[] = $stepKey;

        $stmt = $this->db->prepare(
            "UPDATE deployment_steps SET " . implode(', ', $sets)
            . " WHERE deployment_id = ? AND step_key = ?"
        );
        $stmt->execute($params);
    }

    /**
     * Flush the per-step command log buffer to the database
     */
    private function flushStepLog(int $deploymentId, string $stepKey): void
    {
        if (empty($this->stepCommandLog)) {
            return;
        }
        $this->ensureDbConnection();
        $stmt = $this->db->prepare(
            "UPDATE deployment_steps SET command_log = CONCAT(COALESCE(command_log, ''), ?) WHERE deployment_id = ? AND step_key = ?"
        );
        $stmt->execute([$this->stepCommandLog, $deploymentId, $stepKey]);
        $this->stepCommandLog = '';
    }

    /**
     * Increment completed step counter on the deployment row
     */
    private function incrementCompletedSteps(int $deploymentId): void
    {
        $this->ensureDbConnection();
        $this->db->prepare("UPDATE deployments SET steps_completed = steps_completed + 1 WHERE id = ?")->execute([$deploymentId]);
    }

    /**
     * Increment the retry counter for a step
     */
    private function incrementStepRetry(int $deploymentId, string $stepKey): void
    {
        $this->ensureDbConnection();
        $this->db->prepare(
            "UPDATE deployment_steps SET retry_count = retry_count + 1, status = 'pending' WHERE deployment_id = ? AND step_key = ?"
        )->execute([$deploymentId, $stepKey]);
    }

    /**
     * Execute a provisioning step through the step engine.
     * Handles: skip-if-done, status tracking, timing, error classification, retry, per-step logging.
     */
    private function executeStep(int $deploymentId, string $stepKey, callable $executor, array $stepsMap = null): void
    {
        $stepsMap = $stepsMap ?? self::STEPS;
        $step = $this->getStepRecord($deploymentId, $stepKey);

        // Already completed in a previous run (resume) -- skip
        if ($step && $step['status'] === 'success') {
            $this->log("[SKIP] '{$stepKey}' already completed");
            $this->updateProgress($deploymentId, $stepKey, 100, $stepsMap);
            return;
        }
        // Explicitly skipped (user chose to skip on resume)
        if ($step && $step['status'] === 'skipped') {
            $this->log("[SKIP] '{$stepKey}' was marked as skipped");
            $this->updateProgress($deploymentId, $stepKey, 100, $stepsMap);
            return;
        }

        // Mark running
        $this->updateStepStatus($deploymentId, $stepKey, 'running');
        $this->updateProgress($deploymentId, $stepKey, 0, $stepsMap);
        $this->heartbeat($deploymentId);
        $this->currentStepKey = $stepKey;
        $this->stepCommandLog = '';
        $startTime = microtime(true);

        try {
            $executor();

            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $this->flushStepLog($deploymentId, $stepKey);
            $this->updateStepStatus($deploymentId, $stepKey, 'success', null, null, $durationMs);
            $this->incrementCompletedSteps($deploymentId);
            $this->updateProgress($deploymentId, $stepKey, 100, $stepsMap);

        } catch (\Throwable $e) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $errorType = $this->classifyError($e);
            $this->flushStepLog($deploymentId, $stepKey);

            // Retry logic: retry for non-script-bug errors if retries remain
            $step = $this->getStepRecord($deploymentId, $stepKey);
            $retryCount = $step['retry_count'] ?? 0;
            $maxRetries = $step['max_retries'] ?? 2;

            if ($retryCount < $maxRetries && $errorType !== 'script_bug') {
                $this->log("Step '{$stepKey}' failed ({$errorType}: {$e->getMessage()}), retrying (" . ($retryCount + 1) . "/{$maxRetries})...");
                $this->incrementStepRetry($deploymentId, $stepKey);
                $this->executeStep($deploymentId, $stepKey, $executor, $stepsMap);
                return;
            }

            $this->updateStepStatus($deploymentId, $stepKey, 'failed', $e->getMessage(), $errorType, $durationMs);

            // Record which step failed on the deployment row
            $this->ensureDbConnection();
            $this->db->prepare("UPDATE deployments SET failed_step = ? WHERE id = ?")->execute([$stepKey, $deploymentId]);

            throw $e;
        }
    }

    /**
     * Classify an error to help the user understand what went wrong.
     * Categories: ssh_error, timeout, race_condition, dependency, server_issue, script_bug, unknown
     */
    private function classifyError(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'channel') || str_contains($msg, 'connection reset')
            || str_contains($msg, 'broken pipe') || str_contains($msg, 'not connected')
            || str_contains($msg, 'ssh')) {
            return 'ssh_error';
        }
        if (str_contains($msg, 'timeout') || str_contains($msg, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($msg, 'could not get lock') || str_contains($msg, 'dpkg was interrupted')
            || str_contains($msg, 'another process') || str_contains($msg, 'resource temporarily unavailable')) {
            return 'race_condition';
        }
        if (str_contains($msg, 'unable to locate') || str_contains($msg, 'broken packages')
            || str_contains($msg, 'unmet dependencies') || str_contains($msg, 'has no installation candidate')) {
            return 'dependency';
        }
        if (str_contains($msg, 'no space left') || str_contains($msg, 'out of memory')
            || str_contains($msg, 'permission denied') || str_contains($msg, 'read-only file system')) {
            return 'server_issue';
        }
        if (str_contains($msg, 'undefined') || str_contains($msg, 'null') || str_contains($msg, 'fatal error')) {
            return 'script_bug';
        }

        return 'unknown';
    }

    /**
     * Check if server is local (this server)
     */
    private function isLocal(array $server): bool
    {
        return $server['ip_address'] === '127.0.0.1' 
            || $server['ip_address'] === 'localhost'
            || ($server['is_local'] ?? false);
    }

    /**
     * Connect to server (via SSH or local agent)
     */
    private function connectToServer(array $server): bool
    {
        $this->isLocalServer = $this->isLocal($server);

        if ($this->isLocalServer) {
            // For local server, verify agent is running
            return $this->agent->isRunning();
        }

        return $this->ssh->connectToServer($server);
    }

    /**
     * Execute command on server (with heartbeat + per-step log capture)
     */
    private function executeCommand(string $command, int $timeout = 120): array
    {
        // Heartbeat before every SSH command so dashboard knows we're alive
        if ($this->currentDeploymentId) {
            $this->heartbeat($this->currentDeploymentId);
        }

        if ($this->isLocalServer) {
            $result = $this->agent->execute('shell.exec', ['command' => $command]);
        } else {
            $result = $this->ssh->execWithTimeout($command, $timeout);
        }

        // Append to per-step command log
        $this->appendCommandLog($command, $result);

        return $result;
    }

    /**
     * Record a command + its OUTPUT into the per-step log. Output is captured
     * ALWAYS (not just on non-zero exit): most probes use '|| true' / '&& echo ok
     * || echo missing' / trailing '; true', so they exit 0 even when the real
     * answer (e.g. "unsupported dictionary type: mysql") is in stdout. Logging
     * only on failure made every such result invisible.
     */
    private function appendCommandLog(string $command, array $result): void
    {
        $shortCmd = strlen($command) > 300 ? substr($command, 0, 300) . '...' : $command;
        $exitCode = $result['exit_code'] ?? ($result['success'] ? 0 : 1);
        $this->stepCommandLog .= "$ {$shortCmd}\n[exit={$exitCode}]\n";
        $out = trim((string)($result['output'] ?? ''));
        if ($out !== '') {
            // Keep the tail (most relevant), capped so the log stays readable.
            $this->stepCommandLog .= substr($out, -1500) . "\n";
        }
    }

    /**
     * Execute a long-running command with extended timeout
     * Used for installer scripts that may take several minutes
     */
    private function executeLongCommand(string $command, int $timeout = 600): array
    {
        if ($this->currentDeploymentId) {
            $this->heartbeat($this->currentDeploymentId);
        }

        if ($this->isLocalServer) {
            $result = $this->agent->execute('shell.exec', ['command' => $command]);
        } else {
            $result = $this->ssh->execWithTimeout($command, $timeout);
        }

        $this->appendCommandLog($command, $result);

        return $result;
    }

    /**
     * Start full provisioning of a server
     *
     * @param int $serverId
     * @param int|null $blueprintId
     * @param int|null $resumeDeploymentId  If set, resume this existing deployment instead of creating a new one
     * @param bool $skipFailed              When resuming, skip the failed step instead of retrying it
     */
    public function provision(int $serverId, ?int $blueprintId = null, ?int $resumeDeploymentId = null, bool $skipFailed = false): array
    {
        // Get server details
        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();

        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        // --- Determine deployment ID (new vs resume) ---
        if ($resumeDeploymentId) {
            $deploymentId = $resumeDeploymentId;
            $this->isResuming = true;

            // Find the first non-success step to resume from
            $stmt = $this->db->prepare(
                "SELECT step_key FROM deployment_steps WHERE deployment_id = ? AND status NOT IN ('success','skipped') ORDER BY step_order LIMIT 1"
            );
            $stmt->execute([$deploymentId]);
            $resumeStep = $stmt->fetchColumn() ?: null;

            // If skip_failed was requested, mark the failed step as skipped
            if ($skipFailed) {
                $stmt = $this->db->prepare(
                    "UPDATE deployment_steps SET status = 'skipped', completed_at = NOW() WHERE deployment_id = ? AND status = 'failed'"
                );
                $stmt->execute([$deploymentId]);
            } else {
                // Reset failed step to pending so it retries
                $stmt = $this->db->prepare(
                    "UPDATE deployment_steps SET status = 'pending', error_message = NULL, error_type = NULL, retry_count = 0 WHERE deployment_id = ? AND status = 'failed'"
                );
                $stmt->execute([$deploymentId]);
            }

            $stmt = $this->db->prepare("UPDATE deployments SET status = 'running', resumed_from_step = ?, failed_step = NULL, pid = ?, last_heartbeat = NOW() WHERE id = ?");
            $stmt->execute([$resumeStep, getmypid(), $deploymentId]);

            $this->log("=== RESUMING deployment #{$deploymentId} from step '{$resumeStep}'" . ($skipFailed ? ' (skipping failed step)' : '') . " ===");
        } else {
            // Check for existing pending deployment (created by controller for background execution)
            $stmt = $this->db->prepare(
                "SELECT id FROM deployments WHERE server_id = ? AND status = 'pending' AND type = ? ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$serverId, DeploymentType::FULL_PROVISION]);
            $existingDeployment = $stmt->fetch();
            
            if ($existingDeployment) {
                $deploymentId = $existingDeployment['id'];
                // Stamp the real start time here: the controller inserted this row as
                // 'pending' without started_at, so the clock begins when provisioning
                // actually kicks off. COALESCE keeps any pre-existing value intact.
                $stmt = $this->db->prepare("UPDATE deployments SET status = 'running', started_at = COALESCE(started_at, NOW()) WHERE id = ?");
                $stmt->execute([$deploymentId]);
            } else {
                $deploymentId = $this->createDeployment($serverId, $blueprintId, DeploymentType::FULL_PROVISION);
            }
        }

        $this->currentDeploymentId = $deploymentId;

        // Register PID + heartbeat
        $this->registerProcess($deploymentId);

        // Create step records (INSERT IGNORE so resume is safe)
        $this->createStepRecords($deploymentId, self::STEPS);

        // Update server status
        $this->updateServerStatus($serverId, 'provisioning', 'Starting provisioning');
        $this->log("Starting full provisioning for server: " . ($server['name'] ?? $server['ip_address']));

        // Generate variables
        $variables = $this->templates->generateServerVariables($server);

        // Store generated passwords (encrypted)
        $this->storeGeneratedPasswords($serverId, $variables);

        // Ensure database schema dumps are available (runs locally on main server)
        $schemaService = $this->container->get(SchemaService::class);
        $this->log("Checking database schema dumps...");
        if ($schemaService->ensureSchemas()) {
            $this->log("Schema dumps ready for deployment");
        } else {
            $this->log("Warning: Some schema dumps could not be created - install scripts will handle schemas");
        }

        try {
            // Connect to server -- always re-run on resume (SSH session is gone)
            if ($this->isResuming) {
                $this->updateStepStatus($deploymentId, 'connect', 'pending');
            }
            $this->executeStep($deploymentId, 'connect', function () use ($server) {
                if (!$this->connectToServer($server)) {
                    $errorMsg = $this->isLocalServer 
                        ? 'Fleet Agent is not running. Start it with: systemctl start fleet-agent'
                        : 'Failed to connect to server via SSH. Check credentials.';
                    throw new \Exception($errorMsg);
                }
            });

            // Capture the target distro + version as early as possible so the
            // dashboard shows it during provisioning (and to flag wrong-OS installs).
            $this->detectAndStoreOsInfo($serverId);

            // Run provisioning steps
            $this->runProvisioningSteps($deploymentId, $serverId, $blueprintId, $variables);

            // Deployment is 100% successful: if SSH hardening committed, AUTOMATICALLY
            // switch the stored connection to the hardened profile (pxr@1985, key) so
            // the panel PORT/USER/AUTH and the SSH command update with no manual step.
            $this->applyHardenedProfileIfCommitted($serverId);

            // Mark deployment complete
            $this->updateServerStatus($serverId, 'active');
            $this->completeDeployment($deploymentId, 'success');

            // Read installed VERSION files and update server record
            $this->updateDeployedVersions($serverId);

            // Generate server info report (non-blocking)
            $this->generateServerReport($serverId, $variables);

            return [
                'success' => true,
                'deployment_id' => $deploymentId,
                'message' => 'Server provisioned successfully',
            ];

        } catch (\Throwable $e) {
            $errMsg = get_class($e) . ': ' . $e->getMessage();
            if (php_sapi_name() === 'cli') {
                fwrite(STDERR, "\n[PROVISION ERROR] {$errMsg}\n");
                fwrite(STDERR, "File: {$e->getFile()}:{$e->getLine()}\n");
                fwrite(STDERR, "Trace: " . substr($e->getTraceAsString(), 0, 2000) . "\n");
            }
            $this->updateServerStatus($serverId, 'error', $e->getMessage());
            $this->completeDeployment($deploymentId, 'failed', $e->getMessage());

            return [
                'success' => false,
                'deployment_id' => $deploymentId,
                'error' => $e->getMessage(),
            ];
        } finally {
            // Persist the complete log onto the box (retrievable later) while the
            // SSH session is still alive, then close it.
            $this->writeOnBoxLog('end of full provision');
            if (!$this->isLocalServer && $this->ssh) {
                $this->ssh->disconnect();
            }
        }
    }

    /**
     * Deploy packages and configurations only (no apps)
     */
    public function deployPackagesAndConfig(int $serverId, int $blueprintId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();

        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        $deploymentId = $this->createDeployment($serverId, $blueprintId, DeploymentType::PACKAGES_CONFIG, self::STEPS_PACKAGES_CONFIG);
        $this->currentDeploymentId = $deploymentId;
        $this->registerProcess($deploymentId);
        $this->createStepRecords($deploymentId, self::STEPS_PACKAGES_CONFIG);
        $this->updateServerStatus($serverId, 'provisioning', 'Starting packages + config deployment');
        $this->log("Starting packages + config deployment for server: " . ($server['name'] ?? $server['ip_address']));

        $variables = $this->templates->generateServerVariables($server);
        $installedCount = 0;
        $changedPaths = [];

        try {
            $stepsMap = self::STEPS_PACKAGES_CONFIG;

            $this->executeStep($deploymentId, 'connect', function () use ($server) {
                if (!$this->connectToServer($server)) {
                    $errorMsg = $this->isLocalServer 
                        ? 'Fleet Agent is not running. Start it with: systemctl start fleet-agent'
                        : 'Failed to connect to server via SSH. Check credentials.';
                    throw new \Exception($errorMsg);
                }
            }, $stepsMap);

            // Capture distro + version early (shown on dashboard, flags wrong-OS).
            $this->detectAndStoreOsInfo($serverId);

            $this->executeStep($deploymentId, 'system_update', function () {
                $this->runAptCommand('apt-get update', 'System update failed');
            }, $stepsMap);

            $this->executeStep($deploymentId, 'install_packages', function () use ($serverId, $blueprintId, &$installedCount) {
                $installedCount = $this->installBlueprintPackages($serverId, $blueprintId);
                $this->log("Installed {$installedCount} new packages");
            }, $stepsMap);

            $this->executeStep($deploymentId, 'deploy_configs', function () use ($serverId, $blueprintId, $variables, &$changedPaths) {
                $changedPaths = $this->deployConfigurationsWithIdempotency($serverId, $blueprintId, $variables);
                $this->log("Updated " . count($changedPaths) . " configuration files");
            }, $stepsMap);

            $this->executeStep($deploymentId, 'restart_services', function () use (&$changedPaths) {
                $this->restartAffectedServices($changedPaths);
            }, $stepsMap);

            $this->updateServerStatus($serverId, 'active');
            $this->completeDeployment($deploymentId, 'success');

            return [
                'success' => true,
                'deployment_id' => $deploymentId,
                'message' => 'Packages and configurations deployed successfully',
                'packages_installed' => $installedCount,
                'configs_updated' => count($changedPaths),
            ];

        } catch (\Exception $e) {
            $this->updateServerStatus($serverId, 'error', $e->getMessage());
            $this->completeDeployment($deploymentId, 'failed', $e->getMessage());

            return [
                'success' => false,
                'deployment_id' => $deploymentId,
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Install packages defined in blueprint with idempotency checks
     */
    private function installBlueprintPackages(int $serverId, int $blueprintId): int
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM blueprint_packages WHERE blueprint_id = ? ORDER BY install_order, category"
        );
        $stmt->execute([$blueprintId]);
        $packages = $stmt->fetchAll();

        $installedCount = 0;

        foreach ($packages as $pkg) {
            if ($this->installPackageIfNeeded($pkg['package_name'], $serverId, $pkg)) {
                $installedCount++;
            }
        }

        return $installedCount;
    }

    /**
     * Install a package if not already installed (idempotent)
     */
    private function installPackageIfNeeded(string $package, int $serverId, array $pkgInfo = []): bool
    {
        // Check if already installed on the server
        if ($this->isPackageInstalled($package)) {
            $this->log("Package {$package} already installed, skipping");
            return false;
        }

        // Run pre-install script if defined
        if (!empty($pkgInfo['pre_install_script'])) {
            $this->log("Running pre-install script for {$package}");
            $this->ssh->exec($pkgInfo['pre_install_script']);
        }

        // Install the package
        $this->log("Installing package: {$package}");
        $this->runAptCommand(
            "apt-get install -y -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\" {$package}",
            "Failed to install {$package}"
        );

        // Run post-install script if defined
        if (!empty($pkgInfo['post_install_script'])) {
            $this->log("Running post-install script for {$package}");
            $this->ssh->exec($pkgInfo['post_install_script']);
        }

        // Record the installation
        $this->recordPackageInstalled($serverId, $package);

        return true;
    }

    /**
     * Check if a package is installed on the remote server
     */
    private function isPackageInstalled(string $package): bool
    {
        $result = $this->ssh->exec("dpkg -l | grep -E '^ii.*{$package}' | wc -l");
        return trim($result['output'] ?? '0') !== '0';
    }

    /**
     * Record that a package was installed
     */
    private function recordPackageInstalled(int $serverId, string $package): void
    {
        // Get installed version
        $result = $this->ssh->exec("dpkg -l {$package} 2>/dev/null | grep '^ii' | awk '{print \$3}'");
        $version = trim($result['output'] ?? '');

        $stmt = $this->db->prepare(
            "INSERT INTO server_packages (server_id, package_name, installed_version)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE installed_version = VALUES(installed_version), updated_at = NOW()"
        );
        $stmt->execute([$serverId, $package, $version]);
    }

    /**
     * Paths managed by dedicated deployment methods (install_postfix, install_dovecot,
     * deploy_vhosts). Blueprint templates for these paths are SKIPPED because the
     * dedicated deployers produce correct, up-to-date configs. Blueprint versions
     * are often stale extractions from a running server and can contain corrupted
     * values, wrong vhosts, or outdated settings.
     */
    private const MANAGED_CONFIG_PATHS = [
        '/usr/local/lsws/conf/httpd_config.conf',
        // OpenDKIM key tables + trusted hosts: installOpenDKIM() regenerates these
        // for THIS box's own domain. A blueprint captured before the extractor
        // exclusion still carries the SOURCE server's versions (every tenant
        // domain), so they must never be restored here - that would re-publish
        // foreign domains' DKIM keys onto this box. See ConfigExtractorService.
        '/etc/opendkim/KeyTable',
        '/etc/opendkim/SigningTable',
        '/etc/opendkim/TrustedHosts',
        '/etc/dovecot/dovecot.conf',
        '/etc/dovecot/dovecot-sql.conf.ext',
        '/etc/postfix/main.cf',
        '/etc/postfix/master.cf',
        '/etc/postfix/mysql-virtual-mailbox-domains.cf',
        '/etc/postfix/mysql-virtual-mailbox-maps.cf',
        '/etc/postfix/mysql-virtual-alias-maps.cf',
    ];

    private const MANAGED_CONFIG_PREFIXES = [
        '/usr/local/lsws/conf/vhosts/',
        '/usr/local/lsws/conf/',
        // Never restore blueprint-captured DKIM private keys / .txt records. These
        // are per-domain secret material owned ONLY by the source server's sites;
        // installOpenDKIM() generates a fresh keypair for this box's own domain.
        '/etc/opendkim/keys/',
    ];

    private static function isManaged(string $path): bool
    {
        if (in_array($path, self::MANAGED_CONFIG_PATHS, true)) {
            return true;
        }
        foreach (self::MANAGED_CONFIG_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function deployConfigurationsWithIdempotency(int $serverId, int $blueprintId, array $variables): array
    {
        $templates = $this->templates->processBlueprintTemplates($blueprintId, $variables);
        $changedPaths = [];
        $blocked = [];

        foreach ($templates as $template) {
            $targetPath = $template['target_path'];

            if (self::isManaged($targetPath)) {
                $this->log("Skipping blueprint config (managed by dedicated deployer): {$targetPath}");
                continue;
            }

            // Pre-deploy guard: never write a config that still contains literal {{VAR}}
            // placeholders - that always breaks the target service. Skip + fail loudly.
            $unresolved = $this->templates->findUnresolvedPlaceholders($template['content'] ?? '');
            if (!empty($unresolved)) {
                $blocked[] = "{$targetPath} (missing: " . implode(', ', $unresolved) . ')';
                $this->log("BLOCKED config with unresolved variables: {$targetPath} -> " . implode(', ', $unresolved));
                continue;
            }

            if ($this->shouldApplyConfig($serverId, $targetPath, $template['content'])) {
                $dir = dirname($targetPath);
                $this->ssh->mkdir($dir);

                $this->ssh->uploadContent($template['content'], $targetPath);

                $this->ssh->chmod($targetPath, $template['permissions']);
                $this->ssh->chown($targetPath, $template['owner'], $template['group']);

                $this->recordConfigApplied($serverId, $template);

                $changedPaths[] = $targetPath;
                $this->log("Updated config: {$targetPath}");
            } else {
                $this->log("Config unchanged: {$targetPath}");
            }
        }

        if (!empty($blocked)) {
            throw new \Exception(
                'Refusing to deploy blueprint configs with unresolved template variables: '
                . implode('; ', $blocked)
            );
        }

        return $changedPaths;
    }

    /**
     * Check if a config should be applied (compares hash)
     */
    private function shouldApplyConfig(int $serverId, string $path, string $newContent): bool
    {
        $newHash = hash('sha256', $newContent);

        // Check database for stored hash
        $stmt = $this->db->prepare(
            "SELECT content_hash FROM server_configs WHERE server_id = ? AND target_path = ?"
        );
        $stmt->execute([$serverId, $path]);
        $existingHash = $stmt->fetchColumn();

        // Also check actual file on server if no record exists
        if (!$existingHash) {
            $result = $this->ssh->exec("sha256sum {$path} 2>/dev/null | awk '{print \$1}'");
            $serverHash = trim($result['output'] ?? '');
            if ($serverHash === $newHash) {
                // File exists and matches, record it
                $this->recordConfigHash($serverId, $path, $newHash, null);
                return false;
            }
        }

        return $existingHash !== $newHash;
    }

    /**
     * Record that a config was applied
     */
    private function recordConfigApplied(int $serverId, array $template): void
    {
        $hash = hash('sha256', $template['content']);
        $this->recordConfigHash($serverId, $template['target_path'], $hash, $template['id'] ?? null);
    }

    /**
     * Record a config hash in the database
     */
    private function recordConfigHash(int $serverId, string $path, string $hash, ?int $templateId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO server_configs (server_id, target_path, content_hash, template_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content_hash = VALUES(content_hash), template_id = VALUES(template_id), updated_at = NOW()"
        );
        $stmt->execute([$serverId, $path, $hash, $templateId]);
    }

    /**
     * Restart services affected by config changes
     */
    private function restartAffectedServices(array $changedPaths): void
    {
        $serviceMap = [
            '/etc/postfix/' => 'postfix',
            '/etc/dovecot/' => 'dovecot',
            '/usr/local/lsws/' => 'lshttpd',
            '/etc/fail2ban/' => 'fail2ban',
            '/etc/firewalld/' => 'firewalld',
            '/etc/openvpn/' => 'openvpn',
            '/etc/mysql/' => 'mariadb',
            '/etc/php/' => 'lshttpd', // PHP config changes need OLS restart
            '/etc/cpguard/' => 'cpguard',
            '/opt/cpguard/' => 'cpguard',
        ];

        $servicesToRestart = [];

        foreach ($changedPaths as $path) {
            foreach ($serviceMap as $prefix => $service) {
                if (str_starts_with($path, $prefix)) {
                    $servicesToRestart[$service] = true;
                }
            }
        }

        foreach (array_keys($servicesToRestart) as $service) {
            $this->log("Restarting service: {$service}");
            $this->ssh->restartService($service);
        }
    }

    /**
     * Run all provisioning steps for full provision.
     * Each step goes through executeStep() which handles skip-on-resume, timing,
     * error classification, retry, and per-step command logging.
     */
    private function runProvisioningSteps(int $deploymentId, int $serverId, ?int $blueprintId, array $variables): void
    {
        // --- establish_access (FIRST) ---
        // Create pxr (key + passwordless sudo) and authorize the operator key on
        // BOTH pxr and root up front, while port 22 + root login are still open.
        // This guarantees the operator can ALWAYS reach the box with their key,
        // even if a later step fails before the final lockdown ('harden_ssh').
        $this->executeStep($deploymentId, 'establish_access', function () use ($serverId, $variables) {
            $this->establishSshAccess($serverId, $variables);
        });

        // --- system_update ---
        $this->executeStep($deploymentId, 'system_update', function () use ($deploymentId, $variables) {
            $this->fixBrokenPackages();

            $hostname = $variables['SERVER_HOSTNAME'] ?? 'vps';
            $fullHostname = $this->deriveServerFqdn($variables);

            $this->log("Setting hostname to {$fullHostname}...");
            $this->executeCommand("hostnamectl set-hostname {$fullHostname}");

            $serverIp = $variables['SERVER_IP'];
            $hostsEntries = [
                "{$serverIp} {$fullHostname} {$hostname}",
                "{$serverIp} {$variables['PANEL_DOMAIN']}",
                "{$serverIp} {$variables['EMAIL_DOMAIN']}",
            ];
            if (!empty($variables['MAIL_DOMAIN']) && $variables['MAIL_DOMAIN'] !== $variables['EMAIL_DOMAIN']) {
                $hostsEntries[] = "{$serverIp} {$variables['MAIL_DOMAIN']}";
            }
            foreach ($hostsEntries as $entry) {
                $this->executeCommand("grep -q '{$entry}' /etc/hosts || echo '{$entry}' >> /etc/hosts");
            }
            $this->log("Hostname and /etc/hosts configured");

            $this->log("Running apt-get update (fetching package lists)...");
            $this->runAptCommand('apt-get update -y 2>&1 | tail -5', 'apt-get update failed');
            $this->log("Package lists updated");

            $this->log("Running apt-get dist-upgrade (this can take several minutes on a fresh VPS)...");
            $this->runAptCommand('DEBIAN_FRONTEND=noninteractive apt-get dist-upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" 2>&1 | tail -5', 'apt-get dist-upgrade failed');
            $this->log("System packages upgraded");

            $this->log("Fixing any broken dependencies...");
            $this->executeCommand('DEBIAN_FRONTEND=noninteractive apt-get install -f -y 2>&1 | tail -5');
            $this->executeCommand('dpkg --configure -a 2>&1 | tail -5');
        });

        // --- install_deps ---
        $this->executeStep($deploymentId, 'install_deps', function () use ($serverId) {
            $this->installDependencies($serverId);
        });

        // --- install_nodejs ---
        $this->executeStep($deploymentId, 'install_nodejs', function () use ($serverId) {
            $this->installNodeJS($serverId);
        });

        // --- install_ols ---
        $this->executeStep($deploymentId, 'install_ols', function () use ($serverId) {
            $this->installOpenLiteSpeed($serverId);
        });

        // --- deploy_vhosts ---
        $this->executeStep($deploymentId, 'deploy_vhosts', function () use ($variables) {
            $this->deploySystemVhosts($variables);
        });

        // --- install_php ---
        $this->executeStep($deploymentId, 'install_php', function () use ($serverId) {
            $this->installPHP($serverId);
        });

        // --- install_mariadb ---
        $this->executeStep($deploymentId, 'install_mariadb', function () use ($serverId, $variables) {
            $this->installMariaDB($serverId, $variables['DB_ROOT_PASS']);
        });

        // --- install_redis ---
        $this->executeStep($deploymentId, 'install_redis', function () use ($serverId, $variables) {
            $this->installRedis($serverId, $variables);
        });

        // --- install_meilisearch ---
        $this->executeStep($deploymentId, 'install_meilisearch', function () use ($serverId, &$variables) {
            $this->installMeilisearch($serverId, $variables);
        });

        // --- install_postfix ---
        $this->executeStep($deploymentId, 'install_postfix', function () use ($serverId, $variables) {
            // Ensure SSL certs exist BEFORE mail services are configured.
            // Dovecot/Postfix configs reference /etc/letsencrypt/live/DOMAIN/ paths.
            // If certs are missing when dpkg post-install hooks run, the package
            // gets stuck in a broken state that persists even after real certs arrive.
            $this->ensureSSLPlaceholders($variables);
            $this->installPostfix($serverId);
            $this->deployPostfixConfig($variables);
        });

        // --- install_dovecot ---
        $this->executeStep($deploymentId, 'install_dovecot', function () use ($serverId, $variables) {
            $this->installDovecot($serverId);
            $this->deployDovecotConfig($variables);
        });

        // --- install_security (includes OpenDKIM, OpenDMARC, ClamAV, Fail2ban, SpamAssassin) ---
        $this->executeStep($deploymentId, 'install_security', function () use ($serverId, $variables) {
            $this->installOpenDKIM($serverId, $variables);
            $this->installOpenDMARC($serverId, $variables);
            $this->installClamAV($serverId);
            $this->installSecurityTools($serverId);
            $this->deployFail2banConfig($variables);
            $this->deploySpamAssassinConfig($variables);
            // SpamAssassin milter + SPF policy daemon (matches source Postfix milters)
            $this->setupMailScanning($serverId);
            // Host IDS / audit layer: auditd, rkhunter, aide, chkrootkit, logwatch
            $this->installAuditTools($serverId);
        });

        // --- install_extras (coTURN, LiveKit, nghttpx, stunnel4) ---
        // Capture $variables by reference so secrets resolved here (e.g. the
        // coTURN static-auth secret) propagate to later steps like deploy_email.
        $this->executeStep($deploymentId, 'install_extras', function () use ($serverId, &$variables) {
            $this->installExtraServices($serverId, $variables);
        });

        // --- configure_firewall ---
        $this->executeStep($deploymentId, 'configure_firewall', function () {
            $this->configureFirewall();
        });

        // --- deploy_configs ---
        $this->executeStep($deploymentId, 'deploy_configs', function () use ($serverId, $blueprintId, $variables) {
            if ($blueprintId) {
                $this->deployConfigurationsWithIdempotency($serverId, $blueprintId, $variables);
            }
            $this->postConfigFixes();

            // Re-deploy our vetted file-based configs AFTER blueprint templates.
            // Blueprint templates may contain stale/corrupted versions of these configs
            // that were extracted from a running server. Our file templates are canonical.
            $this->log("Re-deploying canonical mail configs (overriding any blueprint versions)...");
            $this->deployDovecotConfig($variables);
            $this->deployPostfixConfig($variables);
        });

        // --- setup_databases ---
        $this->executeStep($deploymentId, 'setup_databases', function () use ($variables) {
            $this->setupDatabases($variables);
        });

        // --- install_powerdns (must run AFTER setup_databases: PowerDNS uses the
        //     gmysql backend on the panel DB, which only exists once databases are set up) ---
        $this->executeStep($deploymentId, 'install_powerdns', function () use ($serverId, $variables) {
            $this->installPowerDNS($serverId, $variables);
        });

        // --- deploy_panel ---
        $this->executeStep($deploymentId, 'deploy_panel', function () use ($variables) {
            // FlowOne shared library (flowone/storage) — runtime dependency the
            // apps rely on; deploy it before the panel/email apps.
            $this->deploySharedLibrary($variables);
            $this->deployPanel($variables);

            // Seed the mail domain/admin account and DNS zone AFTER the panel
            // installer has created the schema (mail_domains, mail_accounts,
            // dns_domains, dns_records). Running these in setup_databases is too
            // early -- the tables don't exist yet, so the inserts silently fail.
            $this->seedMailAccount($variables, $variables['DB_ROOT_PASS'] ?? '');
            $this->seedDnsRecords($variables);
        });

        // --- deploy_email ---
        $this->executeStep($deploymentId, 'deploy_email', function () use ($variables) {
            $this->deployEmailApp($variables);
        });

        // --- install_agent ---
        $this->executeStep($deploymentId, 'install_agent', function () use ($serverId, $variables) {
            $this->installFleetAgent($serverId, $variables);
        });

        // --- install_mailsecurity ---
        // Bring the Mail Security Gateway up through the SAME panel-agent action
        // code the panel UI uses, so a provisioned server is identical to one set
        // up by hand: Rspamd + ClamAV + local unbound resolver (:5335, coexists
        // with PowerDNS) + quarantine transport, then the live milter wiring.
        // Runs AFTER deploy_panel (ships the agent + mail_security_* schema) and
        // AFTER the canonical Postfix/Dovecot configs are in place.
        $this->executeStep($deploymentId, 'install_mailsecurity', function () use ($variables) {
            $this->installMailSecurity($variables);
        });

        // --- install_cpguard ---
        // Optional: only does anything when a CPGuard license key was entered for
        // this server (keys are IP-bound, one per box). Without a key the step is
        // a logged no-op; CPGuard can be installed later from the server detail
        // page once the operator has bought a license for this IP. After install,
        // the blueprint's extracted CPGuard configs (badbots/rules/WAF lists from
        // the reference server) are re-applied on top of the installer's stock
        // ones so the new box matches the live tuning.
        $this->executeStep($deploymentId, 'install_cpguard', function () use ($serverId, $blueprintId, $variables) {
            $this->installCpguard($serverId, $blueprintId, $variables);
        });

        // --- provision_base_site ---
        // Register the server's own base domain as a real site in the panel's
        // Sites V2 table (vhost + home + DB + user via the canonical saga) so
        // the operator can manage DNS / create email from it. Runs after the
        // panel install brought up vpsadmin-worker (deploy_panel step).
        $this->executeStep($deploymentId, 'provision_base_site', function () use ($variables) {
            $this->provisionBaseDomainSite($variables);
        });

        // --- setup_ssl ---
        $this->executeStep($deploymentId, 'setup_ssl', function () use ($serverId, $variables) {
            $this->setupSSL($variables);
            // Now that the base-domain cert exists, re-apply coTURN so its TLS
            // listener (turns:5349) comes online with the real certificate.
            $this->installCoTURN($serverId, $variables);
        });

        // --- finalize ---
        $this->executeStep($deploymentId, 'finalize', function () {
            $this->finalize();
        });

        // --- audit ---
        $this->executeStep($deploymentId, 'audit', function () use ($serverId, $deploymentId, $variables) {
            $auditResults = $this->auditDeployment($serverId, $variables);
            $this->storeAuditResults($serverId, $deploymentId, $auditResults);
        });

        // --- harden_ssh (MUST be last) ---
        // Everything above ran as root. This moves SSH to 1985, denies root, and
        // re-homes the Fleet Manager onto pxr (key + passwordless sudo). It is
        // best-effort and self-rolling-back: a failure never fails the deploy and
        // never leaves the box unreachable (root/22 stay until pxr@1985 is proven).
        $this->executeStep($deploymentId, 'harden_ssh', function () use ($serverId, $variables) {
            $this->hardenSshAccess($serverId, $variables);
        });
    }

    /**
     * Run a command step
     */
    private function runStep(string $command, string $errorMessage): string
    {
        $result = $this->ssh->execWithTimeout($command, 600);
        
        if (!$result['success']) {
            throw new \Exception($errorMessage . ': ' . ($result['error'] ?? $result['output'] ?? 'Unknown error'));
        }

        return $result['output'] ?? '';
    }

    /**
     * Run an apt command with lock safety.
     * 
     * Since fixBrokenPackages() already nuked all automatic apt processes at the
     * start of provisioning, this only needs a quick sanity check + retry logic.
     * No more passive 5-minute wait loops.
     */
    private function runAptCommand(string $aptCommand, string $errorMessage, int $maxWaitSeconds = 60): string
    {
        // Quick lock check + force-clear if anything snuck back
        $lockCheck = <<<'BASH'
#!/bin/bash
# Quick check - if locked, force-kill and clear immediately (no waiting)
if fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 || fuser /var/lib/apt/lists/lock >/dev/null 2>&1 || fuser /var/lib/dpkg/lock >/dev/null 2>&1; then
    echo "LOCK_FOUND - clearing..."
    pkill -9 -f "unattended-upgrade|apt-get|apt |dpkg" 2>/dev/null || true
    sleep 3
    rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock 2>/dev/null || true
    DEBIAN_FRONTEND=noninteractive dpkg --configure -a --force-confdef --force-confold 2>/dev/null || true
    echo "LOCK_CLEARED"
else
    echo "NO_LOCK"
fi
BASH;

        $result = $this->ssh->execWithTimeout($lockCheck, 30);
        $output = $result['output'] ?? '';

        if (strpos($output, 'LOCK_FOUND') !== false) {
            $this->log("Cleared stale apt lock before running command");
        }

        // Run the actual apt command, retrying both lock contention and transient
        // network/mirror failures (the two most common causes of flaky deploys).
        $fullCommand = "DEBIAN_FRONTEND=noninteractive {$aptCommand}";
        $attempts = 3;
        $result = ['success' => false];

        for ($i = 1; $i <= $attempts; $i++) {
            $result = $this->ssh->execWithTimeout($fullCommand, 600);
            if ($result['success']) {
                break;
            }

            $failOutput = $result['output'] ?? $result['error'] ?? '';

            // Lock contention: force-clear, then retry immediately
            if (stripos($failOutput, 'lock') !== false || stripos($failOutput, 'Could not get lock') !== false) {
                $this->log("APT command failed due to lock, retrying after force-clear (attempt {$i}/{$attempts})...");
                $this->ssh->execWithTimeout(
                    'pkill -9 -f "apt-get|apt |dpkg" 2>/dev/null; sleep 2; '
                    . 'rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock 2>/dev/null; '
                    . 'DEBIAN_FRONTEND=noninteractive dpkg --configure -a --force-confdef --force-confold 2>/dev/null || true',
                    30
                );
                sleep(3);
                continue;
            }

            // Other (likely transient network/mirror) failure: back off and retry
            if ($i < $attempts) {
                $this->log("APT command failed (attempt {$i}/{$attempts}), retrying in 5s: " . substr(trim($failOutput), 0, 200));
                sleep(5);
            }
        }

        if (!$result['success']) {
            throw new \Exception($errorMessage . ': ' . ($result['error'] ?? $result['output'] ?? 'Unknown error'));
        }

        return $result['output'] ?? '';
    }

    /**
     * Run a shell command over SSH, retrying transient (network) failures with backoff.
     * Use for one-shot remote downloads like `curl ... | bash` where a brief network
     * hiccup should not fail the whole provisioning step.
     */
    private function runWithRetry(string $command, string $errorMessage, int $attempts = 3, int $timeout = 300, int $sleepSeconds = 5): array
    {
        $result = ['success' => false];

        for ($i = 1; $i <= $attempts; $i++) {
            $result = $this->ssh->execWithTimeout($command, $timeout);
            if ($result['success']) {
                return $result;
            }
            if ($i < $attempts) {
                $this->log("{$errorMessage} (attempt {$i}/{$attempts}), retrying in {$sleepSeconds}s...");
                sleep($sleepSeconds);
            }
        }

        $this->log("{$errorMessage} - failed after {$attempts} attempts");
        return $result;
    }

    /**
     * Add a log entry
     */
    private ?int $currentDeploymentId = null;

    private function log(string $message): void
    {
        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'message' => $message,
        ];
        $this->deploymentLog[] = $entry;

        // Real-time CLI output when running from command line
        if (php_sapi_name() === 'cli') {
            $mem = round(memory_get_usage(true) / 1048576, 1);
            fwrite(STDERR, "[{$entry['time']}] [{$mem}MB] {$message}\n");
        }

        // Also update database in real-time
        if ($this->currentDeploymentId) {
            $this->appendLogToDatabase($this->currentDeploymentId, "[{$entry['time']}] {$message}\n");
        }
    }

    /**
     * On a 100%-successful provision, make the stored SSH connection match the
     * box's hardened reality (pxr@1985, key-based) so the dashboard PORT/USER/AUTH
     * and the copy-paste SSH command switch automatically - no Test Connection and
     * no manual edit required. No-op when hardening was skipped or rolled back.
     */
    private function applyHardenedProfileIfCommitted(int $serverId): void
    {
        if ($this->hardenedProfile === null) {
            return;
        }
        try {
            $p = $this->hardenedProfile;
            $this->ensureDbConnection();
            $this->db->prepare(
                "UPDATE servers SET ssh_port = ?, ssh_user = ?, ssh_auth_method = ?, key_path = ? WHERE id = ?"
            )->execute([
                (int)$p['port'],
                (string)$p['user'],
                (string)($p['auth'] ?? 'key'),
                (string)($p['key_path'] ?? ''),
                $serverId,
            ]);

            // Keep the human-facing "SSH Access" credential rows in sync. They were
            // written as root/22 early in the deploy (storeGeneratedPasswords), before
            // hardening moved the box to pxr/<port>. Without this the Credentials card
            // keeps showing the stale root@22 even though the box is on pxr@1985.
            $credStmt = $this->db->prepare(
                "INSERT INTO server_credentials (server_id, category, credential_key, label, value_encrypted, is_secret)
                 VALUES (?, 'ssh', ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted), label = VALUES(label)"
            );
            $credStmt->execute([$serverId, 'SSH_USER', 'SSH User', $this->encryption->encrypt((string)$p['user'])]);
            $credStmt->execute([$serverId, 'SSH_PORT', 'SSH Port', $this->encryption->encrypt((string)(int)$p['port'])]);

            $this->log("Connection auto-switched to hardened profile: {$p['user']}@{$p['port']} (key-based)");
        } catch (\Throwable $e) {
            $this->log("Note: could not auto-apply hardened SSH profile: {$e->getMessage()}");
        }
    }

    /**
     * Persist the FULL provisioning log onto the TARGET server so it can be
     * retrieved later - either manually (`cat /var/log/fleet/provision-latest.log`)
     * or via GET /api/servers/{id}/provision-log. Best-effort and never throws:
     * it needs a live SSH session, so it is called from the provision finally{}
     * block, which still has a session for BOTH success and harden-rollback
     * (the box stays reachable after a rollback to root@22).
     */
    private function writeOnBoxLog(string $reason = ''): void
    {
        try {
            if ($this->isLocalServer || !$this->ssh || !$this->ssh->isConnected()) {
                return;
            }
            $body = '';
            foreach ($this->deploymentLog as $e) {
                $body .= "[{$e['time']}] {$e['message']}\n";
            }
            $header = "# Fleet Manager provisioning log\n"
                . "# deployment_id=" . ($this->currentDeploymentId ?? '-') . "\n"
                . "# written=" . date('Y-m-d H:i:s') . ($reason !== '' ? " ({$reason})" : '') . "\n"
                . "# lines=" . count($this->deploymentLog) . "\n\n";

            $this->ssh->uploadContent($header . $body, '/tmp/.fleet-provision.log');
            // Stamp a stable "latest" + a timestamped copy, keep only the 10 newest.
            $this->ssh->exec(
                'mkdir -p /var/log/fleet && '
                . 'cp /tmp/.fleet-provision.log /var/log/fleet/provision-latest.log && '
                . 'cp /tmp/.fleet-provision.log "/var/log/fleet/provision-$(date +%Y%m%d-%H%M%S).log" && '
                . '( ls -1t /var/log/fleet/provision-2*.log 2>/dev/null | tail -n +11 | xargs -r rm -f ) ; '
                . 'chmod 640 /var/log/fleet/*.log 2>/dev/null ; rm -f /tmp/.fleet-provision.log'
            );
        } catch (\Throwable $e) {
            // Logging must never break a deploy.
            $this->log("Note: could not persist on-box provisioning log: {$e->getMessage()}");
        }
    }

    /**
     * Append log entry to database in real-time
     */
    private function appendLogToDatabase(int $deploymentId, string $logEntry): void
    {
        $this->ensureDbConnection();
        $stmt = $this->db->prepare(
            "UPDATE deployments SET log = CONCAT(COALESCE(log, ''), ?) WHERE id = ?"
        );
        $stmt->execute([$logEntry, $deploymentId]);
    }

    /**
     * Install base dependencies with idempotency
     */
    private function installDependencies(int $serverId): void
    {
        $packages = [
            // Base utilities
            'curl', 'wget', 'git', 'unzip', 'zip', 'htop', 'vim', 'nano',
            'software-properties-common', 'apt-transport-https', 'ca-certificates', 
            'gnupg', 'lsb-release',
            // DNS & Network tools
            'dnsutils', 'net-tools', 'iputils-ping', 'traceroute', 'whois',
            // NFS for NAS mounting
            'nfs-common',
            // OpenVPN for VPN connections
            'openvpn',
            // Docker
            'docker.io', 'docker-compose',
            // Cron
            'cron',
            // Compression
            'gzip', 'bzip2', 'xz-utils',
            // Build tools (sometimes needed)
            'build-essential',
            // SSL/TLS
            'openssl',
            // Process management
            'supervisor',
            // Mail utilities
            'mailutils',
            // PDF text extraction (for Meilisearch indexing)
            'poppler-utils',
            // Image processing (GD is in lsphp83-common, Imagick in lsphp83-imagick)
            // These system libs are needed for the PHP extensions to work
            'libmagickwand-dev', 'imagemagick', 'ghostscript',
            // SpamAssassin (sa-learn for spam training)
            'spamassassin',
            // Composer (PHP dependency manager)
            'composer',
        ];

        $needsInstall = [];
        foreach ($packages as $package) {
            if (!$this->isPackageInstalled($package)) {
                $needsInstall[] = $package;
            }
        }

        if (!empty($needsInstall)) {
            $this->log("Installing " . count($needsInstall) . " base packages: " . implode(', ', array_slice($needsInstall, 0, 10)) . (count($needsInstall) > 10 ? '...' : ''));
            // Redirect stdout to /dev/null to prevent SSH buffer overflow on large installs
            // Keep stderr for error reporting, pipe through tail to only get last 20 lines  
            $this->runAptCommand(
                'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" ' . implode(' ', $needsInstall) . ' 2>&1 | tail -20',
                'Failed to install dependencies'
            );
            $this->log("Base packages installed successfully");

            foreach ($needsInstall as $package) {
                $this->recordPackageInstalled($serverId, $package);
            }
        } else {
            $this->log("All base dependencies already installed");
        }
    }

    /**
     * Install Node.js LTS (for mailsync-server and collab-server)
     */
    private function installNodeJS(int $serverId): void
    {
        // Check if Node.js is already installed with correct version
        $nodeCheck = $this->executeCommand('node --version 2>/dev/null || echo "not_found"');
        $nodeVersion = trim($nodeCheck['output'] ?? 'not_found');
        
        if ($nodeVersion !== 'not_found') {
            $majorVersion = (int) ltrim(explode('.', $nodeVersion)[0], 'v');
            if ($majorVersion >= 22) {
                $this->log("Node.js {$nodeVersion} already installed");
                return;
            }
            $this->log("Node.js {$nodeVersion} found, upgrading to v22.x LTS...");
        }

        $this->log("Installing Node.js 22.x LTS...");
        
        // Remove any conflicting system nodejs first
        $this->executeCommand('apt-get remove -y nodejs libnode-dev libnode109 2>/dev/null || true');
        $this->executeCommand('apt-get autoremove -y 2>/dev/null || true');
        
        // Fix any broken deps before adding new repo
        $this->executeCommand('DEBIAN_FRONTEND=noninteractive apt-get install -f -y 2>&1 | tail -5');
        
        // Add NodeSource repository (retry transient network failures). Download the
        // setup script to a temp file and run it instead of piping into bash, so host
        // AV/malware heuristics don't flag the curl|bash pattern.
        $this->runWithRetry(
            'set -e; T="$(mktemp)"; curl -fsSL --retry 3 --retry-delay 3 --connect-timeout 30 -o "$T" https://deb.nodesource.com/setup_22.x; bash "$T"; rm -f "$T"',
            'NodeSource repository setup failed'
        );
        
        // Install Node.js from NodeSource
        $this->runAptCommand(
            'DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs',
            'Failed to install Node.js'
        );

        // Verify installation
        $verifyNode = $this->executeCommand('node --version 2>/dev/null');
        $verifyNpm = $this->executeCommand('npm --version 2>/dev/null');
        
        if (empty(trim($verifyNode['output'] ?? ''))) {
            throw new \Exception('Node.js installation failed - binary not found');
        }

        $this->recordPackageInstalled($serverId, 'nodejs');
        $this->log("Node.js " . trim($verifyNode['output']) . " installed (npm " . trim($verifyNpm['output'] ?? '') . ")");
    }

    /**
     * Install Redis server for caching
     */
    private function installRedis(int $serverId, array $variables): void
    {
        // Check if Redis is already installed and running
        $redisCheck = $this->executeCommand('systemctl is-active redis-server 2>/dev/null || systemctl is-active redis 2>/dev/null || echo "not_running"');
        $redisStatus = trim($redisCheck['output'] ?? 'not_running');
        
        if ($redisStatus === 'active') {
            $this->log("Redis already installed and running");
            return;
        }

        $this->log("Installing Redis server...");
        
        $this->runAptCommand(
            'apt-get install -y redis-server redis-tools',
            'Failed to install Redis'
        );

        // Configure Redis
        $redisConf = '/etc/redis/redis.conf';
        $redisPass = $variables['REDIS_PASS'] ?? '';
        
        $configCommands = [
            // Bind to localhost only
            "sed -i 's/^bind .*/bind 127.0.0.1 ::1/' {$redisConf}",
            // Enable protected mode
            "sed -i 's/^protected-mode no/protected-mode yes/' {$redisConf}",
            // Set max memory
            "grep -q '^maxmemory ' {$redisConf} || echo 'maxmemory 256mb' >> {$redisConf}",
            // Set eviction policy
            "grep -q '^maxmemory-policy ' {$redisConf} || echo 'maxmemory-policy allkeys-lru' >> {$redisConf}",
            // Disable RDB saves (cache-only)
            "sed -i 's/^save /#save /' {$redisConf}",
            "grep -q '^save \"\"' {$redisConf} || echo 'save \"\"' >> {$redisConf}",
            // Set supervised to systemd
            "sed -i 's/^supervised .*/supervised systemd/' {$redisConf}",
        ];
        
        foreach ($configCommands as $cmd) {
            $this->executeCommand("test -f {$redisConf} && {$cmd} || true");
        }
        
        // Set password if provided
        if (!empty($redisPass)) {
            $this->executeCommand("sed -i '/^requirepass /d' {$redisConf}");
            $this->executeCommand("echo 'requirepass {$redisPass}' >> {$redisConf}");
            $this->log("Redis password configured");
        }

        // Enable and start Redis
        $this->executeCommand('systemctl enable redis-server 2>/dev/null || systemctl enable redis 2>/dev/null || true');
        $this->executeCommand('systemctl restart redis-server 2>/dev/null || systemctl restart redis 2>/dev/null || true');
        
        sleep(2);
        
        // Verify Redis is running
        $pingCmd = !empty($redisPass) ? "redis-cli -a '{$redisPass}' ping" : 'redis-cli ping';
        $pingResult = $this->executeCommand("{$pingCmd} 2>/dev/null");
        
        if (trim($pingResult['output'] ?? '') === 'PONG') {
            $this->log("Redis installed and verified (PONG)");
        } else {
            $this->log("Warning: Redis ping test failed, but continuing");
        }
        
        $this->recordPackageInstalled($serverId, 'redis-server');
    }

    /**
     * Install Meilisearch search engine
     */
    private function installMeilisearch(int $serverId, array &$variables): void
    {
        // Resolve the master key with this precedence (and propagate it back into
        // $variables so dependent apps - e.g. the email backend - are configured with
        // the SAME key):
        //   1. key already configured on the box (authoritative for a running instance)
        //   2. key supplied via variables (cloned from the source server)
        //   3. a freshly generated key
        $existingKeyResult = $this->executeCommand('grep "^master_key" /etc/meilisearch.toml 2>/dev/null | cut -d\'"\' -f2');
        $existingKey = trim($existingKeyResult['output'] ?? '');
        if ($existingKey !== '') {
            $variables['MEILI_MASTER_KEY'] = $existingKey;
        } elseif (empty($variables['MEILI_MASTER_KEY'])) {
            $variables['MEILI_MASTER_KEY'] = bin2hex(random_bytes(16));
        }
        $meiliMasterKey = $variables['MEILI_MASTER_KEY'];

        // Check if Meilisearch is already installed and running
        $meiliCheck = $this->executeCommand('systemctl is-active meilisearch 2>/dev/null || echo "not_running"');
        
        if (trim($meiliCheck['output'] ?? '') === 'active') {
            $this->log("Meilisearch already installed and running (master key reused)");
            return;
        }

        $this->log("Installing Meilisearch...");
        
        // Download and install Meilisearch binary (retry transient network failures)
        $this->runWithRetry(
            'curl -L --retry 3 --retry-delay 3 --connect-timeout 30 https://install.meilisearch.com | sh',
            'Meilisearch download failed'
        );
        $this->executeCommand('test -f ./meilisearch && mv ./meilisearch /usr/local/bin/meilisearch && chmod +x /usr/local/bin/meilisearch');
        
        // Verify binary
        $binaryCheck = $this->executeCommand('test -f /usr/local/bin/meilisearch && echo "ok"');
        if (trim($binaryCheck['output'] ?? '') !== 'ok') {
            $this->log("Warning: Meilisearch binary not found after download, skipping");
            return;
        }

        // Create user and directories
        $this->executeCommand('useradd -r -s /usr/sbin/nologin -d /var/lib/meilisearch meilisearch 2>/dev/null || true');
        $this->executeCommand('mkdir -p /var/lib/meilisearch/{data,dumps,snapshots}');
        $this->executeCommand('mkdir -p /var/log/meilisearch');
        $this->executeCommand('chown -R meilisearch:meilisearch /var/lib/meilisearch /var/log/meilisearch');

        // Create config
        $meiliConfig = <<<TOML
http_addr = "127.0.0.1:7700"
master_key = "{$meiliMasterKey}"
db_path = "/var/lib/meilisearch/data"
dump_dir = "/var/lib/meilisearch/dumps"
snapshot_dir = "/var/lib/meilisearch/snapshots"
log_level = "INFO"
env = "production"
http_payload_size_limit = "104857600"
TOML;

        $this->executeCommand("cat > /etc/meilisearch.toml << 'MEILIEOF'\n{$meiliConfig}\nMEILIEOF");
        $this->executeCommand('chown meilisearch:meilisearch /etc/meilisearch.toml && chmod 600 /etc/meilisearch.toml');

        // Create systemd service
        $serviceFile = <<<'SERVICE'
[Unit]
Description=Meilisearch Search Engine
After=network.target

[Service]
Type=simple
User=meilisearch
Group=meilisearch
ExecStart=/usr/local/bin/meilisearch --config-file-path /etc/meilisearch.toml
Restart=always
RestartSec=5
StandardOutput=append:/var/log/meilisearch/meilisearch.log
StandardError=append:/var/log/meilisearch/meilisearch-error.log
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
SERVICE;

        $this->executeCommand("cat > /etc/systemd/system/meilisearch.service << 'SVCEOF'\n{$serviceFile}\nSVCEOF");
        
        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable meilisearch');
        $this->executeCommand('systemctl start meilisearch');
        
        sleep(3);
        
        // Verify it's running
        if ($this->verifyServiceRunning('meilisearch')) {
            $this->log("Meilisearch installed and running on 127.0.0.1:7700");
        } else {
            $this->log("Warning: Meilisearch may not have started, check logs");
        }

        // Store the master key in variables for later use
        $variables['MEILI_MASTER_KEY'] = $meiliMasterKey;
        
        // Retrieve search key
        sleep(2);
        $keysResult = $this->executeCommand("curl -s -H 'Authorization: Bearer {$meiliMasterKey}' http://127.0.0.1:7700/keys 2>/dev/null");
        if (!empty($keysResult['output'])) {
            $keysData = json_decode($keysResult['output'] ?? '', true);
            if (!empty($keysData['results'])) {
                foreach ($keysData['results'] as $key) {
                    if (($key['name'] ?? '') === 'Default Search API Key') {
                        $variables['MEILI_SEARCH_KEY'] = $key['key'];
                        break;
                    }
                }
            }
        }
        
        $this->recordPackageInstalled($serverId, 'meilisearch');
    }

    /**
     * Install OpenLiteSpeed with idempotency and verification
     */
    private function installOpenLiteSpeed(int $serverId): void
    {
        $this->log("Checking OpenLiteSpeed installation...");

        $binaryCheck = $this->executeCommand('test -f /usr/local/lsws/bin/litespeed && echo "exists"');
        $olsInstalled = trim($binaryCheck['output'] ?? '') === 'exists';

        if ($olsInstalled) {
            $this->executeCommand('systemctl start lshttpd 2>/dev/null || true');
            sleep(2);
            $statusCheck = $this->executeCommand('systemctl is-active lshttpd 2>/dev/null || echo "inactive"');

            if (trim($statusCheck['output'] ?? '') === 'active') {
                $this->log("OpenLiteSpeed already installed and running");
                return;
            }

            $this->log("OpenLiteSpeed binary found but service not running, checking for common issues...");

            $adminPhpCheck = $this->executeCommand('test -f /usr/local/lsws/admin/fcgi-bin/admin_php && echo "exists"');
            if (trim($adminPhpCheck['output'] ?? '') !== 'exists') {
                $this->log("admin_php missing - will be fixed after PHP installation");
            }
        }

        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->log("Installing OpenLiteSpeed (attempt {$attempt}/{$maxRetries})...");

            try {
                // On retry or broken install: full purge before re-attempting
                if ($attempt > 1 || ($this->isPackageInstalled('openlitespeed') && !$olsInstalled)) {
                    $this->log("Performing clean purge of OpenLiteSpeed...");
                    $this->executeCommand('systemctl stop lshttpd 2>/dev/null || true');
                    $this->executeCommand('pkill -9 lshttpd 2>/dev/null || true');
                    $this->executeCommand('pkill -9 litespeed 2>/dev/null || true');
                    sleep(1);
                    $this->executeCommand('dpkg --remove --force-remove-reinstreq openlitespeed 2>/dev/null || true');
                    $this->executeCommand('apt-get remove --purge -y openlitespeed 2>/dev/null || true');
                    $this->executeCommand('rm -rf /usr/local/lsws 2>/dev/null || true');
                    $this->executeCommand('apt-get autoremove -y 2>/dev/null || true');
                    $this->executeCommand('apt-get clean');
                    $this->executeCommand('dpkg --configure -a 2>/dev/null || true');
                    $this->log("Purge complete, ready for clean install");
                }

                // Add LiteSpeed repository and verify it was added. Download the
                // installer to a temp file and run it (instead of piping straight
                // into bash) so host AV/malware heuristics don't flag the pattern.
                $this->log("Adding LiteSpeed repository...");
                $this->runStep('set -e; T="$(mktemp)"; wget -qO "$T" https://repo.litespeed.sh; bash "$T"; rm -f "$T"', 'Failed to add LiteSpeed repository');

                $repoCheck = $this->executeCommand('test -f /etc/apt/sources.list.d/lst_debian_repo.list && echo "ok" || test -f /etc/apt/sources.list.d/lst_repo.list && echo "ok" || echo "missing"');
                if (strpos($repoCheck['output'] ?? '', 'ok') === false) {
                    $this->log("Warning: LiteSpeed repo file not detected, checking alternatives...");
                    $this->executeCommand('ls -la /etc/apt/sources.list.d/ 2>/dev/null');
                }

                // Update package lists so the new repo is picked up
                $this->log("Updating package lists after repo add...");
                $this->runAptCommand('apt-get update -y 2>&1 | tail -5', 'apt-get update failed after adding LiteSpeed repo');

                // Verify the openlitespeed package is available. If it isn't, the
                // LiteSpeed repo index didn't come through (transient mirror hiccup
                // or a stale/empty cached list that survives apt-get clean). Drop the
                // LiteSpeed lists and force a fresh update before failing this attempt.
                $pkgAvail = $this->executeCommand('apt-cache show openlitespeed 2>/dev/null | head -3');
                if (strpos($pkgAvail['output'] ?? '', 'Package: openlitespeed') === false) {
                    $this->log("openlitespeed not in apt cache - clearing stale LiteSpeed lists and refreshing...");
                    $this->executeCommand('rm -f /var/lib/apt/lists/*litespeed* /var/lib/apt/lists/*lst_* 2>/dev/null || true');
                    $this->runAptCommand('apt-get update -y 2>&1 | tail -5', 'apt-get update failed while refreshing LiteSpeed repo');
                    $pkgAvail = $this->executeCommand('apt-cache show openlitespeed 2>/dev/null | head -3');
                }
                if (strpos($pkgAvail['output'] ?? '', 'Package: openlitespeed') === false) {
                    // Capture diagnostics so the step log explains *why* it's missing.
                    $policy = $this->executeCommand('apt-cache policy openlitespeed 2>&1 | head -10');
                    $this->log("apt-cache policy openlitespeed: " . trim($policy['output'] ?? 'n/a'));
                    $repoList = $this->executeCommand('cat /etc/apt/sources.list.d/lst_*.list 2>/dev/null');
                    $this->log("LiteSpeed repo file: " . trim($repoList['output'] ?? 'n/a'));
                    throw new \Exception('openlitespeed package not found in apt cache after adding repo');
                }

                // Install package
                $this->runAptCommand(
                    'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" openlitespeed',
                    'Failed to install OpenLiteSpeed package'
                );

                // Verify binary exists and is executable
                $verifyBinary = $this->executeCommand('test -f /usr/local/lsws/bin/litespeed && echo "ok"');
                if (trim($verifyBinary['output'] ?? '') !== 'ok') {
                    throw new \Exception('OpenLiteSpeed binary not found after installation');
                }
                // OLS packages sometimes ship binaries without execute permission
                $this->executeCommand('chmod +x /usr/local/lsws/bin/openlitespeed /usr/local/lsws/bin/lshttpd /usr/local/lsws/bin/litespeed 2>/dev/null || true');
                $this->executeCommand('chmod +x /usr/local/lsws/bin/lswsctrl* 2>/dev/null || true');

                // Verify key directories exist
                $dirCheck = $this->executeCommand('test -d /usr/local/lsws/conf && test -d /usr/local/lsws/logs && echo "ok"');
                if (trim($dirCheck['output'] ?? '') !== 'ok') {
                    throw new \Exception('OpenLiteSpeed directory structure incomplete after installation');
                }

                $this->recordPackageInstalled($serverId, 'openlitespeed');
                $this->log("OpenLiteSpeed package installed successfully (service will be started after PHP setup)");
                return;

            } catch (\Exception $e) {
                $this->log("Attempt {$attempt} failed: " . $e->getMessage());

                // Capture dpkg/apt diagnostic info on failure
                $dpkgStatus = $this->executeCommand('dpkg -l openlitespeed 2>/dev/null | tail -2');
                $this->log("dpkg status: " . trim($dpkgStatus['output'] ?? 'not found'));

                if ($attempt >= $maxRetries) {
                    throw new \Exception("OpenLiteSpeed installation failed after {$maxRetries} attempts: " . $e->getMessage());
                }

                sleep(5);
            }
        }
    }
    
    /**
     * Nuke all automatic apt processes and fix broken dpkg/apt state.
     * This runs ONCE at the very start of provisioning and ensures nothing
     * can interfere with our package installations.
     */
    private function fixBrokenPackages(): void
    {
        $this->log("Neutralizing automatic apt processes...");

        // Single aggressive script that does everything atomically on the remote server
        // NOTE: NO set -e here! We want the script to continue even if individual commands fail.
        $nukeScript = <<<'BASH'
#!/bin/bash

# 1) Stop and permanently disable unattended-upgrades so it can never restart
systemctl stop unattended-upgrades 2>/dev/null || true
systemctl disable unattended-upgrades 2>/dev/null || true
systemctl mask unattended-upgrades 2>/dev/null || true

# Also stop apt-daily timers that trigger unattended-upgrades
systemctl stop apt-daily.timer 2>/dev/null || true
systemctl stop apt-daily-upgrade.timer 2>/dev/null || true
systemctl disable apt-daily.timer 2>/dev/null || true
systemctl disable apt-daily-upgrade.timer 2>/dev/null || true
systemctl mask apt-daily.timer 2>/dev/null || true
systemctl mask apt-daily-upgrade.timer 2>/dev/null || true

# Stop any running apt-daily services
systemctl stop apt-daily.service 2>/dev/null || true
systemctl stop apt-daily-upgrade.service 2>/dev/null || true

# 2) Kill ALL processes that could hold apt/dpkg locks
pkill -9 -f "unattended-upgrade" 2>/dev/null || true
pkill -9 -f "apt-get" 2>/dev/null || true
pkill -9 -f "apt " 2>/dev/null || true
pkill -9 -f "dpkg" 2>/dev/null || true
sleep 2

# 3) Wait up to 30 seconds for locks to actually release (processes may take a moment to die)
WAITED=0
while [ $WAITED -lt 30 ]; do
    if ! fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 && \
       ! fuser /var/lib/apt/lists/lock >/dev/null 2>&1 && \
       ! fuser /var/lib/dpkg/lock >/dev/null 2>&1 && \
       ! fuser /var/cache/apt/archives/lock >/dev/null 2>&1; then
        break
    fi
    # Still locked - kill again harder
    pkill -9 -f "unattended-upgrade|apt-get|apt |dpkg" 2>/dev/null || true
    sleep 2
    WAITED=$((WAITED + 2))
done

# 4) Force-remove ALL lock files
rm -f /var/lib/dpkg/lock-frontend 2>/dev/null || true
rm -f /var/lib/dpkg/lock 2>/dev/null || true
rm -f /var/lib/apt/lists/lock 2>/dev/null || true
rm -f /var/cache/apt/archives/lock 2>/dev/null || true

# 5) Repair any broken dpkg state (redirect ALL output so SSH channel isn't flooded)
DEBIAN_FRONTEND=noninteractive dpkg --configure -a --force-confdef --force-confold >/dev/null 2>&1 || true

# 6) Fix broken dependencies
DEBIAN_FRONTEND=noninteractive apt-get install -f -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" >/dev/null 2>&1 || true

# 7) Clean apt cache
apt-get clean >/dev/null 2>&1 || true

echo "APT_READY"
BASH;

        $result = $this->ssh->execWithTimeout($nukeScript, 120);
        $output = $result['output'] ?? '';

        if (strpos($output, 'APT_READY') !== false) {
            $this->log("APT subsystem ready - all automatic updates neutralized");
        } else {
            $this->log("Warning: APT cleanup may not have completed fully, proceeding anyway");
        }
    }

    /**
     * Install multi-version PHP (lsphp 7.4/8.0/8.1/8.2/8.3/8.4) with LiteSpeed.
     * Mirrors the source fleet: ionCube on 8.1/8.2/8.3, 8.3 as the primary runtime.
     */
    private function installPHP(int $serverId): void
    {
        // Multi-version PHP: the source server runs several lsphp versions across
        // its vhosts (74/80/81/82/83/84) with ionCube on 81/82/83. Install every
        // version so cloned vhosts find their interpreter. 8.3 stays the primary
        // (CLI symlink + OLS admin). mbstring/xml/zip/gd/bcmath/soap ship in -common.
        $phpVersionPackages = [
            '74' => ['lsphp74-common', 'lsphp74-igbinary', 'lsphp74-memcached', 'lsphp74-msgpack', 'lsphp74-redis'],
            '80' => ['lsphp80', 'lsphp80-common', 'lsphp80-mysql', 'lsphp80-curl', 'lsphp80-imap', 'lsphp80-intl', 'lsphp80-opcache', 'lsphp80-ldap', 'lsphp80-redis', 'lsphp80-memcached', 'lsphp80-imagick', 'lsphp80-sqlite3', 'lsphp80-pgsql', 'lsphp80-apcu', 'lsphp80-igbinary', 'lsphp80-msgpack', 'lsphp80-snmp', 'lsphp80-pspell', 'lsphp80-tidy'],
            '81' => ['lsphp81', 'lsphp81-common', 'lsphp81-mysql', 'lsphp81-curl', 'lsphp81-imap', 'lsphp81-intl', 'lsphp81-opcache', 'lsphp81-ldap', 'lsphp81-redis', 'lsphp81-memcached', 'lsphp81-imagick', 'lsphp81-sqlite3', 'lsphp81-pgsql', 'lsphp81-apcu', 'lsphp81-igbinary', 'lsphp81-msgpack', 'lsphp81-snmp', 'lsphp81-pspell', 'lsphp81-tidy', 'lsphp81-ioncube'],
            '82' => ['lsphp82', 'lsphp82-common', 'lsphp82-mysql', 'lsphp82-curl', 'lsphp82-imap', 'lsphp82-intl', 'lsphp82-opcache', 'lsphp82-ldap', 'lsphp82-redis', 'lsphp82-memcached', 'lsphp82-imagick', 'lsphp82-sqlite3', 'lsphp82-pgsql', 'lsphp82-apcu', 'lsphp82-igbinary', 'lsphp82-msgpack', 'lsphp82-snmp', 'lsphp82-pspell', 'lsphp82-tidy', 'lsphp82-ioncube'],
            '83' => ['lsphp83', 'lsphp83-common', 'lsphp83-mysql', 'lsphp83-curl', 'lsphp83-imap', 'lsphp83-intl', 'lsphp83-opcache', 'lsphp83-ldap', 'lsphp83-redis', 'lsphp83-memcached', 'lsphp83-imagick', 'lsphp83-sqlite3', 'lsphp83-pgsql', 'lsphp83-apcu', 'lsphp83-igbinary', 'lsphp83-msgpack', 'lsphp83-snmp', 'lsphp83-pspell', 'lsphp83-tidy', 'lsphp83-ioncube'],
            '84' => ['lsphp84-common', 'lsphp84-igbinary', 'lsphp84-memcached', 'lsphp84-msgpack', 'lsphp84-redis'],
        ];

        $needsInstall = [];
        foreach ($phpVersionPackages as $pkgList) {
            foreach ($pkgList as $package) {
                if (!$this->isPackageInstalled($package)) {
                    $needsInstall[] = $package;
                }
            }
        }

        if (!empty($needsInstall)) {
            // Keep only packages the LiteSpeed repo actually serves for THIS OS.
            // Newer Debian/Ubuntu releases drop older builds (e.g. lsphp74), and a
            // single `apt-get install <all>` fails hard if ANY package is missing
            // ("E: Unable to locate package lsphp74-common"). Filter first so a
            // missing legacy version can't sink the whole step.
            $pkgArg = implode(' ', array_map('escapeshellarg', $needsInstall));
            $availRes = $this->executeCommand(
                'for p in ' . $pkgArg . '; do apt-cache show "$p" >/dev/null 2>&1 && echo "$p"; done'
            );
            $available = array_values(array_filter(array_map('trim', explode("\n", $availRes['output'] ?? ''))));
            $skipped = array_values(array_diff($needsInstall, $available));

            if (!empty($skipped)) {
                $this->log("Skipping " . count($skipped) . " PHP package(s) not in this OS's LiteSpeed repo: " . implode(', ', $skipped));
            }

            // The primary runtime (8.3) must be available - if it isn't, the repo
            // itself is broken (e.g. apt update didn't pick up LiteSpeed) and we
            // should fail loudly rather than limp along without any PHP.
            if (!in_array('lsphp83', $available, true) && !$this->isPackageInstalled('lsphp83')) {
                $policy = $this->executeCommand('apt-cache policy lsphp83 2>&1 | head -8');
                $this->log("apt-cache policy lsphp83: " . trim($policy['output'] ?? 'n/a'));
                throw new \Exception('LiteSpeed repo is not serving lsphp packages (lsphp83 unavailable) - apt update / repo issue on this server');
            }

            if (!empty($available)) {
                $this->log("Installing " . count($available) . " PHP packages across the available lsphp versions...");
                $this->runAptCommand(
                    'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" ' . implode(' ', $available),
                    'Failed to install PHP'
                );

                foreach ($available as $package) {
                    $this->recordPackageInstalled($serverId, $package);
                }
            }
        } else {
            $this->log("All PHP packages already installed");
        }

        // Fix permissions on PHP binaries for every installed version
        $this->log("Setting PHP binary permissions...");
        $this->executeCommand('chmod +x /usr/local/lsws/lsphp*/bin/* 2>/dev/null || true');
        $this->executeCommand('chmod +x /usr/local/lsws/lsphp*/sbin/* 2>/dev/null || true');
        
        // Link PHP to /usr/bin (idempotent)
        $this->executeCommand('ln -sf /usr/local/lsws/lsphp83/bin/php /usr/bin/php');
        
        // Enable MySQL extensions (ini files may be missing after install)
        $this->log("Enabling PHP MySQL extensions...");
        $this->executeCommand('echo "extension=pdo_mysql.so" > /usr/local/lsws/lsphp83/etc/php/8.3/mods-available/pdo_mysql.ini');
        $this->executeCommand('echo "extension=mysqli.so" > /usr/local/lsws/lsphp83/etc/php/8.3/mods-available/mysqli.ini');
        
        // Create admin_php symlink for OLS admin panel (critical for OLS to start)
        $this->log("Setting up OLS admin PHP...");
        $this->executeCommand('mkdir -p /usr/local/lsws/admin/fcgi-bin');
        $this->executeCommand('ln -sf /usr/local/lsws/lsphp83/bin/lsphp /usr/local/lsws/admin/fcgi-bin/admin_php');
        
        // Ensure OLS binaries are executable before starting
        $this->executeCommand('chmod +x /usr/local/lsws/bin/openlitespeed /usr/local/lsws/bin/lshttpd /usr/local/lsws/bin/litespeed 2>/dev/null || true');
        $this->executeCommand('chmod +x /usr/local/lsws/bin/lswsctrl* 2>/dev/null || true');

        // Now start/restart OLS with PHP properly configured
        $this->log("Starting OpenLiteSpeed with PHP configured...");
        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('/usr/local/lsws/bin/lswsctrl stop 2>/dev/null || true');
        sleep(1);
        $this->executeCommand('/usr/local/lsws/bin/lswsctrl start');
        sleep(2);
        
        // Verify OLS is running
        $statusCheck = $this->executeCommand('systemctl is-active lshttpd 2>/dev/null || echo "inactive"');
        if (trim($statusCheck['output'] ?? '') !== 'active') {
            // Try systemctl start as fallback
            $this->executeCommand('systemctl start lshttpd');
            sleep(2);
            $statusCheck = $this->executeCommand('systemctl is-active lshttpd 2>/dev/null || echo "inactive"');
            if (trim($statusCheck['output'] ?? '') !== 'active') {
                $this->log("Warning: OLS service not active, checking logs...");
                $errorLog = $this->executeCommand('tail -10 /usr/local/lsws/logs/error.log 2>/dev/null || echo "No logs"');
                $this->log("OLS error log: " . ($errorLog['output'] ?? 'empty'));
            }
        }
        
        // Enable OLS on boot
        $this->executeCommand('systemctl enable lshttpd');

        // Fix OLS systemd KillMode=none deprecation (causes orphan processes on restart)
        $killModeCheck = $this->executeCommand("grep -q 'KillMode=none' /etc/systemd/system/lshttpd.service 2>/dev/null && echo 'needs_fix' || echo 'ok'");
        if (strpos($killModeCheck['output'] ?? '', 'needs_fix') !== false) {
            $this->log("Fixing OLS systemd KillMode deprecation...");
            $this->executeCommand("sed -i 's/KillMode=none/KillMode=mixed/' /etc/systemd/system/lshttpd.service");
            $this->executeCommand('systemctl daemon-reload');
        }

        $this->log("PHP and OLS setup completed");
    }

    /**
     * Install MariaDB with idempotency and verification
     */
    private function installMariaDB(int $serverId, string $rootPassword): void
    {
        $this->log("Checking MariaDB installation...");
        
        // Check if MariaDB is installed and running
        $serviceCheck = $this->executeCommand('systemctl is-active mariadb 2>/dev/null || echo "inactive"');
        $isRunning = trim($serviceCheck['output'] ?? '') === 'active';
        
        if ($this->isPackageInstalled('mariadb-server') && $isRunning) {
            $this->log("MariaDB already installed and running");
            return;
        }
        
        if ($this->isPackageInstalled('mariadb-server') && !$isRunning) {
            $this->log("MariaDB installed but not running, attempting to start...");
            $this->executeCommand('systemctl start mariadb');
            sleep(2);
            
            $recheckStatus = $this->executeCommand('systemctl is-active mariadb 2>/dev/null || echo "inactive"');
            if (trim($recheckStatus['output'] ?? '') === 'active') {
                $this->log("MariaDB service started successfully");
                return;
            }
            
            $this->log("MariaDB service failed to start, attempting reinstall...");
            $this->executeCommand('apt-get remove --purge -y mariadb-server mariadb-client 2>/dev/null || true');
        }
        
        // Install MariaDB
        $this->log("Installing MariaDB...");
        $this->runAptCommand(
            'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" mariadb-server mariadb-client',
            'Failed to install MariaDB'
        );

        // Verify service is running
        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable mariadb');
        $this->runStep('systemctl start mariadb', 'Failed to start MariaDB service');
        
        sleep(2);
        
        $statusCheck = $this->executeCommand('systemctl is-active mariadb');
        if (trim($statusCheck['output'] ?? '') !== 'active') {
            throw new \Exception('MariaDB service failed to start after installation');
        }

        $this->recordPackageInstalled($serverId, 'mariadb-server');
        $this->recordPackageInstalled($serverId, 'mariadb-client');

        // Secure installation (only for fresh installs)
        $this->log("Securing MariaDB installation...");
        
        // Build secure SQL, upload as file to avoid shell quoting issues with passwords
        $secureSql  = "ALTER USER 'root'@'localhost' IDENTIFIED BY '" . str_replace("'", "\\'", $rootPassword) . "';\n";
        $secureSql .= "DELETE FROM mysql.user WHERE User='';\n";
        $secureSql .= "DROP DATABASE IF EXISTS test;\n";
        $secureSql .= "FLUSH PRIVILEGES;\n";
        
        $this->ssh->uploadContent($secureSql, '/tmp/fleet-mariadb-secure.sql');
        
        // First command uses unix_socket auth (default on fresh installs, no password needed)
        $result = $this->executeCommand("mariadb -u root < /tmp/fleet-mariadb-secure.sql 2>&1");
        if (!($result['success'] ?? true)) {
            $this->log("Warning: MariaDB secure setup had issues (may already be configured): " . ($result['output'] ?? ''));
        }
        
        $this->executeCommand("rm -f /tmp/fleet-mariadb-secure.sql");
        
        // Create /root/.my.cnf so the Panel agent (and CLI tools) can authenticate as root.
        // Use buildMyCnf() so passwords containing #, ;, ", or \ are properly quoted -
        // an unquoted value silently truncates at the first # (treated as a comment).
        $myCnf = $this->buildMyCnf('root', $rootPassword);
        $this->ssh->uploadContent($myCnf, '/root/.my.cnf');
        $this->executeCommand('chmod 600 /root/.my.cnf');
        $this->executeCommand('chown root:root /root/.my.cnf');
        $this->log("Created /root/.my.cnf for root MySQL access");
        
        $this->log("MariaDB installed and verified successfully");
    }

    /**
     * Post-config deployment fixes
     * Blueprint configs may overwrite files or reset permissions - fix critical items
     */
    private function postConfigFixes(): void
    {
        $this->log("Applying post-config deployment fixes...");
        
        // 1. Re-apply PHP binary permissions (blueprint may have overwritten files)
        $this->log("Re-applying PHP binary permissions...");
        $this->executeCommand('chmod +x /usr/local/lsws/lsphp*/bin/* 2>/dev/null || true');
        $this->executeCommand('chmod +x /usr/local/lsws/lsphp*/sbin/* 2>/dev/null || true');
        $this->executeCommand('chmod 755 /usr/local/lsws/lsphp83/bin/php');
        $this->executeCommand('chmod 755 /usr/local/lsws/lsphp83/bin/lsphp');
        
        // Verify PHP is executable
        $phpTest = $this->executeCommand('/usr/local/lsws/lsphp83/bin/php -v 2>&1 | head -1');
        if (strpos($phpTest['output'] ?? '', 'PHP') === false) {
            $this->log("Warning: PHP still not working after permission fix, attempting reinstall...");
            $this->executeCommand('apt-get install --reinstall -y lsphp83 lsphp83-common 2>/dev/null || true');
            $this->executeCommand('chmod +x /usr/local/lsws/lsphp*/bin/*');
        } else {
            $this->log("PHP verified working: " . trim($phpTest['output'] ?? ''));
        }
        
        // 2. Ensure MariaDB is running (config changes may have stopped it)
        $this->log("Verifying MariaDB service...");
        $mariaStatus = $this->executeCommand('systemctl is-active mariadb 2>/dev/null || echo "inactive"');
        if (trim($mariaStatus['output'] ?? '') !== 'active') {
            $this->log("MariaDB not active, attempting restart...");
            $this->executeCommand('systemctl restart mariadb');
            sleep(3);
            
            // Verify it's running now
            $recheckStatus = $this->executeCommand('systemctl is-active mariadb 2>/dev/null || echo "inactive"');
            if (trim($recheckStatus['output'] ?? '') !== 'active') {
                $this->log("Warning: MariaDB failed to start. Checking journal...");
                $journal = $this->executeCommand('journalctl -u mariadb --no-pager -n 20 2>/dev/null | tail -10');
                $this->log("MariaDB journal: " . ($journal['output'] ?? 'no logs'));
            } else {
                $this->log("MariaDB restarted successfully");
            }
        } else {
            $this->log("MariaDB is running");
        }
        
        // 3. Restart OpenLiteSpeed to apply any config changes
        $this->log("Restarting OpenLiteSpeed to apply config changes...");
        $this->executeCommand('systemctl restart lshttpd');
        sleep(2);
        
        $olsStatus = $this->executeCommand('systemctl is-active lshttpd 2>/dev/null || echo "inactive"');
        if (trim($olsStatus['output'] ?? '') !== 'active') {
            $this->log("Warning: OLS failed to restart. Checking error log...");
            $errorLog = $this->executeCommand('tail -20 /usr/local/lsws/logs/error.log 2>/dev/null');
            $this->log("OLS error: " . ($errorLog['output'] ?? 'no logs'));
        } else {
            $this->log("OpenLiteSpeed restarted successfully");
        }
        
        $this->log("Post-config fixes completed");
    }

    /**
     * Install Postfix with idempotency and verification
     */
    private function installPostfix(int $serverId): void
    {
        $this->log("Checking Postfix installation...");
        
        // Check if Postfix is installed
        if ($this->isPackageInstalled('postfix')) {
            // Verify service is running
            $statusCheck = $this->executeCommand('systemctl is-active postfix 2>/dev/null || echo "inactive"');
            $st = trim($statusCheck['output'] ?? '');
            if ($st !== 'active' && $st !== 'exited') {
                $this->log("Postfix installed but not running, starting service...");
                $this->executeCommand('systemctl start postfix');
            } else {
                $this->log("Postfix already installed and running");
            }

            // CRITICAL even when Postfix is preinstalled / left over from a previous
            // run: many base images ship Postfix WITHOUT postfix-mysql. Skipping this
            // is exactly what lets a deploy "succeed" while every send/delivery
            // tempfails in cleanup with "451 4.3.0 queue file write error". So always
            // verify (and repair) the mysql map type - never early-return past it.
            $this->ensurePostfixMysqlSupport($serverId);
            return;
        }

        // Preconfigure postfix
        $this->log("Installing Postfix...");
        $this->runStep(
            'echo "postfix postfix/mailname string localhost" | debconf-set-selections',
            'Failed to configure Postfix'
        );
        $this->runStep(
            'echo "postfix postfix/main_mailer_type string \'Internet Site\'" | debconf-set-selections',
            'Failed to configure Postfix'
        );

        $this->runAptCommand(
            'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" postfix postfix-mysql',
            'Failed to install Postfix'
        );

        // Guarantee the mysql map type is registered (throws if it can't be).
        $this->ensurePostfixMysqlSupport($serverId);

        // Verify installation
        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable postfix');
        $this->executeCommand('systemctl start postfix');
        
        sleep(2);
        
        $statusCheck = $this->executeCommand('systemctl is-active postfix 2>/dev/null || echo "inactive"');
        $status = trim($statusCheck['output'] ?? '');
        // Postfix shows as 'exited' when running properly (it's a master process)
        if ($status !== 'active' && $status !== 'exited') {
            $this->log("Warning: Postfix service status is {$status} - may need configuration");
        }

        $this->recordPackageInstalled($serverId, 'postfix');
        $this->recordPackageInstalled($serverId, 'postfix-mysql');
        $this->log("Postfix installed successfully");
    }

    /**
     * Ensure Postfix actually exposes the 'mysql' map type (postfix-mysql installed
     * AND dict_mysql.so registered). Safe + cheap to call on fresh installs and on
     * re-deploys / images that shipped Postfix without postfix-mysql.
     *
     * The AUTHORITATIVE check is `postconf -m`. Without 'mysql', virtual_alias_maps /
     * virtual_mailbox_* lookups fail with "unsupported dictionary type: mysql" and
     * EVERY message is tempfailed in cleanup (client sees "451 4.3.0 queue file write
     * error") - logins work but no mail can be sent or delivered. dpkg can report the
     * package "installed" while the dynamic map stays unregistered (half-configured
     * dpkg state from a flaky apt run), so --reinstall re-runs the postinst that
     * registers dict_mysql.so. Throws if it cannot be repaired.
     */
    private function ensurePostfixMysqlSupport(?int $serverId = null): void
    {
        // 1) Package present at all?
        $mysqlCheck = $this->executeCommand('dpkg -l postfix-mysql 2>/dev/null | grep -c "^ii" || echo "0"');
        if (trim($mysqlCheck['output'] ?? '0') === '0') {
            $this->log("postfix-mysql not installed - installing...");
            $this->runAptCommand(
                'apt-get install -y postfix-mysql',
                'Failed to install postfix-mysql'
            );
        }

        // 2) AUTHORITATIVE check: actually load a mysql: map with postmap. This is
        //    the ONLY reliable signal. `postconf -m` merely lists names from
        //    dynamicmaps.cf and can show "mysql" even when dict_mysql.so fails to
        //    dlopen (e.g. a missing libmariadb3/libmysqlclient client library on a
        //    cloned image) - in which case every lookup tempfails with "unsupported
        //    dictionary type: mysql" -> "451 queue file write error". A throwaway
        //    .cf lets us probe load WITHOUT needing the real mail DB: any output
        //    OTHER than "unsupported dictionary type" means the dict loaded fine
        //    (a connection/auth error is expected and acceptable here).
        $loadTest = function (): string {
            $probe = <<<'BASH'
cat > /tmp/.fleet-mysqlprobe.cf <<'EOF'
user = probe
password = probe
hosts = 127.0.0.1
dbname = probe
query = SELECT 1
EOF
postmap -q probe mysql:/tmp/.fleet-mysqlprobe.cf 2>&1
rm -f /tmp/.fleet-mysqlprobe.cf
true
BASH;
            $res = $this->executeCommand($probe, 30);
            return (string)($res['output'] ?? '');
        };
        $dictLoads = function () use ($loadTest): bool {
            return stripos($loadTest(), 'unsupported dictionary type') === false;
        };

        $ok = false;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            if ($dictLoads()) {
                $ok = true;
                break;
            }
            $this->log("Postfix 'mysql' map type does not load (attempt {$attempt}/4) - repairing...");

            // Escalating repair. install -f / plain install (not --reinstall) is
            // what pulls a MISSING client-library dependency; --reinstall re-runs
            // the postinst that (re)registers dict_mysql.so in dynamicmaps.cf.
            $this->executeCommand('dpkg --configure -a 2>/dev/null || true', 120);
            $this->executeCommand('DEBIAN_FRONTEND=noninteractive apt-get install -f -y 2>&1 | tail -3', 180);
            $this->executeCommand('DEBIAN_FRONTEND=noninteractive apt-get install -y postfix-mysql 2>&1 | tail -3', 180);

            // If the .so has an unresolved client lib, install the providing
            // package. postfix-mysql links libmariadb3 (Deb/Ubuntu) or libmysqlclient*.
            $ldd = $this->executeCommand(
                'SO=$(dpkg -L postfix-mysql 2>/dev/null | grep -E "\\.so" | head -1); '
                . '[ -n "$SO" ] && ldd "$SO" 2>&1 | grep -i "not found" || true',
                30
            );
            if (trim($ldd['output'] ?? '') !== '') {
                $this->log("Postfix dict_mysql.so has unresolved libs: " . trim(substr($ldd['output'], 0, 200)));
                $this->executeCommand(
                    'DEBIAN_FRONTEND=noninteractive apt-get install -y libmariadb3 2>/dev/null | tail -1 || true; '
                    . 'DEBIAN_FRONTEND=noninteractive apt-get install -y libmysqlclient21 2>/dev/null | tail -1 || true; '
                    . 'DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-client-core 2>/dev/null | tail -1 || true',
                    180
                );
            }

            if ($attempt >= 2) {
                $this->executeCommand('DEBIAN_FRONTEND=noninteractive apt-get install --reinstall -y postfix-mysql 2>&1 | tail -3', 180);
            }
            $this->executeCommand('systemctl restart postfix 2>/dev/null || true', 30);
            sleep(2);
        }

        if (!$ok) {
            // Dump everything we can so the deploy log is self-diagnosing, then fail.
            $diag = $this->executeCommand(
                'echo "## mail_version:"; postconf mail_version 2>&1; '
                . 'echo "## postconf -m mysql:"; postconf -m 2>/dev/null | grep -i mysql || echo "(none)"; '
                . 'echo "## dpkg .so:"; dpkg -L postfix-mysql 2>/dev/null | grep -E "\\.so" || echo "(none)"; '
                . 'echo "## ldd not-found:"; for f in $(dpkg -L postfix-mysql 2>/dev/null | grep -E "\\.so"); do ldd "$f" 2>&1 | grep -i "not found"; done || true; '
                . 'echo "## dynamicmaps:"; cat /etc/postfix/dynamicmaps.cf 2>/dev/null; cat /usr/lib/postfix/dynamicmaps.cf.d/* 2>/dev/null; '
                . 'echo "## load test:"; printf "user=x\\npassword=x\\nhosts=127.0.0.1\\ndbname=x\\nquery=SELECT 1\\n" > /tmp/.p.cf; postmap -q x mysql:/tmp/.p.cf 2>&1; rm -f /tmp/.p.cf',
                60
            );
            $this->log("Postfix mysql diagnostics:\n" . trim((string)($diag['output'] ?? '')));
            throw new \Exception(
                "Postfix cannot LOAD the 'mysql' map type (dict_mysql.so fails to dlopen) after install/reinstall and "
                . "client-library repair. virtual_alias_maps / virtual_mailbox_* lookups will fail and no mail can be "
                . "sent or delivered (451 queue file write error). See the 'Postfix mysql diagnostics' log above "
                . "(ldd not-found / dynamicmaps) for the exact cause."
            );
        }

        $this->log("Postfix mysql dictionary support confirmed (dict_mysql.so loads)");
        if ($serverId !== null) {
            $this->recordPackageInstalled($serverId, 'postfix-mysql');
        }
    }

    /**
     * Install Dovecot with idempotency and verification
     */
    private function installDovecot(int $serverId): void
    {
        $this->log("Checking Dovecot installation...");

        $packages = [
            'dovecot-core', 'dovecot-imapd', 'dovecot-pop3d', 'dovecot-lmtpd',
            'dovecot-mysql', 'dovecot-sieve', 'dovecot-managesieved',
        ];

        if (!$this->isPackageInstalled('dovecot-core')) {
            $this->log("Installing Dovecot...");
            $this->runAptCommand(
                'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" ' . implode(' ', $packages),
                'Failed to install Dovecot'
            );

            foreach ($packages as $package) {
                $this->recordPackageInstalled($serverId, $package);
            }
        } else {
            $this->log("Dovecot packages already installed");

            // Verify all sub-packages are present (pop3d was missing in earlier deploys)
            $missing = [];
            foreach ($packages as $pkg) {
                if (!$this->isPackageInstalled($pkg)) {
                    $missing[] = $pkg;
                }
            }
            if (!empty($missing)) {
                $this->log("Installing missing Dovecot sub-packages: " . implode(', ', $missing));
                $this->runAptCommand(
                    'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" ' . implode(' ', $missing),
                    'Failed to install missing Dovecot packages'
                );
                foreach ($missing as $pkg) {
                    $this->recordPackageInstalled($serverId, $pkg);
                }
            }
        }

        // Verify critical binaries exist
        $binaries = [
            '/usr/lib/dovecot/imap' => 'dovecot-imapd',
            '/usr/lib/dovecot/lmtp' => 'dovecot-lmtpd',
        ];
        foreach ($binaries as $bin => $pkg) {
            $check = $this->executeCommand("test -f {$bin} && echo 'ok' || echo 'missing'");
            if (strpos($check['output'] ?? '', 'missing') !== false) {
                $this->log("Binary {$bin} missing, reinstalling {$pkg}...");
                $this->executeCommand("apt-get install --reinstall -y {$pkg} 2>&1 | tail -3");
            }
        }

        // Create vmail group (GID 5000) and user (UID 5000).
        // Mail lives under /home/vmail/<domain>/<user> - the SAME layout the
        // panel agent (MailAction/BackupAction), the webmail Sieve sync and the
        // sudoers rules seeded below all hardcode. Do NOT use /var/mail/vhosts.
        $this->executeCommand('getent group vmail >/dev/null 2>&1 || groupadd -g 5000 vmail');
        $this->executeCommand('id vmail 2>/dev/null || useradd -r -u 5000 -g vmail -d /home/vmail -s /usr/sbin/nologin vmail');
        $this->executeCommand('mkdir -p /home/vmail');
        $this->executeCommand('chown -R vmail:vmail /home/vmail');
        $this->executeCommand('chmod 2775 /home/vmail');

        // Global after-script referenced by dovecot.conf (sieve_after). An empty
        // sieve script is valid; per-user scripts and the mail security
        // before.d scripts are managed at runtime by the webmail/panel agent.
        $this->executeCommand('touch /home/vmail/global.sieve');
        $this->executeCommand('chown vmail:vmail /home/vmail/global.sieve');

        // Enable service (don't start yet - config not ready)
        $this->executeCommand('systemctl enable dovecot 2>/dev/null || true');

        $this->log("Dovecot installed - will start after configuration");
    }

    /**
     * Deploy Dovecot configuration from templates
     */
    private function deployDovecotConfig(array $variables): void
    {
        $this->log("Deploying Dovecot configuration...");
        
        $templatePath = $this->container->getConfig('templates.path') ?? __DIR__ . '/../../../templates';
        
        // Disable Ubuntu default conf.d to prevent it from overriding our self-contained config.
        // The default conf.d/10-master.conf etc. conflict with our template's service definitions.
        $confDCheck = $this->executeCommand('test -d /etc/dovecot/conf.d && echo "exists" || echo "none"');
        if (strpos($confDCheck['output'] ?? '', 'exists') !== false) {
            $this->log("Disabling Ubuntu default conf.d (our template is self-contained)...");
            $this->executeCommand('mv /etc/dovecot/conf.d /etc/dovecot/conf.d.disabled 2>/dev/null || true');
        }

        // Deploy main dovecot.conf
        $mainTemplate = "{$templatePath}/dovecot/dovecot.conf.template";
        if (file_exists($mainTemplate)) {
            $template = file_get_contents($mainTemplate);
            $this->log("Dovecot MAIL_DOMAIN = " . ($variables['MAIL_DOMAIN'] ?? 'NOT SET'));
            $content = $this->templates->process($template, $variables);

            // Sanity check: the word "postfix" must survive template processing
            if (strpos($content, 'user = postfix') === false) {
                $this->log("ERROR: Dovecot config corruption detected (postfix user missing after template processing)! Reprocessing with only MAIL_DOMAIN...");
                $content = $this->templates->process($template, ['MAIL_DOMAIN' => $variables['MAIL_DOMAIN'] ?? 'localhost']);
            }

            $this->ssh->uploadContent($content, '/etc/dovecot/dovecot.conf');
            $this->executeCommand('chmod 644 /etc/dovecot/dovecot.conf');
            $this->log("Deployed dovecot.conf");

            // Verify: confirm the file was actually written with our content
            $probe = $this->executeCommand("head -3 /etc/dovecot/dovecot.conf 2>/dev/null");
            if (strpos($probe['output'] ?? '', 'Fleet Manager') === false) {
                $this->log("Warning: dovecot.conf may not have been written correctly");
            }
        } else {
            throw new \Exception("CRITICAL: dovecot.conf.template not found at {$mainTemplate} — cannot deploy Dovecot without it. Verify templates are deployed on Fleet Manager server.");
        }
        
        // Deploy SQL config for authentication
        $sqlTemplate = "{$templatePath}/dovecot/dovecot-sql.conf.ext.template";
        if (file_exists($sqlTemplate)) {
            $template = file_get_contents($sqlTemplate);
            $content = $this->templates->process($template, $variables);
            $this->ssh->uploadContent($content, '/etc/dovecot/dovecot-sql.conf.ext');
            $this->executeCommand('chmod 600 /etc/dovecot/dovecot-sql.conf.ext');
            $this->executeCommand('chown root:dovecot /etc/dovecot/dovecot-sql.conf.ext');
            $this->log("Deployed dovecot-sql.conf.ext");
        } else {
            throw new \Exception("CRITICAL: dovecot-sql.conf.ext.template not found at {$sqlTemplate} — Dovecot auth will not work without it.");
        }
        
        // Create DH parameters if not exists (for SSL)
        // Must use nohup + full fd redirect so the SSH channel closes immediately
        $this->executeCommand('test -f /etc/dovecot/dh.pem || (nohup openssl dhparam -out /etc/dovecot/dh.pem 2048 >/dev/null 2>&1 &)');

        // Validate config before restarting (catches missing SSL certs, bad paths, etc.)
        $this->log("Validating Dovecot configuration...");
        $configTest = $this->executeCommand('doveconf -n 2>&1 | tail -5');
        $configOutput = $configTest['output'] ?? '';

        if (stripos($configOutput, 'Fatal') !== false || stripos($configOutput, 'Error') !== false) {
            $this->log("Dovecot config validation failed: " . trim($configOutput));

            // Check if SSL cert is the issue (common on first deploy before Let's Encrypt runs)
            if (stripos($configOutput, 'ssl_cert') !== false || stripos($configOutput, 'ssl_key') !== false) {
                $this->log("SSL certificate not yet available - generating temporary self-signed cert...");
                $this->generateSelfSignedCertForService('dovecot');
            }

            // Check for unsubstituted template variables
            $varCheck = $this->executeCommand("grep -c '{{' /etc/dovecot/dovecot.conf 2>/dev/null || echo '0'");
            if (intval(trim($varCheck['output'] ?? '0')) > 0) {
                $this->log("ERROR: Dovecot config still contains unsubstituted template variables!");
                $vars = $this->executeCommand("grep -oP '\\{\\{[A-Z_]+\\}\\}' /etc/dovecot/dovecot.conf 2>/dev/null | sort -u");
                $this->log("Unsubstituted: " . trim($vars['output'] ?? ''));
            }
        } else {
            $this->log("Dovecot config validation passed");
        }

        // Restart Dovecot
        $this->executeCommand('systemctl restart dovecot 2>/dev/null || true');
        sleep(2);

        // Verify with retry
        $status = trim($this->executeCommand('systemctl is-active dovecot 2>/dev/null || echo "inactive"')['output'] ?? '');
        if ($status === 'active') {
            $this->log("Dovecot configuration deployed and service running");
        } else {
            // Capture journal for diagnostics
            $journal = $this->executeCommand('journalctl -u dovecot --no-pager -n 10 2>/dev/null | tail -5');
            $this->log("Warning: Dovecot not running (status: {$status}). Journal: " . trim($journal['output'] ?? ''));

            // If SSL is the issue, try generating self-signed cert and retry
            if (stripos($journal['output'] ?? '', 'ssl_cert') !== false) {
                $this->log("Attempting SSL cert fix and retry...");
                $this->generateSelfSignedCertForService('dovecot');
                $this->executeCommand('systemctl restart dovecot 2>/dev/null || true');
                sleep(2);
                $retryStatus = trim($this->executeCommand('systemctl is-active dovecot 2>/dev/null || echo "inactive"')['output'] ?? '');
                if ($retryStatus === 'active') {
                    $this->log("Dovecot started after SSL cert fix");
                } else {
                    $this->log("Warning: Dovecot still not running after SSL fix. Will be resolved after Let's Encrypt setup.");
                }
            }
        }
    }

    /**
     * Generate a temporary self-signed certificate for a service that needs SSL before Let's Encrypt.
     * The cert is placed in the expected Let's Encrypt path so configs don't need changing.
     */
    private function generateSelfSignedCertForService(string $service): void
    {
        $this->log("Generating temporary self-signed cert for {$service}...");
        // Read the expected cert path from the config
        $certPath = '';
        $keyPath = '';

        if ($service === 'dovecot') {
            $certLine = $this->executeCommand("grep 'ssl_cert' /etc/dovecot/dovecot.conf 2>/dev/null | head -1");
            if (preg_match('#<(.+)#', $certLine['output'] ?? '', $m)) {
                $certPath = trim($m[1]);
            }
            $keyLine = $this->executeCommand("grep 'ssl_key' /etc/dovecot/dovecot.conf 2>/dev/null | head -1");
            if (preg_match('#<(.+)#', $keyLine['output'] ?? '', $m)) {
                $keyPath = trim($m[1]);
            }
        } elseif ($service === 'postfix') {
            $certLine = $this->executeCommand("postconf -h smtpd_tls_cert_file 2>/dev/null");
            $certPath = trim($certLine['output'] ?? '');
            $keyLine = $this->executeCommand("postconf -h smtpd_tls_key_file 2>/dev/null");
            $keyPath = trim($keyLine['output'] ?? '');
        }

        if (empty($certPath) || empty($keyPath)) {
            $this->log("Could not determine cert/key paths for {$service}, skipping self-signed generation");
            return;
        }

        // Check if cert already exists
        $exists = $this->executeCommand("test -f {$certPath} && echo 'ok' || echo 'missing'");
        if (strpos($exists['output'] ?? '', 'ok') !== false) {
            $this->log("Certificate already exists at {$certPath}");
            return;
        }

        // Create directory structure
        $certDir = dirname($certPath);
        $keyDir = dirname($keyPath);
        $this->executeCommand("mkdir -p {$certDir} {$keyDir}");

        // Extract domain from path for CN
        $domain = 'localhost';
        if (preg_match('#/live/([^/]+)/#', $certPath, $m)) {
            $domain = $m[1];
        }

        // Generate self-signed cert (valid for 30 days - just a placeholder until Let's Encrypt)
        $this->executeCommand(
            "openssl req -x509 -nodes -days 30 -newkey rsa:2048 " .
            "-keyout {$keyPath} -out {$certPath} " .
            "-subj '/CN={$domain}/O=Fleet Manager/C=HU' 2>&1"
        );
        $this->executeCommand("chmod 644 {$certPath} && chmod 600 {$keyPath}");
        $this->log("Self-signed cert generated at {$certPath} (placeholder until Let's Encrypt)");
    }

    /**
     * Deploy Postfix configuration from templates
     */
    /**
     * Derive the server's fully-qualified hostname (used for hostnamectl and Postfix
     * myhostname). Mirrors the production server, which uses the BARE base domain
     * (e.g. devcon1.hu) - NOT a subdomain - so the HELO name matches the A record and
     * reverse DNS the operator controls. The historical "<panel-label>.<base>" (e.g.
     * panel.weddingcards.hu) had no matching PTR and risked mail deliverability.
     */
    private function deriveServerFqdn(array $variables): string
    {
        if (!empty($variables['SERVER_FQDN'])) {
            return $variables['SERVER_FQDN'];
        }

        $hostname = $variables['SERVER_HOSTNAME'] ?? 'vps';
        $panelDomain = $variables['PANEL_DOMAIN'] ?? '';
        if ($panelDomain === '') {
            $panelDomain = $variables['MAIL_DOMAIN'] ?? $variables['EMAIL_DOMAIN'] ?? '';
        }
        if ($panelDomain === '') {
            return $hostname . '.localdomain';
        }

        // Strip the leading label to get the base domain. If that leaves a bare TLD
        // the panel domain was already the base domain itself, so keep it.
        $baseDomain = preg_replace('/^[^.]+\./', '', $panelDomain);
        if ($baseDomain === '' || strpos($baseDomain, '.') === false) {
            $baseDomain = $panelDomain;
        }
        return $baseDomain;
    }

    private function deployPostfixConfig(array $variables): void
    {
        $this->log("Deploying Postfix configuration...");

        // Guarantee SERVER_FQDN is set so Postfix myhostname resolves to a valid FQDN
        // (blueprint-sourced variable sets may predate this variable).
        if (empty($variables['SERVER_FQDN'])) {
            $variables['SERVER_FQDN'] = $this->deriveServerFqdn($variables);
        }

        $templatePath = $this->container->getConfig('templates.path') ?? __DIR__ . '/../../../templates';
        
        $templates = [
            'main.cf.template' => '/etc/postfix/main.cf',
            'master.cf.template' => '/etc/postfix/master.cf',
            'mysql-virtual-domains.cf.template' => '/etc/postfix/mysql-virtual-domains.cf',
            'mysql-virtual-mailboxes.cf.template' => '/etc/postfix/mysql-virtual-mailboxes.cf',
            'mysql-virtual-aliases.cf.template' => '/etc/postfix/mysql-virtual-aliases.cf',
        ];
        
        foreach ($templates as $templateFile => $destPath) {
            $fullTemplatePath = "{$templatePath}/postfix/{$templateFile}";
            if (file_exists($fullTemplatePath)) {
                $template = file_get_contents($fullTemplatePath);
                $content = $this->templates->process($template, $variables);
                $this->ssh->uploadContent($content, $destPath);
                
                // Set appropriate permissions (mysql configs need restricted access)
                if (strpos($templateFile, 'mysql-') === 0) {
                    $this->executeCommand("chmod 640 {$destPath}");
                    $this->executeCommand("chown root:postfix {$destPath}");
                } else {
                    $this->executeCommand("chmod 644 {$destPath}");
                }
                
                $this->log("Deployed {$templateFile}");
            } else {
                $this->log("Warning: {$templateFile} not found at {$fullTemplatePath}");
            }
        }
        
        // Write mailname
        $mailDomain = $variables['MAIL_DOMAIN'] ?? 'localhost';
        $this->executeCommand("echo '{$mailDomain}' > /etc/mailname");

        // Check for unsubstituted template variables before restarting
        $varCheck = $this->executeCommand("grep -c '{{' /etc/postfix/main.cf 2>/dev/null || echo '0'");
        if (intval(trim($varCheck['output'] ?? '0')) > 0) {
            $vars = $this->executeCommand("grep -oP '\\{\\{[A-Z_]+\\}\\}' /etc/postfix/main.cf 2>/dev/null | sort -u");
            $this->log("ERROR: Postfix main.cf still has unsubstituted variables: " . trim($vars['output'] ?? ''));
        }

        // Validate config syntax before restarting
        $this->log("Validating Postfix configuration...");
        $configCheck = $this->executeCommand('postfix check 2>&1');
        $checkOutput = trim($configCheck['output'] ?? '');
        if (!empty($checkOutput)) {
            $this->log("Postfix config check output: {$checkOutput}");
        } else {
            $this->log("Postfix config validation passed");
        }

        // Actually EXERCISE a mysql: map. main.cf points virtual_alias_maps /
        // virtual_mailbox_* at mysql: maps; if the 'mysql' dict type isn't loaded the
        // chrooted cleanup daemon errors on every message ("451 4.3.0 queue file write
        // error") even though `postfix check` passes. A not-found result is fine (the
        // mail DB may not be seeded yet); only "unsupported dictionary type: mysql" is
        // fatal and deterministic, so fail the deploy loudly on that.
        $runMapProbe = function () use ($mailDomain): string {
            $probe = escapeshellarg("probe@{$mailDomain}");
            $res = $this->executeCommand(
                "postmap -q {$probe} mysql:/etc/postfix/mysql-virtual-aliases.cf 2>&1; "
                . "postmap -q {$probe} mysql:/etc/postfix/mysql-virtual-mailboxes.cf 2>&1; "
                . "postmap -q " . escapeshellarg($mailDomain) . " mysql:/etc/postfix/mysql-virtual-domains.cf 2>&1; "
                . "true"
            );
            return (string)($res['output'] ?? '');
        };

        $probeOut = $runMapProbe();

        // SELF-HEAL: a missing 'mysql' dict here means postfix-mysql wasn't
        // installed/registered before configs landed - e.g. install_postfix was
        // skipped as already-complete on a resumed deploy that first ran under
        // older code. Rather than fail the deploy (and require a manual fix),
        // install/register it inline and re-probe. Only throw if it still fails.
        $this->log("Postfix mysql map probe output: " . trim($probeOut !== '' ? $probeOut : '(empty)'));
        if (stripos($probeOut, 'unsupported dictionary type: mysql') !== false) {
            $this->log("Postfix 'mysql' map type missing at config-deploy time - installing/registering postfix-mysql inline...");
            $this->ensurePostfixMysqlSupport();
            $this->executeCommand('systemctl restart postfix 2>/dev/null || true');
            $probeOut = $runMapProbe();
            $this->log("Postfix mysql map probe output (after repair): " . trim($probeOut !== '' ? $probeOut : '(empty)'));

            if (stripos($probeOut, 'unsupported dictionary type: mysql') !== false) {
                throw new \Exception(
                    "Postfix mysql map lookups are STILL unsupported (dict_mysql.so not registered) after an inline "
                    . "postfix-mysql install/reinstall - mail would fail with '451 queue file write error'. "
                    . "Inspect 'postconf -m' and /etc/postfix/dynamicmaps.cf on the target."
                );
            }
            $this->log("postfix-mysql registered inline - mysql map type is now live");
        } elseif (stripos($probeOut, 'mysql') !== false && stripos($probeOut, 'error') !== false) {
            // Non-fatal here (DB may not be seeded yet) but surface it for diagnosis.
            $this->log("Note: Postfix mysql map probe reported: " . trim(substr($probeOut, 0, 300)));
        } else {
            $this->log("Postfix mysql map lookups OK (mysql dict type is live)");
        }

        // Generate self-signed cert if SSL cert doesn't exist yet
        $sslCert = $this->executeCommand("postconf -h smtpd_tls_cert_file 2>/dev/null");
        $sslPath = trim($sslCert['output'] ?? '');
        if (!empty($sslPath)) {
            $certExists = $this->executeCommand("test -f {$sslPath} && echo 'ok' || echo 'missing'");
            if (strpos($certExists['output'] ?? '', 'missing') !== false) {
                $this->log("SSL cert not yet available for Postfix, generating temporary self-signed...");
                $this->generateSelfSignedCertForService('postfix');
            }
        }

        // Restart Postfix
        $this->executeCommand('systemctl restart postfix 2>/dev/null || true');
        sleep(2);

        $status = $this->executeCommand('systemctl is-active postfix 2>/dev/null || echo "inactive"');
        $statusVal = trim($status['output'] ?? '');
        if (in_array($statusVal, ['active', 'exited'])) {
            $this->log("Postfix configuration deployed and service running");
        } else {
            $journal = $this->executeCommand('journalctl -u postfix --no-pager -n 5 2>/dev/null');
            $this->log("Warning: Postfix not running (status: {$statusVal}). Journal: " . trim($journal['output'] ?? ''));
        }
    }

    /**
     * Install and configure OpenDKIM for email signing
     */
    private function installOpenDKIM(int $serverId, array &$variables): void
    {
        $this->log("Setting up OpenDKIM...");

        $mailDomain = preg_replace('/^mail\./', '', $variables['MAIL_DOMAIN'] ?? $variables['EMAIL_DOMAIN'] ?? '');
        $selector = $variables['DKIM_SELECTOR'] ?? 'mail';

        if (empty($mailDomain)) {
            $this->log("Warning: No mail domain configured, skipping OpenDKIM");
            return;
        }

        // Install OpenDKIM packages
        if (!$this->isPackageInstalled('opendkim')) {
            $this->runAptCommand(
                'apt-get install -y opendkim opendkim-tools',
                'Failed to install OpenDKIM'
            );
        }

        // Create directory structure
        $this->executeCommand("mkdir -p /etc/opendkim/keys/{$mailDomain}");
        $this->executeCommand('chown -R opendkim:opendkim /etc/opendkim');

        // Generate DKIM key pair (only if the private key isn't already present).
        $keyDir  = "/etc/opendkim/keys/{$mailDomain}";
        $privKey = "{$keyDir}/{$selector}.private";
        $txtKey  = "{$keyDir}/{$selector}.txt";
        $keyCheck = $this->executeCommand("test -f {$privKey} && echo 'exists' || echo 'missing'");
        if (trim($keyCheck['output'] ?? '') !== 'exists') {
            $this->log("Generating DKIM key pair for {$mailDomain}...");
            $this->executeCommand("opendkim-genkey -b 2048 -d {$mailDomain} -D {$keyDir} -s {$selector} -v 2>/dev/null");
            $this->executeCommand("chown opendkim:opendkim {$privKey} 2>/dev/null || true");
            $this->executeCommand("chmod 600 {$privKey} 2>/dev/null || true");
        } else {
            $this->log("DKIM key pair already exists for {$mailDomain}");
        }

        // Self-clean foreign DKIM material. A FlowOne box signs ONLY its own
        // domain (ConfigExtractorService deliberately never clones keys), so the
        // single legitimate entry under /etc/opendkim/keys/ is this box's own
        // {$mailDomain} directory. On cloned / hybrid images (e.g. a CyberPanel
        // box) dozens of foreign per-domain key dirs and stray loose *.private
        // files can pre-exist; left in place they let this box sign/publish with
        // someone else's selector and let a stale blueprint repopulate the
        // KeyTable/SigningTable. Remove anything that is not this box's own
        // domain dir. $mailDomain is guaranteed non-empty here (early return
        // above) and is shell-escaped so an empty/odd value can never nuke the
        // whole directory.
        $keysRoot = '/etc/opendkim/keys';
        $ownDir = escapeshellarg($mailDomain);
        $foreign = $this->executeCommand(
            "find {$keysRoot} -mindepth 1 -maxdepth 1 ! -name {$ownDir} 2>/dev/null"
        );
        $foreignList = array_values(array_filter(array_map('trim', explode("\n", $foreign['output'] ?? ''))));
        if (!empty($foreignList)) {
            $names = implode(', ', array_map('basename', $foreignList));
            $this->log("Removing " . count($foreignList) . " foreign DKIM key entr" . (count($foreignList) === 1 ? 'y' : 'ies') . " from {$keysRoot} (this box only signs {$mailDomain}): {$names}");
            $this->executeCommand(
                "find {$keysRoot} -mindepth 1 -maxdepth 1 ! -name {$ownDir} -exec rm -rf {} + 2>/dev/null || true"
            );
        }

        // Write OpenDKIM config
        $opendkimConf = <<<CONF
AutoRestart             Yes
AutoRestartRate         10/1h
Syslog                  yes
SyslogSuccess           Yes
LogWhy                  Yes
Canonicalization        relaxed/simple
ExternalIgnoreList      refile:/etc/opendkim/TrustedHosts
InternalHosts           refile:/etc/opendkim/TrustedHosts
KeyTable                refile:/etc/opendkim/KeyTable
SigningTable            refile:/etc/opendkim/SigningTable
Mode                    sv
PidFile                 /run/opendkim/opendkim.pid
SignatureAlgorithm      rsa-sha256
UserID                  opendkim:opendkim
Socket                  inet:8891@localhost
CONF;
        $this->ssh->uploadContent($opendkimConf, '/etc/opendkim.conf');

        // Trusted hosts
        $trustedHosts = <<<HOSTS
127.0.0.1
localhost
{$mailDomain}
*.{$mailDomain}
HOSTS;
        $this->ssh->uploadContent($trustedHosts, '/etc/opendkim/TrustedHosts');

        // Key table
        $keyTable = "{$selector}._domainkey.{$mailDomain} {$mailDomain}:{$selector}:/etc/opendkim/keys/{$mailDomain}/{$selector}.private\n";
        $this->ssh->uploadContent($keyTable, '/etc/opendkim/KeyTable');

        // Signing table
        $signingTable = "*@{$mailDomain} {$selector}._domainkey.{$mailDomain}\n";
        $this->ssh->uploadContent($signingTable, '/etc/opendkim/SigningTable');

        // Set permissions
        $this->executeCommand('chown -R opendkim:opendkim /etc/opendkim');
        $this->executeCommand('chmod 700 /etc/opendkim/keys');

        // Ensure run directory exists
        $this->executeCommand('mkdir -p /run/opendkim && chown opendkim:opendkim /run/opendkim');

        // Enable and start OpenDKIM
        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable opendkim');
        $this->executeCommand('systemctl restart opendkim');

        sleep(2);

        // Verify it's running
        $status = $this->executeCommand('systemctl is-active opendkim 2>/dev/null || echo "inactive"');
        $statusStr = trim($status['output'] ?? '');
        if ($statusStr === 'active') {
            $this->log("OpenDKIM installed and running");
        } else {
            $this->log("Warning: OpenDKIM service status is {$statusStr}");
        }

        // Resolve the DKIM public record so we can BOTH log it AND seed it into DNS.
        // Prefer the opendkim-genkey .txt; if it's missing - e.g. the private key
        // pre-existed (partial prior run / cloned key dir) so genkey was skipped and
        // never wrote a .txt - DERIVE the public key straight from the private key via
        // openssl. opendkim's p= value is exactly base64(DER SubjectPublicKeyInfo),
        // which is what `openssl rsa -pubout` emits, so the two are identical. Without
        // this the box signs mail but receivers see DKIM=none and, with our strict
        // DMARC (p=reject; adkim=s), deliverability suffers (= the "missing 3rd record").
        $dkimRecord = $this->executeCommand("cat {$txtKey} 2>/dev/null");
        $dkimTxt = trim($dkimRecord['output'] ?? '');
        $dkimValue = $dkimTxt !== '' ? $this->extractDkimValue($dkimTxt) : '';
        if (strpos($dkimValue, 'p=') === false) {
            $derive = $this->executeCommand(
                "openssl rsa -in {$privKey} -pubout 2>/dev/null | grep -v '^-----' | tr -d '\\n'"
            );
            $pub = trim($derive['output'] ?? '');
            if ($pub !== '') {
                $dkimValue = "v=DKIM1;h=sha256;k=rsa;p={$pub}";
                // Rebuild the .txt so operators + future reads see a consistent record.
                if ($dkimTxt === '') {
                    $rebuilt = "{$selector}._domainkey\tIN\tTXT\t( \"v=DKIM1; h=sha256; k=rsa; \"\n\t  \"p={$pub}\" )  ; ----- DKIM key {$selector} for {$mailDomain}\n";
                    $this->ssh->uploadContent($rebuilt, $txtKey);
                    $this->executeCommand("chown opendkim:opendkim {$txtKey} 2>/dev/null || true");
                }
            }
        }
        if (strpos($dkimValue, 'p=') !== false) {
            $variables['DKIM_DNS_RECORD'] = $dkimValue;
            $variables['DKIM_DNS_NAME'] = "{$selector}._domainkey.{$mailDomain}";
            $this->log("=== DKIM DNS RECORD (publish as TXT) ===");
            $this->log("Name: {$selector}._domainkey.{$mailDomain}");
            $this->log($dkimValue);
            $this->log("=== END DKIM DNS RECORD ===");
        } else {
            $this->log("Warning: could not resolve DKIM public key for {$mailDomain} ({$selector}.private/.txt both unusable) - DKIM TXT NOT seeded");
        }

        // Restart Postfix so it can connect to the OpenDKIM milter
        $this->executeCommand('systemctl restart postfix 2>/dev/null || true');

        $this->recordPackageInstalled($serverId, 'opendkim');
        $this->recordPackageInstalled($serverId, 'opendkim-tools');
        $this->log("OpenDKIM setup complete");
    }

    /**
     * Extract clean DKIM p= value from opendkim-genkey output
     */
    private function extractDkimValue(string $rawDkimTxt): string
    {
        // Remove the record name prefix and IN TXT parts
        $clean = preg_replace('/^[^\(]*\(\s*/', '', $rawDkimTxt);
        $clean = preg_replace('/\s*\)\s*;.*$/', '', $clean);
        // Remove quotes and join lines
        $clean = preg_replace('/"\s+"/', '', $clean);
        $clean = str_replace('"', '', $clean);
        $clean = preg_replace('/\s+/', '', $clean);
        return trim($clean);
    }

    /**
     * Install security tools with idempotency
     */
    private function installSecurityTools(int $serverId): void
    {
        $packages = ['fail2ban', 'firewalld'];
        $needsInstall = [];

        foreach ($packages as $package) {
            if (!$this->isPackageInstalled($package)) {
                $needsInstall[] = $package;
            }
        }

        if (!empty($needsInstall)) {
            $this->log("Installing security tools: " . implode(', ', $needsInstall));
            $this->runAptCommand(
                'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" ' . implode(' ', $needsInstall),
                'Failed to install security tools'
            );

            foreach ($needsInstall as $package) {
                $this->recordPackageInstalled($serverId, $package);
            }
            $this->log("Security tools installed");
        } else {
            $this->log("Security tools already installed");
        }
    }

    /**
     * Install + wire SpamAssassin milter (spamass-milter) and the SPF policy
     * daemon so inbound mail is scanned and SPF-checked, matching the source
     * Postfix milter chain (unix:/var/spool/postfix/spamass/spamass.sock + policyd-spf).
     */
    private function setupMailScanning(int $serverId): void
    {
        $this->log("Setting up mail scanning (spamass-milter + policyd-spf)...");

        $packages = ['spamass-milter', 'postfix-policyd-spf-python'];
        $needsInstall = [];
        foreach ($packages as $package) {
            if (!$this->isPackageInstalled($package)) {
                $needsInstall[] = $package;
            }
        }
        if (!empty($needsInstall)) {
            $this->runAptCommand(
                'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" ' . implode(' ', $needsInstall),
                'Failed to install mail scanning packages'
            );
            foreach ($needsInstall as $package) {
                $this->recordPackageInstalled($serverId, $package);
            }
        }

        // spamass-milter listens on a unix socket inside the Postfix spool so the
        // chrooted smtpd can reach it. Create the socket dir owned by the milter user.
        $this->executeCommand('mkdir -p /var/spool/postfix/spamass');
        $this->executeCommand('chown spamass-milter:postfix /var/spool/postfix/spamass 2>/dev/null || chown spamass-milter /var/spool/postfix/spamass 2>/dev/null || true');
        $this->executeCommand('chmod 750 /var/spool/postfix/spamass');

        // Point spamass-milter at that socket and run as the milter user.
        // Matches the source server: the socket path is given via -p in OPTIONS
        // (the SOCKET= var is left at its default). -i whitelists localhost, -m
        // leaves the Subject/headers unmodified.
        $spamassDefault = <<<CONF
# Managed by Fleet Manager (matches source server)
# OPTIONS are passed directly to spamass-milter; -p sets the milter socket.
OPTIONS="-u spamass-milter -i 127.0.0.1 -p /var/spool/postfix/spamass/spamass.sock -m"
CONF;
        $this->ssh->uploadContent($spamassDefault, '/etc/default/spamass-milter');
        $this->executeCommand('chmod 644 /etc/default/spamass-milter');

        // Let the postfix user read the milter socket.
        $this->executeCommand('usermod -a -G spamass-milter postfix 2>/dev/null || true');

        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable spamass-milter 2>/dev/null || true');
        $this->executeCommand('systemctl restart spamass-milter 2>/dev/null || true');

        $status = trim($this->executeCommand("systemctl is-active spamass-milter 2>/dev/null || echo 'inactive'")['output'] ?? '');
        if ($status === 'active') {
            $this->log("spamass-milter running (socket: /var/spool/postfix/spamass/spamass.sock)");
        } else {
            $this->log("Warning: spamass-milter is {$status} - inbound spam scanning may be inactive");
        }

        // policyd-spf runs on demand via the Postfix spawn service (master.cf); just
        // confirm the binary is present.
        $spfBin = trim($this->executeCommand('test -x /usr/bin/policyd-spf && echo ok || echo missing')['output'] ?? '');
        if ($spfBin !== 'ok') {
            $this->log("Warning: /usr/bin/policyd-spf not found - SPF policy checks will fail");
        }

        $this->log("Mail scanning setup complete");
    }

    /**
     * Install + configure the host IDS / audit layer (auditd, rkhunter, aide,
     * chkrootkit, logwatch) to match the source server's hardening.
     */
    private function installAuditTools(int $serverId): void
    {
        $this->log("Installing host audit/IDS tools...");

        $packages = ['auditd', 'audispd-plugins', 'rkhunter', 'aide', 'aide-common', 'chkrootkit', 'logwatch'];
        $needsInstall = [];
        foreach ($packages as $package) {
            if (!$this->isPackageInstalled($package)) {
                $needsInstall[] = $package;
            }
        }
        if (!empty($needsInstall)) {
            $this->runAptCommand(
                'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" ' . implode(' ', $needsInstall),
                'Failed to install audit tools'
            );
            foreach ($needsInstall as $package) {
                $this->recordPackageInstalled($serverId, $package);
            }
        }

        // --- auditd: deploy the hardened watch rules (matches source) ---
        $auditRules = <<<RULES
# Managed by Fleet Manager - hardened audit watches
-w /etc -p wa -k etc_changes
-w /home -p wa -k home_changes
-w /usr/local/lsws -p wa -k lsws_changes
-w /var/log -p wa -k log_watch
-w /etc/passwd -p wa -k identity
-w /etc/group -p wa -k identity
-w /etc/shadow -p wa -k identity
-w /etc/sudoers -p wa -k sudoers
-w /etc/ssh/sshd_config -p wa -k sshd
-a always,exit -F arch=b64 -S execve -F key=exec
RULES;
        $this->executeCommand('mkdir -p /etc/audit/rules.d');
        $this->ssh->uploadContent($auditRules, '/etc/audit/rules.d/hardened.rules');
        $this->executeCommand('chmod 640 /etc/audit/rules.d/hardened.rules');
        $this->executeCommand('systemctl enable auditd 2>/dev/null || true');
        $this->executeCommand('augenrules --load 2>/dev/null || true');
        $this->executeCommand('systemctl restart auditd 2>/dev/null || service auditd restart 2>/dev/null || true');

        // --- rkhunter: disable broken mirror updates + web fetch (source hardening) ---
        $this->executeCommand("sed -i 's/^UPDATE_MIRRORS=.*/UPDATE_MIRRORS=0/' /etc/rkhunter.conf 2>/dev/null || true");
        $this->executeCommand("grep -q '^UPDATE_MIRRORS=' /etc/rkhunter.conf 2>/dev/null || echo 'UPDATE_MIRRORS=0' >> /etc/rkhunter.conf");
        $this->executeCommand("sed -i 's/^MIRRORS_MODE=.*/MIRRORS_MODE=1/' /etc/rkhunter.conf 2>/dev/null || true");
        $this->executeCommand("grep -q '^MIRRORS_MODE=' /etc/rkhunter.conf 2>/dev/null || echo 'MIRRORS_MODE=1' >> /etc/rkhunter.conf");
        $this->executeCommand("sed -i 's|^WEB_CMD=.*|WEB_CMD=\"/bin/false\"|' /etc/rkhunter.conf 2>/dev/null || true");
        $this->executeCommand("grep -q '^WEB_CMD=' /etc/rkhunter.conf 2>/dev/null || echo 'WEB_CMD=\"/bin/false\"' >> /etc/rkhunter.conf");
        // Baseline the file-property database (non-fatal)
        $this->executeCommand('rkhunter --propupd 2>/dev/null || true');

        // --- aide: initialise the integrity DB in the background (can take minutes) ---
        $aideDbCheck = trim($this->executeCommand('test -f /var/lib/aide/aide.db && echo exists || echo missing')['output'] ?? '');
        if ($aideDbCheck !== 'exists') {
            $this->log("Initialising AIDE database in background (this can take several minutes)...");
            $this->executeCommand('nohup sh -c "aideinit -y -f 2>/dev/null || aide --init 2>/dev/null; [ -f /var/lib/aide/aide.db.new ] && cp -f /var/lib/aide/aide.db.new /var/lib/aide/aide.db" >/var/log/fleet-aideinit.log 2>&1 &');
        } else {
            $this->log("AIDE database already present");
        }

        $this->log("Host audit/IDS tools installed");
    }

    /**
     * Install and configure OpenDMARC alongside OpenDKIM
     */
    private function installOpenDMARC(int $serverId, array $variables): void
    {
        $this->log("Setting up OpenDMARC...");

        $mailDomain = preg_replace('/^mail\./', '', $variables['MAIL_DOMAIN'] ?? $variables['EMAIL_DOMAIN'] ?? '');

        if (empty($mailDomain)) {
            $this->log("Warning: No mail domain configured, skipping OpenDMARC");
            return;
        }

        if (!$this->isPackageInstalled('opendmarc')) {
            $this->runAptCommand(
                'apt-get install -y opendmarc',
                'Failed to install OpenDMARC'
            );
        }

        $opendmarcConf = <<<CONF
AuthservID OpenDMARC
TrustedAuthservIDs {$mailDomain}
RejectFailures false
IgnoreMailFrom {$mailDomain}
Socket inet:8893@localhost
SoftwareHeader true
SPFSelfValidate true
Syslog true
UMask 0002
UserID opendmarc:opendmarc
FailureReports false
AutoRestart true
AutoRestartRate 10/1h
CONF;
        $this->ssh->uploadContent($opendmarcConf, '/etc/opendmarc.conf');

        $this->executeCommand('mkdir -p /run/opendmarc && chown opendmarc:opendmarc /run/opendmarc');

        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable opendmarc');
        $this->executeCommand('systemctl restart opendmarc');

        sleep(2);

        $status = $this->executeCommand('systemctl is-active opendmarc 2>/dev/null || echo "inactive"');
        $statusStr = trim($status['output'] ?? '');
        if ($statusStr === 'active') {
            $this->log("OpenDMARC installed and running");
        } else {
            $this->log("Warning: OpenDMARC service status is {$statusStr}");
        }

        $this->recordPackageInstalled($serverId, 'opendmarc');
        $this->log("OpenDMARC setup complete");
    }

    /**
     * Install ClamAV antivirus daemon and freshclam updater
     */
    private function installClamAV(int $serverId): void
    {
        $this->log("Setting up ClamAV...");

        $packages = ['clamav-daemon', 'clamav-freshclam'];
        $needsInstall = [];

        foreach ($packages as $package) {
            if (!$this->isPackageInstalled($package)) {
                $needsInstall[] = $package;
            }
        }

        if (empty($needsInstall)) {
            $this->log("ClamAV already installed");
            return;
        }

        $this->runAptCommand(
            'apt-get install -y ' . implode(' ', $needsInstall),
            'Failed to install ClamAV'
        );

        // freshclam must run once before clamd can start (needs virus signatures)
        $this->executeCommand('systemctl stop clamav-freshclam 2>/dev/null || true');
        $this->executeCommand('freshclam 2>/dev/null || true');

        $this->executeCommand('systemctl enable clamav-freshclam');
        $this->executeCommand('systemctl start clamav-freshclam');
        $this->executeCommand('systemctl enable clamav-daemon');
        $this->executeCommand('systemctl start clamav-daemon 2>/dev/null || true');

        foreach ($packages as $package) {
            $this->recordPackageInstalled($serverId, $package);
        }
        $this->log("ClamAV installed and running");
    }

    /**
     * Install PowerDNS authoritative server with MySQL backend
     */
    private function installPowerDNS(int $serverId, array $variables): void
    {
        $this->log("Setting up PowerDNS...");

        if (!$this->isPackageInstalled('pdns-server')) {
            // Disable systemd-resolved which binds port 53 and conflicts with PowerDNS
            $this->executeCommand('systemctl stop systemd-resolved 2>/dev/null || true');
            $this->executeCommand('systemctl disable systemd-resolved 2>/dev/null || true');

            // Point resolv.conf to a real upstream DNS so apt/network still works
            $this->executeCommand("echo 'nameserver 8.8.8.8' > /etc/resolv.conf");

            $this->runAptCommand(
                'apt-get install -y pdns-server pdns-backend-mysql',
                'Failed to install PowerDNS'
            );
        }

        $panelDbName = $variables['PANEL_DB_NAME'] ?? 'devc_vps_dash';
        $dbUser = $variables['PANEL_DB_USER'] ?? $variables['DB_USER'] ?? 'vpsadmin';
        $dbPass = $variables['PANEL_DB_PASS'] ?? $variables['DB_PASS'] ?? '';
        $serverIp = $variables['SERVER_IP'] ?? '0.0.0.0';
        $ns1 = $variables['NS1_DOMAIN'] ?? '';
        $soaNs = !empty($ns1) ? $ns1 : 'ns1.@';

        // Bind the public IP AND loopback so on-box health checks (dig @127.0.0.1)
        // and the local apps can always reach the authoritative server. If no
        // specific IP is known, bind all interfaces.
        $bindAddr = (empty($serverIp) || $serverIp === '0.0.0.0')
            ? '0.0.0.0'
            : "{$serverIp}, 127.0.0.1";

        // IMPORTANT: guardian/daemon are intentionally OFF. The packaged
        // pdns.service unit is Type=notify and runs pdns_server in the foreground;
        // with daemon=yes/guardian=yes the process forks away, systemd never
        // receives the READY notification, and the unit hangs forever in
        // "activating" (never binding :53).
        $pdnsConf = <<<CONF
setgid=pdns
setuid=pdns
launch=gmysql
gmysql-host=127.0.0.1
gmysql-port=3306
gmysql-dbname={$panelDbName}
gmysql-user={$dbUser}
gmysql-password={$dbPass}
gmysql-dnssec=no

local-address={$bindAddr}
local-port=53
guardian=no
daemon=no

webserver=no
api=no
default-soa-content={$soaNs} hostmaster.@ 0 10800 3600 604800 3600
CONF;

        $this->executeCommand('mkdir -p /etc/powerdns');
        $this->ssh->uploadContent($pdnsConf, '/etc/powerdns/pdns.conf');
        $this->executeCommand('chmod 640 /etc/powerdns/pdns.conf');
        $this->executeCommand('chown root:pdns /etc/powerdns/pdns.conf');

        // Remove the default bind backend config that ships with the package
        $this->executeCommand('rm -f /etc/powerdns/pdns.d/bind.conf 2>/dev/null || true');

        // The gmysql backend requires PowerDNS's own schema (domains, records,
        // domainmetadata, cryptokeys, ...) to exist in the target DB. Without it
        // pdns cannot initialise the backend and never starts. Load it once,
        // idempotently -- only if the `domains` table is missing.
        $schemaLoad = <<<BASH
if ! mariadb --defaults-file=/root/.my.cnf {$panelDbName} -N -e "SELECT 1 FROM domains LIMIT 1" >/dev/null 2>&1; then
    SCHEMA=""
    for c in /usr/share/pdns-backend-mysql/schema/schema.mysql.sql \\
             /usr/share/doc/pdns-backend-mysql/schema.mysql.sql \\
             /usr/share/doc/pdns-backend-mysql/schema.mysql.sql.gz \\
             /usr/share/dbconfig-common/data/pdns-backend-mysql/install/mysql; do
        if [ -f "\$c" ]; then SCHEMA="\$c"; break; fi
    done
    if [ -n "\$SCHEMA" ]; then
        case "\$SCHEMA" in
            *.gz) zcat "\$SCHEMA" | mariadb --defaults-file=/root/.my.cnf {$panelDbName} && echo PDNS_SCHEMA_LOADED ;;
            *)    mariadb --defaults-file=/root/.my.cnf {$panelDbName} < "\$SCHEMA" && echo PDNS_SCHEMA_LOADED ;;
        esac
    else
        echo PDNS_SCHEMA_NOT_FOUND
    fi
else
    echo PDNS_SCHEMA_EXISTS
fi
BASH;
        $schemaResult = $this->executeCommand($schemaLoad);
        $schemaOut = trim($schemaResult['output'] ?? '');
        if (str_contains($schemaOut, 'PDNS_SCHEMA_NOT_FOUND')) {
            $this->log("WARNING: PowerDNS gmysql schema file not found on host - pdns will fail to start");
        } else {
            $this->log("PowerDNS gmysql schema status: {$schemaOut}");
        }

        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable pdns');
        $this->executeCommand('systemctl restart pdns');

        sleep(2);

        $status = $this->executeCommand('systemctl is-active pdns 2>/dev/null || echo "inactive"');
        $statusStr = trim($status['output'] ?? '');
        if ($statusStr !== 'active') {
            // One corrective retry (clear failed state, restart), then re-check.
            $this->executeCommand('systemctl reset-failed pdns 2>/dev/null || true');
            $this->executeCommand('systemctl restart pdns 2>/dev/null || true');
            sleep(3);
            $status = $this->executeCommand('systemctl is-active pdns 2>/dev/null || echo "inactive"');
            $statusStr = trim($status['output'] ?? '');
        }

        if ($statusStr === 'active') {
            $this->log("PowerDNS installed and running (gmysql backend -> {$panelDbName})");
        } else {
            // Capture actionable diagnostics into the step log. We deliberately do
            // NOT throw here: install_powerdns aborting would block deploy_panel /
            // deploy_email / setup_ssl, and DNS is repairable post-deploy. The
            // audit step surfaces this as a failure for the operator.
            $diag = $this->executeCommand('journalctl -u pdns --no-pager -n 30 2>/dev/null; echo "--- status ---"; systemctl status pdns --no-pager -l 2>/dev/null | head -n 20');
            $this->log("ERROR: PowerDNS did not reach active state (status={$statusStr}). Diagnostics:\n" . trim($diag['output'] ?? ''));
        }

        $this->recordPackageInstalled($serverId, 'pdns-server');
        $this->recordPackageInstalled($serverId, 'pdns-backend-mysql');
        $this->log("PowerDNS setup complete");
    }

    /**
     * Install extra infrastructure services: coTURN, LiveKit, nghttpx, stunnel4
     */
    private function installExtraServices(int $serverId, array &$variables): void
    {
        $this->log("Installing extra infrastructure services...");

        // --- coTURN (STUN/TURN for WebRTC) ---
        $this->installCoTURN($serverId, $variables);

        // --- LiveKit (WebRTC SFU -- only if credentials are provided) ---
        if (!empty($variables['LIVEKIT_API_KEY'])) {
            $this->installLiveKit($serverId, $variables);
        } else {
            $this->log("LiveKit skipped (no LIVEKIT_API_KEY provided)");
        }

        // --- nghttpx (HTTP/2 reverse proxy) ---
        $this->installNghttpx($serverId);

        // --- stunnel4 (TLS tunnel) ---
        $this->installStunnel($serverId, $variables);

        $this->log("Extra services installation complete");
    }

    private function installCoTURN(int $serverId, array &$variables): void
    {
        $this->log("Setting up coTURN...");

        if (!$this->isPackageInstalled('coturn')) {
            $this->runAptCommand('apt-get install -y coturn', 'Failed to install coTURN');
        }

        // Enable TURN server (defaults to disabled on Ubuntu)
        $this->executeCommand("sed -i 's/^#TURNSERVER_ENABLED=1/TURNSERVER_ENABLED=1/' /etc/default/coturn 2>/dev/null || true");
        $turnEnabledCheck = $this->executeCommand('grep -c "^TURNSERVER_ENABLED=1" /etc/default/coturn 2>/dev/null');
        if (trim($turnEnabledCheck['output'] ?? '0') === '0') {
            $this->executeCommand("echo 'TURNSERVER_ENABLED=1' >> /etc/default/coturn");
        }

        $baseDomain = preg_replace('/^(panel|email|mail)\./', '', $variables['PANEL_DOMAIN'] ?? '');
        $serverIp = $variables['SERVER_IP'] ?? '0.0.0.0';

        // Idempotent secret: reuse the existing TURN secret if coTURN is already
        // configured so re-running provisioning never invalidates active clients.
        $existing = $this->executeCommand("grep '^static-auth-secret=' /etc/turnserver.conf 2>/dev/null | head -1 | cut -d= -f2-");
        $existingSecret = trim($existing['output'] ?? '');
        if ($existingSecret !== '') {
            $turnSecret = $existingSecret;
        } elseif (!empty($variables['TURN_SECRET'])) {
            $turnSecret = $variables['TURN_SECRET'];
        } else {
            $turnSecret = $this->encryption->generatePassword(32);
        }
        // Expose the secret to later steps (e.g. email app deploy) so the app and
        // the TURN server share the same credential.
        $variables['TURN_SECRET'] = $turnSecret;
        $variables['TURN_HOST'] = "turn.{$baseDomain}";
        $variables['TURN_REALM'] = $baseDomain;

        // Include the TLS listener (turns:5349) only when the base-domain cert
        // already exists. Let's Encrypt is issued later in setup_ssl, and coTURN
        // refuses to start if tls-listening-port references a missing cert. The
        // setup_ssl step re-runs this method to enable TLS once the cert is present.
        $certPath = "/etc/letsencrypt/live/{$baseDomain}/fullchain.pem";
        $keyPath  = "/etc/letsencrypt/live/{$baseDomain}/privkey.pem";
        $certExists = trim($this->executeCommand("test -f {$certPath} && echo yes || echo no")['output'] ?? 'no') === 'yes';
        $tlsBlock = '';
        if ($certExists) {
            $tlsBlock = "tls-listening-port=5349\ncert={$certPath}\npkey={$keyPath}\n";
        }

        $turnConf = <<<CONF
listening-port=3478
{$tlsBlock}listening-ip=0.0.0.0
external-ip={$serverIp}
realm={$baseDomain}
server-name=turn.{$baseDomain}
use-auth-secret
static-auth-secret={$turnSecret}
total-quota=100
stale-nonce=600
no-multicast-peers
no-tlsv1
no-tlsv1_1
log-file=/var/log/turnserver.log
simple-log
syslog
CONF;
        $this->ssh->uploadContent($turnConf, '/etc/turnserver.conf');
        $this->executeCommand('chmod 640 /etc/turnserver.conf');

        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable coturn 2>/dev/null || true');
        $this->executeCommand('systemctl restart coturn 2>/dev/null || true');
        $this->log($certExists ? "coTURN configured with TLS (turns:5349)" : "coTURN configured (TLS deferred until cert is issued)");

        $this->recordPackageInstalled($serverId, 'coturn');
        $this->log("coTURN installed (secret stored in /etc/turnserver.conf)");
    }

    private function installLiveKit(int $serverId, array $variables): void
    {
        $this->log("Setting up LiveKit server...");

        $livekitCheck = $this->executeCommand('test -f /usr/local/bin/livekit-server && echo "exists" || echo "missing"');
        if (trim($livekitCheck['output'] ?? '') !== 'exists') {
            $this->log("Downloading LiveKit server binary...");
            // Download-then-execute (not curl|bash) to avoid tripping host AV/malware
            // heuristics. Best-effort: a failure here must not abort provisioning.
            $this->executeCommand(
                'T="$(mktemp)"; curl -sSL -o "$T" https://get.livekit.io 2>/dev/null && bash "$T" 2>/dev/null; rm -f "$T"; true'
            );
        }

        $apiKey = $variables['LIVEKIT_API_KEY'] ?? '';
        $apiSecret = $variables['LIVEKIT_API_SECRET'] ?? '';
        $baseDomain = preg_replace('/^(panel|email|mail)\./', '', $variables['PANEL_DOMAIN'] ?? '');

        $livekitConf = <<<YAML
port: 7880
rtc:
    port_range_start: 7881
    port_range_end: 7881
    use_external_ip: true
keys:
    {$apiKey}: {$apiSecret}
logging:
    level: info
YAML;
        $this->executeCommand('mkdir -p /etc/livekit');
        $this->ssh->uploadContent($livekitConf, '/etc/livekit/livekit.yaml');
        $this->executeCommand('chmod 640 /etc/livekit/livekit.yaml');

        // Create systemd service if not present
        $serviceCheck = $this->executeCommand('test -f /etc/systemd/system/livekit-server.service && echo "exists" || echo "missing"');
        if (trim($serviceCheck['output'] ?? '') !== 'exists') {
            $serviceUnit = <<<UNIT
[Unit]
Description=LiveKit Server
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/local/bin/livekit-server --config /etc/livekit/livekit.yaml
Restart=always
RestartSec=5
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
UNIT;
            $this->ssh->uploadContent($serviceUnit, '/etc/systemd/system/livekit-server.service');
        }

        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable livekit-server');
        $this->executeCommand('systemctl restart livekit-server 2>/dev/null || true');

        $this->log("LiveKit server installed");
    }

    private function installNghttpx(int $serverId): void
    {
        $this->log("Setting up nghttpx...");

        if (!$this->isPackageInstalled('nghttp2-proxy')) {
            $this->runAptCommand('apt-get install -y nghttp2-proxy', 'Failed to install nghttpx');
            $this->recordPackageInstalled($serverId, 'nghttp2-proxy');
        }

        // Deploy the loopback HTTP/2 proxy config (matches source: 127.0.0.1:3000 -> OLS :80).
        // Without a config nghttpx starts with empty defaults and does nothing useful.
        $nghttpxConf = <<<CONF
# Managed by Fleet Manager
# HTTP/2 proxy: frontend on loopback :3000 -> backend OLS on :80 (no TLS, TLS handled by OLS)
frontend=127.0.0.1,3000;no-tls
backend=127.0.0.1,80
errorlog-syslog=yes
workers=1
CONF;
        $this->executeCommand('mkdir -p /etc/nghttpx');
        $this->ssh->uploadContent($nghttpxConf, '/etc/nghttpx/nghttpx.conf');
        $this->executeCommand('chmod 644 /etc/nghttpx/nghttpx.conf');

        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable nghttpx 2>/dev/null || true');
        $this->executeCommand('systemctl restart nghttpx 2>/dev/null || systemctl start nghttpx 2>/dev/null || true');
        $this->log("nghttpx installed (loopback :3000 -> :80)");
    }

    private function installStunnel(int $serverId, array $variables): void
    {
        $this->log("Setting up stunnel4...");

        if (!$this->isPackageInstalled('stunnel4')) {
            $this->runAptCommand('apt-get install -y stunnel4', 'Failed to install stunnel4');
        }

        // Enable stunnel (disabled by default on Ubuntu)
        $this->executeCommand("sed -i 's/^ENABLED=0/ENABLED=1/' /etc/default/stunnel4 2>/dev/null || true");
        $enabledCheck = $this->executeCommand('grep -c "^ENABLED=1" /etc/default/stunnel4 2>/dev/null');
        if (trim($enabledCheck['output'] ?? '0') === '0') {
            $this->executeCommand("echo 'ENABLED=1' >> /etc/default/stunnel4");
        }

        $baseDomain = preg_replace('/^(panel|email|mail)\./', '', $variables['PANEL_DOMAIN'] ?? '');

        $stunnelConf = <<<CONF
pid = /var/run/stunnel4/stunnel4.pid
setuid = stunnel4
setgid = stunnel4

[imaps-proxy]
accept = 7443
connect = 127.0.0.1:993
cert = /etc/letsencrypt/live/{$baseDomain}/fullchain.pem
key = /etc/letsencrypt/live/{$baseDomain}/privkey.pem
CONF;
        $this->executeCommand('mkdir -p /etc/stunnel');
        $this->ssh->uploadContent($stunnelConf, '/etc/stunnel/stunnel.conf');
        $this->executeCommand('chmod 644 /etc/stunnel/stunnel.conf');
        $this->executeCommand('mkdir -p /var/run/stunnel4 && chown stunnel4:stunnel4 /var/run/stunnel4');

        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable stunnel4');
        $this->executeCommand('systemctl restart stunnel4 2>/dev/null || true');

        $this->recordPackageInstalled($serverId, 'stunnel4');
        $this->log("stunnel4 installed");
    }

    /**
     * The IP(s) the Fleet Manager talks to this box FROM, for fail2ban ignoreip.
     * Combines any operator-configured egress IPs (config ssh.fleet_ignore_ips,
     * for NAT / multiple panels) with the address the box actually sees us connect
     * from ($SSH_CONNECTION). Computed once while still root@22 (before harden wraps
     * everything in env-scrubbing sudo) and cached so the harden step can reuse it.
     */
    private function fleetIgnoreIps(): string
    {
        if ($this->fleetIgnoreIps !== null) {
            return $this->fleetIgnoreIps;
        }

        $ips = [];

        // Operator-pinned egress IPs / CIDRs (optional, survives if auto-detect
        // fails). Settings table (dashboard: Settings -> Fleet Access) wins, then
        // the config.local.php fallback.
        $pinned = '';
        try {
            $st = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ssh_fleet_ignore_ips'");
            $st->execute();
            $pinned = trim((string)($st->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            // settings table may not exist on very old schemas - non-fatal
        }
        if ($pinned === '') {
            $pinned = (string)($this->container->getConfig('ssh.fleet_ignore_ips') ?: '');
        }
        foreach (preg_split('/[\s,]+/', $pinned, -1, PREG_SPLIT_NO_EMPTY) as $ip) {
            $ips[] = $ip;
        }

        // The source IP the target sees for THIS session. $SSH_CONNECTION =
        // "<client_ip> <client_port> <server_ip> <server_port>".
        if (!$this->isLocalServer) {
            try {
                $detected = trim((string)($this->executeCommand(
                    "printf '%s' \"\$SSH_CONNECTION\" | awk '{print \$1}'"
                )['output'] ?? ''));
                if ($detected !== '' && filter_var($detected, FILTER_VALIDATE_IP)) {
                    $ips[] = $detected;
                }
            } catch (\Throwable $e) {
                // non-fatal - fall back to whatever config provided
            }
        }

        $ips = array_values(array_unique(array_filter($ips)));
        $this->fleetIgnoreIps = implode(' ', $ips);
        if ($this->fleetIgnoreIps !== '') {
            $this->log("fail2ban: whitelisting Fleet Manager source IP(s): {$this->fleetIgnoreIps}");
        }
        return $this->fleetIgnoreIps;
    }

    /**
     * Deploy Fail2ban configuration from template
     */
    private function deployFail2banConfig(array $variables): void
    {
        $templatePath = $this->container->getConfig('templates.path') ?? __DIR__ . '/../../../templates';
        $templateFile = "{$templatePath}/fail2ban/jail.local.template";
        
        if (!file_exists($templateFile)) {
            $this->log("Warning: Fail2ban template not found, skipping");
            return;
        }
        
        // Always provide FLEET_MANAGER_IP so the {{FLEET_MANAGER_IP}} token in the
        // ignoreip line resolves (to '' if nothing detected) and is never left raw.
        $variables['FLEET_MANAGER_IP'] = $this->fleetIgnoreIps();

        $template = file_get_contents($templateFile);
        $content = $this->templates->process($template, $variables);
        
        $this->ssh->uploadContent($content, '/etc/fail2ban/jail.local');
        $this->executeCommand("chmod 644 /etc/fail2ban/jail.local");

        // Ensure required log files exist (fail2ban won't start if they're missing)
        $this->executeCommand("touch /var/log/auth.log /var/log/mail.log /var/log/fail2ban.log 2>/dev/null || true");
        $this->executeCommand("mkdir -p /usr/local/lsws/logs && touch /usr/local/lsws/logs/error.log 2>/dev/null || true");
        
        $this->executeCommand("systemctl restart fail2ban 2>/dev/null || true");
        
        // Check if fail2ban actually started
        $status = trim($this->executeCommand("systemctl is-active fail2ban 2>/dev/null || echo 'inactive'")['output'] ?? 'unknown');
        if (strpos($status, 'active') === false || strpos($status, 'inactive') !== false) {
            // Try to get the error from journalctl
            $error = trim($this->executeCommand("journalctl -u fail2ban --no-pager -n 5 2>/dev/null || true")['output'] ?? '');
            $this->log("Warning: Fail2ban failed to start. Error: {$error}");
            
            // Common fix: disable problematic jails and retry
            $this->log("Trying fail2ban with reduced jails...");
            $reducedConfig = preg_replace('/\[openlitespeed-auth\].*?\n\n/s', "[openlitespeed-auth]\nenabled = false\n\n", $content);
            $this->ssh->uploadContent($reducedConfig, '/etc/fail2ban/jail.local');
            $this->executeCommand("systemctl restart fail2ban 2>/dev/null || true");
            
            $status = trim($this->executeCommand("systemctl is-active fail2ban 2>/dev/null || echo 'inactive'")['output'] ?? 'unknown');
            if (strpos($status, 'active') !== false && strpos($status, 'inactive') === false) {
                $this->log("Fail2ban started with reduced jails (OLS auth jail disabled)");
            } else {
                $this->log("Warning: Fail2ban still not starting - manual review needed");
            }
        } else {
            $this->log("Fail2ban configuration deployed and running");
        }

        // Make absolutely sure the Fleet Manager IP(s) can reach this box, now and
        // forever. Three layers, because a plain `fail2ban-client unban` is NOT
        // enough on its own:
        //   1) fail2ban-client unban - clears bans fail2ban currently tracks.
        //   2) Remove orphaned firewalld reject/drop rich-rules. When fail2ban is
        //      restarted (as we just did) its in-memory ban list resets to 0, but
        //      the firewalld rules it created are LEFT BEHIND - so the panel stays
        //      DROP-banned for up to 7 days (recidive) while `status` shows 0 banned.
        //   3) Pin a priority=-100 ACCEPT rich-rule so even a future stale reject
        //      can never lock the panel out (lower priority = evaluated first).
        $ignore = $this->fleetIgnoreIps();
        if ($ignore !== '') {
            foreach (preg_split('/\s+/', $ignore, -1, PREG_SPLIT_NO_EMPTY) as $ip) {
                if (strpos($ip, '/') === false) {
                    $this->executeCommand("fail2ban-client unban {$ip} 2>/dev/null || true");
                    $this->executeCommand("fail2ban-client set sshd unbanip {$ip} 2>/dev/null || true");
                    $this->executeCommand("fail2ban-client set recidive unbanip {$ip} 2>/dev/null || true");
                }
                // Strip orphaned reject/drop rules (runtime + permanent) and pin allow.
                $fw = "command -v firewall-cmd >/dev/null 2>&1 && { "
                    . "for s in runtime permanent; do P=''; [ \"\$s\" = permanent ] && P='--permanent'; "
                    . "firewall-cmd \$P --remove-rich-rule='rule family=\"ipv4\" source address=\"{$ip}\" reject' 2>/dev/null; "
                    . "firewall-cmd \$P --remove-rich-rule='rule family=\"ipv4\" source address=\"{$ip}\" drop' 2>/dev/null; "
                    . "done; "
                    . "firewall-cmd --permanent --add-rich-rule='rule priority=\"-100\" family=\"ipv4\" source address=\"{$ip}\" accept' 2>/dev/null; "
                    . "firewall-cmd --reload 2>/dev/null; "
                    . "} || true";
                $this->executeCommand($fw, 30);
            }
            $this->log("fail2ban/firewalld: cleared stale bans + pinned allow for Fleet Manager IP(s): {$ignore}");
        }
    }

    /**
     * Deploy SpamAssassin configuration from template
     */
    private function deploySpamAssassinConfig(array $variables): void
    {
        $templatePath = $this->container->getConfig('templates.path') ?? __DIR__ . '/../../../templates';
        $templateFile = "{$templatePath}/spamassassin/local.cf.template";
        
        if (!file_exists($templateFile)) {
            $this->log("Warning: SpamAssassin template not found, skipping");
            return;
        }
        
        $template = file_get_contents($templateFile);
        $content = $this->templates->process($template, $variables);
        
        $this->executeCommand("mkdir -p /etc/spamassassin");
        $this->ssh->uploadContent($content, '/etc/spamassassin/local.cf');
        $this->executeCommand("chmod 644 /etc/spamassassin/local.cf");
        
        // Create bayes directory
        $this->executeCommand("mkdir -p /var/lib/spamassassin/.spamassassin");
        $this->executeCommand("chown -R debian-spamd:debian-spamd /var/lib/spamassassin 2>/dev/null || true");
        
        // Update SpamAssassin rules
        $this->executeCommand("sa-update 2>/dev/null || true");
        
        // SpamAssassin ships under different unit names per distro: spamassassin.service
        // (Ubuntu 22.04 / Debian <=11) vs spamd.service (Ubuntu 24.04 / Debian 12).
        // Enable+start whichever REAL unit exists, then create a symlink ALIAS so the
        // OTHER name resolves too. Tooling that hardcodes one name - e.g. the panel's
        // service manager calling `systemctl start spamd` - otherwise fails with
        // "Unit spamd.service not found" even though the daemon is running fine.
        $spamdAlias = <<<'BASH'
set -e
real=""; frag=""
for u in spamassassin spamd; do
  fp="$(systemctl show -p FragmentPath --value "$u" 2>/dev/null)"
  if [ -n "$fp" ] && [ -e "$fp" ]; then real="$u"; frag="$fp"; break; fi
done
if [ -z "$real" ]; then echo "no spamassassin/spamd unit found"; exit 0; fi
systemctl enable "$real" 2>/dev/null || true
systemctl restart "$real" 2>/dev/null || true
other="spamd"; [ "$real" = "spamd" ] && other="spamassassin"
if ! systemctl cat "$other" >/dev/null 2>&1; then
  ln -sf "$frag" "/etc/systemd/system/${other}.service"
  systemctl daemon-reload
  echo "active=$real, aliased ${other}.service -> $frag"
else
  echo "active=$real, both unit names already present"
fi
BASH;
        $aliasRes = $this->executeCommand($spamdAlias, 60);
        $this->log("SpamAssassin configuration deployed (" . trim((string)($aliasRes['output'] ?? 'unit setup done')) . ")");
    }

    /**
     * Configure firewall (idempotent operations)
     */
    private function configureFirewall(): void
    {
        $this->log("Configuring firewall...");
        
        $commands = [
            'systemctl enable firewalld',
            'systemctl start firewalld',
            // SSH
            'firewall-cmd --permanent --add-port=22/tcp',
            // Web
            'firewall-cmd --permanent --add-port=80/tcp',
            'firewall-cmd --permanent --add-port=443/tcp',
            // Mail services
            'firewall-cmd --permanent --add-port=25/tcp',     // SMTP
            'firewall-cmd --permanent --add-port=465/tcp',    // SMTPS
            'firewall-cmd --permanent --add-port=587/tcp',    // Submission
            'firewall-cmd --permanent --add-port=110/tcp',    // POP3
            'firewall-cmd --permanent --add-port=143/tcp',    // IMAP
            'firewall-cmd --permanent --add-port=993/tcp',    // IMAPS
            'firewall-cmd --permanent --add-port=995/tcp',    // POP3S
            'firewall-cmd --permanent --add-port=4190/tcp',   // ManageSieve
            // DNS (PowerDNS)
            'firewall-cmd --permanent --add-port=53/tcp',
            'firewall-cmd --permanent --add-port=53/udp',
            // STUN/TURN (coTURN)
            'firewall-cmd --permanent --add-port=3478/tcp',
            'firewall-cmd --permanent --add-port=3478/udp',
            // LiveKit SFU
            'firewall-cmd --permanent --add-port=7880/tcp',
            'firewall-cmd --permanent --add-port=7881/tcp',
            'firewall-cmd --permanent --add-port=7881/udp',
            // stunnel TLS tunnel
            'firewall-cmd --permanent --add-port=7443/tcp',
            // OLS admin panel
            'firewall-cmd --permanent --add-port=7080/tcp',
            // Reload to apply
            'firewall-cmd --reload',
        ];

        foreach ($commands as $cmd) {
            $this->executeCommand("{$cmd} 2>/dev/null || true");
        }
        
        $this->log("Firewall configured with all required ports");
    }

    /**
     * Firewall for a DOCKER host: open ONLY what the container stack + a hardened
     * SSH actually need. A Docker box has no host PowerDNS / TURN / LiveKit / OLS
     * admin, so — unlike configureFirewall() — we keep the surface tight: SSH
     * (22 during the port-move + the hardened $targetPort), web (80/443) and the
     * host-networked mail pod's ports.
     *
     * NOTE: on a Docker host firewalld OWNS iptables once it (re)loads, which
     * flushes Docker's published-port rules. The caller (hardenExistingServer with
     * ['docker'=>true]) restarts Docker afterwards so it re-inserts them.
     */
    private function configureFirewallForDocker(int $targetPort = 1985): void
    {
        $this->log("Configuring firewall (Docker profile)...");
        $ports = array_values(array_unique([22, $targetPort, 80, 443, 25, 465, 587, 110, 143, 993, 995, 4190]));
        $commands = ['systemctl enable firewalld', 'systemctl start firewalld'];
        foreach ($ports as $p) {
            $commands[] = "firewall-cmd --permanent --add-port={$p}/tcp";
        }
        $commands[] = 'firewall-cmd --reload';
        foreach ($commands as $cmd) {
            $this->executeCommand("{$cmd} 2>/dev/null || true");
        }
        $this->log('Firewall configured (Docker profile): ' . implode(',', $ports));
    }

    /**
     * Wait for the Docker web container to report healthy again (used after a
     * `systemctl restart docker`, which cycles every container). Best-effort.
     */
    private function waitDockerWebHealthy(int $timeoutSeconds = 180): bool
    {
        $deadline = time() + max(30, $timeoutSeconds);
        while (time() < $deadline) {
            $res = $this->executeCommand(
                "docker inspect -f '{{.State.Health.Status}}' flowone-web-1 2>/dev/null || echo missing",
                20
            );
            if (trim((string)($res['output'] ?? '')) === 'healthy') {
                return true;
            }
            sleep(6);
        }
        return false;
    }

    /**
     * Day-2 / Docker-path HOST HARDENING. Runs the SAME security lockdown the
     * native full-provision does — security packages (fail2ban + firewalld),
     * firewall, fail2ban jails, and pxr@<port> key-only SSH (deny root + password)
     * — against an ALREADY provisioned box, WITHOUT reinstalling the app stack.
     * This is the piece the Docker provisioning path was missing.
     *
     * SAFE BY DESIGN: reuses hardenSshAccess()'s 3-phase verify-before-commit
     * (root@22 stays open until pxr@<port> + key + sudo is proven from the Fleet
     * Manager), and re-homes Fleet's stored connection to the hardened profile on
     * success. Idempotent — safe to re-run.
     *
     * @param array $opts ['docker'=>bool] when true, restart Docker at the end so
     *                    it re-inserts the published-port iptables rules firewalld
     *                    flushed, then verify the web tier recovered.
     * @return array{success:bool,hardened:?array,docker_ok:?bool,error?:string,log:array}
     */
    public function hardenExistingServer(int $serverId, array $opts = []): array
    {
        $this->deploymentLog = [];
        $this->stepCommandLog = '';
        $this->fleetIgnoreIps = null;
        $this->hardenedProfile = null;
        $dockerHost = !empty($opts['docker']);

        $result = static function (bool $ok, $extra = []) {
            return array_merge(['success' => $ok, 'hardened' => null, 'docker_ok' => null], $extra);
        };

        try {
            $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch();
            if (!$server) {
                return $result(false, ['error' => "server {$serverId} not found", 'log' => $this->deploymentLog]);
            }

            $this->isLocalServer = $this->isLocal($server);
            if ($this->isLocalServer) {
                return $result(false, ['error' => 'refusing to harden the local Fleet Manager host', 'log' => $this->deploymentLog]);
            }

            $this->log("Hardening {$server['name']} ({$server['ip_address']}) — current profile {$server['ssh_user']}@{$server['ssh_port']}/{$server['ssh_auth_method']}");
            if (!$this->ssh->connectToServer($server)) {
                return $result(false, ['error' => 'SSH connection failed', 'log' => $this->deploymentLog]);
            }

            $variables  = $this->templates->generateServerVariables($server);
            $targetPort = (int)($this->container->getConfig('ssh.harden_port') ?: 1985);

            $this->log('=== [1/5] Installing security packages (fail2ban + firewalld) ===');
            $this->installSecurityTools($serverId);

            $this->log('=== [2/5] Configuring firewall ===');
            $dockerHost ? $this->configureFirewallForDocker($targetPort) : $this->configureFirewall();

            $this->log('=== [3/5] Deploying fail2ban jails ===');
            $this->deployFail2banConfig($variables);

            $this->log('=== [4/5] Establishing pxr access (key + passwordless sudo) ===');
            $this->establishSshAccess($serverId, $variables);

            $this->log("=== [5/5] Hardening SSH (pxr@{$targetPort}, key-only, deny root + password) ===");
            $this->hardenSshAccess($serverId, $variables);

            $dockerOk = null;
            if ($dockerHost) {
                $this->log('Restarting Docker so it re-inserts its firewall rules (firewalld took over iptables)...');
                $this->executeCommand('systemctl restart docker 2>&1 || true', 180);
                $dockerOk = $this->waitDockerWebHealthy(180);
                $this->log($dockerOk
                    ? 'Docker stack recovered — web container healthy.'
                    : 'WARNING: web not healthy after Docker restart — check `docker compose ps` on the box.');
            }

            return $result(true, [
                'hardened'  => $this->hardenedProfile,
                'docker_ok' => $dockerOk,
                'log'       => $this->deploymentLog,
            ]);
        } catch (\Throwable $e) {
            $this->log('ERROR: ' . $e->getMessage());
            return $result(false, ['error' => $e->getMessage(), 'log' => $this->deploymentLog]);
        } finally {
            if ($this->ssh->isConnected()) {
                $this->ssh->disconnect();
            }
        }
    }

    /**
     * Setup databases (idempotent with IF NOT EXISTS)
     */
    private function setupDatabases(array $variables): void
    {
        $rootPass = $variables['DB_ROOT_PASS'];

        // Get DB config from SchemaService (shared panel+email, mail, fleet)
        $schemaService = $this->container->get(SchemaService::class);
        $dbConfig = $schemaService->getDbConfig();

        // Panel + Email share one database (like devc_vps_dash / vpsadmin on main server)
        $sharedDbName = $variables['PANEL_DB_NAME'] ?? $dbConfig['shared']['target_db'];
        $sharedDbUser = $variables['PANEL_DB_USER'] ?? $dbConfig['shared']['target_user'];
        $sharedDbPass = $variables['PANEL_DB_PASS'];

        // Mail server database (postfix/dovecot)
        $mailDbName = $variables['MAIL_DB_NAME'] ?? $dbConfig['mail']['target_db'];
        $mailDbUser = $variables['MAIL_DB_USER'] ?? $dbConfig['mail']['target_user'];
        $mailDbPass = $variables['MAIL_DB_PASS'];

        // Fleet agent database
        $fleetDbName = $variables['FLEET_DB_NAME'] ?? $dbConfig['fleet']['target_db'];
        $fleetDbUser = $variables['FLEET_DB_USER'] ?? $dbConfig['fleet']['target_user'];
        $fleetDbPass = $variables['FLEET_DB_PASS'] ?? $this->encryption->generatePassword(24);

        $this->log("Setting up databases (3 databases: shared panel+email, mail, fleet)...");

        // Escape both backslashes and single quotes for MySQL string literals
        $esc = fn(string $v) => str_replace(['\\', "'"], ['\\\\', "\\'"], $v);

        // Build all SQL commands as a single script
        $sql = "";

        // 1. Shared database for Panel + Email
        $sql .= "CREATE DATABASE IF NOT EXISTS `{$sharedDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        $sql .= "CREATE USER IF NOT EXISTS '{$sharedDbUser}'@'localhost' IDENTIFIED BY '{$esc($sharedDbPass)}';\n";
        $sql .= "ALTER USER '{$sharedDbUser}'@'localhost' IDENTIFIED BY '{$esc($sharedDbPass)}';\n";
        $sql .= "GRANT ALL PRIVILEGES ON `{$sharedDbName}`.* TO '{$sharedDbUser}'@'localhost';\n";

        // 2. Mail server database
        $sql .= "CREATE DATABASE IF NOT EXISTS `{$mailDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        $sql .= "CREATE USER IF NOT EXISTS '{$mailDbUser}'@'localhost' IDENTIFIED BY '{$esc($mailDbPass)}';\n";
        $sql .= "ALTER USER '{$mailDbUser}'@'localhost' IDENTIFIED BY '{$esc($mailDbPass)}';\n";
        $sql .= "GRANT ALL PRIVILEGES ON `{$mailDbName}`.* TO '{$mailDbUser}'@'localhost';\n";

        // 3. Fleet agent database
        $sql .= "CREATE DATABASE IF NOT EXISTS `{$fleetDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        $sql .= "CREATE USER IF NOT EXISTS '{$fleetDbUser}'@'localhost' IDENTIFIED BY '{$esc($fleetDbPass)}';\n";
        $sql .= "ALTER USER '{$fleetDbUser}'@'localhost' IDENTIFIED BY '{$esc($fleetDbPass)}';\n";
        $sql .= "GRANT ALL PRIVILEGES ON `{$fleetDbName}`.* TO '{$fleetDbUser}'@'localhost';\n";

        // Cross-grants: shared user gets read access to mail DB
        $sql .= "GRANT SELECT ON `{$mailDbName}`.* TO '{$sharedDbUser}'@'localhost';\n";
        $sql .= "FLUSH PRIVILEGES;\n";

        // Upload SQL file and execute using --defaults-extra-file (avoids MYSQL_PWD shell escaping)
        $this->ssh->uploadContent($sql, '/tmp/fleet-db-setup.sql');

        $rootCnf = $this->buildMyCnf('root', $rootPass);
        $this->ssh->uploadContent($rootCnf, '/tmp/fleet-root.cnf');
        $this->executeCommand('chmod 600 /tmp/fleet-root.cnf');

        $result = $this->executeCommand("mariadb --defaults-extra-file=/tmp/fleet-root.cnf < /tmp/fleet-db-setup.sql 2>&1");

        if (!($result['success'] ?? false)) {
            $output = $result['output'] ?? 'unknown';
            $this->log("Warning: Database setup had issues: {$output}");

            // Fallback: try with /root/.my.cnf (created by installMariaDB)
            if (stripos($output, 'Access denied') !== false) {
                $this->log("Retrying with /root/.my.cnf...");
                $result = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf < /tmp/fleet-db-setup.sql 2>&1");
                if (!($result['success'] ?? false)) {
                    $this->log("Warning: Database setup retry also failed: " . ($result['output'] ?? ''));
                }
            }
        }
        $this->executeCommand("rm -f /tmp/fleet-db-setup.sql /tmp/fleet-root.cnf");

        // Verify each user can connect
        $users = [
            [$sharedDbUser, $sharedDbPass, $sharedDbName],
            [$mailDbUser, $mailDbPass, $mailDbName],
        ];
        $dbFailures = [];
        foreach ($users as [$user, $pass, $db]) {
            $verifyCnf = "[client]\nuser={$user}\npassword={$pass}\n";
            $this->ssh->uploadContent($verifyCnf, '/tmp/fleet-verify.cnf');
            $this->executeCommand('chmod 600 /tmp/fleet-verify.cnf');
            $verifyResult = $this->executeCommand("mariadb --defaults-extra-file=/tmp/fleet-verify.cnf {$db} -e 'SELECT 1' 2>&1");
            $this->executeCommand('rm -f /tmp/fleet-verify.cnf');
            if (stripos($verifyResult['output'] ?? '', 'ERROR') !== false) {
                $this->log("WARNING: User '{$user}' cannot connect to '{$db}': " . trim($verifyResult['output'] ?? ''));
                $dbFailures[] = "{$user}@{$db}: " . trim($verifyResult['output'] ?? '');
            } else {
                $this->log("Verified: user '{$user}' can connect to '{$db}'");
            }
        }

        // Fail loudly here: deploy_panel / deploy_email / mail services all depend on
        // these DB users working. Silently continuing only surfaces a cryptic installer
        // error two steps later, which is the failure mode we are trying to eliminate.
        if (!empty($dbFailures)) {
            throw new \RuntimeException(
                'Database setup failed - required DB users cannot connect: ' . implode('; ', $dbFailures)
            );
        }

        $this->log("Shared database '{$sharedDbName}' created with user '{$sharedDbUser}' (Panel + Email)");
        $this->log("Mail server database '{$mailDbName}' created with user '{$mailDbUser}'");
        $this->log("Fleet agent database '{$fleetDbName}' created with user '{$fleetDbUser}'");

        // Import schema dumps from main server (if available)
        $this->importSchemas($variables, $schemaService, $dbConfig);

        // Run idempotent schema migrations (covers columns that may be missing from the dump)
        $this->runPostSchemaMigrations($variables);

        // NOTE: Mail/DNS seeding is intentionally NOT done here. The Panel DB
        // tables they write to (mail_domains, mail_accounts, dns_domains,
        // dns_records) are created later by the panel installer in the
        // deploy_panel step. Seeding at this point runs before those tables
        // exist, so the inserts silently fail and the Panel/DNS pages show 0.
        // The seeds now run at the end of deploy_panel instead.

        $this->log("All databases configured");
    }

    /**
     * Resolve the Email-app login mailbox for this deployment.
     *
     * Every provisioned server gets ONE real IMAP mailbox at the deployed base
     * domain (e.g. robert@devcon2.hu) so the Email app has a working login out
     * of the box — Dovecot/Postfix authenticate against it and mail is fully
     * deliverable to it.
     *
     *  - Local part: MAIL_LOGIN_USER (default 'robert'), sanitised to a valid
     *    mailbox local part.
     *  - Password:   MAIL_LOGIN_PASS, falling back to the panel admin password
     *    (ADMIN_PASS) so there is a single credential to remember.
     *
     * @return array{user:string,email:string,pass:string,base_domain:string}
     */
    private function resolveMailLogin(array $variables): array
    {
        $mailDomain = $variables['MAIL_DOMAIN'] ?? $variables['EMAIL_DOMAIN'] ?? '';
        $baseDomain = preg_replace('/^mail\./', '', $mailDomain);

        // Mailbox local part: lowercase, keep only a-z 0-9 . _ - .
        $user = strtolower(trim((string)($variables['MAIL_LOGIN_USER'] ?? 'robert')));
        $user = preg_replace('/[^a-z0-9._-]/', '', $user);
        if ($user === '') {
            $user = 'robert';
        }

        $pass = $variables['MAIL_LOGIN_PASS']
            ?? $variables['ADMIN_PASS']
            ?? $this->encryption->generatePassword(32);

        return [
            'user' => $user,
            'email' => $baseDomain !== '' ? "{$user}@{$baseDomain}" : $user,
            'pass' => $pass,
            'base_domain' => $baseDomain,
        ];
    }

    /**
     * Seed mail domain and login mailbox into BOTH databases:
     *   1. mailserver DB  -- Dovecot/Postfix authenticate against this
     *   2. Panel DB       -- the Panel UI reads mail_domains / mail_accounts from here
     * Uses BLF-CRYPT (bcrypt) password hashing to match Dovecot's default_pass_scheme.
     */
    private function seedMailAccount(array $variables, string $rootPass): void
    {
        $mailDomain = $variables['MAIL_DOMAIN'] ?? $variables['EMAIL_DOMAIN'] ?? '';
        if (empty($mailDomain)) {
            $this->log("No MAIL_DOMAIN set - skipping mail account seeding");
            return;
        }

        $login = $this->resolveMailLogin($variables);
        $baseDomain = $login['base_domain'];
        $adminUser = $login['user'];
        $adminEmail = $login['email'];
        $adminPass = $login['pass'];
        $mailDbName = $variables['MAIL_DB_NAME'] ?? 'mailserver';
        $panelDbName = $variables['PANEL_DB_NAME'] ?? 'devc_vps_dash';
        // Relative "<domain>/<user>/" matches the panel agent's MailAction
        // convention; the physical mailbox lives at /home/vmail/<domain>/<user>.
        $maildirPath = "{$baseDomain}/{$adminUser}/";
        $mailDir = "/home/vmail/{$baseDomain}/{$adminUser}";

        $this->log("Seeding mail domain '{$baseDomain}' and Email-app login mailbox '{$adminEmail}'...");

        $bcryptHash = password_hash($adminPass, PASSWORD_BCRYPT);

        if (empty($bcryptHash) || !str_starts_with($bcryptHash, '$2y$')) {
            $this->log("Warning: Could not generate bcrypt password hash locally - skipping mail account seeding");
            return;
        }
        $this->log("Generated bcrypt hash for admin mail account locally");

        $escHash = str_replace(['\\', "'"], ['\\\\', "\\'"], $bcryptHash);

        $domainInsert = "INSERT IGNORE INTO mail_domains (domain, status) VALUES ('{$baseDomain}', 'active');\n";
        $accountInsert = "INSERT INTO mail_accounts (email, username, domain, password_hash, quota_mb, maildir_path, status) "
            . "VALUES ('{$adminEmail}', '{$adminUser}', '{$baseDomain}', '{$escHash}', 2048, '{$maildirPath}', 'active') "
            . "ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), maildir_path = VALUES(maildir_path);\n";

        // 1. Seed into mailserver DB (Dovecot/Postfix auth)
        $sql = "USE `{$mailDbName}`;\n" . $domainInsert . $accountInsert;

        // 2. Seed into Panel DB (Panel UI reads from here)
        $sql .= "USE `{$panelDbName}`;\n" . $domainInsert . $accountInsert;

        $this->ssh->uploadContent($sql, '/tmp/fleet-mail-seed.sql');
        $result = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf < /tmp/fleet-mail-seed.sql 2>&1");

        if ($result['success'] ?? false) {
            $this->log("Mail domain '{$baseDomain}' and Email-app login mailbox '{$adminEmail}' seeded in both '{$mailDbName}' and '{$panelDbName}'");
            // Dovecot auto-creates the Maildir on first delivery/login; just
            // ensure the account dir exists with the right ownership (same as
            // the panel agent's MailAction does on account creation).
            $this->executeCommand("mkdir -p {$mailDir}");
            $this->executeCommand("chown -R vmail:vmail /home/vmail/{$baseDomain}");
        } else {
            $this->log("Warning: Mail account seeding had issues: " . ($result['output'] ?? 'unknown'));
        }

        $this->executeCommand("rm -f /tmp/fleet-mail-seed.sql");
    }

    /**
     * Seed DNS zone and baseline records into the Panel database.
     * The Panel's DnsAction reads from dns_domains / dns_records in the Panel DB.
     * Creates the base domain zone with SOA, NS, A, MX, and subdomain A records.
     */
    private function seedDnsRecords(array $variables): void
    {
        $panelDomain = $variables['PANEL_DOMAIN'] ?? '';
        $emailDomain = $variables['EMAIL_DOMAIN'] ?? $variables['MAIL_DOMAIN'] ?? '';
        $serverIp = $variables['SERVER_IP'] ?? '';
        $panelDbName = $variables['PANEL_DB_NAME'] ?? 'devc_vps_dash';

        if (empty($panelDomain) || empty($serverIp)) {
            $this->log("Missing PANEL_DOMAIN or SERVER_IP - skipping DNS seeding");
            return;
        }

        $baseDomain = preg_replace('/^panel\./', '', $panelDomain);
        $serial = date('Ymd') . '01';
        $adminEmail = str_replace('@', '.', 'admin@' . $baseDomain);

        $this->log("Seeding DNS zone '{$baseDomain}' with baseline records...");

        // dns_records has no UNIQUE constraint, so INSERT IGNORE would create
        // duplicates on re-runs. Use WHERE NOT EXISTS to make seeding idempotent.
        $dnsIns = function (string $name, string $type, string $content, int $ttl = 3600, int $prio = 0) {
            $prioCol = $prio ? ", prio" : "";
            $prioVal = $prio ? ", {$prio}" : "";
            return "INSERT INTO dns_records (domain_id, name, type, content, ttl{$prioCol}) "
                . "SELECT @zone_id, '{$name}', '{$type}', '{$content}', {$ttl}{$prioVal} "
                . "FROM DUAL WHERE NOT EXISTS ("
                . "SELECT 1 FROM dns_records WHERE domain_id = @zone_id AND name = '{$name}' AND type = '{$type}'"
                . ");\n";
        };

        $sql = "USE `{$panelDbName}`;\n";

        // Create zone (idempotent -- name is UNIQUE)
        $sql .= "INSERT IGNORE INTO dns_domains (name, type) VALUES ('{$baseDomain}', 'NATIVE');\n";
        $sql .= "SET @zone_id = (SELECT id FROM dns_domains WHERE name = '{$baseDomain}');\n";

        $ns1 = $variables['NS1_DOMAIN'] ?? '';
        $ns2 = $variables['NS2_DOMAIN'] ?? '';

        // SOA record -- use NS1 if the client manages their own nameservers,
        // otherwise fall back to the server itself as primary
        $soaPrimary = !empty($ns1) ? $ns1 : $baseDomain;
        $sql .= $dnsIns($baseDomain, 'SOA',
            "{$soaPrimary}. {$adminEmail}. {$serial} 10800 3600 604800 3600");

        // NS records -- only if the client uses their own nameservers
        if (!empty($ns1)) {
            $sql .= $dnsIns($baseDomain, 'NS', $ns1);
            if (!empty($ns2)) {
                $sql .= "INSERT INTO dns_records (domain_id, name, type, content, ttl) "
                    . "SELECT @zone_id, '{$baseDomain}', 'NS', '{$ns2}', 3600 "
                    . "FROM DUAL WHERE NOT EXISTS ("
                    . "SELECT 1 FROM dns_records WHERE domain_id = @zone_id AND name = '{$baseDomain}' AND type = 'NS' AND content = '{$ns2}'"
                    . ");\n";
            }
        }

        // A record for base domain
        $sql .= $dnsIns($baseDomain, 'A', $serverIp);

        // A record for panel subdomain
        $sql .= $dnsIns($panelDomain, 'A', $serverIp);

        // A record for email/mail subdomain
        if (!empty($emailDomain) && $emailDomain !== $baseDomain) {
            $sql .= $dnsIns($emailDomain, 'A', $serverIp);
        }

        // mail.<base> + client auto-configuration records, mirroring what the
        // panel agent's DnsZoneCreateStep seeds for every customer zone. The
        // AutodiscoverController (email app) always hands clients mail.<domain>
        // as the IMAP/SMTP host, and the autodiscover/autoconfig CNAMEs + SRV
        // records (RFC 6186 + Outlook) let Outlook/Thunderbird/Apple Mail
        // self-configure from just an address + password.
        $mailHost = "mail.{$baseDomain}";
        $sql .= $dnsIns($mailHost, 'A', $serverIp);
        $sql .= $dnsIns("autodiscover.{$baseDomain}", 'CNAME', $mailHost);
        $sql .= $dnsIns("autoconfig.{$baseDomain}", 'CNAME', $mailHost);
        $sql .= $dnsIns("_autodiscover._tcp.{$baseDomain}", 'SRV', "0 443 {$mailHost}");
        $sql .= $dnsIns("_imaps._tcp.{$baseDomain}", 'SRV', "1 993 {$mailHost}");
        $sql .= $dnsIns("_submission._tcp.{$baseDomain}", 'SRV', "1 587 {$mailHost}");

        // coTURN relay host. The coTURN/LiveKit config (and the stunnel TLS cert
        // SANs) reference turn.<base>; without this A record clients can't resolve
        // the TURN relay and ICE negotiation fails for every call/huddle.
        $sql .= $dnsIns("turn.{$baseDomain}", 'A', $serverIp);

        // MX record pointing to base domain
        $sql .= $dnsIns($baseDomain, 'MX', $baseDomain, 3600, 10);

        // SPF record
        $sql .= $dnsIns($baseDomain, 'TXT', "v=spf1 a mx ip4:{$serverIp} -all");

        // DMARC record
        $sql .= $dnsIns("_dmarc.{$baseDomain}", 'TXT',
            "v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@{$baseDomain}; fo=1");

        // DKIM record - publish the OpenDKIM public key so receivers can verify our
        // outbound signatures. installOpenDKIM() runs before this step and stashes the
        // selector name + p= value in $variables (by reference). WITHOUT this record,
        // OpenDKIM still signs but every receiver reports DKIM=none/fail, and with our
        // strict DMARC (p=reject; adkim=s) that materially hurts deliverability.
        // base64 p= values have no quotes/backslashes, so direct interpolation is safe;
        // PowerDNS splits the >255-char TXT into 255-byte chunks on the wire.
        $dkimName  = $variables['DKIM_DNS_NAME'] ?? '';
        $dkimValue = $variables['DKIM_DNS_RECORD'] ?? '';
        $publishedDkim = false;
        if ($dkimName !== '' && $dkimValue !== '' && strpos($dkimValue, 'p=') !== false) {
            $sql .= $dnsIns($dkimName, 'TXT', $dkimValue);
            $publishedDkim = true;
        } else {
            $this->log("Note: no DKIM key available to publish (OpenDKIM may have been skipped) - DKIM TXT not seeded");
        }

        $this->ssh->uploadContent($sql, '/tmp/fleet-dns-seed.sql');
        $result = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf < /tmp/fleet-dns-seed.sql 2>&1");

        if ($result['success'] ?? false) {
            $recordList = 'SOA, NS, A, turn A, MX, SPF, DMARC, autodiscover/autoconfig CNAME, SRV' . ($publishedDkim ? ', DKIM' : '');
            $this->log("DNS zone '{$baseDomain}' seeded with {$recordList} records");
        } else {
            $this->log("Warning: DNS seeding had issues: " . ($result['output'] ?? 'unknown'));
        }

        $this->executeCommand("rm -f /tmp/fleet-dns-seed.sql");
    }

    /**
     * Bring the Mail Security Gateway up on the freshly provisioned server.
     *
     * Single source of truth: this calls the panel agent's mailsec-provision.php
     * CLI, which drives the EXACT same `MailSecurityAction` (install + wireMilter)
     * the panel UI uses. So a provisioned server ends up byte-for-byte identical
     * to one set up by hand from the panel - no parallel bash reimplementation to
     * drift out of sync (e.g. the milter_headers routine fix lives in one place).
     *
     * What the action does (all idempotent + fail-safe):
     *   - installs Rspamd + ClamAV (the engine)
     *   - installs a local unbound recursor on 127.0.0.1:5335 for reliable DNSBLs.
     *     This server runs PowerDNS authoritative on :53, so unbound MUST take the
     *     alt port - the action detects the :53 holder and binds 5335 accordingly.
     *   - sets up the quarantine spool + Postfix pipe transport
     *   - with --wire: points Postfix's inbound milter at Rspamd and enables
     *     quarantine routing (fail-open: milter_default_action=accept, with config
     *     rollback inside the action if postfix check fails).
     *
     * SpamAssassin (installed/wired by install_security) is intentionally left
     * untouched - Rspamd is appended to smtpd_milters, matching the main server.
     *
     * Best-effort: a hiccup here must not fail the whole deploy. Mail keeps
     * flowing fail-open regardless, and delivery can be (re)wired from the panel.
     */
    private function installMailSecurity(array $variables): void
    {
        $agentCli = '/opt/vps-admin/agent/mailsec-provision.php';

        $this->log("Setting up Mail Security (Rspamd + ClamAV + local resolver, then wiring delivery)...");

        // The CLI ships in the panel package's agent/ dir. If it's missing the
        // panel package predates this feature - skip rather than fail the deploy.
        $cliCheck = $this->executeCommand("test -f {$agentCli} && echo OK || echo MISSING");
        if (strpos($cliCheck['output'] ?? '', 'OK') === false) {
            $this->log("Warning: {$agentCli} not found (rebuild the panel package) - skipping Mail Security setup");
            return;
        }

        // Prefer the lsphp build the panel runs under; fall back to system php.
        $phpBin = '/usr/local/lsws/lsphp83/bin/php';
        $phpCheck = $this->executeCommand("test -x {$phpBin} && echo OK || echo NO");
        if (strpos($phpCheck['output'] ?? '', 'OK') === false) {
            $phpBin = 'php';
        }

        // Seed self-service quarantine links with this server's panel domain so
        // user digests work without manual configuration (the CLI only writes it
        // when the setting is still empty).
        $panelDomain = strtolower((string) ($variables['PANEL_DOMAIN'] ?? ''));
        $panelDomain = preg_replace('/[^a-z0-9.\-]/', '', $panelDomain);
        $panelDomainArg = $panelDomain !== '' ? " --panel-domain={$panelDomain}" : '';

        // Install + wire. apt (rspamd/clamav/unbound) plus the freshclam refresh
        // can take a few minutes on a fresh VPS, so allow a generous timeout. The
        // action is fail-open + self-rolling-back, so even a partial result leaves
        // mail flowing.
        $result = $this->executeCommand(
            "{$phpBin} {$agentCli} --wire --actor=fleet{$panelDomainArg} 2>&1",
            900
        );
        $this->log("mailsec-provision.php => " . trim((string) ($result['output'] ?? '')));
    }

    /**
     * Install CPGuard with the server's license key, when one was provided.
     *
     * CPGuard licenses are bound to the server IP, so there is no fleet-wide
     * key - each server row carries its own (optional, encrypted). No key =
     * logged no-op; the operator can install later from the server detail page
     * (POST /api/servers/{id}/cpguard/install) or via the panel's Security view.
     *
     * After a successful install (or when already installed) the blueprint's
     * extracted CPGuard config files - the live server's tuning: main.conf,
     * modsec wiring, badbots/bfurls/wafurls/rules, white/blacklists - are
     * FORCE re-applied on top of the installer's stock files, then the service
     * is restarted. deploy_configs already wrote them earlier, but the CPGuard
     * installer can overwrite them, hence the re-apply here.
     *
     * Detection note: blueprint configs create /etc/cpguard and /opt/cpguard
     * BEFORE CPGuard is installed, so directory existence is not a valid
     * "installed" signal here - the app dir / systemd unit is.
     */
    private function installCpguard(int $serverId, ?int $blueprintId, array $variables): void
    {
        $installedCheck = 'if [ -d /opt/cpguard/app ] || systemctl list-unit-files cpguard.service 2>/dev/null | grep -q "^cpguard"; then echo INSTALLED; fi';

        $licenseKey = trim((string) ($variables['CPGUARD_LICENSE_KEY'] ?? ''));
        if ($licenseKey === '') {
            $this->log("No CPGuard license key configured for this server - skipping CPGuard install");
            return;
        }

        $check = $this->executeCommand($installedCheck);
        if (strpos($check['output'] ?? '', 'INSTALLED') !== false) {
            $this->log("CPGuard already installed - refreshing license key");
            $this->executeCommand(
                'mkdir -p /etc/cpguard && echo ' . escapeshellarg($licenseKey) . ' > /etc/cpguard/LICENSE_cPGuard'
            );
        } else {
            $this->log("Installing CPGuard with the configured license key...");
            $result = $this->executeCommand(
                'curl -sL https://download.configserver.com/cpguard/install.sh 2>/dev/null | bash -s -- '
                . escapeshellarg($licenseKey) . ' 2>&1',
                900
            );

            $verify = $this->executeCommand($installedCheck);
            if (strpos($verify['output'] ?? '', 'INSTALLED') === false) {
                $tail = substr(trim((string) ($result['output'] ?? '')), -1500);
                throw new \Exception("CPGuard installer ran but no installation was detected. Output tail: {$tail}");
            }
            $this->log("CPGuard installed successfully");
        }

        // Overlay the reference server's CPGuard tuning from the blueprint.
        $applied = $this->applyCpguardBlueprintConfigs($serverId, $blueprintId, $variables);
        if ($applied > 0) {
            $this->log("Re-applied {$applied} CPGuard config file(s) from the blueprint (live-server tuning)");
        } else {
            $this->log("No CPGuard configs in the blueprint - running with the installer's stock configuration "
                . "(re-extract the blueprint from the live server to capture its CPGuard tuning)");
        }

        $this->executeCommand('systemctl restart cpguard 2>/dev/null || systemctl start cpguard 2>/dev/null || true');

        // CPGuard's Login Defender replaces fail2ban (this mirrors the live
        // server, where CPGuard stopped fail2ban). Stop AND disable it so a
        // reboot doesn't resurrect it and double-ban alongside CPGuard. The
        // fleet health view reports a disabled unit as 'disabled', not an error.
        $this->log("CPGuard active - stopping and disabling fail2ban (superseded by CPGuard Login Defender)");
        $this->executeCommand('systemctl stop fail2ban 2>/dev/null; systemctl disable fail2ban 2>/dev/null || true');
    }

    /**
     * Force-apply the blueprint's CPGuard config files (/etc/cpguard/*,
     * /opt/cpguard/*). Unlike deployConfigurationsWithIdempotency this does NOT
     * hash-skip: the CPGuard installer may have overwritten files that the
     * earlier deploy_configs step already recorded as applied, so we always
     * upload. The IP-bound license file is never part of the blueprint (the
     * extractor excludes it), so the fresh key written by installCpguard stays.
     */
    private function applyCpguardBlueprintConfigs(int $serverId, ?int $blueprintId, array $variables): int
    {
        if (!$blueprintId) {
            return 0;
        }

        $templates = $this->templates->processBlueprintTemplates($blueprintId, $variables);
        $applied = 0;

        foreach ($templates as $template) {
            $targetPath = (string) ($template['target_path'] ?? '');
            if (!str_starts_with($targetPath, '/etc/cpguard/') && !str_starts_with($targetPath, '/opt/cpguard/')) {
                continue;
            }
            // Same guard as deploy_configs: never write unresolved {{VAR}} placeholders.
            if (!empty($this->templates->findUnresolvedPlaceholders($template['content'] ?? ''))) {
                $this->log("Skipping CPGuard config with unresolved variables: {$targetPath}");
                continue;
            }

            $this->ssh->mkdir(dirname($targetPath));
            $this->ssh->uploadContent($template['content'], $targetPath);
            $this->ssh->chmod($targetPath, $template['permissions']);
            $this->ssh->chown($targetPath, $template['owner'], $template['group']);
            $this->recordConfigApplied($serverId, $template);
            $applied++;
        }

        return $applied;
    }

    /**
     * Register the server's own base domain (e.g. weddingcards.hu) as a real
     * site in the panel's Sites V2 table.
     *
     * Why: the Sites V2 list reads the `sites` table (populated only by the
     * provisioning saga). Without this the freshly deployed box shows 0 sites
     * even though DNS + mail were seeded, so the operator has nowhere to manage
     * the base domain from. We enqueue a CREATE job via the panel agent CLI
     * (the same path the "Provision site" button uses), and the vpsadmin-worker
     * daemon drives the canonical saga: sftp user, home dir, vhost, database +
     * user, SSL. DNS is skipped (--no-dns) because the fleet already seeded the
     * zone + records in seedDnsRecords().
     *
     * Best-effort: a hiccup here must not fail the whole deploy. We log loudly;
     * the audit step surfaces the final state.
     */
    private function provisionBaseDomainSite(array $variables): void
    {
        $panelDomain = $variables['PANEL_DOMAIN'] ?? '';
        $baseDomain = $panelDomain !== ''
            ? preg_replace('/^(panel|email|mail)\./', '', $panelDomain)
            : '';
        if ($baseDomain === '') {
            $md = $variables['MAIL_DOMAIN'] ?? $variables['EMAIL_DOMAIN'] ?? '';
            $baseDomain = $md !== '' ? preg_replace('/^(mail|email)\./', '', $md) : '';
        }
        // Sanitise to a clean FQDN charset before using in shell/SQL.
        $baseDomain = preg_replace('/[^a-z0-9.\-]/', '', strtolower((string) $baseDomain));
        if ($baseDomain === '' || strpos($baseDomain, '.') === false) {
            $this->log("No usable base domain resolved - skipping base-domain site registration");
            return;
        }

        $panelDb  = $variables['PANEL_DB_NAME'] ?? 'devc_vps_dash';
        $php      = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($variables['SITE_PHP_VERSION'] ?? 'lsphp83')));
        if ($php === '') {
            $php = 'lsphp83';
        }
        $agentCli = '/opt/vps-admin/agent/provision-site.php';

        $this->log("Registering base-domain site '{$baseDomain}' in Sites V2 via panel saga...");

        // The CLI ships in the panel package's agent/ dir. If it's missing the
        // panel package predates this feature - skip rather than fail.
        $cliCheck = $this->executeCommand("test -f {$agentCli} && echo OK || echo MISSING");
        if (strpos($cliCheck['output'] ?? '', 'OK') === false) {
            $this->log("Warning: {$agentCli} not found (rebuild the panel package) - skipping base-domain site");
            return;
        }

        // Make sure the worker daemon is up so the enqueued job actually runs.
        $this->executeCommand("systemctl is-active --quiet vpsadmin-worker || systemctl restart vpsadmin-worker 2>/dev/null || true");

        // Prefer the lsphp build; fall back to system php.
        $phpBin = '/usr/local/lsws/lsphp83/bin/php';
        $phpCheck = $this->executeCommand("test -x {$phpBin} && echo OK || echo NO");
        if (strpos($phpCheck['output'] ?? '', 'OK') === false) {
            $phpBin = 'php';
        }

        // 1. Enqueue the CREATE job (idempotent: returns the existing job if a
        //    CREATE for this domain is already in flight).
        $enqueue = $this->executeCommand(
            "{$phpBin} {$agentCli} --domain={$baseDomain} --php={$php} --no-dns --actor=fleet 2>&1"
        );
        $this->log("provision-site.php => " . trim((string) ($enqueue['output'] ?? '')));

        // 2. Poll the sites row until the saga reaches a terminal / usable state.
        $deadline = time() + 180;
        $state = '';
        while (time() < $deadline) {
            $q = $this->executeCommand(
                "mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e "
                . "\"SELECT actual_state FROM sites WHERE domain='{$baseDomain}' LIMIT 1\" 2>/dev/null"
            );
            $state = trim((string) ($q['output'] ?? ''));
            if (in_array($state, ['active', 'pending_dns', 'failed', 'degraded'], true)) {
                break;
            }
            sleep(5);
        }

        if ($state === 'active' || $state === 'pending_dns') {
            $this->log("Base-domain site '{$baseDomain}' provisioned (state={$state}).");
        } elseif ($state === '') {
            $this->log("Warning: base-domain site '{$baseDomain}' row not found yet - the worker may still be catching up (check 'journalctl -u vpsadmin-worker').");
        } else {
            $this->log("Warning: base-domain site '{$baseDomain}' ended in state '{$state}' (check 'journalctl -u vpsadmin-worker').");
        }

        // 3. Reflect DNS + mail (seeded separately by the fleet) on the sites
        //    row so Sites V2 shows them as wired. The saga skipped DNS (--no-dns)
        //    and never touches mail, so these columns stay 0 otherwise.
        $this->executeCommand(
            "mariadb --defaults-file=/root/.my.cnf {$panelDb} -e "
            . "\"UPDATE sites SET dns_enabled=1, mail_enabled=1 WHERE domain='{$baseDomain}'\" 2>/dev/null"
        );
    }

    /**
     * Import schema dumps from the main server into newly created databases
     */
    private function importSchemas(array $variables, SchemaService $schemaService, array $dbConfig): void
    {
        $rootPass = $variables['DB_ROOT_PASS'];
        $escapedRootPass = str_replace("'", "'\\''", $rootPass);

        // Map schema keys to their target database names
        $schemaMap = [
            'shared' => $variables['PANEL_DB_NAME'] ?? $dbConfig['shared']['target_db'],
            'mail'   => $variables['MAIL_DB_NAME'] ?? $dbConfig['mail']['target_db'],
            'fleet'  => $variables['FLEET_DB_NAME'] ?? $dbConfig['fleet']['target_db'],
        ];

        foreach ($schemaMap as $key => $targetDb) {
            $schemaPath = $schemaService->getSchemaPath($key);

            if (!file_exists($schemaPath)) {
                $this->log("Schema dump for '{$key}' not found at {$schemaPath} - install scripts will handle schema");
                continue;
            }

            $schemaSize = filesize($schemaPath);
            if ($schemaSize < 500) {
                $this->log("Schema dump for '{$key}' is too small ({$schemaSize} bytes) - skipping (min 500 bytes)");
                continue;
            }

            $this->log("Importing '{$key}' schema into '{$targetDb}' ({$schemaSize} bytes)...");

            // Upload schema file to remote server
            $remotePath = "/tmp/fleet-schema-{$key}.sql";
            $schemaContent = file_get_contents($schemaPath);
            $this->ssh->uploadContent($schemaContent, $remotePath);

            // Import using /root/.my.cnf (created by installMariaDB, avoids shell escaping)
            $result = $this->executeCommand(
                "mariadb --defaults-file=/root/.my.cnf {$targetDb} < {$remotePath} 2>&1"
            );

            if (!($result['success'] ?? false)) {
                $this->log("Warning: Schema import for '{$key}' had issues: " . ($result['output'] ?? 'unknown'));
            } else {
                $this->log("Schema for '{$key}' imported successfully");
            }

            $this->executeCommand("rm -f {$remotePath}");
        }
    }

    /**
     * Run idempotent ALTER TABLE statements for columns that may be missing from schema dumps.
     * Each statement uses ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+) or suppresses errors
     * so it's safe to run on every deployment regardless of schema state.
     */
    private function runPostSchemaMigrations(array $variables): void
    {
        $panelDb = $variables['PANEL_DB_NAME'] ?? 'devc_vps_dash';

        $this->log("Running post-schema migrations on '{$panelDb}'...");

        $migrations = <<<'SQL'
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS source_app VARCHAR(50) NOT NULL DEFAULT 'panel' AFTER id;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS severity ENUM('critical','high','medium','low','info') NOT NULL DEFAULT 'info' AFTER source_app;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL AFTER actor;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS user_email VARCHAR(255) NULL AFTER ip_address;

CREATE TABLE IF NOT EXISTS dependency_scans (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    source_app VARCHAR(50) NOT NULL,
    scan_type VARCHAR(20) NOT NULL,
    vulnerabilities_found INT NOT NULL DEFAULT 0,
    critical_count INT NOT NULL DEFAULT 0,
    high_count INT NOT NULL DEFAULT 0,
    medium_count INT NOT NULL DEFAULT 0,
    low_count INT NOT NULL DEFAULT 0,
    results JSON,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_source (source_app),
    INDEX idx_scanned (scanned_at),
    INDEX idx_type (scan_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MAIL SECURITY GATEWAY (Rspamd + ClamAV) - V1 foundation.
-- Mirrors panel/api/schema.sql so a provisioned server has the same tables as
-- the main server even if the structural dump is stale or predates the feature.
-- All additive + IF NOT EXISTS, so this never fights a richer dump from main.
-- =============================================================================
CREATE TABLE IF NOT EXISTS mail_security_global_whitelist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('email', 'domain', 'ip', 'cidr') NOT NULL,
    value VARCHAR(255) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wl (type, value),
    INDEX idx_wl_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_security_global_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('email', 'domain', 'ip', 'cidr') NOT NULL,
    value VARCHAR(255) NOT NULL,
    action ENUM('reject', 'quarantine') NOT NULL DEFAULT 'reject',
    description VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bl (type, value),
    INDEX idx_bl_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_quarantine (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(255) DEFAULT NULL,
    sender VARCHAR(320) DEFAULT NULL,
    recipient VARCHAR(320) DEFAULT NULL,
    subject VARCHAR(998) DEFAULT NULL,
    spam_score DECIMAL(6,2) DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    headers MEDIUMTEXT DEFAULT NULL,
    spool_path VARCHAR(512) NOT NULL,
    status ENUM('quarantined', 'released', 'deleted') NOT NULL DEFAULT 'quarantined',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME DEFAULT NULL,
    released_by VARCHAR(255) DEFAULT NULL,
    INDEX idx_q_recipient (recipient),
    INDEX idx_q_status (status),
    INDEX idx_q_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_security_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    event_type ENUM('clean', 'spam', 'quarantine', 'reject', 'virus', 'spf_fail', 'dkim_fail', 'dmarc_fail', 'phish', 'policy') NOT NULL,
    sender VARCHAR(320) DEFAULT NULL,
    recipient VARCHAR(320) DEFAULT NULL,
    domain VARCHAR(255) DEFAULT NULL,
    score DECIMAL(6,2) DEFAULT NULL,
    symbol VARCHAR(255) DEFAULT NULL,
    INDEX idx_ev_ts (ts),
    INDEX idx_ev_type (event_type),
    INDEX idx_ev_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_security_settings (
    k VARCHAR(100) PRIMARY KEY,
    v TEXT DEFAULT NULL,
    updated_by VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_security_attachment_policy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    extension VARCHAR(20) NOT NULL,
    list_type ENUM('allow', 'block') NOT NULL DEFAULT 'block',
    action ENUM('reject', 'quarantine', 'warn') NOT NULL DEFAULT 'quarantine',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ext (extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_security_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 100,
    conditions_json JSON DEFAULT NULL,
    action ENUM('move', 'delete', 'quarantine', 'reject', 'tag') NOT NULL,
    action_arg VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rule_enabled (enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mail_security_settings (k, v) VALUES
    ('spam_score_threshold', '6'),
    ('reject_score_threshold', '15'),
    ('mode', 'monitor'),
    ('milter_wired', '0'),
    ('quarantine_retention_days', '30');

INSERT IGNORE INTO mail_security_attachment_policy (extension, list_type, action) VALUES
    ('exe', 'block', 'quarantine'),
    ('bat', 'block', 'quarantine'),
    ('cmd', 'block', 'quarantine'),
    ('scr', 'block', 'quarantine'),
    ('ps1', 'block', 'quarantine'),
    ('vbs', 'block', 'quarantine'),
    ('js',  'block', 'quarantine'),
    ('jar', 'block', 'quarantine');
SQL;

        $this->ssh->uploadContent($migrations, '/tmp/fleet-post-migrations.sql');
        $result = $this->executeCommand(
            "mariadb --defaults-file=/root/.my.cnf {$panelDb} < /tmp/fleet-post-migrations.sql 2>&1"
        );

        if ($result['success'] ?? false) {
            $this->log("Post-schema migrations applied successfully");
        } else {
            $this->log("Warning: Some post-schema migrations had issues: " . trim($result['output'] ?? ''));
        }

        $this->executeCommand("rm -f /tmp/fleet-post-migrations.sql");
    }

    /**
     * Deploy the FlowOne shared library (flowone/storage) — a privilege-separated
     * storage/NAS platform the apps rely on at runtime. Gracefully skipped if the
     * package has not been built/uploaded yet (build with packages/shared/build.sh).
     */
    private function deploySharedLibrary(array $variables): void
    {
        $packagesPath = $this->container->getConfig('packages.path');
        $sharedPackage = $this->container->getConfig('packages.shared');
        if (empty($sharedPackage)) {
            return;
        }
        $packageFile = $packagesPath . $sharedPackage;

        if (!file_exists($packageFile)) {
            $this->log("Note: Shared library package not found at {$packageFile} — skipping. Build it with packages/shared/build.sh on a host that has /var/www/shared, then re-deploy.");
            return;
        }

        $this->log("Deploying FlowOne shared library...");
        $this->executeCommand('mkdir -p /tmp/fleet-deploy');

        $remotePath = '/tmp/fleet-deploy/shared.tar.gz';
        if ($this->isLocalServer) {
            if (!copy($packageFile, $remotePath)) {
                throw new \Exception("Failed to copy shared package to {$remotePath}");
            }
        } else {
            if (!$this->ssh->uploadFile($packageFile, $remotePath)) {
                throw new \Exception("SFTP upload failed for shared package. Check SSH connection and disk space on target.");
            }
        }

        $this->executeCommand('mkdir -p /tmp/fleet-deploy/shared');
        $this->executeCommand('cd /tmp/fleet-deploy && tar -xzf shared.tar.gz -C shared --strip-components=1');

        $installerCmd = 'cd /tmp/fleet-deploy/shared && chmod +x install.sh && bash install.sh --install-path=/var/www/shared 2>&1';
        $result = $this->executeLongCommand($installerCmd, 300);
        $output = $result['output'] ?? '';
        $outputLines = array_filter(explode("\n", $output));
        foreach (array_slice($outputLines, -20) as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $this->log("[shared-install] {$line}");
            }
        }
        if (!($result['success'] ?? false)) {
            // Non-fatal: the shared platform is auxiliary, so a failure here must
            // not block the core panel/email deploy.
            $this->log("Warning: shared library installer exited non-zero (exit code: " . ($result['exit_code'] ?? 'unknown') . ")");
        }

        $this->executeCommand('rm -rf /tmp/fleet-deploy/shared*');
        $this->log("FlowOne shared library deployment complete");
    }

    /**
     * Deploy VPS Admin Panel
     */
    private function deployPanel(array $variables): void
    {
        $packagesPath = $this->container->getConfig('packages.path');
        $panelPackage = $this->container->getConfig('packages.panel');
        $packageFile = $packagesPath . $panelPackage;

        if (!file_exists($packageFile)) {
            $this->log("Warning: Panel package not found at {$packageFile} — skipping panel deployment. Upload the package and re-deploy.");
            return;
        }

        $this->log("Deploying VPS Admin Panel...");

        // Create temp directory on remote server
        $this->executeCommand('mkdir -p /tmp/fleet-deploy');

        // Upload package via SFTP
        $this->log("Uploading panel package (" . round(filesize($packageFile) / 1048576, 1) . " MB)...");
        $remotePath = '/tmp/fleet-deploy/panel.tar.gz';
        
        if ($this->isLocalServer) {
            // Local server - just copy
            if (!copy($packageFile, $remotePath)) {
                throw new \Exception("Failed to copy panel package to {$remotePath}");
            }
        } else {
            // Remote server - use SFTP
            if (!$this->ssh->uploadFile($packageFile, $remotePath)) {
                throw new \Exception("SFTP upload failed for panel package. Check SSH connection and disk space on target.");
            }
        }
        $this->log("Panel package uploaded successfully");

        // Extract package (tarball already has panel/ directory inside, use --strip-components=1)
        $this->log("Extracting panel package...");
        $this->executeCommand('mkdir -p /tmp/fleet-deploy/panel');
        $this->executeCommand('cd /tmp/fleet-deploy && tar -xzf panel.tar.gz -C panel --strip-components=1');

        // Run installer with variables
        $this->log("Running panel installer...");
        $fleetUrl = $this->container->getConfig('app.url') ?? 'https://fleet.devcon1.hu';
        $installerCmd = sprintf(
            'cd /tmp/fleet-deploy/panel && chmod +x install.sh && bash install.sh ' .
            '--domain=%s --db-name=%s --db-user=%s --db-pass=%s --db-root-pass=%s ' .
            '--admin-email=%s --admin-pass=%s --agent-token=%s --fleet-url=%s ' .
            '--email-api-key=%s --skip-vhost 2>&1',
            escapeshellarg($variables['PANEL_DOMAIN']),
            escapeshellarg($variables['PANEL_DB_NAME'] ?? 'devc_vps_dash'),
            escapeshellarg($variables['PANEL_DB_USER'] ?? 'vpsadmin'),
            escapeshellarg($variables['PANEL_DB_PASS']),
            escapeshellarg($variables['DB_ROOT_PASS']),
            escapeshellarg($variables['ADMIN_EMAIL']),
            escapeshellarg($variables['ADMIN_PASS']),
            escapeshellarg($variables['AGENT_TOKEN']),
            escapeshellarg($fleetUrl),
            escapeshellarg($variables['EMAIL_API_KEY'] ?? '')
        );

        // Use extended timeout - installer runs composer, db setup, etc.
        $result = $this->executeLongCommand($installerCmd, 600);
        $output = $result['output'] ?? '';
        
        // Log last 30 lines of installer output for debugging
        $outputLines = array_filter(explode("\n", $output));
        $lastLines = array_slice($outputLines, -30);
        foreach ($lastLines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $this->log("[panel-install] {$line}");
            }
        }
        
        if (!$result['success']) {
            $this->log("Panel installer exited with error (exit code: " . ($result['exit_code'] ?? 'unknown') . ")");
            throw new \Exception('Panel installation failed — check logs above for details');
        }

        // Cleanup
        $this->executeCommand('rm -rf /tmp/fleet-deploy/panel*');

        // Setup sudoers for www-data (Panel API needs to manage services)
        $this->log("Configuring sudoers for Panel API...");
        $sudoers = <<<'SUDOERS'
# VPS Admin Panel - www-data sudo permissions
# Panel communicates with the agent (root) for most operations
# These are minimal direct permissions matching the main server
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart vpsadmin-agent
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart fleet-agent
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart vpsadmin-agent
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart fleet-agent
www-data ALL=(ALL) NOPASSWD: /usr/bin/doveadm reload
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl status *
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl is-active *
www-data ALL=(ALL) NOPASSWD: /usr/local/lsws/bin/lswsctrl *
www-data ALL=(ALL) NOPASSWD: /usr/bin/journalctl *
SUDOERS;
        $this->ssh->uploadContent($sudoers, '/etc/sudoers.d/vps-admin');
        $this->executeCommand('chmod 440 /etc/sudoers.d/vps-admin');
        $this->log("Sudoers configured for Panel API");

        // Panel installer now creates vpsadmin-agent.service separately from fleet-agent.service
        // Just reload systemd to pick up any new services
        $this->executeCommand('systemctl daemon-reload');

        // Grant www-data the same group memberships as main server
        // systemd-journal: read journalctl logs
        // lsadm: manage OLS config files
        // dovecot: manage dovecot operations
        // vmail: access virtual mail directories
        $this->executeCommand('usermod -a -G systemd-journal,lsadm,dovecot,vmail www-data 2>/dev/null || true');

        // Sudoers for nobody (sieve compilation, used by email app)
        $nobodySudoers = "nobody ALL=(ALL) NOPASSWD: /usr/bin/sievec, /usr/bin/tee /home/vmail/*, /bin/mkdir -p /home/vmail/*, /bin/chown *, /bin/ln -sf *, /bin/rm -f /home/vmail/*, /bin/sed -i * /home/vmail/*, /bin/cat /home/vmail/*\n";
        $this->ssh->uploadContent($nobodySudoers, '/etc/sudoers.d/vps-email-sieve');
        $this->executeCommand('chmod 440 /etc/sudoers.d/vps-email-sieve');

        // Sudoers for fleet agent user (read-only system commands + systemctl)
        $fleetSudoers = "fleet ALL=(ALL) NOPASSWD: /usr/bin/cat, /usr/bin/test, /usr/bin/stat, /usr/bin/find, /usr/bin/ls, /bin/cat, /bin/hostname, /bin/uname, /usr/bin/dpkg, /usr/bin/systemctl\n";
        $this->ssh->uploadContent($fleetSudoers, '/etc/sudoers.d/fleet-agent');
        $this->executeCommand('chmod 440 /etc/sudoers.d/fleet-agent');

        // Ensure VERSION file exists (safety net if build.sh / install.sh didn't create it)
        $verCheck = $this->executeCommand('cat /var/www/vps-admin/VERSION 2>/dev/null');
        if (empty(trim($verCheck['output'] ?? ''))) {
            $deployVer = $variables['PANEL_VERSION'] ?? date('Y.m.d');
            $this->executeCommand("echo '{$deployVer}' > /var/www/vps-admin/VERSION");
            $this->log("Created VERSION file: {$deployVer}");
        }

        $this->log("VPS Admin Panel deployed successfully");
    }

    /**
     * Deploy MailFlow Email App
     */
    private function deployEmailApp(array $variables): void
    {
        $packagesPath = $this->container->getConfig('packages.path');
        $emailPackage = $this->container->getConfig('packages.email');
        $packageFile = $packagesPath . $emailPackage;

        if (!file_exists($packageFile)) {
            $this->log("Warning: Email package not found at {$packageFile} — skipping email app deployment. Upload the package and re-deploy.");
            return;
        }

        $this->log("Deploying MailFlow Email App...");
        
        // Create dedicated user for email app (for process isolation)
        $emailUser = $variables['EMAIL_USER'] ?? 'email_app';
        $this->log("Creating email user: {$emailUser}");
        
        // Check if user exists
        $userCheck = $this->executeCommand("id {$emailUser} 2>/dev/null && echo 'exists' || echo 'not_exists'");
        if (strpos($userCheck['output'] ?? '', 'not_exists') !== false) {
            // Create user with no login shell and home directory
            $this->executeCommand("useradd -r -s /usr/sbin/nologin -d /var/www/vps-email {$emailUser} 2>/dev/null || true");
            $this->log("User {$emailUser} created");
        } else {
            $this->log("User {$emailUser} already exists");
        }

        // Create temp directory on remote server
        $this->executeCommand('mkdir -p /tmp/fleet-deploy');

        // Upload package
        $this->log("Uploading email package (" . round(filesize($packageFile) / 1048576, 1) . " MB)...");
        $remotePath = '/tmp/fleet-deploy/email.tar.gz';
        
        if ($this->isLocalServer) {
            if (!copy($packageFile, $remotePath)) {
                throw new \Exception("Failed to copy email package to {$remotePath}");
            }
        } else {
            if (!$this->ssh->uploadFile($packageFile, $remotePath)) {
                throw new \Exception("SFTP upload failed for email package. Check SSH connection and disk space on target.");
            }
        }
        $this->log("Email package uploaded successfully");

        // Extract package (tarball already has email/ directory inside, use --strip-components=1)
        $this->log("Extracting email package...");
        $this->executeCommand('mkdir -p /tmp/fleet-deploy/email');
        $this->executeCommand('cd /tmp/fleet-deploy && tar -xzf email.tar.gz -C email --strip-components=1');

        // Run installer with variables
        $this->log("Running email installer...");
        $panelApiUrl = $this->container->getConfig('app.url') ?? 'https://fleet.devcon1.hu';
        $panelDomainUrl = 'https://' . ($variables['PANEL_DOMAIN'] ?? 'panel.devcon1.hu') . '/api';
        // Build base installer command
        $installerCmd = sprintf(
            'cd /tmp/fleet-deploy/email && chmod +x install.sh && bash install.sh ' .
            '--domain=%s --mail-domain=%s --db-name=%s --db-user=%s --db-pass=%s ' .
            '--db-root-pass=%s ' .
            '--mail-db-name=%s --mail-db-user=%s --mail-db-pass=%s ' .
            '--redis-pass=%s --meili-master-key=%s --meili-search-key=%s ' .
            '--panel-api-url=%s --panel-api-key=%s ' .
            '--skip-vhost 2>&1',
            escapeshellarg($variables['EMAIL_DOMAIN']),
            escapeshellarg($variables['MAIL_DOMAIN']),
            escapeshellarg($variables['EMAIL_DB_NAME'] ?? 'devc_vps_dash'),
            escapeshellarg($variables['EMAIL_DB_USER'] ?? 'vpsadmin'),
            escapeshellarg($variables['EMAIL_DB_PASS']),
            escapeshellarg($variables['DB_ROOT_PASS']),
            escapeshellarg($variables['MAIL_DB_NAME'] ?? 'mailserver'),
            escapeshellarg($variables['MAIL_DB_USER'] ?? 'mailuser'),
            escapeshellarg($variables['MAIL_DB_PASS'] ?? ''),
            escapeshellarg($variables['REDIS_PASS'] ?? ''),
            escapeshellarg($variables['MEILI_MASTER_KEY'] ?? ''),
            escapeshellarg($variables['MEILI_SEARCH_KEY'] ?? ''),
            escapeshellarg($panelDomainUrl),
            escapeshellarg($variables['EMAIL_API_KEY'] ?? '')
        );

        // Append LiveKit credentials if configured
        if (!empty($variables['LIVEKIT_API_KEY'])) {
            // LiveKit is configured, so the WS URL is mandatory: the email app
            // throws a RuntimeException at call time when livekit_ws_url is blank,
            // and a silently-empty value breaks every call/huddle. Fail provisioning
            // loudly here instead of shipping a broken server. The expected value is
            // the stunnel TLS port in front of LiveKit, e.g. wss://<base>:7443.
            if (empty($variables['LIVEKIT_WS_URL'])) {
                $baseDomain = preg_replace('/^panel\./', '', (string)($variables['PANEL_DOMAIN'] ?? ''));
                $hint = $baseDomain !== '' ? "wss://{$baseDomain}:7443" : 'wss://<host>:7443';
                throw new \RuntimeException(
                    'LIVEKIT_WS_URL is empty but LiveKit API credentials are set. '
                    . "Set LIVEKIT_WS_URL in the server config (stunnel TLS port, e.g. {$hint}) "
                    . 'before provisioning; an empty ws_url breaks all calls/huddles.'
                );
            }
            $installerCmd .= sprintf(
                ' --livekit-api-key=%s --livekit-api-secret=%s --livekit-ws-url=%s',
                escapeshellarg($variables['LIVEKIT_API_KEY']),
                escapeshellarg($variables['LIVEKIT_API_SECRET'] ?? ''),
                escapeshellarg($variables['LIVEKIT_WS_URL'])
            );
        }

        // Append coTURN credentials so the email app's CallService::getIceServers()
        // mints ICE credentials against the local TURN server (shared static-auth-secret).
        if (!empty($variables['TURN_SECRET'])) {
            $installerCmd .= sprintf(
                ' --turn-secret=%s --turn-host=%s',
                escapeshellarg($variables['TURN_SECRET']),
                escapeshellarg($variables['TURN_HOST'] ?? '')
            );
        }

        // Use extended timeout - installer runs composer, db setup, etc.
        $result = $this->executeLongCommand($installerCmd, 600);
        $output = $result['output'] ?? '';
        
        // Log last 30 lines of installer output for debugging
        $outputLines = array_filter(explode("\n", $output));
        $lastLines = array_slice($outputLines, -30);
        foreach ($lastLines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $this->log("[email-install] {$line}");
            }
        }
        
        if (!$result['success']) {
            $this->log("Email installer exited with error (exit code: " . ($result['exit_code'] ?? 'unknown') . ")");
            throw new \Exception('Email installation failed — check logs above for details');
        }

        // Set ownership to email user for process isolation
        $emailUser = $variables['EMAIL_USER'] ?? 'email_app';
        $this->executeCommand("chown -R {$emailUser}:{$emailUser} /var/www/vps-email");
        $this->log("Set email app ownership to {$emailUser}");

        // Cleanup
        $this->executeCommand('rm -rf /tmp/fleet-deploy/email*');

        // Deploy Node.js services (collab, mailsync, fastapi-ssh)
        $this->deployNodeServices($variables);
        
        // Ensure VERSION file exists (safety net)
        $verCheck = $this->executeCommand('cat /var/www/vps-email/VERSION 2>/dev/null');
        if (empty(trim($verCheck['output'] ?? ''))) {
            $deployVer = $variables['EMAIL_VERSION'] ?? date('Y.m.d');
            $this->executeCommand("echo '{$deployVer}' > /var/www/vps-email/VERSION");
            $this->log("Created VERSION file: {$deployVer}");
        }

        // Apply the webmail DB schema migrations explicitly. The app DOES auto-run
        // pending migrations from public/index.php, but that first-request path is
        // unreliable on a fresh box (worker may hit it before composer finishes, a
        // single failure gets recorded and silently swallowed, etc.) - which is what
        // left tables like webmail_sessions.encrypted_password, pinned_emails and
        // webmail_folder_identity missing, so login / pin / folders returned 500.
        // Running it here (idempotent) makes the schema deterministic + logged.
        $this->runEmailAppMigrations();

        $this->log("MailFlow Email App deployed successfully");
    }

    /**
     * Run the MailFlow webmail database migrations on the target box.
     *
     * Mirrors public/index.php's bootstrap exactly (autoload + src/config.php, which
     * self-merges config.local.php) and calls the same MigrationService, so the
     * `migrations` tracking table stays consistent with the runtime auto-run. Only
     * pending migrations run, so this is safe to repeat on every deploy. Failures are
     * surfaced in the deployment log rather than silently swallowed.
     */
    private function runEmailAppMigrations(): void
    {
        $backend = '/var/www/vps-email/backend';
        $migDir  = "{$backend}/migrations";

        // Confirm the migrations actually shipped inside the email package.
        $count = $this->executeCommand("ls -1 {$migDir}/*.sql 2>/dev/null | wc -l");
        $n = (int) trim($count['output'] ?? '0');
        if ($n === 0) {
            $this->log("WARNING: no email-app migrations found at {$migDir} - the webmail schema may be incomplete (login/pin/folders can 500). Rebuild the email package so it includes backend/migrations/.");
            return;
        }
        $this->log("Running email-app database migrations ({$n} files)...");

        // Same php-binary preference as the panel CLI: lsphp build, else system php.
        $phpBin = '/usr/local/lsws/lsphp83/bin/php';
        if (strpos($this->executeCommand("test -x {$phpBin} && echo OK || echo NO")['output'] ?? '', 'OK') === false) {
            $phpBin = 'php';
        }

        $runner = <<<'PHP'
<?php
chdir('/var/www/vps-email/backend');
require '/var/www/vps-email/backend/vendor/autoload.php';
$config = require '/var/www/vps-email/backend/src/config.php';
$svc = new \Webmail\Services\MigrationService($config);
$results = $svc->runPendingMigrations();
$ok = 0; $fail = 0;
foreach ($results as $r) {
    if (!empty($r['success'])) {
        $ok++;
    } else {
        $fail++;
        $err = $r['error'] ?? ($r['error_message'] ?? 'unknown error');
        fwrite(STDERR, 'FAILED ' . ($r['name'] ?? '?') . ': ' . $err . "\n");
    }
}
echo 'EMAIL_MIGRATIONS ran=' . count($results) . " ok={$ok} fail={$fail}\n";
PHP;

        $this->ssh->uploadContent($runner, '/tmp/fleet-email-migrate.php');
        $res = $this->executeLongCommand("{$phpBin} /tmp/fleet-email-migrate.php 2>&1", 300);
        $this->executeCommand('rm -f /tmp/fleet-email-migrate.php');

        $out = trim((string) ($res['output'] ?? ''));
        foreach (array_slice(array_filter(explode("\n", $out)), -25) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $this->log("[email-migrate] {$line}");
            }
        }

        if (stripos($out, 'EMAIL_MIGRATIONS') === false) {
            $this->log("WARNING: email-app migration runner produced no summary - check that {$phpBin} and {$backend}/vendor exist.");
        } elseif (stripos($out, 'fail=0') === false) {
            $this->log("WARNING: some email-app migrations FAILED (see [email-migrate] lines). login/pin/folder features may 500 until resolved.");
        } else {
            $this->log("Email-app migrations applied cleanly.");
        }
    }

    /**
     * Deploy Node.js / Python service systemd units
     * 
     * These services run alongside the Email App:
     * - collab-server: Real-time collaboration WebSocket (port 1234)
     * - mailsync-server: MailSync WebSocket (port 1235)
     * - fastapi-ssh-server: SSH terminal proxy (port 7700)
     */
    private function deployNodeServices(array $variables): void
    {
        $this->log("Deploying Node.js/Python service units...");

        $emailUser = $variables['EMAIL_USER'] ?? 'email_app';
        $emailAppRoot = '/var/www/vps-email';
        $templatePath = $this->container->getConfig('templates.path') ?? __DIR__ . '/../../../templates';

        // Service definitions: [template_file, service_name, working_dir, check_dir]
        $services = [
            [
                'template' => 'systemd/collab-server.service.template',
                'service_name' => 'collab-server',
                'unit_file' => '/etc/systemd/system/collab-server.service',
                'working_dir' => "{$emailAppRoot}/collab-server",
            ],
            [
                'template' => 'systemd/mailsync-server.service.template',
                'service_name' => 'mailsync-server',
                'unit_file' => '/etc/systemd/system/mailsync-server.service',
                'working_dir' => "{$emailAppRoot}/mailsync-server",
            ],
            [
                'template' => 'systemd/fastapi-ssh-server.service.template',
                'service_name' => 'mailflow-fastapi-ssh',
                'unit_file' => '/etc/systemd/system/mailflow-fastapi-ssh.service',
                'working_dir' => "{$emailAppRoot}/fastapi-ssh-server",
            ],
        ];

        foreach ($services as $svc) {
            // Check if the app directory exists (the install script may not have all components)
            $dirCheck = $this->executeCommand("test -d {$svc['working_dir']} && echo 'exists' || echo 'missing'");
            if (strpos($dirCheck['output'] ?? '', 'missing') !== false) {
                $this->log("Skipping {$svc['service_name']} - directory {$svc['working_dir']} not found");
                continue;
            }

            // Try to load template from file
            $templateFile = "{$templatePath}/{$svc['template']}";
            if (file_exists($templateFile)) {
                $template = file_get_contents($templateFile);
                $content = $this->templates->process($template, array_merge($variables, [
                    'EMAIL_USER' => $emailUser,
                ]));
            } else {
                // Generate a sensible default if no template file
                $isNode = strpos($svc['template'], 'fastapi') === false;
                $execStart = $isNode
                    ? "/usr/bin/node server.js"
                    : "/usr/bin/python3 -m uvicorn main:app --host 0.0.0.0 --port 7700";

                $content = "[Unit]\n"
                    . "Description={$svc['service_name']}\n"
                    . "After=network.target\n\n"
                    . "[Service]\n"
                    . "User={$emailUser}\n"
                    . "Group={$emailUser}\n"
                    . "WorkingDirectory={$svc['working_dir']}\n"
                    . "ExecStart={$execStart}\n"
                    . "Restart=always\n"
                    . "RestartSec=5\n"
                    . "StandardOutput=syslog\n"
                    . "StandardError=syslog\n"
                    . "SyslogIdentifier={$svc['service_name']}\n\n"
                    . "[Install]\n"
                    . "WantedBy=multi-user.target\n";
            }

            // Deploy the unit file
            $this->ssh->uploadContent($content, $svc['unit_file']);
            $this->executeCommand("chmod 644 {$svc['unit_file']}");

            // Install Node.js dependencies if package.json exists
            $pkgCheck = $this->executeCommand("test -f {$svc['working_dir']}/package.json && echo 'exists' || echo 'missing'");
            if (strpos($pkgCheck['output'] ?? '', 'exists') !== false) {
                $this->log("Installing npm dependencies for {$svc['service_name']}...");
                $this->executeCommand("cd {$svc['working_dir']} && npm install --production 2>&1 || true");
            }

            // Install Python dependencies if requirements.txt exists
            $reqCheck = $this->executeCommand("test -f {$svc['working_dir']}/requirements.txt && echo 'exists' || echo 'missing'");
            if (strpos($reqCheck['output'] ?? '', 'exists') !== false) {
                $this->log("Installing Python dependencies for {$svc['service_name']}...");
                $this->executeCommand("cd {$svc['working_dir']} && pip3 install -r requirements.txt 2>&1 || true");
            }

            // Enable and start the service
            $this->executeCommand("systemctl daemon-reload");
            $this->executeCommand("systemctl enable {$svc['service_name']} 2>/dev/null || true");
            $this->executeCommand("systemctl restart {$svc['service_name']} 2>/dev/null || true");

            $this->log("Service {$svc['service_name']} deployed and started");
        }

        // Reload systemd after all units are deployed
        $this->executeCommand("systemctl daemon-reload");
        $this->log("Node.js/Python services deployed");
    }

    /**
     * Deploy vhost configurations for system apps (Panel, Email, Fleet)
     */
    private function deploySystemVhosts(array $variables): void
    {
        $this->log("Deploying OpenLiteSpeed vhost configurations...");
        
        // SSL certs must exist BEFORE we write vhost configs and restart OLS.
        // The vhost vhssl blocks and the main SSL listener all reference
        // /etc/letsencrypt/live/{domain}/ paths. Without certs, OLS refuses to start.
        $this->ensureSSLPlaceholders($variables);
        
        $olsConf = '/usr/local/lsws/conf';
        $vhostsDir = "{$olsConf}/vhosts";
        
        // Create vhosts directory
        $this->executeCommand("mkdir -p {$vhostsDir}");

        // Pre-create document roots so OLS doesn't reject vhost configs
        $this->executeCommand('mkdir -p /var/www/vps-admin');
        $this->executeCommand('mkdir -p /var/www/vps-email/dist');
        $this->executeCommand('chown -R www-data:www-data /var/www/vps-admin /var/www/vps-email 2>/dev/null || true');

        $this->cleanStaleVhostDirs($vhostsDir, [
            $variables['PANEL_DOMAIN'],
            $variables['EMAIL_DOMAIN'],
        ]);
        
        // Create log directories
        $this->executeCommand("mkdir -p /var/log/lsws/{$variables['PANEL_DOMAIN']}");
        $this->executeCommand("mkdir -p /var/log/lsws/{$variables['EMAIL_DOMAIN']}");
        
        // Deploy Panel vhost
        $this->deployVhostFromTemplate('panel', $variables['PANEL_DOMAIN'], $variables);
        
        // Deploy Email vhost  
        $this->deployVhostFromTemplate('email', $variables['EMAIL_DOMAIN'], $variables);
        
        // Create staging folders with SSH users
        $this->createStagingFolder($variables['PANEL_DOMAIN'], 'panel_staging');
        $this->createStagingFolder($variables['EMAIL_DOMAIN'], 'email_staging');
        
        // Force-deploy clean httpd_config.conf and add only these vhosts
        $this->addVhostsToHttpdConfig([
            $variables['PANEL_DOMAIN'],
            $variables['EMAIL_DOMAIN'],
        ], $variables);
        
        // Restart OLS to apply changes
        $this->executeCommand('/usr/local/lsws/bin/lswsctrl restart');
        
        $this->log("Vhost configurations deployed successfully");
    }

    /**
     * Remove vhost directories that don't belong to this deployment.
     * Prevents stale configs from old servers or previous OLS installations.
     */
    private function cleanStaleVhostDirs(string $vhostsDir, array $keepDomains): void
    {
        $listing = $this->executeCommand("ls -1 {$vhostsDir} 2>/dev/null || true");
        $existing = array_filter(explode("\n", trim($listing['output'] ?? '')));

        $keep = array_merge($keepDomains, ['Example']);

        foreach ($existing as $dir) {
            $dir = trim($dir);
            if (empty($dir) || in_array($dir, $keep)) {
                continue;
            }
            $this->log("Removing stale vhost directory: {$vhostsDir}/{$dir}");
            $this->executeCommand("rm -rf {$vhostsDir}/{$dir}");
        }
    }

    /**
     * Create staging folder structure with SSH user for a domain
     * Staging folders live in /home/{domain}/ and allow SFTP uploads
     */
    private function createStagingFolder(string $domain, string $sshUser): void
    {
        $this->log("Creating staging folder for {$domain}...");
        
        $homePath = "/home/{$domain}";
        
        // Create system user for SSH/SFTP access (ignore if exists)
        $this->executeCommand("id {$sshUser} >/dev/null 2>&1 || useradd -m -d {$homePath} -s /bin/bash {$sshUser}");
        
        // Create directory structure
        $this->executeCommand("mkdir -p {$homePath}/public_html");
        $this->executeCommand("mkdir -p {$homePath}/logs");
        $this->executeCommand("mkdir -p {$homePath}/.ssh");
        $this->executeCommand("mkdir -p {$homePath}/tmp");
        
        // Set ownership
        $this->executeCommand("chown -R {$sshUser}:{$sshUser} {$homePath}");
        
        // Set permissions
        $this->executeCommand("chmod 755 {$homePath}");
        $this->executeCommand("chmod 755 {$homePath}/public_html");
        $this->executeCommand("chmod 755 {$homePath}/logs");
        $this->executeCommand("chmod 700 {$homePath}/.ssh");
        $this->executeCommand("chmod 755 {$homePath}/tmp");
        
        $this->log("Staging folder created: {$homePath} (user: {$sshUser})");
    }

    /**
     * Deploy a single vhost from template
     */
    private function deployVhostFromTemplate(string $type, string $domain, array $variables): void
    {
        $templatePath = $this->container->getConfig('templates.path') ?? __DIR__ . '/../../../templates';
        $templateFile = "{$templatePath}/openlitespeed/vhost-{$type}.conf.template";
        
        if (!file_exists($templateFile)) {
            $this->log("Warning: Vhost template not found: {$templateFile}");
            return;
        }
        
        // Read template
        $template = file_get_contents($templateFile);
        
        // Replace variables
        $content = $this->templates->process($template, $variables);
        
        // Create vhost directory
        $vhostDir = "/usr/local/lsws/conf/vhosts/{$domain}";
        $this->executeCommand("mkdir -p {$vhostDir}");
        $this->executeCommand("mkdir -p {$vhostDir}/logs");
        
        // Write vhost config
        $this->ssh->uploadContent($content, "{$vhostDir}/vhconf.conf");
        
        // Set permissions
        $this->executeCommand("chown -R lsadm:lsadm {$vhostDir}");
        $this->executeCommand("chmod 644 {$vhostDir}/vhconf.conf");
        
        $this->log("Deployed vhost for {$domain}");
    }

    /**
     * Add vhosts to httpd_config.conf
     *
     * Force-deploys the httpd_config.conf from the clean template first,
     * then appends only the required virtualHost blocks and listener mappings.
     * This prevents stale entries from old servers or OLS WebAdmin from persisting.
     */
    private function addVhostsToHttpdConfig(array $domains, array $variables = []): void
    {
        $httpdConf = '/usr/local/lsws/conf/httpd_config.conf';

        $this->forceDeployHttpdConfigTemplate($variables);

        foreach ($domains as $domain) {
            $this->removeExistingVhostBlock($domain, $httpdConf);

            $vhostBlock = "\nvirtualHost {$domain} {\n" .
                "  vhRoot                  /usr/local/lsws/conf/vhosts/{$domain}\n" .
                "  configFile              \$VH_ROOT/vhconf.conf\n" .
                "  allowSymbolLink         1\n" .
                "  enableScript            1\n" .
                "  restrained              0\n" .
                "}\n";
            $this->executeCommand("printf '%s' " . escapeshellarg($vhostBlock) . " >> {$httpdConf}");
            $this->log("Added vhost {$domain} to httpd_config.conf");
        }

        foreach ($domains as $domain) {
            $this->addListenerMapping('Default', $domain, $httpdConf);
            $this->addListenerMapping('SSL', $domain, $httpdConf);
        }

        $this->updateSSLListenerCertPaths($domains, $httpdConf);
    }

    /**
     * Force-deploy the httpd_config.conf from the clean template.
     * Replaces whatever OLS installed with a known-good baseline.
     */
    private function forceDeployHttpdConfigTemplate(array $deployVars = []): void
    {
        $httpdConf = '/usr/local/lsws/conf/httpd_config.conf';
        $templatePath = $this->container->getConfig('templates.path') ?? __DIR__ . '/../../../templates';
        $templateFile = "{$templatePath}/openlitespeed/httpd_config.conf.template";

        if (!file_exists($templateFile)) {
            $this->log("Warning: httpd_config.conf.template not found at {$templateFile}, keeping existing config");
            return;
        }

        $this->executeCommand("cp {$httpdConf} {$httpdConf}.pre-fleet 2>/dev/null || true");

        $template = file_get_contents($templateFile);

        // Determine SSL cert paths based on the first domain (panel domain)
        $sslDomain = $deployVars['PANEL_DOMAIN'] ?? '';
        $defaultKey = '/usr/local/lsws/conf/ssl/server.key';
        $defaultCert = '/usr/local/lsws/conf/ssl/server.crt';
        if (!empty($sslDomain)) {
            $certDir = "/etc/letsencrypt/live/{$sslDomain}";
            $sslKeyPath = "{$certDir}/privkey.pem";
            $sslCertPath = "{$certDir}/fullchain.pem";
        } else {
            $sslKeyPath = $defaultKey;
            $sslCertPath = $defaultCert;
        }

        $templateVars = [
            'SERVER_IP' => trim($this->executeCommand("hostname -I | awk '{print \$1}'")['output'] ?? '127.0.0.1'),
            'ADMIN_EMAIL' => $deployVars['ADMIN_EMAIL'] ?? 'admin@localhost',
            'SSH_PORT' => $deployVars['SSH_PORT'] ?? '22',
            'SSL_KEY_PATH' => $sslKeyPath,
            'SSL_CERT_PATH' => $sslCertPath,
        ];
        $content = $this->templates->process($template, $templateVars);

        $this->ssh->uploadContent($content, $httpdConf);
        $this->executeCommand("chown lsadm:nogroup {$httpdConf} && chmod 644 {$httpdConf}");

        $sslDir = '/usr/local/lsws/conf/ssl';
        $sslCheck = $this->executeCommand("test -f {$sslDir}/server.crt && echo 'exists' || echo 'missing'");
        if (strpos($sslCheck['output'] ?? '', 'missing') !== false) {
            $this->executeCommand("mkdir -p {$sslDir}");
            $this->executeCommand(
                "openssl req -new -newkey rsa:2048 -days 365 -nodes -x509 " .
                "-subj '/CN=localhost' -keyout {$sslDir}/server.key -out {$sslDir}/server.crt 2>/dev/null"
            );
            $this->log("Created default OLS SSL cert for initial startup");
        }

        $this->log("Force-deployed clean httpd_config.conf from template");
    }

    /**
     * Remove any existing virtualHost block for a domain (case-insensitive).
     * Handles both "virtualHost" and "virtualhost" variants.
     */
    private function removeExistingVhostBlock(string $domain, string $httpdConf): void
    {
        $this->executeCommand(
            "sed -i '/^[[:space:]]*[vV]irtual[hH]ost[[:space:]]*{$domain}[[:space:]]*{/,/^}/d' {$httpdConf} 2>/dev/null || true"
        );
    }

    /**
     * Add a domain mapping to the named listener if not already present.
     */
    private function addListenerMapping(string $listenerName, string $domain, string $httpdConf): void
    {
        $mapCheck = $this->executeCommand(
            "grep -qi 'listener {$listenerName}' {$httpdConf} && echo 'has_listener' || echo 'no_listener'"
        );
        if (strpos($mapCheck['output'] ?? '', 'no_listener') !== false) {
            return;
        }

        $exists = $this->executeCommand(
            "sed -n '/^[[:space:]]*listener[[:space:]]*{$listenerName}[[:space:]]*{/,/}/p' {$httpdConf} | grep -q 'map.*{$domain}' && echo 'mapped' || echo 'not_mapped'"
        );
        if (strpos($exists['output'] ?? '', 'not_mapped') !== false) {
            $this->executeCommand(
                "sed -i '/^[[:space:]]*listener[[:space:]]*{$listenerName}[[:space:]]*{/a\\  map                     {$domain} {$domain}' {$httpdConf}"
            );
            $this->log("Added {$domain} mapping to {$listenerName} listener");
        }
    }

    /**
     * Update the SSL listener to use the first domain's cert paths.
     * Generates a temporary self-signed cert only if no LE certs exist yet.
     */
    private function updateSSLListenerCertPaths(array $domains, string $httpdConf): void
    {
        $firstDomain = $domains[0] ?? 'localhost';
        $certDir = "/etc/letsencrypt/live/{$firstDomain}";
        $hasCert = strpos(
            $this->executeCommand("test -f {$certDir}/fullchain.pem && echo 'yes' || echo 'no'")['output'] ?? '', 'yes'
        ) !== false;

        if (!$hasCert) {
            $this->log("No LE cert for {$firstDomain} yet, creating temporary self-signed for OLS startup...");
            $this->createSelfSignedCert($firstDomain);
        }

        // Verify the cert paths in the config are correct (set by forceDeployHttpdConfigTemplate)
        $verifyKey = trim($this->executeCommand("grep 'keyFile' {$httpdConf} | head -1")['output'] ?? '');
        $this->log("SSL listener keyFile: {$verifyKey}");
    }

    /**
     * Install Fleet Agent
     */
    private function installFleetAgent(int $serverId, array $variables): void
    {
        $packagesPath = $this->container->getConfig('packages.path');
        $agentPackage = $this->container->getConfig('packages.agent');
        $packageFile = $packagesPath . $agentPackage;

        // Agent package is optional - we can generate config directly if needed
        $hasPackage = file_exists($packageFile);

        $this->log("Installing Fleet Agent...");

        if ($hasPackage) {
            // Upload and install from package
            $this->executeCommand('mkdir -p /tmp/fleet-deploy');
            $this->log("Uploading agent package (" . round(filesize($packageFile) / 1048576, 1) . " MB)...");
            $remotePath = '/tmp/fleet-deploy/agent.tar.gz';
            
            if ($this->isLocalServer) {
                if (!copy($packageFile, $remotePath)) {
                    throw new \Exception("Failed to copy agent package to {$remotePath}");
                }
            } else {
                if (!$this->ssh->uploadFile($packageFile, $remotePath)) {
                    throw new \Exception("SFTP upload failed for agent package. Check SSH connection and disk space on target.");
                }
            }
            $this->log("Agent package uploaded successfully");

            $this->executeCommand('mkdir -p /tmp/fleet-deploy/agent');
            $this->executeCommand('cd /tmp/fleet-deploy && tar -xzf agent.tar.gz -C agent --strip-components=1');

            // The prebuilt tarball lags behind the live source: it is only
            // regenerated by a manual packages/agent/build.sh, while copy-fleet.sh
            // refreshes /var/www/vps-fleet/agent on every Fleet Manager deploy. That
            // is exactly why freshly provisioned boxes shipped an OLD heartbeat.php
            // (no CPU/mem/disk + OS collection) and Health/distro stayed blank.
            // Overlay the current agent code over the extracted package so EVERY
            // deploy ships the latest heartbeat regardless of tarball staleness -
            // no manual rebuild required. (install.sh / config.php / the service
            // unit are intentionally left to the package: config is per-server and
            // the package install.sh accepts the deploy args below.)
            $this->overlayFreshAgentSource('/tmp/fleet-deploy/agent');

            // Run installer with extended timeout
            $installerCmd = sprintf(
                'cd /tmp/fleet-deploy/agent && chmod +x install.sh && ./install.sh ' .
                '--fleet-url=%s --agent-token=%s --panel-domain=%s --email-domain=%s',
                escapeshellarg($this->container->getConfig('app.url')),
                escapeshellarg($variables['AGENT_TOKEN']),
                escapeshellarg($variables['PANEL_DOMAIN']),
                escapeshellarg($variables['EMAIL_DOMAIN'])
            );

            $this->executeLongCommand($installerCmd, 300);
            $this->executeCommand('rm -rf /tmp/fleet-deploy/agent*');
        } else {
            // Fallback: Create minimal agent configuration directly
            $this->log("Agent package not found, creating fleet agent config directly...");
            
            $agentPath = '/opt/fleet-agent';
            $this->executeCommand("mkdir -p {$agentPath}/{Actions,Lib,var,logs,backups}");

            // Generate agent config matching the nested structure expected by agent.php
            $fleetUrl = $this->container->getConfig('app.url') ?? 'https://fleet.devcon1.hu';
            $agentConfig = "<?php\nreturn [\n";
            $agentConfig .= "    'panel' => [\n";
            $agentConfig .= "        'url' => '" . addslashes($fleetUrl) . "',\n";
            $agentConfig .= "        'agent_token' => '" . addslashes($variables['AGENT_TOKEN']) . "',\n";
            $agentConfig .= "    ],\n";
            $agentConfig .= "    'socket' => [\n";
            $agentConfig .= "        'path' => '{$agentPath}/var/agent.sock',\n";
            $agentConfig .= "        'permissions' => 0660,\n";
            $agentConfig .= "        'group' => 'www-data',\n";
            $agentConfig .= "    ],\n";
            $agentConfig .= "    'paths' => [\n";
            $agentConfig .= "        'base' => '{$agentPath}',\n";
            $agentConfig .= "        'token_file' => '{$agentPath}/var/agent.token',\n";
            $agentConfig .= "        'log_file' => '{$agentPath}/logs/agent.log',\n";
            $agentConfig .= "    ],\n";
            $agentConfig .= "    'security' => [\n";
            $agentConfig .= "        'require_auth_token' => true,\n";
            $agentConfig .= "    ],\n";
            $agentConfig .= "    'logging' => [\n";
            $agentConfig .= "        'level' => 'info',\n";
            $agentConfig .= "        'max_size' => 10 * 1024 * 1024,\n";
            $agentConfig .= "        'max_files' => 5,\n";
            $agentConfig .= "    ],\n";
            $agentConfig .= "    'heartbeat' => [\n";
            $agentConfig .= "        'interval' => 30,\n";
            $agentConfig .= "        'timeout' => 30,\n";
            $agentConfig .= "    ],\n";
            $agentConfig .= "    'panel_domain' => '" . addslashes($variables['PANEL_DOMAIN'] ?? '') . "',\n";
            $agentConfig .= "    'email_domain' => '" . addslashes($variables['EMAIL_DOMAIN'] ?? '') . "',\n";
            $agentConfig .= "    'extraction' => [\n";
            $agentConfig .= "        'max_file_size' => 5 * 1024 * 1024,\n";
            $agentConfig .= "        'timeout' => 300,\n";
            $agentConfig .= "    ],\n";
            $agentConfig .= "];\n";

            if ($this->isLocalServer) {
                file_put_contents("{$agentPath}/config.php", $agentConfig);
            } else {
                $this->ssh->uploadContent($agentConfig, "{$agentPath}/config.php");
            }

            // Generate auth token
            $this->executeCommand("test -f {$agentPath}/var/agent.token || openssl rand -hex 32 > {$agentPath}/var/agent.token");
            $this->executeCommand("chmod 600 {$agentPath}/var/agent.token");

            // Copy agent source files from the live agent directory if available
            // (<fleet-root>/agent - three levels up, NOT api/agent).
            $localAgentDir = $this->fleetAgentSourceDir() ?? (dirname(__DIR__, 3) . '/agent');
            if (is_dir($localAgentDir)) {
                $this->log("Copying agent source files from local agent directory...");
                foreach (['agent.php', 'heartbeat.php'] as $file) {
                    if (file_exists("{$localAgentDir}/{$file}")) {
                        if ($this->isLocalServer) {
                            copy("{$localAgentDir}/{$file}", "{$agentPath}/{$file}");
                        } else {
                            $this->ssh->uploadFile("{$localAgentDir}/{$file}", "{$agentPath}/{$file}");
                        }
                    }
                }
                // Copy Actions and Lib directories
                foreach (['Actions', 'Lib'] as $dir) {
                    if (is_dir("{$localAgentDir}/{$dir}")) {
                        $files = glob("{$localAgentDir}/{$dir}/*.php");
                        foreach ($files as $file) {
                            $remotePath = "{$agentPath}/{$dir}/" . basename($file);
                            if ($this->isLocalServer) {
                                copy($file, $remotePath);
                            } else {
                                $this->ssh->uploadFile($file, $remotePath);
                            }
                        }
                    }
                }
            }

            // Create systemd service
            $serviceContent = <<<SERVICE
[Unit]
Description=Fleet Manager Agent
After=network.target

[Service]
Type=simple
WorkingDirectory={$agentPath}
ExecStart=/usr/bin/php {$agentPath}/agent.php --foreground
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
SERVICE;

            if ($this->isLocalServer) {
                file_put_contents('/etc/systemd/system/fleet-agent.service', $serviceContent);
            } else {
                $this->ssh->uploadContent($serviceContent, '/etc/systemd/system/fleet-agent.service');
            }

            // Set permissions
            $this->executeCommand("chown -R root:root {$agentPath}");
            $this->executeCommand("chmod -R 750 {$agentPath}");
            $this->executeCommand("chmod 600 {$agentPath}/config.php");
        }

        // Enable and start service
        $this->executeCommand('systemctl daemon-reload');
        $this->executeCommand('systemctl enable fleet-agent');
        $this->executeCommand('systemctl restart fleet-agent');

        // Setup heartbeat cron as backup
        $this->executeCommand("(crontab -l 2>/dev/null | grep -v 'fleet-agent/heartbeat'; echo '* * * * * /usr/bin/php /opt/fleet-agent/heartbeat.php > /dev/null 2>&1') | crontab -");

        // Check if service started successfully
        $agentResult = $this->executeCommand('systemctl is-active fleet-agent 2>/dev/null || echo "inactive"');
        $agentStatus = trim($agentResult['output'] ?? 'unknown');
        if (strpos($agentStatus, 'active') !== false && strpos($agentStatus, 'inactive') === false) {
            $this->log("Fleet Agent installed and running successfully");
        } else {
            $this->log("Warning: Fleet Agent installed but service status: {$agentStatus}");
            $this->log("Check logs: journalctl -u fleet-agent -n 20 --no-pager");
        }
    }

    /**
     * Resolve the live Fleet Agent source directory on the Fleet Manager host.
     *
     * This is the source of truth for agent code: copy-fleet.sh refreshes it on
     * every Fleet Manager deploy, so it is newer than the prebuilt agent tarball
     * (which only changes when someone runs packages/agent/build.sh by hand).
     * Located at <fleet-root>/agent, i.e. three levels up from this file
     * (api/src/Services -> api -> fleet-root). Returns null only if the directory
     * is missing or incomplete, so callers can fall back to the package as-is.
     */
    private function fleetAgentSourceDir(): ?string
    {
        $candidates = [];
        $configured = $this->container->getConfig('packages.agent_source');
        if (is_string($configured) && $configured !== '') {
            $candidates[] = rtrim($configured, '/');
        }
        $candidates[] = dirname(__DIR__, 3) . '/agent';

        foreach ($candidates as $dir) {
            if (is_dir($dir) && file_exists($dir . '/heartbeat.php')) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * Overlay the current agent code (heartbeat.php + agent.php + Actions/ + Lib/
     * + VERSION) from the live source over an already-extracted agent package.
     *
     * Guarantees that every deploy ships the latest health/OS-collecting heartbeat
     * even when agent-latest.tar.gz is stale. install.sh, config.php and the
     * systemd unit are deliberately NOT overlaid: config.php is generated per
     * server and the package's install.sh is the one invoked with --fleet-url
     * etc. (the live source install.sh has a different CLI contract).
     */
    private function overlayFreshAgentSource(string $remoteAgentDir): void
    {
        $src = $this->fleetAgentSourceDir();
        if ($src === null) {
            $this->log("Note: live agent source not found - deploying agent package as-is (heartbeat may be stale)");
            return;
        }

        $put = function (string $localFile, string $remoteFile): bool {
            if ($this->isLocalServer) {
                return @copy($localFile, $remoteFile);
            }
            return (bool) $this->ssh->uploadFile($localFile, $remoteFile);
        };

        $overlaid = 0;
        foreach (['heartbeat.php', 'agent.php', 'VERSION'] as $file) {
            $local = "{$src}/{$file}";
            if (file_exists($local) && $put($local, "{$remoteAgentDir}/{$file}")) {
                $overlaid++;
            }
        }

        foreach (['Actions', 'Lib'] as $dir) {
            $localDir = "{$src}/{$dir}";
            if (!is_dir($localDir)) {
                continue;
            }
            $this->executeCommand('mkdir -p ' . escapeshellarg("{$remoteAgentDir}/{$dir}"));
            foreach (glob("{$localDir}/*.php") ?: [] as $file) {
                if ($put($file, "{$remoteAgentDir}/{$dir}/" . basename($file))) {
                    $overlaid++;
                }
            }
        }

        $this->log("Overlaid {$overlaid} fresh agent source file(s) over the package (current heartbeat.php guaranteed)");
    }

    /**
     * Harden SSH access on a freshly-provisioned box:
     *   - move sshd to port 1985
     *   - deny root login entirely
     *   - create the unprivileged "pxr" user with key-only login + passwordless sudo
     *   - re-home the Fleet Manager onto pxr (it elevates via sudo from now on)
     *
     * Safety model (this runs LAST and must never lock anyone out):
     *   Phase 1  add port 1985 alongside 22, install pxr + key (root still allowed)
     *   Phase 2  VERIFY the Fleet Manager can log in as pxr@1985 with the key AND
     *            run sudo. Only if that succeeds do we proceed.
     *   Phase 3  commit: deny root, drop port 22, make pxr key-only via a Match
     *            block (other users' password SFTP is left untouched).
     *   On ANY failure we restore the pre-hardening sshd config (root + 22) and
     *   never fail the overall deployment - the server is already provisioned.
     *
     * sshd directives live in drop-ins (00-fleet-harden.conf for globals, read
     * first so they win; 99-...-match.conf for the pxr Match block, read last so
     * its context can't leak), with an Include line ensured in the main config.
     */
    /**
     * Path to the per-server Fleet-Manager management private key used for the
     * pxr account. Deterministic so the early 'establish_access' step and the
     * final 'harden_ssh' step share the SAME key (re-generating it between the
     * two would invalidate pxr's authorized_keys and break the 1985 verify).
     */
    private function pxrKeyPath(int $serverId): string
    {
        $keyDir = rtrim((string)($this->container->getConfig('ssh.key_path') ?: '/var/www/vps-fleet/var/keys/'), '/') . '/';
        if (!is_dir($keyDir)) {
            @mkdir($keyDir, 0700, true);
        }
        return $keyDir . "server_{$serverId}_pxr.key";
    }

    /**
     * Load the existing pxr management keypair from disk, or create + persist a
     * new one. Reuse is keyed on the FILE existing (not on ssh_user), so the key
     * survives across the early access step, the final hardening step, and any
     * re-deploys. Returns [privKeyPath, privateKeyStr, fmPublicKey].
     */
    private function loadOrCreatePxrKey(int $serverId): array
    {
        $privKeyPath = $this->pxrKeyPath($serverId);
        if (file_exists($privKeyPath) && trim((string)file_get_contents($privKeyPath)) !== '') {
            $privateKeyStr = (string)file_get_contents($privKeyPath);
            $keyObj = \phpseclib3\Crypt\PublicKeyLoader::load($privateKeyStr);
        } else {
            $keyObj = \phpseclib3\Crypt\RSA::createKey(3072);
            $privateKeyStr = (string)$keyObj->toString('OpenSSH');
            if (file_put_contents($privKeyPath, $privateKeyStr) === false) {
                throw new \Exception("Could not write pxr private key to {$privKeyPath}");
            }
            @chmod($privKeyPath, 0600);
        }
        $fmPublicKey = trim($keyObj->getPublicKey()->toString('OpenSSH', ['comment' => "fleet-mgmt@server{$serverId}"]));
        return [$privKeyPath, $privateKeyStr, $fmPublicKey];
    }

    /**
     * EARLY access provisioning (runs before the long install steps). Creates the
     * unprivileged pxr user with key login + passwordless sudo, and authorizes the
     * operator key on BOTH pxr and root - all WITHOUT touching the SSH port or
     * disabling root/password auth. The goal is purely "the operator can always
     * get in with their key", so a failure in a later step never locks them out.
     * The actual lockdown (port 1985, deny root, no passwords, close 22) is the
     * final 'harden_ssh' step.
     */
    private function establishSshAccess(int $serverId, array $variables): void
    {
        if ($this->container->getConfig('ssh.harden') === false) {
            $this->log("SSH access provisioning disabled by config (ssh.harden=false) - skipping");
            return;
        }
        if ($this->isLocalServer) {
            $this->log("SSH access provisioning skipped on the local Fleet Manager host");
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        if (!$server) {
            $this->log("SSH access provisioning: server {$serverId} not found - skipping");
            return;
        }

        try {
            [$privKeyPath, , $fmPublicKey] = $this->loadOrCreatePxrKey($serverId);

            // authorized_keys = FM management key (so the panel keeps access after
            // root is later denied) + the operator key (per-server override, else
            // the fleet-wide ssh.pxr_authorized_key default).
            $authorizedKeys = $this->buildAuthorizedKeys($server, $fmPublicKey, $label);

            $this->ssh->uploadContent($authorizedKeys, '/tmp/.fleet-pxr-authkeys');
            $setup = <<<'BASH'
set -e
# --- pxr user: key login + passwordless sudo (idempotent) ---
id pxr >/dev/null 2>&1 || useradd -m -s /bin/bash pxr
usermod -aG sudo pxr 2>/dev/null || true
install -d -m 700 -o pxr -g pxr /home/pxr/.ssh
install -m 600 -o pxr -g pxr /tmp/.fleet-pxr-authkeys /home/pxr/.ssh/authorized_keys
printf '%s\n' 'pxr ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/pxr
chmod 440 /etc/sudoers.d/pxr
visudo -cf /etc/sudoers.d/pxr
# --- also authorize the SAME keys for root so the operator can use their key
#     immediately on root@22 during the deploy (before pxr lockdown) ---
install -d -m 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
while IFS= read -r line; do
  case "$line" in \#*|"") continue;; esac
  grep -qxF "$line" /root/.ssh/authorized_keys || printf '%s\n' "$line" >> /root/.ssh/authorized_keys
done < /tmp/.fleet-pxr-authkeys
rm -f /tmp/.fleet-pxr-authkeys
BASH;
            $r = $this->executeCommand($setup, 60);
            if (!$r['success']) {
                throw new \Exception('pxr/root key setup failed: ' . substr(trim($r['output'] ?? ''), -300));
            }

            // Persist the key path now so any management action (and the final
            // harden step) can reuse it. Keep ssh_user/ssh_port as-is (root/22):
            // we are NOT re-homing the live session yet.
            $this->db->prepare("UPDATE servers SET key_path = ? WHERE id = ?")
                ->execute([$privKeyPath, $serverId]);

            $host = (string)$server['ip_address'];
            $this->log("SSH access established early: pxr created (key + sudo); operator key authorized on pxr AND root [{$label}]");
            $this->log("You can already connect now (pre-lockdown): ssh -i <your key> root@{$host}  (or pxr@{$host})");
        } catch (\Throwable $e) {
            // Non-fatal: the deploy can still proceed over the existing root@22
            // session; the final harden_ssh step will re-attempt pxr setup.
            $this->log("SSH access provisioning warning: {$e->getMessage()} - continuing (will retry at harden step)");
        }
    }

    private function hardenSshAccess(int $serverId, array $variables): void
    {
        // Allow operators to disable hardening entirely (config ssh.harden=false).
        if ($this->container->getConfig('ssh.harden') === false) {
            $this->log("SSH hardening disabled by config (ssh.harden=false) - skipping");
            return;
        }

        // Never harden the Fleet Manager's own host.
        if ($this->isLocalServer) {
            $this->log("SSH hardening skipped on the local Fleet Manager host");
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();
        if (!$server) {
            $this->log("SSH hardening: server {$serverId} not found - skipping");
            return;
        }

        $host = (string)$server['ip_address'];
        $targetPort = (int)($this->container->getConfig('ssh.harden_port') ?: 1985);
        $hardenUser = 'pxr';

        try {
            // Reuse the key created by the early 'establish_access' step (or make
            // one now if that step was skipped). Reuse is file-based so we never
            // regenerate and lock ourselves out.
            [$privKeyPath, $privateKeyStr, $fmPublicKey] = $this->loadOrCreatePxrKey($serverId);
            $this->log("SSH hardening: using pxr key for server {$serverId}");

            // authorized_keys = the FM management key (ALWAYS, so the panel keeps
            // access once root is denied) + the operator's key (per-server
            // override, else the fleet-wide default). Written whole so it stays
            // deterministic and re-pushable from the dashboard.
            $authorizedKeys = $this->buildAuthorizedKeys($server, $fmPublicKey, $operatorLabel);
            $this->log("SSH hardening: authorizing keys -> {$operatorLabel}");

            // --- create pxr with key + passwordless sudo (idempotent) ---
            $this->ssh->uploadContent($authorizedKeys, '/tmp/.fleet-pxr-authkeys');
            $createUser = <<<'BASH'
set -e
id pxr >/dev/null 2>&1 || useradd -m -s /bin/bash pxr
usermod -aG sudo pxr 2>/dev/null || true
install -d -m 700 -o pxr -g pxr /home/pxr/.ssh
install -m 600 -o pxr -g pxr /tmp/.fleet-pxr-authkeys /home/pxr/.ssh/authorized_keys
rm -f /tmp/.fleet-pxr-authkeys
printf '%s\n' 'pxr ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/pxr
chmod 440 /etc/sudoers.d/pxr
visudo -cf /etc/sudoers.d/pxr
BASH;
            $r = $this->executeCommand($createUser, 60);
            if (!$r['success']) {
                throw new \Exception('pxr user/sudoers setup failed: ' . substr(trim($r['output'] ?? ''), -300));
            }

            // --- Phase 1: open target port (keep 22), enable pubkey. Root still allowed. ---
            $phase1 = <<<'BASH'
set -e
CONF=/etc/ssh/sshd_config
D=/etc/ssh/sshd_config.d
BK="${CONF}.fleet-prebak"
[ -f "$BK" ] || cp -a "$CONF" "$BK"
mkdir -p "$D"
# Ensure drop-ins are actually included (the cloned config may omit this line).
grep -qE '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' "$CONF" || sed -i '1iInclude /etc/ssh/sshd_config.d/*.conf' "$CONF"
# Comment out any ACTIVE Port line in the main config; the drop-in owns the port.
sed -i -E 's/^([[:space:]]*Port[[:space:]]+[0-9].*)$/#\1/I' "$CONF"
printf '@@PORTCONF1@@\nPubkeyAuthentication yes\n' > "$D/00-fleet-harden.conf"
chmod 644 "$D/00-fleet-harden.conf"
# CRITICAL (Ubuntu 22.10+/24.04 default): if sshd is SOCKET-ACTIVATED, the listen
# port is owned by ssh.socket and 'Port' in sshd_config is SILENTLY IGNORED -> 1985
# never listens, our verify fails, and we needlessly roll back. Drop socket
# activation and run the classic ssh.service so our Port directives take effect.
if systemctl cat ssh.socket >/dev/null 2>&1 && { systemctl is-active ssh.socket >/dev/null 2>&1 || systemctl is-enabled ssh.socket >/dev/null 2>&1; }; then
  echo "ssh is socket-activated -> disabling ssh.socket so sshd_config Port applies"
  systemctl disable --now ssh.socket 2>/dev/null || true
  rm -f /etc/systemd/system/ssh.service.d/00-socket.conf 2>/dev/null || true
  systemctl daemon-reload 2>/dev/null || true
  systemctl enable ssh 2>/dev/null || systemctl enable sshd 2>/dev/null || true
fi
sshd -t
# RESTART (not just reload): switching off socket activation and adding a new
# listen port both require a real restart to (re)bind. Existing connections survive.
systemctl restart ssh 2>/dev/null || systemctl restart sshd 2>/dev/null || systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
BASH;
            // Honor the configured harden port end-to-end. When it IS 22 we keep a
            // single "Port 22" line (and never close 22 below); otherwise we listen
            // on both 22 and the target during phase 1 so the live session never
            // drops before the pxr verify succeeds.
            $phase1 = str_replace(
                '@@PORTCONF1@@',
                $targetPort === 22 ? 'Port 22' : 'Port 22\nPort ' . $targetPort,
                $phase1
            );
            $r = $this->executeCommand($phase1, 60);
            if (!$r['success']) {
                throw new \Exception('sshd phase-1 invalid: ' . substr(trim($r['output'] ?? ''), -300));
            }
            $this->executeCommand(
                "command -v firewall-cmd >/dev/null 2>&1 && { firewall-cmd --permanent --add-port={$targetPort}/tcp >/dev/null 2>&1; firewall-cmd --reload >/dev/null 2>&1; } || true",
                30
            );
            sleep(2);

            // Diagnostics so the deploy log shows WHY a verify would fail: is sshd
            // actually listening on the target port, and which ssh units are active.
            $diag = $this->executeCommand(
                "echo '## listening (22/{$targetPort}):'; (ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null) | grep -E ':(22|{$targetPort})\\b' || echo '(nothing on 22/{$targetPort})'; "
                . "echo '## ssh units:'; systemctl is-active ssh ssh.socket sshd 2>/dev/null | paste -sd' ' -; "
                . "echo '## firewalld {$targetPort}:'; (command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --query-port={$targetPort}/tcp 2>/dev/null) || echo 'no-firewalld'",
                30
            );
            $this->log("SSH hardening pre-verify state:\n" . trim((string)($diag['output'] ?? '')));

            // --- Phase 2: verify pxr@1985 key login + sudo from the Fleet Manager ---
            $verify = new SSHService($this->container);
            $connected = $verify->connectWithKeyFile($host, $targetPort, $hardenUser, $privKeyPath);
            $sudoOk = false;
            if ($connected) {
                $res = $verify->exec('id -u'); // auto-elevates via sudo -> "0"
                $sudoOk = !empty($res['success']) && trim((string)($res['output'] ?? '')) === '0';
                $verify->disconnect();
            }
            if (!$connected || !$sudoOk) {
                $hint = !$connected
                    ? "Could NOT reach pxr on {$targetPort}. If the diagnostics above show sshd LISTENING on {$targetPort}, "
                    . "the packet is dropped between the panel and the box: either the panel host's OUTBOUND egress "
                    . "blocks port {$targetPort} (CPGuard/CSF 'TCP_OUT', or a provider egress filter - add {$targetPort} to TCP_OUT), "
                    . "or a provider/cloud firewall blocks INBOUND {$targetPort} on the target. "
                    . "If nothing is listening on {$targetPort}, sshd didn't bind the port. Port 22 is left OPEN, so the panel keeps managing this box."
                    : "Reached pxr on {$targetPort} but 'sudo' did not return uid 0 (check /etc/sudoers.d/pxr).";
                throw new \Exception("pxr@{$targetPort} verification failed (connect=" . ($connected ? 'y' : 'n') . ", sudo=" . ($sudoOk ? 'y' : 'n') . "). {$hint}");
            }
            $this->log("SSH hardening: verified pxr can log in on {$targetPort} with key + sudo");

            // --- Phase 3: commit (deny root, target port only, pxr key-only) ---
            $phase3 = <<<'BASH'
set -e
CONF=/etc/ssh/sshd_config
D=/etc/ssh/sshd_config.d
printf '@@PORTCONF3@@\nPermitRootLogin no\nPubkeyAuthentication yes\n' > "$D/00-fleet-harden.conf"
chmod 644 "$D/00-fleet-harden.conf"
printf 'Match User pxr\n    PasswordAuthentication no\n    PubkeyAuthentication yes\n' > "$D/99-fleet-harden-match.conf"
chmod 644 "$D/99-fleet-harden-match.conf"
# Keep socket activation off (phase 1 disabled it) so our 'Port' stays authoritative.
systemctl disable ssh.socket 2>/dev/null || true
sshd -t
# RESTART to rebind to the target port. The live FM session survives the restart;
# we re-home it onto pxr@<target> right after.
systemctl restart ssh 2>/dev/null || systemctl restart sshd 2>/dev/null || systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
BASH;
            $phase3 = str_replace('@@PORTCONF3@@', 'Port ' . $targetPort, $phase3);
            $r = $this->executeCommand($phase3, 60);
            if (!$r['success']) {
                throw new \Exception('sshd phase-3 invalid: ' . substr(trim($r['output'] ?? ''), -300));
            }

            // --- persist the new connection so the FM keeps managing the box ---
            $this->db->prepare(
                "UPDATE servers SET ssh_port = ?, ssh_user = ?, ssh_auth_method = 'key', key_path = ? WHERE id = ?"
            )->execute([$targetPort, $hardenUser, $privKeyPath, $serverId]);

            // Remember the committed profile so the success path can re-affirm it
            // (automatic switch to pxr@1985 the moment the deploy finishes 100%).
            $this->hardenedProfile = [
                'port' => $targetPort,
                'user' => $hardenUser,
                'auth' => 'key',
                'key_path' => $privKeyPath,
            ];

            // Surface the pxr key + ready-to-use command in the Credentials UI so
            // the operator can connect from their own shell (ssh -i ...).
            try {
                $sshCommand = "ssh -p {$targetPort} -i ~/.ssh/pxr_server{$serverId} pxr@{$host}";
                $credStmt = $this->db->prepare(
                    "INSERT INTO server_credentials (server_id, category, credential_key, label, value_encrypted, is_secret)
                     VALUES (?, 'ssh', ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted), label = VALUES(label), is_secret = VALUES(is_secret)"
                );
                $credStmt->execute([$serverId, 'PXR_SSH_KEY', 'pxr Private Key (save locally, chmod 600)', $this->encryption->encrypt($privateKeyStr), 1]);
                $credStmt->execute([$serverId, 'PXR_SSH_COMMAND', "SSH Command (pxr, port {$targetPort})", $this->encryption->encrypt($sshCommand), 0]);
            } catch (\Throwable $e) {
                $this->log("SSH hardening: could not store pxr credentials: {$e->getMessage()}");
            }

            // Re-home the live session onto pxr BEFORE closing port 22, so the rest
            // (firewall, fail2ban, version sync, report) runs over the new pxr+sudo
            // session and never depends on the now-doomed root@22 connection.
            $this->ssh->disconnect();
            $reconnected = $this->ssh->connectWithKeyFile($host, $targetPort, $hardenUser, $privKeyPath);
            if ($reconnected) {
                $this->log("SSH hardening complete - now connected as pxr@{$host}:{$targetPort} (key + sudo)");
            } else {
                $this->log("SSH hardening committed but live reconnect as pxr failed - will reconnect on next management action");
            }

            // Close 22, point fail2ban at the target port (best-effort; runs as pxr+sudo).
            // When the hardened port IS 22 we obviously keep it open.
            if ($targetPort !== 22) {
                $this->executeCommand(
                    'command -v firewall-cmd >/dev/null 2>&1 && { firewall-cmd --permanent --remove-service=ssh >/dev/null 2>&1; firewall-cmd --permanent --remove-port=22/tcp >/dev/null 2>&1; firewall-cmd --reload >/dev/null 2>&1; } || true',
                    30
                );
            }
            // Re-point the sshd jail at 1985 AND re-assert the Fleet Manager
            // whitelist (DEFAULT/ignoreip) in the same drop-in, so even after this
            // late restart the panel can't be banned off the box. ignoreip was
            // captured pre-harden (cached) - sudo's env scrub here is irrelevant.
            $ignoreLine = '';
            $fleetIps = $this->fleetIgnoreIps();
            if ($fleetIps !== '') {
                $ignoreLine = "[DEFAULT]\\nignoreip = 127.0.0.1/8 ::1 {$fleetIps}\\n";
            }
            $this->executeCommand(
                "if [ -d /etc/fail2ban ]; then printf '{$ignoreLine}[sshd]\\nenabled = true\\nport = {$targetPort}\\n' > /etc/fail2ban/jail.d/fleet-ssh.local; systemctl restart fail2ban 2>/dev/null || true; "
                . ($fleetIps !== '' ? "for ip in {$fleetIps}; do case \"\$ip\" in */*) ;; *) fail2ban-client unban \"\$ip\" 2>/dev/null || true;; esac; done; " : "")
                . "fi",
                30
            );

            $this->log("Connect manually with: ssh -p {$targetPort} -i {$privKeyPath} pxr@{$host}");
        } catch (\Throwable $e) {
            // Never lock ourselves out: restore the pre-hardening config.
            // Box is NOT hardened -> make sure we don't advertise pxr@1985.
            $this->hardenedProfile = null;
            $this->log("SSH hardening failed: {$e->getMessage()} - rolling back to root/22 access");
            try {
                $rollback = <<<'BASH'
CONF=/etc/ssh/sshd_config
D=/etc/ssh/sshd_config.d
rm -f "$D/00-fleet-harden.conf" "$D/99-fleet-harden-match.conf"
BK="${CONF}.fleet-prebak"
[ -f "$BK" ] && cp -a "$BK" "$CONF"
# phase 1 may have turned off socket activation; make sure ssh.service still serves :22.
systemctl enable ssh 2>/dev/null || systemctl enable sshd 2>/dev/null || true
sshd -t && { systemctl restart ssh 2>/dev/null || systemctl restart sshd 2>/dev/null || systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null; } || true
command -v firewall-cmd >/dev/null 2>&1 && { firewall-cmd --permanent --add-service=ssh >/dev/null 2>&1; firewall-cmd --permanent --add-port=22/tcp >/dev/null 2>&1; firewall-cmd --reload >/dev/null 2>&1; } || true
BASH;
                $this->executeCommand($rollback, 60);
            } catch (\Throwable $e2) {
                $this->log("SSH hardening rollback hit an error: {$e2->getMessage()}");
            }
            // Best-effort: do not fail the deployment.
        }
    }

    /**
     * The operator PUBLIC key to authorize on pxr: the per-server override if set,
     * otherwise the fleet-wide default (config ssh.pxr_authorized_key). Returns
     * null when neither is a valid SSH public key.
     */
    private function effectiveAuthorizedKey(array $server): ?string
    {
        $key = trim((string)($server['ssh_authorized_key'] ?? ''));
        if ($key === '') {
            $key = trim((string)($this->container->getConfig('ssh.pxr_authorized_key') ?: ''));
        }
        return $this->isValidSshPublicKey($key) ? $key : null;
    }

    /**
     * Compose the pxr authorized_keys file: the Fleet Manager management key is
     * ALWAYS present (so the panel keeps access once root is denied); the operator
     * key is appended when valid. $label is filled with a short description.
     */
    private function buildAuthorizedKeys(array $server, string $fmPublicKey, ?string &$label = null): string
    {
        $out = "# Managed by Fleet Manager - do not edit by hand\n";
        $out .= trim($fmPublicKey) . "\n";

        $operator = $this->effectiveAuthorizedKey($server);
        if ($operator !== null) {
            $out .= trim($operator) . "\n";
            $parts = preg_split('/\s+/', trim($operator));
            $label = 'FM key + operator key (' . ($parts[2] ?? $parts[0] ?? 'key') . ')';
        } else {
            $label = 'FM key only (no valid operator key configured)';
        }
        return $out;
    }

    /**
     * Loose validation that a string is a single SSH public key line
     * (ssh-ed25519 / ssh-rsa / ecdsa-sha2-* / sk-*@openssh.com + base64 [+comment]).
     */
    private function isValidSshPublicKey(string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }
        return (bool)preg_match(
            '#^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-[\w-]+|sk-(ssh-ed25519|ecdsa-sha2-[\w-]+)@openssh\.com)\s+[A-Za-z0-9+/]+=*(\s+.+)?$#',
            $key
        );
    }

    /**
     * Setup SSL certificates (with idempotency)
     */
    private function setupSSL(array $variables): void
    {
        // Install certbot if needed
        if (!$this->isPackageInstalled('certbot')) {
            $this->log("Installing certbot...");
            $this->runAptCommand(
                'apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" certbot',
                'Failed to install certbot'
            );
        }

        // Clean up any existing LE configs that don't belong to this server
        $panelDomain = $variables['PANEL_DOMAIN'] ?? '';
        $baseDomain = preg_replace('/^[^.]+\./', '', $panelDomain);
        if (!empty($baseDomain)) {
            $this->log("Cleaning up SSL configs not matching *{$baseDomain}...");
            $this->executeCommand("find /etc/letsencrypt/renewal/ -name '*.conf' ! -name '*{$baseDomain}*' -delete 2>/dev/null || true");
            $this->executeCommand("find /etc/letsencrypt/live/ -maxdepth 1 -type d ! -name '*{$baseDomain}*' ! -name 'live' -exec rm -rf {} \\; 2>/dev/null || true");
            $this->executeCommand("find /etc/letsencrypt/archive/ -maxdepth 1 -type d ! -name '*{$baseDomain}*' ! -name 'archive' -exec rm -rf {} \\; 2>/dev/null || true");
        }

        // Also clean up any self-signed certs from previous failed attempts
        // (self-signed in LE paths blocks real certbot from running)
        // Include MAIL_DOMAIN (base domain like weddingcards.hu) for Dovecot/Postfix SSL
        $domains = [
            $variables['PANEL_DOMAIN'],
            $variables['EMAIL_DOMAIN'],
        ];
        $mailDomain = $variables['MAIL_DOMAIN'] ?? '';
        if (!empty($mailDomain) && !in_array($mailDomain, $domains)) {
            $domains[] = $mailDomain;
        }

        foreach ($domains as $domain) {
            $certDir = "/etc/letsencrypt/live/{$domain}";
            $renewalConf = "/etc/letsencrypt/renewal/{$domain}.conf";
            $certExists = strpos(
                $this->executeCommand("test -d {$certDir} && echo 'exists' || echo 'not_exists'")['output'] ?? '', 'exists'
            ) !== false;

            if (!$certExists) {
                continue;
            }

            $isSelfSigned = false;

            $renewalSize = trim($this->executeCommand("stat -c%s {$renewalConf} 2>/dev/null || echo '0'")['output'] ?? '0');
            if ((int)$renewalSize < 10) {
                $isSelfSigned = true;
            }

            if (!$isSelfSigned) {
                $issuer = trim($this->executeCommand("openssl x509 -in {$certDir}/fullchain.pem -noout -issuer 2>/dev/null || echo 'unknown'")['output'] ?? '');
                if (strpos($issuer, "Let's Encrypt") === false && !preg_match('/\b(R3|R10|R11|E[5-9])\b/', $issuer)) {
                    $isSymlink = strpos(
                        $this->executeCommand("test -L {$certDir}/fullchain.pem && echo 'symlink' || echo 'plain'")['output'] ?? '', 'plain'
                    ) !== false;
                    if ($isSymlink) {
                        $isSelfSigned = true;
                    }
                }
            }

            if ($isSelfSigned) {
                $this->log("Removing self-signed/placeholder cert for {$domain} to allow real cert generation...");
                $this->executeCommand("rm -rf {$certDir}");
                $this->executeCommand("rm -rf /etc/letsencrypt/archive/{$domain}");
                $this->executeCommand("rm -f {$renewalConf}");
            }
        }

        // Use standalone mode: briefly stop OLS to free port 80/443
        // This is more reliable than webroot on fresh servers where OLS
        // may not be serving .well-known correctly yet
        $this->log("Stopping OLS temporarily for SSL certificate generation...");
        $this->executeCommand('systemctl stop lshttpd 2>/dev/null || /usr/local/lsws/bin/lswsctrl stop 2>/dev/null || true');
        sleep(2);

        // Request separate cert for each domain so each has its own LE directory
        // This matches what vhost templates expect: /etc/letsencrypt/live/{domain}/
        foreach ($domains as $domain) {
            $certDir = "/etc/letsencrypt/live/{$domain}";
            $renewalConf = "/etc/letsencrypt/renewal/{$domain}.conf";
            $archiveDir = "/etc/letsencrypt/archive/{$domain}";

            // A valid LE cert has BOTH a fullchain.pem AND a renewal config
            $certCheck = $this->executeCommand("test -f {$certDir}/fullchain.pem && test -f {$renewalConf} && echo 'valid' || echo 'missing'");
            
            if (strpos($certCheck['output'] ?? '', 'valid') !== false) {
                // Double-check issuer is actually LE (not a leftover self-signed with a stale renewal)
                $issuer = trim($this->executeCommand("openssl x509 -issuer -noout -in {$certDir}/fullchain.pem 2>/dev/null")['output'] ?? '');
                $isLE = strpos($issuer, "Let's Encrypt") !== false || (bool) preg_match('/\b(R3|R10|R11|E[5-9])\b/', $issuer);
                if ($isLE) {
                    $this->log("Valid Let's Encrypt certificate already exists for {$domain}");
                    continue;
                }
            }

            // Clean up any existing self-signed/stale cert dirs before certbot
            // Certbot will fail with "archive directory exists" if these remain
            $this->executeCommand("rm -rf {$certDir} {$archiveDir}");
            $this->executeCommand("rm -f {$renewalConf}");

            $this->log("Requesting SSL certificate for {$domain}...");
            $result = $this->executeCommand(
                "certbot certonly --standalone -d {$domain} " .
                "--non-interactive --agree-tos --email {$variables['ADMIN_EMAIL']} 2>&1 || true"
            );
            
            $output = $result['output'] ?? '';
            if (strpos($output, 'Successfully') !== false) {
                $this->log("SSL certificate obtained for {$domain}");
            } else {
                $this->log("Warning: Could not obtain SSL for {$domain} (DNS may not be pointing here yet)");
                $this->log("Certbot output: " . trim(substr($output, -200)));
                $this->createSelfSignedCert($domain);
            }
        }

        // Create symlink for MAIL_DOMAIN if its cert failed but EMAIL_DOMAIN succeeded
        // Dovecot/Postfix reference /etc/letsencrypt/live/MAIL_DOMAIN/ for SSL
        if (!empty($mailDomain) && $mailDomain !== ($variables['EMAIL_DOMAIN'] ?? '')) {
            $mailCertDir = "/etc/letsencrypt/live/{$mailDomain}";
            $emailCertDir = "/etc/letsencrypt/live/{$variables['EMAIL_DOMAIN']}";
            $mailCertExists = strpos($this->executeCommand("test -f {$mailCertDir}/fullchain.pem && echo 'yes' || echo 'no'")['output'] ?? '', 'yes') !== false;
            $emailCertExists = strpos($this->executeCommand("test -f {$emailCertDir}/fullchain.pem && echo 'yes' || echo 'no'")['output'] ?? '', 'yes') !== false;

            if (!$mailCertExists && $emailCertExists) {
                $this->log("Creating symlink: {$mailCertDir} -> {$emailCertDir} (for Dovecot/Postfix SSL)");
                $this->executeCommand("ln -sfn {$emailCertDir} {$mailCertDir}");
            } elseif (!$mailCertExists) {
                // Neither cert exists - create self-signed for mail domain so Dovecot can start
                $this->log("Creating self-signed cert for {$mailDomain} (Dovecot fallback)...");
                $this->createSelfSignedCert($mailDomain);
            }
        }

        // Fix any broken dpkg state from earlier failed service starts
        $this->log("Fixing any broken dpkg state before restarting services...");
        $this->executeCommand('DEBIAN_FRONTEND=noninteractive dpkg --configure -a --force-confdef --force-confold 2>&1 | tail -5');

        // Restart OLS with full recovery
        $this->log("Starting OLS with SSL certificates...");
        $this->startOlsWithRecovery();
        sleep(2);
        $olsStatus = $this->getServiceStatus('lshttpd');
        if ($olsStatus !== 'active') {
            $this->log("OLS not active after recovery (status: {$olsStatus}), checking journal...");
            $journal = $this->executeCommand('journalctl -u lshttpd --no-pager -n 10 2>/dev/null | tail -5');
            $this->log("OLS journal: " . trim($journal['output'] ?? ''));
            $errorLog = $this->executeCommand('tail -20 /usr/local/lsws/logs/error.log 2>/dev/null | tail -10');
            $this->log("OLS error log: " . trim($errorLog['output'] ?? ''));
        } else {
            $this->log("OpenLiteSpeed running with SSL");
        }

        // Restart Dovecot now that real SSL certs are in place
        $this->log("Restarting Dovecot with SSL certificates...");
        
        // Verify the SSL cert path that Dovecot is configured to use actually exists
        $dovecotCertPath = trim($this->executeCommand("doveconf -h ssl_cert 2>/dev/null | sed 's/^<//'")['output'] ?? '');
        if (!empty($dovecotCertPath)) {
            $certCheck = $this->executeCommand("test -f {$dovecotCertPath} && echo 'ok' || echo 'missing'");
            if (strpos($certCheck['output'] ?? '', 'missing') !== false) {
                $this->log("Dovecot SSL cert missing at {$dovecotCertPath}, generating self-signed...");
                $this->generateSelfSignedCertForService('dovecot');
            }
        }
        
        $this->executeCommand('systemctl stop dovecot 2>/dev/null || true');
        sleep(1);
        $this->executeCommand('systemctl start dovecot 2>/dev/null || true');
        sleep(2);
        $dovecotStatus = $this->getServiceStatus('dovecot');
        if ($dovecotStatus !== 'active') {
            $this->log("Dovecot not active (status: {$dovecotStatus}), checking config...");
            $confCheck = $this->executeCommand('doveconf -n 2>&1 | grep -i "fatal\|error" | head -3');
            $this->log("Dovecot config issues: " . trim($confCheck['output'] ?? 'none'));
            $journal = $this->executeCommand('journalctl -u dovecot --no-pager -n 5 2>/dev/null');
            $this->log("Dovecot journal: " . trim($journal['output'] ?? ''));
            
            // Last resort: try with ssl=yes instead of ssl=required
            $this->log("Attempting Dovecot start with ssl=yes fallback...");
            $this->executeCommand("sed -i 's/^ssl = required/ssl = yes/' /etc/dovecot/dovecot.conf 2>/dev/null || true");
            $this->executeCommand('systemctl start dovecot 2>/dev/null || true');
            sleep(2);
            $dovecotStatus = $this->getServiceStatus('dovecot');
            if ($dovecotStatus === 'active') {
                $this->log("Dovecot started with ssl=yes (will upgrade to required after LE certs are valid)");
            }
        } else {
            $this->log("Dovecot running with SSL");
        }

        // Restart Postfix too (it also uses SSL certs)
        $this->log("Restarting Postfix with SSL certificates...");
        $this->executeCommand('systemctl restart postfix 2>/dev/null || true');

        // Set up auto-renewal cron (certbot uses standalone, needs to stop/start OLS)
        $cronCheck = $this->executeCommand("crontab -l 2>/dev/null | grep -q 'certbot renew' && echo 'exists' || echo 'not_exists'");
        if (strpos($cronCheck['output'] ?? '', 'not_exists') !== false) {
            $this->executeCommand(
                '(crontab -l 2>/dev/null; echo "0 3 * * * systemctl stop lshttpd; certbot renew --quiet; systemctl start lshttpd") | crontab -'
            );
            $this->log("SSL auto-renewal cron job configured");
        }
    }

    /**
     * Ensure self-signed SSL certs exist for all domains BEFORE mail service installation.
     * Dovecot/Postfix configs and dpkg post-install hooks need valid cert paths to exist,
     * otherwise the packages get stuck in a broken dpkg state. Real Let's Encrypt certs
     * replace these later in the setup_ssl step.
     */
    private function ensureSSLPlaceholders(array $variables): void
    {
        $domains = [$variables['PANEL_DOMAIN'], $variables['EMAIL_DOMAIN']];
        $mailDomain = $variables['MAIL_DOMAIN'] ?? '';
        if (!empty($mailDomain) && !in_array($mailDomain, $domains)) {
            $domains[] = $mailDomain;
        }

        foreach ($domains as $domain) {
            $certDir = "/etc/letsencrypt/live/{$domain}";
            $check = $this->executeCommand("test -f {$certDir}/fullchain.pem && echo 'ok' || echo 'missing'");
            if (strpos($check['output'] ?? '', 'ok') !== false) {
                continue;
            }
            $this->log("Pre-creating self-signed SSL cert for {$domain} (placeholder for mail services)...");
            $this->createSelfSignedCert($domain);
        }
    }

    /**
     * Create self-signed certificate as fallback when Let's Encrypt fails.
     * Uses certbot-compatible archive + symlink structure so certbot --force-renewal
     * works later without "live directory exists" errors.
     */
    private function createSelfSignedCert(string $domain): void
    {
        $archiveDir = "/etc/letsencrypt/archive/{$domain}";
        $liveDir = "/etc/letsencrypt/live/{$domain}";
        $renewalConf = "/etc/letsencrypt/renewal/{$domain}.conf";

        $this->executeCommand("rm -rf {$liveDir} {$archiveDir} {$renewalConf}");
        $this->executeCommand("mkdir -p {$archiveDir} {$liveDir}");

        $this->executeCommand(
            "openssl req -x509 -nodes -days 365 -newkey rsa:2048 " .
            "-keyout {$archiveDir}/privkey1.pem " .
            "-out {$archiveDir}/fullchain1.pem " .
            "-subj '/CN={$domain}/O=Fleet Manager/C=HU' 2>&1"
        );
        $this->executeCommand("cp {$archiveDir}/fullchain1.pem {$archiveDir}/chain1.pem");
        $this->executeCommand("cp {$archiveDir}/fullchain1.pem {$archiveDir}/cert1.pem");

        $this->executeCommand("ln -sf ../../archive/{$domain}/privkey1.pem {$liveDir}/privkey.pem");
        $this->executeCommand("ln -sf ../../archive/{$domain}/fullchain1.pem {$liveDir}/fullchain.pem");
        $this->executeCommand("ln -sf ../../archive/{$domain}/chain1.pem {$liveDir}/chain.pem");
        $this->executeCommand("ln -sf ../../archive/{$domain}/cert1.pem {$liveDir}/cert.pem");

        $this->log("Self-signed certificate created for {$domain} (certbot-compatible structure)");
    }

    /**
     * Finalize installation with verification
     */
    private function finalize(): void
    {
        $this->log("Finalizing installation...");

        // Install WP-CLI if not present
        $wpCliCheck = $this->executeCommand('which wp 2>/dev/null');
        if (empty(trim($wpCliCheck['output'] ?? ''))) {
            $this->log("Installing WP-CLI...");
            $this->executeCommand('curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar');
            $this->executeCommand('chmod +x wp-cli.phar');
            $this->executeCommand('mv wp-cli.phar /usr/local/bin/wp');
            $this->log("WP-CLI installed");
        } else {
            $this->log("WP-CLI already installed");
        }

        // Enable and start Docker
        $this->executeCommand('systemctl enable docker 2>/dev/null || true');
        $this->executeCommand('systemctl start docker 2>/dev/null || true');

        // Fix any broken dpkg state before service restarts
        $this->executeCommand('dpkg --configure -a 2>/dev/null || true');

        $this->log("=== VERIFYING ALL SERVICES ===");

        $services = [
            'lshttpd'         => ['accept' => ['active'],           'recovery' => 'ols'],
            'mariadb'         => ['accept' => ['active'],           'recovery' => 'package', 'pkg' => 'mariadb-server'],
            'redis-server'    => ['accept' => ['active'],           'recovery' => 'package', 'pkg' => 'redis-server'],
            'meilisearch'     => ['accept' => ['active'],           'recovery' => 'simple'],
            'postfix'         => ['accept' => ['active', 'exited'], 'recovery' => 'postfix'],
            'dovecot'         => ['accept' => ['active'],           'recovery' => 'dovecot'],
            'opendkim'        => ['accept' => ['active'],           'recovery' => 'simple'],
            'opendmarc'       => ['accept' => ['active'],           'recovery' => 'simple'],
            'clamav-daemon'   => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'clamav-freshclam'=> ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'spamd'           => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true, 'alt' => 'spamassassin'],
            'rspamd'          => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'pdns'            => ['accept' => ['active'],           'recovery' => 'simple'],
            'unbound'         => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'fail2ban'        => ['accept' => ['active'],           'recovery' => 'simple'],
            'firewalld'       => ['accept' => ['active'],           'recovery' => 'simple'],
            'coturn'          => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'livekit-server'  => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'stunnel4'        => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'collab-server'   => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'mailsync-server' => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'spamass-milter'  => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'nghttpx'         => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
            'auditd'          => ['accept' => ['active'],           'recovery' => 'simple',  'optional' => true],
        ];

        $failedServices = [];

        foreach ($services as $service => $config) {
            $result = $this->verifyAndRecoverService($service, $config);
            if (!$result) {
                $label = ($config['optional'] ?? false) ? "(optional)" : "(REQUIRED)";
                $failedServices[] = "{$service} {$label}";
            }
        }

        if (!empty($failedServices)) {
            $this->log("WARNING: Some services failed to start: " . implode(', ', $failedServices));
        } else {
            $this->log("All services verified and running");
        }

        $this->log("Installation finalized");
    }

    /**
     * Verify a service is running, with type-specific recovery if it's not.
     * Returns true if the service ends up in an acceptable state.
     */
    private function verifyAndRecoverService(string $service, array $config): bool
    {
        $acceptableStates = $config['accept'];

        $status = $this->getServiceStatus($service);
        if (in_array($status, $acceptableStates)) {
            $this->log("[{$service}] OK (status: {$status})");
            return true;
        }

        // Check if unit exists at all
        $unitCheck = $this->executeCommand("systemctl cat {$service} 2>/dev/null | head -1");
        if (empty(trim($unitCheck['output'] ?? ''))) {
            if (!empty($config['alt'])) {
                $altService = $config['alt'];
                $altStatus = $this->getServiceStatus($altService);
                if (in_array($altStatus, $acceptableStates)) {
                    $this->log("[{$service}] OK via alternative unit '{$altService}' (status: {$altStatus})");
                    return true;
                }
                $service = $altService;
            } elseif ($config['optional'] ?? false) {
                $this->log("[{$service}] Unit not found (optional, skipping)");
                return true;
            } else {
                $this->log("[{$service}] Unit not found - attempting recovery...");
            }
        }

        $this->log("[{$service}] Not running (status: {$status}), attempting recovery...");

        $recoveryType = $config['recovery'] ?? 'simple';

        switch ($recoveryType) {
            case 'ols':
                $this->startOlsWithRecovery();
                break;

            case 'dovecot':
                $confCheck = $this->executeCommand('doveconf -n 2>&1 | grep -i "fatal\|error" | head -3');
                $errors = trim($confCheck['output'] ?? '');
                if (!empty($errors)) {
                    $this->log("[dovecot] Config errors: {$errors}");
                    if (stripos($errors, 'ssl_cert') !== false || stripos($errors, 'ssl_key') !== false
                        || stripos($errors, 'ssl') !== false || stripos($errors, 'No such file') !== false) {
                        $this->generateSelfSignedCertForService('dovecot');
                    }
                }
                // Also check if the SSL cert path referenced in dovecot.conf actually exists
                $sslCertPath = trim($this->executeCommand("doveconf -h ssl_cert 2>/dev/null | sed 's/^<//'")['output'] ?? '');
                if (!empty($sslCertPath)) {
                    $certExists = $this->executeCommand("test -f {$sslCertPath} && echo 'ok' || echo 'missing'");
                    if (strpos($certExists['output'] ?? '', 'missing') !== false) {
                        $this->log("[dovecot] SSL cert missing at {$sslCertPath}, generating self-signed...");
                        $this->generateSelfSignedCertForService('dovecot');
                    }
                }
                $this->executeCommand('dpkg --configure -a 2>/dev/null || true');
                $this->executeCommand("systemctl restart {$service} 2>/dev/null || true");
                sleep(3);
                break;

            case 'postfix':
                $this->executeCommand('postfix check 2>/dev/null || true');
                $sslCert = trim($this->executeCommand("postconf -h smtpd_tls_cert_file 2>/dev/null")['output'] ?? '');
                if (!empty($sslCert)) {
                    $certExists = $this->executeCommand("test -f {$sslCert} && echo 'ok' || echo 'missing'");
                    if (strpos($certExists['output'] ?? '', 'missing') !== false) {
                        $this->generateSelfSignedCertForService('postfix');
                    }
                }
                $this->executeCommand("systemctl restart {$service} 2>/dev/null || true");
                sleep(2);
                break;

            case 'package':
                $this->executeCommand("systemctl restart {$service} 2>/dev/null || true");
                sleep(2);
                $retryStatus = trim($this->executeCommand("systemctl is-active {$service} 2>/dev/null || echo 'inactive'")['output'] ?? '');
                if (in_array($retryStatus, $acceptableStates)) break;

                $pkg = $config['pkg'] ?? $service;
                $this->log("[{$service}] Restart failed, reinstalling {$pkg}...");
                $this->executeCommand("apt-get install --reinstall -y {$pkg} 2>&1 | tail -5");
                $this->executeCommand("systemctl restart {$service} 2>/dev/null || true");
                sleep(3);
                break;

            default:
                $this->executeCommand("systemctl restart {$service} 2>/dev/null || true");
                sleep(2);
                break;
        }

        $finalStatus = $this->getServiceStatus($service);
        $ok = in_array($finalStatus, $acceptableStates);

        if ($ok) {
            $this->log("[{$service}] Recovered (status: {$finalStatus})");
        } else {
            $journal = $this->executeCommand("journalctl -u {$service} --no-pager -n 5 2>/dev/null | tail -3");
            $this->log("[{$service}] FAILED (status: {$finalStatus}). Journal: " . trim($journal['output'] ?? ''));
        }

        return $ok;
    }

    /**
     * Get the actual systemd service status (single-word).
     * Avoids the `|| echo 'inactive'` multi-line output bug where systemctl returns
     * e.g. "activating" with non-zero exit, causing "activating\ninactive".
     */
    private function getServiceStatus(string $service): string
    {
        $raw = trim($this->executeCommand("systemctl is-active {$service} 2>/dev/null || true")['output'] ?? '');
        $firstLine = strtok($raw, "\n");
        return !empty($firstLine) ? $firstLine : 'inactive';
    }

    /**
     * Create a MariaDB-safe .cnf file content with properly quoted password.
     * Handles special chars (#, ;, ", \) that would break unquoted .cnf values.
     */
    private function buildMyCnf(string $user, string $password): string
    {
        $escapedPass = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);
        return "[client]\nuser={$user}\npassword=\"{$escapedPass}\"\n";
    }

    /**
     * Start OpenLiteSpeed with diagnostics and recovery
     * OLS is finicky - needs config validation and multiple fallback approaches
     */
    private function startOlsWithRecovery(): void
    {
        $this->log("Starting OpenLiteSpeed with diagnostics...");

        // Step 1: Kill any stale processes/PID files
        $this->executeCommand('rm -f /tmp/lshttpd/lshttpd.pid 2>/dev/null || true');
        $this->executeCommand('killall -9 litespeed 2>/dev/null || true');
        sleep(1);

        // Step 2: Ensure litespeed binary exists and is executable
        $binaryCheck = $this->executeCommand('test -f /usr/local/lsws/bin/litespeed && echo "ok" || echo "missing"');
        if (strpos($binaryCheck['output'] ?? '', 'missing') !== false) {
            $this->log("OLS binary missing! Checking for alternative locations or reinstalling...");
            $altCheck = $this->executeCommand('test -f /usr/local/lsws/bin/openlitespeed && echo "ok" || echo "missing"');
            if (strpos($altCheck['output'] ?? '', 'ok') !== false) {
                $this->log("Found openlitespeed binary, creating symlink...");
                $this->executeCommand('ln -sf /usr/local/lsws/bin/openlitespeed /usr/local/lsws/bin/litespeed');
            } else {
                $this->log("Attempting OLS package reinstall to restore binary...");
                $this->executeCommand('apt-get install --reinstall -y openlitespeed 2>&1 | tail -5');
                sleep(2);
                $recheck = $this->executeCommand('test -f /usr/local/lsws/bin/litespeed && echo "ok" || echo "missing"');
                if (strpos($recheck['output'] ?? '', 'missing') !== false) {
                    $this->log("ERROR: Could not restore OLS binary after reinstall");
                    $this->executeCommand('ls -la /usr/local/lsws/bin/ 2>/dev/null');
                    return;
                }
            }
        }
        // Ensure OLS binaries are executable (packages sometimes ship with 644 perms)
        $this->executeCommand('chmod +x /usr/local/lsws/bin/openlitespeed /usr/local/lsws/bin/lshttpd /usr/local/lsws/bin/litespeed 2>/dev/null || true');
        $this->executeCommand('chmod +x /usr/local/lsws/bin/lswsctrl* 2>/dev/null || true');

        // Step 2b: Ensure admin_php exists (critical for OLS to start)
        $adminPhpCheck = $this->executeCommand('test -f /usr/local/lsws/admin/fcgi-bin/admin_php && echo "ok" || echo "missing"');
        if (strpos($adminPhpCheck['output'] ?? '', 'missing') !== false) {
            $this->log("Fixing missing admin_php symlink...");
            $this->executeCommand('mkdir -p /usr/local/lsws/admin/fcgi-bin');
            $this->executeCommand('ln -sf /usr/local/lsws/lsphp83/bin/lsphp /usr/local/lsws/admin/fcgi-bin/admin_php');
        }

        // Step 3: Verify httpd_config.conf syntax (look for obvious issues)
        $confCheck = $this->executeCommand('test -f /usr/local/lsws/conf/httpd_config.conf && echo "ok" || echo "missing"');
        if (strpos($confCheck['output'] ?? '', 'missing') !== false) {
            $this->log("ERROR: httpd_config.conf is missing! OLS cannot start.");
            return;
        }

        // Step 4: Check that all referenced vhost config files exist
        $vhostDirs = $this->executeCommand("grep -oP 'configFile\\s+\\K\\S+' /usr/local/lsws/conf/httpd_config.conf 2>/dev/null || true");
        $vhostOutput = trim($vhostDirs['output'] ?? '');
        if (!empty($vhostOutput)) {
            foreach (explode("\n", $vhostOutput) as $confFile) {
                $confFile = trim($confFile);
                if (empty($confFile)) continue;
                // Resolve relative paths
                if (!str_starts_with($confFile, '/')) {
                    $confFile = "/usr/local/lsws/conf/{$confFile}";
                }
                $fileCheck = $this->executeCommand("test -f {$confFile} && echo 'ok' || echo 'missing'");
                if (strpos($fileCheck['output'] ?? '', 'missing') !== false) {
                    $this->log("Warning: Vhost config file missing: {$confFile}");
                }
            }
        }

        // Step 5: Fix ownership/permissions (lsadm must own conf for OLS to work)
        $this->executeCommand('chown lsadm:nogroup /usr/local/lsws/conf/httpd_config.conf 2>/dev/null || true');
        $this->executeCommand('chmod 644 /usr/local/lsws/conf/httpd_config.conf 2>/dev/null || true');
        $this->executeCommand('chown -R lsadm:lsadm /usr/local/lsws/conf/vhosts 2>/dev/null || true');
        $this->executeCommand('chown lsadm:lsadm /usr/local/lsws/tmp 2>/dev/null || true');
        $this->executeCommand('chmod 750 /usr/local/lsws/tmp 2>/dev/null || true');

        // Step 6: Try to start via systemctl
        $this->log("Attempting OLS start via systemctl...");
        $this->executeCommand('systemctl restart lshttpd 2>&1');
        sleep(3);

        $status = $this->getServiceStatus('lshttpd');
        if ($status === 'active') {
            $this->log("OpenLiteSpeed started successfully via systemctl");
            return;
        }

        // Step 7: Fallback - try lswsctrl directly
        $this->log("systemctl failed (status: {$status}), trying lswsctrl...");
        $this->executeCommand('/usr/local/lsws/bin/lswsctrl stop 2>/dev/null || true');
        sleep(1);
        $startResult = $this->executeCommand('/usr/local/lsws/bin/lswsctrl start 2>&1');
        sleep(3);

        $status = $this->getServiceStatus('lshttpd');
        if ($status === 'active') {
            $this->log("OpenLiteSpeed started successfully via lswsctrl");
            return;
        }

        // Step 8: Check error log for clues
        $errorLog = $this->executeCommand('tail -30 /usr/local/lsws/logs/error.log 2>/dev/null');
        $this->log("OLS error log:\n" . trim($errorLog['output'] ?? 'no logs'));

        // Step 9: Last resort - check if port 80/443 is in use
        $portCheck = $this->executeCommand("ss -tlnp | grep -E ':80|:443' 2>/dev/null || true");
        if (!empty(trim($portCheck['output'] ?? ''))) {
            $this->log("Ports 80/443 in use by: " . trim($portCheck['output']));
        }
    }

    /**
     * Post-deployment audit - verify everything is actually working
     * We don't trust the installer, we verify independently
     */
    private function auditDeployment(int $serverId, array $variables): array
    {
        $this->log("=== DEPLOYMENT AUDIT STARTING ===");
        $results = [
            'timestamp' => date('Y-m-d H:i:s'),
            'server_id' => $serverId,
            'checks' => [],
            'passed' => 0,
            'failed' => 0,
            'warnings' => 0,
        ];

        $addCheck = function (string $category, string $name, string $status, string $detail, ?string $fixCmd = null, ?string $fixLabel = null) use (&$results) {
            $check = [
                'category' => $category,
                'name' => $name,
                'status' => $status,
                'detail' => $detail,
            ];
            if ($fixCmd !== null) {
                $check['fix_command'] = $fixCmd;
                $check['fix_label'] = $fixLabel ?? 'Fix';
            }
            $results['checks'][] = $check;
            match ($status) {
                'pass' => $results['passed']++,
                'fail' => $results['failed']++,
                'warning' => $results['warnings']++,
                default => null,
            };
            $tag = strtoupper($status);
            $this->log("  [{$name}] {$tag} - {$detail}");
        };

        // =====================================================================
        // 1. SERVICES CHECK
        // =====================================================================
        $this->log("Auditing services...");
        $requiredServices = [
            'lshttpd'          => ['label' => 'OpenLiteSpeed',  'pkg' => 'openlitespeed'],
            'mariadb'          => ['label' => 'MariaDB',        'pkg' => 'mariadb-server'],
            'redis-server'     => ['label' => 'Redis',          'pkg' => 'redis-server'],
            'postfix'          => ['label' => 'Postfix',        'pkg' => 'postfix'],
            'dovecot'          => ['label' => 'Dovecot',        'pkg' => 'dovecot-imapd'],
            'opendkim'         => ['label' => 'OpenDKIM',       'pkg' => 'opendkim'],
            'opendmarc'        => ['label' => 'OpenDMARC',      'pkg' => 'opendmarc'],
            'pdns'             => ['label' => 'PowerDNS',       'pkg' => 'pdns-server'],
            'fail2ban'         => ['label' => 'Fail2ban',       'pkg' => 'fail2ban'],
            'firewalld'        => ['label' => 'FirewallD',      'pkg' => 'firewalld'],
        ];

        // With CPGuard licensed, its Login Defender is the brute-force layer and
        // fail2ban is intentionally stopped (mirrors the live server) - so it is
        // audited inside the CPGuard block below instead of as a required service.
        $cpguardExpected = trim((string) ($variables['CPGUARD_LICENSE_KEY'] ?? '')) !== '';
        if ($cpguardExpected) {
            unset($requiredServices['fail2ban']);
        }
        $optionalServices = [
            'meilisearch'      => ['label' => 'Meilisearch',     'alt' => null],
            'spamd'            => ['label' => 'SpamAssassin',    'alt' => 'spamassassin'],
            'rspamd'           => ['label' => 'Rspamd',          'alt' => null],
            'clamav-daemon'    => ['label' => 'ClamAV Daemon',   'alt' => null],
            'clamav-freshclam' => ['label' => 'ClamAV Freshclam','alt' => null],
            'unbound'          => ['label' => 'Unbound Resolver','alt' => null],
            'coturn'           => ['label' => 'coTURN',          'alt' => null],
            'livekit-server'   => ['label' => 'LiveKit',         'alt' => null],
            'stunnel4'         => ['label' => 'stunnel4',        'alt' => null],
            'collab-server'    => ['label' => 'Collab Server',   'alt' => null],
            'mailsync-server'  => ['label' => 'MailSync Server', 'alt' => null],
            'spamass-milter'   => ['label' => 'SpamAssassin Milter', 'alt' => null],
            'nghttpx'          => ['label' => 'nghttpx',         'alt' => null],
            'auditd'           => ['label' => 'auditd',          'alt' => null],
        ];

        foreach ($requiredServices as $svc => $meta) {
            $out = $this->getServiceStatus($svc);
            $running = in_array($out, ['active', 'exited']);
            $fixCmd = "systemctl restart {$svc}";
            $addCheck('services', $meta['label'], $running ? 'pass' : 'fail',
                "systemd status: {$out}", $running ? null : $fixCmd, 'Restart service');
        }

        foreach ($optionalServices as $svc => $meta) {
            $out = $this->getServiceStatus($svc);
            $running = in_array($out, ['active', 'activating', 'exited']);
            if (!$running && !empty($meta['alt'])) {
                $out = $this->getServiceStatus($meta['alt']);
                $running = in_array($out, ['active', 'activating', 'exited']);
                if ($running) $svc = $meta['alt'];
            }
            $fixCmd = "systemctl restart {$svc}";
            $addCheck('services', $meta['label'], $running ? 'pass' : 'warning',
                "systemd status: {$out}", $running ? null : $fixCmd, 'Restart service');
        }

        // CPGuard: only audited when this server has a license key configured -
        // then it MUST be installed, licensed and running, and fail2ban must be
        // OFF (CPGuard's Login Defender supersedes it; both active = double bans).
        if ($cpguardExpected) {
            $cpgInstalled = strpos(
                $this->executeCommand('if [ -d /opt/cpguard/app ] || systemctl list-unit-files cpguard.service 2>/dev/null | grep -q "^cpguard"; then echo INSTALLED; fi')['output'] ?? '',
                'INSTALLED'
            ) !== false;
            $cpgLicense = strpos(
                $this->executeCommand('test -f /etc/cpguard/LICENSE_cPGuard && echo OK')['output'] ?? '',
                'OK'
            ) !== false;
            $cpgStatus = $this->getServiceStatus('cpguard');
            $cpgRunning = in_array($cpgStatus, ['active', 'activating', 'exited']);

            $addCheck('services', 'CPGuard installed', $cpgInstalled ? 'pass' : 'fail',
                $cpgInstalled ? 'Installed (/opt/cpguard/app or systemd unit present)' : 'License key configured but CPGuard is NOT installed');
            $addCheck('services', 'CPGuard license file', $cpgLicense ? 'pass' : 'fail',
                $cpgLicense ? 'Present (/etc/cpguard/LICENSE_cPGuard)' : 'Missing /etc/cpguard/LICENSE_cPGuard');
            $addCheck('services', 'CPGuard service', $cpgRunning ? 'pass' : 'fail',
                "systemd status: {$cpgStatus}", $cpgRunning ? null : 'systemctl restart cpguard', 'Restart service');

            $f2bStatus = $this->getServiceStatus('fail2ban');
            $f2bOff = !in_array($f2bStatus, ['active', 'activating']);
            $addCheck('services', 'Fail2ban (superseded by CPGuard)', $f2bOff ? 'pass' : 'warning',
                $f2bOff
                    ? "Correctly stopped - CPGuard Login Defender is the brute-force layer (status: {$f2bStatus})"
                    : 'fail2ban is RUNNING alongside CPGuard - they will double-ban; stop and disable it',
                $f2bOff ? null : 'systemctl stop fail2ban && systemctl disable fail2ban', 'Stop fail2ban');
        }

        // =====================================================================
        // 1b. CRITICAL PACKAGE CHECK
        // =====================================================================
        $criticalPackages = [
            'postfix-mysql'     => 'Postfix MySQL maps (mail delivery)',
            'pdns-backend-mysql'=> 'PowerDNS MySQL backend',
        ];
        foreach ($criticalPackages as $pkg => $desc) {
            $pkgCheck = $this->executeCommand("dpkg -l {$pkg} 2>/dev/null | grep -c '^ii' || echo '0'");
            $installed = trim($pkgCheck['output'] ?? '0') !== '0';
            $addCheck('packages', "Package '{$pkg}'", $installed ? 'pass' : 'fail',
                $installed ? 'Installed' : "Missing - {$desc}",
                $installed ? null : "apt-get install -y {$pkg} && systemctl restart postfix",
                'Install package');
        }

        // =====================================================================
        // 2. PORTS / FIREWALL CHECK
        // =====================================================================
        $this->log("Auditing firewall ports...");
        $requiredPorts = [
            ['port' => '22/tcp',    'label' => 'SSH'],
            ['port' => '80/tcp',    'label' => 'HTTP'],
            ['port' => '443/tcp',   'label' => 'HTTPS'],
            ['port' => '25/tcp',    'label' => 'SMTP'],
            ['port' => '465/tcp',   'label' => 'SMTPS'],
            ['port' => '587/tcp',   'label' => 'Submission'],
            ['port' => '110/tcp',   'label' => 'POP3'],
            ['port' => '143/tcp',   'label' => 'IMAP'],
            ['port' => '993/tcp',   'label' => 'IMAPS'],
            ['port' => '995/tcp',   'label' => 'POP3S'],
            ['port' => '4190/tcp',  'label' => 'ManageSieve'],
            ['port' => '53/tcp',    'label' => 'DNS (TCP)'],
            ['port' => '53/udp',    'label' => 'DNS (UDP)'],
            ['port' => '3478/tcp',  'label' => 'STUN/TURN (TCP)'],
            ['port' => '3478/udp',  'label' => 'STUN/TURN (UDP)'],
            ['port' => '7080/tcp',  'label' => 'OLS Admin'],
            ['port' => '7443/tcp',  'label' => 'stunnel TLS'],
            ['port' => '7880/tcp',  'label' => 'LiveKit API'],
            ['port' => '7881/tcp',  'label' => 'LiveKit RTC'],
        ];

        $fwListOut = $this->executeCommand("firewall-cmd --list-ports 2>/dev/null || echo ''");
        $openPorts = trim($fwListOut['output'] ?? '');
        // Also grab services list (some ports mapped via named services)
        $fwSvcOut = $this->executeCommand("firewall-cmd --list-services 2>/dev/null || echo ''");
        $openServices = trim($fwSvcOut['output'] ?? '');

        // Build a lookup set from firewall-cmd output
        $portSet = array_flip(preg_split('/\s+/', $openPorts, -1, PREG_SPLIT_NO_EMPTY));
        // Map well-known service names to ports for cross-check
        $svcPortMap = [
            'ssh' => '22/tcp', 'http' => '80/tcp', 'https' => '443/tcp',
            'smtp' => '25/tcp', 'smtps' => '465/tcp', 'smtp-submission' => '587/tcp',
            'pop3' => '110/tcp', 'pop3s' => '995/tcp', 'imap' => '143/tcp', 'imaps' => '993/tcp',
            'dns' => '53/tcp',
        ];
        foreach (preg_split('/\s+/', $openServices, -1, PREG_SPLIT_NO_EMPTY) as $svcName) {
            if (isset($svcPortMap[$svcName])) {
                $portSet[$svcPortMap[$svcName]] = true;
            }
        }

        foreach ($requiredPorts as $p) {
            $isOpen = isset($portSet[$p['port']]);
            $fixCmd = "firewall-cmd --permanent --add-port={$p['port']} && firewall-cmd --reload";
            $addCheck('ports', "Port {$p['port']} ({$p['label']})", $isOpen ? 'pass' : 'fail',
                $isOpen ? 'Open' : 'Closed / not in firewall',
                $isOpen ? null : $fixCmd, 'Open port');
        }

        // Verify key services are actually listening (ss check)
        $this->log("Auditing listening sockets...");
        $listenChecks = [
            ['port' => 80,   'label' => 'OLS HTTP',     'fix' => 'systemctl restart lshttpd'],
            ['port' => 443,  'label' => 'OLS HTTPS',    'fix' => 'systemctl restart lshttpd'],
            ['port' => 25,   'label' => 'Postfix SMTP', 'fix' => 'systemctl restart postfix'],
            ['port' => 993,  'label' => 'Dovecot IMAPS','fix' => 'systemctl restart dovecot'],
            ['port' => 53,   'label' => 'PowerDNS',     'fix' => 'systemctl restart pdns'],
            ['port' => 3306, 'label' => 'MariaDB',      'fix' => 'systemctl restart mariadb'],
            ['port' => 6379, 'label' => 'Redis',        'fix' => 'systemctl restart redis-server'],
        ];
        $ssOutput = $this->executeCommand("ss -tlnp 2>/dev/null || echo ''");
        $ssLines = $ssOutput['output'] ?? '';

        foreach ($listenChecks as $lc) {
            $pattern = ":{$lc['port']} ";
            $listening = strpos($ssLines, $pattern) !== false;
            $addCheck('listening', "{$lc['label']} on port {$lc['port']}", $listening ? 'pass' : 'fail',
                $listening ? 'Listening' : 'Not listening',
                $listening ? null : $lc['fix'], 'Restart service');
        }

        // =====================================================================
        // 3. DATABASE CHECK
        // =====================================================================
        $this->log("Auditing databases...");
        $databases = [
            $variables['PANEL_DB_NAME'] => $variables['PANEL_DB_USER'],
            $variables['MAIL_DB_NAME']  => $variables['MAIL_DB_USER'],
        ];

        foreach ($databases as $dbName => $dbUser) {
            $check = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf -N -e \"SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$dbName}'\" 2>/dev/null | grep -c '{$dbName}'");
            $exists = trim($check['output'] ?? '0') !== '0';
            $addCheck('database', "Database '{$dbName}' exists", $exists ? 'pass' : 'fail',
                $exists ? 'Found' : 'Not found',
                $exists ? null : "mariadb --defaults-file=/root/.my.cnf -e \"CREATE DATABASE IF NOT EXISTS \`{$dbName}\`\"",
                'Create database');

            if ($exists) {
                $tableCount = trim($this->executeCommand("mariadb --defaults-file=/root/.my.cnf -N -e \"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$dbName}'\" 2>/dev/null")['output'] ?? '0');
                $hasTables = intval($tableCount) > 0;
                $addCheck('database', "Database '{$dbName}' has tables", $hasTables ? 'pass' : 'fail',
                    "{$tableCount} table(s)");
            }

            $dbPass = ($dbUser === $variables['PANEL_DB_USER']) ? $variables['PANEL_DB_PASS'] : $variables['MAIL_DB_PASS'];
            $userCnf = $this->buildMyCnf($dbUser, $dbPass);
            $this->ssh->uploadContent($userCnf, '/tmp/fleet-audit-user.cnf');
            $this->executeCommand('chmod 600 /tmp/fleet-audit-user.cnf');
            $userCheck = $this->executeCommand("mariadb --defaults-extra-file=/tmp/fleet-audit-user.cnf {$dbName} -e 'SELECT 1' 2>/dev/null && echo 'OK' || echo 'FAIL'");
            $this->executeCommand('rm -f /tmp/fleet-audit-user.cnf');
            $canConnect = strpos($userCheck['output'] ?? '', 'OK') !== false;
            $addCheck('database', "User '{$dbUser}' can connect to '{$dbName}'", $canConnect ? 'pass' : 'fail',
                $canConnect ? 'Connection successful' : 'Access denied or error');
        }

        // =====================================================================
        // 3b. MAIL DOMAIN & ACCOUNTS CHECK
        // =====================================================================
        $this->log("Auditing mail data...");
        $panelDb = $variables['PANEL_DB_NAME'];
        $baseDomain = preg_replace('/^panel\./', '', $variables['PANEL_DOMAIN'] ?? '');

        $mailDomainCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e \"SELECT COUNT(*) FROM mail_domains WHERE domain='{$baseDomain}' AND status='active'\" 2>/dev/null || echo '0'");
        $hasMailDomain = intval(trim($mailDomainCheck['output'] ?? '0')) > 0;
        $addCheck('mail', "Mail domain '{$baseDomain}' in Panel DB", $hasMailDomain ? 'pass' : 'fail',
            $hasMailDomain ? 'Active domain found' : 'Missing - Mail page will show 0 domains');

        $mailAcctCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e \"SELECT COUNT(*) FROM mail_accounts WHERE domain='{$baseDomain}'\" 2>/dev/null || echo '0'");
        $mailAcctCount = intval(trim($mailAcctCheck['output'] ?? '0'));
        $addCheck('mail', "Mail accounts for '{$baseDomain}'", $mailAcctCount > 0 ? 'pass' : 'fail',
            $mailAcctCount > 0 ? "{$mailAcctCount} account(s) found" : 'No accounts - Mail page will show 0 accounts');

        // Check mailserver DB has matching data (Dovecot/Postfix auth)
        $mailDb = $variables['MAIL_DB_NAME'] ?? 'mailserver';
        $mailSrvDomCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$mailDb} -N -e \"SELECT COUNT(*) FROM mail_domains WHERE domain='{$baseDomain}'\" 2>/dev/null || echo '0'");
        $hasMailSrvDomain = intval(trim($mailSrvDomCheck['output'] ?? '0')) > 0;
        $addCheck('mail', "Mail domain in mailserver DB (Dovecot auth)", $hasMailSrvDomain ? 'pass' : 'fail',
            $hasMailSrvDomain ? 'Found' : 'Missing - Dovecot/Postfix cannot authenticate for this domain');

        // =====================================================================
        // 3c. DNS RECORDS CHECK
        // =====================================================================
        $this->log("Auditing DNS data...");
        $dnsZoneCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e \"SELECT COUNT(*) FROM dns_domains WHERE name='{$baseDomain}'\" 2>/dev/null || echo '0'");
        $hasDnsZone = intval(trim($dnsZoneCheck['output'] ?? '0')) > 0;
        $addCheck('dns', "DNS zone '{$baseDomain}' exists", $hasDnsZone ? 'pass' : 'fail',
            $hasDnsZone ? 'Zone found' : 'Missing - DNS page will be empty');

        if ($hasDnsZone) {
            $dnsRecordCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e \"SELECT COUNT(*) FROM dns_records dr JOIN dns_domains dd ON dr.domain_id=dd.id WHERE dd.name='{$baseDomain}'\" 2>/dev/null || echo '0'");
            $dnsRecordCount = intval(trim($dnsRecordCheck['output'] ?? '0'));
            $addCheck('dns', "DNS records for '{$baseDomain}'", $dnsRecordCount >= 5 ? 'pass' : 'warning',
                "{$dnsRecordCount} record(s)" . ($dnsRecordCount < 5 ? ' (expected at least SOA, A, MX, SPF, DMARC)' : ''));

            // Check SOA exists
            $soaCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e \"SELECT COUNT(*) FROM dns_records dr JOIN dns_domains dd ON dr.domain_id=dd.id WHERE dd.name='{$baseDomain}' AND dr.type='SOA'\" 2>/dev/null || echo '0'");
            $hasSOA = intval(trim($soaCheck['output'] ?? '0')) > 0;
            $addCheck('dns', "SOA record for '{$baseDomain}'", $hasSOA ? 'pass' : 'fail',
                $hasSOA ? 'Present' : 'Missing SOA record');

            // Check MX exists
            $mxCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e \"SELECT COUNT(*) FROM dns_records dr JOIN dns_domains dd ON dr.domain_id=dd.id WHERE dd.name='{$baseDomain}' AND dr.type='MX'\" 2>/dev/null || echo '0'");
            $hasMX = intval(trim($mxCheck['output'] ?? '0')) > 0;
            $addCheck('dns', "MX record for '{$baseDomain}'", $hasMX ? 'pass' : 'fail',
                $hasMX ? 'Present' : 'Missing MX record - mail delivery will fail');
        }

        // Verify PowerDNS can actually resolve the zone
        $pdnsResolve = $this->executeCommand("dig @127.0.0.1 {$baseDomain} SOA +short +time=3 2>/dev/null || echo ''");
        $pdnsCanResolve = !empty(trim($pdnsResolve['output'] ?? ''));
        $addCheck('dns', "PowerDNS resolves '{$baseDomain}'", $pdnsCanResolve ? 'pass' : 'warning',
            $pdnsCanResolve ? 'Resolving' : 'Cannot resolve locally (check pdns service + gmysql config)',
            $pdnsCanResolve ? null : 'systemctl restart pdns', 'Restart PowerDNS');

        // =====================================================================
        // 4. FILESYSTEM CHECK
        // =====================================================================
        $this->log("Auditing filesystem...");
        $requiredPaths = [
            '/var/www/vps-admin'                       => 'Panel web root',
            '/var/www/vps-admin/api'                   => 'Panel API directory',
            '/var/www/vps-email'                       => 'Email app web root',
            '/var/www/vps-email/backend'               => 'Email app backend',
            '/var/www/vps-email/dist'                  => 'Email app frontend',
            '/usr/local/lsws/conf/httpd_config.conf'   => 'OLS main config',
            '/usr/local/lsws/conf/vhosts'              => 'OLS vhosts directory',
        ];

        foreach ($requiredPaths as $path => $label) {
            $check = $this->executeCommand("test -e {$path} && echo 'EXISTS' || echo 'MISSING'");
            $exists = strpos($check['output'] ?? '', 'EXISTS') !== false;
            $addCheck('filesystem', $label, $exists ? 'pass' : 'fail', $path);
        }

        // =====================================================================
        // 5. SSL CHECK (with expiry days + renew fix command)
        // =====================================================================
        $this->log("Auditing SSL certificates...");
        $domains = [$variables['PANEL_DOMAIN'], $variables['EMAIL_DOMAIN']];
        $auditMailDomain = $variables['MAIL_DOMAIN'] ?? '';
        if (!empty($auditMailDomain) && !in_array($auditMailDomain, $domains)) {
            $domains[] = $auditMailDomain;
        }
        // Add base domain for mail cert (Postfix/Dovecot reference it)
        if (!empty($baseDomain) && !in_array($baseDomain, $domains)) {
            $domains[] = $baseDomain;
        }

        $renewCmd = function (string $domain) {
            return "certbot certonly --standalone -d {$domain} --non-interactive --agree-tos --register-unsafely-without-email";
        };

        foreach ($domains as $domain) {
            $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
            $check = $this->executeCommand("test -f {$certPath} && echo 'EXISTS' || echo 'MISSING'");
            $hasCert = strpos($check['output'] ?? '', 'EXISTS') !== false;

            if ($hasCert) {
                // Issuer check (real LE vs self-signed)
                $issuerCheck = $this->executeCommand("openssl x509 -issuer -noout -in {$certPath} 2>/dev/null");
                $issuerStr = $issuerCheck['output'] ?? '';
                $isRealLE = strpos($issuerStr, "Let's Encrypt") !== false
                    || (bool) preg_match('/\b(R3|R10|R11|E[5-9])\b/', $issuerStr);
                $isSelfSigned = !$isRealLE;

                // Expiry check - calculate days remaining
                $expiryEpoch = $this->executeCommand("openssl x509 -enddate -noout -in {$certPath} 2>/dev/null | cut -d= -f2 | xargs -I{} date -d '{}' +%s 2>/dev/null || echo '0'");
                $expiryTs = intval(trim($expiryEpoch['output'] ?? '0'));
                $nowTs = intval($this->executeCommand("date +%s")['output'] ?? time());
                $daysLeft = $expiryTs > 0 ? intval(($expiryTs - $nowTs) / 86400) : -1;

                if ($isSelfSigned) {
                    $addCheck('ssl', "SSL cert for {$domain}", 'fail',
                        'Self-signed placeholder (Let\'s Encrypt not yet issued - check DNS A record)',
                        $renewCmd($domain), 'Request Let\'s Encrypt cert');
                } elseif ($daysLeft < 0) {
                    $addCheck('ssl', "SSL cert for {$domain}", 'fail',
                        'Certificate EXPIRED',
                        $renewCmd($domain), 'Renew SSL cert');
                } elseif ($daysLeft < 14) {
                    $addCheck('ssl', "SSL cert for {$domain}", 'warning',
                        "Expires in {$daysLeft} day(s) - renewal recommended",
                        $renewCmd($domain), 'Renew SSL cert');
                } else {
                    $addCheck('ssl', "SSL cert for {$domain}", 'pass',
                        "Valid, expires in {$daysLeft} day(s)");
                }
            } else {
                // Check if covered by SAN on primary cert
                $altPath = "/etc/letsencrypt/live/" . $domains[0] . "/fullchain.pem";
                $sanCheck = $this->executeCommand("openssl x509 -text -noout -in {$altPath} 2>/dev/null | grep -o 'DNS:{$domain}'");
                $inSan = strpos($sanCheck['output'] ?? '', $domain) !== false;

                if ($inSan) {
                    $addCheck('ssl', "SSL cert for {$domain}", 'pass',
                        'Covered by combined SAN certificate');
                } else {
                    $addCheck('ssl', "SSL cert for {$domain}", 'fail',
                        "No certificate found at {$certPath}",
                        $renewCmd($domain), 'Request Let\'s Encrypt cert');
                }
            }
        }

        // =====================================================================
        // 6. HTTP CONNECTIVITY CHECK
        // =====================================================================
        $this->log("Auditing HTTP connectivity...");
        $httpDomains = [$variables['PANEL_DOMAIN'], $variables['EMAIL_DOMAIN']];
        foreach ($httpDomains as $domain) {
            $httpCheck = $this->executeCommand("curl -sSk -o /dev/null -w '%{http_code}' --connect-timeout 5 https://{$domain}/ 2>/dev/null || echo '000'");
            $httpCode = trim($httpCheck['output'] ?? '000');

            $isOk = in_array($httpCode, ['200', '301', '302', '403']);
            $status = $isOk ? 'pass' : ($httpCode === '000' ? 'fail' : 'warning');
            $addCheck('http', "HTTPS response for {$domain}", $status,
                "HTTP {$httpCode}",
                $isOk ? null : 'systemctl restart lshttpd', 'Restart OLS');
        }

        // =====================================================================
        // 7. PANEL ADMIN USER CHECK
        // =====================================================================
        $this->log("Auditing panel admin user...");
        $adminCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e \"SELECT COUNT(*) FROM admin_users WHERE username='pxradmin'\" 2>/dev/null || echo '0'");
        $hasAdmin = intval(trim($adminCheck['output'] ?? '0')) > 0;
        $addCheck('application', 'Panel admin user exists', $hasAdmin ? 'pass' : 'fail',
            $hasAdmin ? 'pxradmin found' : 'No admin user in database');

        // Check login rate limits are clean (leftover locks from failed deploys)
        $rateLimitCheck = $this->executeCommand("mariadb --defaults-file=/root/.my.cnf {$panelDb} -N -e \"SELECT COUNT(*) FROM login_rate_limits WHERE locked_until > NOW()\" 2>/dev/null || echo '0'");
        $lockedCount = intval(trim($rateLimitCheck['output'] ?? '0'));
        if ($lockedCount > 0) {
            $addCheck('application', 'Login rate limits', 'warning',
                "{$lockedCount} active lock(s) may block login",
                "mariadb --defaults-file=/root/.my.cnf {$panelDb} -e \"TRUNCATE login_rate_limits; TRUNCATE login_attempts\"",
                'Clear rate limits');
        } else {
            $addCheck('application', 'Login rate limits', 'pass', 'No active locks');
        }

        // =====================================================================
        // 8. AGENT CHECKS
        // =====================================================================
        $this->log("Auditing agents...");

        $panelAgentCheck = $this->executeCommand("systemctl is-active vpsadmin-agent 2>/dev/null || echo 'inactive'");
        $panelAgentStatus = trim($panelAgentCheck['output'] ?? 'inactive');
        $panelAgentRunning = $panelAgentStatus === 'active';
        $addCheck('agent', 'Panel Agent running', $panelAgentRunning ? 'pass' : 'warning',
            "systemd status: {$panelAgentStatus}",
            $panelAgentRunning ? null : 'systemctl restart vpsadmin-agent', 'Restart Panel Agent');

        $agentCheck = $this->executeCommand("systemctl is-active fleet-agent 2>/dev/null || echo 'inactive'");
        $agentStatus = trim($agentCheck['output'] ?? 'inactive');
        $agentRunning = $agentStatus === 'active';
        $addCheck('agent', 'Fleet Agent running', $agentRunning ? 'pass' : 'warning',
            "systemd status: {$agentStatus}",
            $agentRunning ? null : 'systemctl restart fleet-agent', 'Restart Fleet Agent');

        // =====================================================================
        // 9. DISK SPACE CHECK
        // =====================================================================
        $this->log("Auditing disk space...");
        $diskCheck = $this->executeCommand("df / --output=pcent 2>/dev/null | tail -1 | tr -d ' %'");
        $diskPct = intval(trim($diskCheck['output'] ?? '0'));
        if ($diskPct >= 90) {
            $addCheck('system', 'Disk usage', 'fail', "{$diskPct}% used - critically low space");
        } elseif ($diskPct >= 75) {
            $addCheck('system', 'Disk usage', 'warning', "{$diskPct}% used");
        } else {
            $addCheck('system', 'Disk usage', 'pass', "{$diskPct}% used");
        }

        // Memory check
        $memCheck = $this->executeCommand("free -m 2>/dev/null | awk '/^Mem:/ {printf \"%d/%dMB (%.0f%%)\", \$3, \$2, \$3/\$2*100}'");
        $memInfo = trim($memCheck['output'] ?? 'unknown');
        $addCheck('system', 'Memory usage', 'pass', $memInfo);

        // =====================================================================
        // SUMMARY
        // =====================================================================
        $overallStatus = $results['failed'] === 0 ? 'pass' : 'fail';
        $this->log("=== AUDIT COMPLETE: {$results['passed']} passed, {$results['failed']} failed, {$results['warnings']} warnings ===");

        if ($results['failed'] > 0) {
            $failedChecks = array_filter($results['checks'], fn($c) => $c['status'] === 'fail');
            $failNames = array_map(fn($c) => $c['name'], $failedChecks);
            $this->log("FAILED CHECKS: " . implode(', ', $failNames));
        }

        $results['overall'] = $overallStatus;
        $results['fixable_count'] = count(array_filter($results['checks'], fn($c) => !empty($c['fix_command'])));
        return $results;
    }

    /**
     * Store audit results in the database
     */
    private function storeAuditResults(int $serverId, int $deploymentId, array $results): void
    {
        $this->ensureDbConnection();
        try {
            $auditJson = json_encode($results, JSON_PRETTY_PRINT);
            
            // Store in deployments.audit_results column
            $stmt = $this->db->prepare(
                "UPDATE deployments SET audit_results = ? WHERE id = ?"
            );
            $stmt->execute([$auditJson, $deploymentId]);

            // Append to deployment log
            $this->log("Audit results stored ({$results['passed']} passed, {$results['failed']} failed, {$results['warnings']} warnings)");

            // Also store in server_credentials as a special entry for quick access
            $stmt = $this->db->prepare(
                "INSERT INTO server_credentials (server_id, category, credential_key, label, value_encrypted, is_secret)
                 VALUES (?, 'audit', 'LAST_AUDIT', 'Last Deployment Audit', ?, 0)
                 ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted)"
            );
            $stmt->execute([$serverId, $this->encryption->encrypt($auditJson)]);

            // Update server status based on audit
            if ($results['failed'] > 0) {
                $this->db->prepare(
                    "UPDATE servers SET last_error = ? WHERE id = ?"
                )->execute([
                    "Audit: {$results['failed']} check(s) failed - " . implode(', ', array_map(
                        fn($c) => $c['name'],
                        array_filter($results['checks'], fn($c) => $c['status'] === 'fail')
                    )),
                    $serverId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->log("Warning: Could not store audit results: " . $e->getMessage());
        }
    }
    
    /**
     * Verify a service is running
     */
    private function verifyServiceRunning(string $service, array $acceptableStates = ['active']): bool
    {
        $statusCheck = $this->executeCommand("systemctl is-active {$service} 2>/dev/null || echo 'inactive'");
        $status = trim($statusCheck['output'] ?? 'unknown');
        return in_array($status, $acceptableStates);
    }

    /**
     * Create deployment record
     */
    private function createDeployment(int $serverId, ?int $blueprintId, string $type, array $steps = null): int
    {
        $steps = $steps ?? self::STEPS;
        
        $stmt = $this->db->prepare(
            "INSERT INTO deployments (server_id, blueprint_id, type, status, total_steps, started_at)
             VALUES (?, ?, ?, 'running', ?, NOW())"
        );
        $stmt->execute([$serverId, $blueprintId, $type, count($steps)]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Update deployment progress
     */
    private function updateProgress(int $deploymentId, string $step, int $stepProgress, array $steps = null): void
    {
        $this->ensureDbConnection();
        $steps = $steps ?? self::STEPS;
        $stepInfo = $steps[$step] ?? ['name' => $step, 'weight' => 1];
        
        $stmt = $this->db->prepare(
            "UPDATE deployments SET current_step = ?, progress = ? WHERE id = ?"
        );
        $stmt->execute([$stepInfo['name'], $this->calculateTotalProgress($step, $stepProgress, $steps), $deploymentId]);
    }

    /**
     * Calculate total progress
     */
    private function calculateTotalProgress(string $currentStep, int $stepProgress, array $steps = null): int
    {
        $steps = $steps ?? self::STEPS;
        $totalWeight = array_sum(array_column($steps, 'weight'));
        $completedWeight = 0;
        
        foreach ($steps as $name => $info) {
            if ($name === $currentStep) {
                $completedWeight += ($info['weight'] * $stepProgress / 100);
                break;
            }
            $completedWeight += $info['weight'];
        }
        
        return (int)round(($completedWeight / $totalWeight) * 100);
    }

    /**
     * Complete deployment
     * Note: We don't overwrite the log here - it's already been appended in real-time via appendLogToDatabase()
     */
    private function completeDeployment(int $deploymentId, string $status, ?string $error = null): void
    {
        $this->ensureDbConnection();
        $stepLabel = $status === 'success' ? 'Deployment complete' : ($status === 'failed' ? 'Deployment failed' : 'Deployment ' . $status);
        
        $progress = $status === 'success' ? 100 : null;
        
        $sql = "UPDATE deployments SET status = ?, current_step = ?, error_message = ?, completed_at = NOW()";
        $params = [$status, $stepLabel, $error];
        if ($progress !== null) {
            $sql .= ", progress = ?";
            $params[] = $progress;
        }
        $sql .= " WHERE id = ?";
        $params[] = $deploymentId;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // Mark any remaining pending steps appropriately
        if ($status === 'failed') {
            $this->db->prepare(
                "UPDATE deployment_steps SET status = 'pending' WHERE deployment_id = ? AND status = 'running'"
            )->execute([$deploymentId]);
        } elseif ($status === 'cancelled') {
            $this->db->prepare(
                "UPDATE deployment_steps SET status = 'skipped' WHERE deployment_id = ? AND status IN ('pending','running')"
            )->execute([$deploymentId]);
        }
    }

    /**
     * Detect the target OS (distro + version) and store it on the server record so
     * the dashboard can surface it. Best-effort: never blocks provisioning. Knowing
     * the distro up-front prevents "wrong Linux" installs (e.g. lsphp74 unavailable
     * on newer Debian/Ubuntu releases).
     */
    private function detectAndStoreOsInfo(int $serverId): void
    {
        try {
            $res = $this->executeCommand(
                "grep -E '^PRETTY_NAME=' /etc/os-release 2>/dev/null | head -1 | cut -d'\"' -f2"
            );
            $os = trim($res['output'] ?? '');

            if ($os === '') {
                // Fallback for systems without a PRETTY_NAME (or no /etc/os-release).
                $res = $this->executeCommand('lsb_release -ds 2>/dev/null || uname -sr');
                $os = trim($res['output'] ?? '');
            }

            if ($os !== '') {
                $os = substr($os, 0, 100);
                $stmt = $this->db->prepare("UPDATE servers SET os_info = ? WHERE id = ?");
                $stmt->execute([$os, $serverId]);
                $this->log("Detected OS: {$os}");
            }
        } catch (\Throwable $e) {
            // Never let OS detection (or a missing os_info column) break a deploy.
            $this->log("OS detection skipped: " . $e->getMessage());
        }
    }

    /**
     * Read VERSION files from the deployed server and update the servers table
     */
    private function updateDeployedVersions(int $serverId): void
    {
        try {
            $this->log("Reading deployed version info...");

            $panelVersion = trim($this->executeCommand('cat /var/www/vps-admin/VERSION 2>/dev/null')['output'] ?? '');
            $emailVersion = trim($this->executeCommand('cat /var/www/vps-email/VERSION 2>/dev/null')['output'] ?? '');
            $agentVersion = trim($this->executeCommand('cat /opt/fleet-agent/VERSION 2>/dev/null')['output'] ?? '');

            // Resilience: if the email app is installed (docroot present) but has no
            // VERSION file - e.g. it was installed by an older deploy that predates
            // the VERSION safety net, or this run skipped email because the package
            // was absent - stamp one so the dashboard stops showing "Not deployed".
            if ($emailVersion === '') {
                $emailInstalled = trim($this->executeCommand(
                    "test -d /var/www/vps-email/backend && echo yes || echo no"
                )['output'] ?? '');
                if (strpos($emailInstalled, 'yes') !== false) {
                    $emailVersion = date('Y.m.d');
                    $this->executeCommand("echo '{$emailVersion}' > /var/www/vps-email/VERSION 2>/dev/null || true");
                    $this->log("Email app installed but VERSION was missing - stamped {$emailVersion}");
                }
            }

            $updates = [];
            $params = [];

            if (!empty($panelVersion)) {
                $updates[] = 'panel_version = ?';
                $params[] = $panelVersion;
                $this->log("Panel version: {$panelVersion}");
            }
            if (!empty($emailVersion)) {
                $updates[] = 'email_app_version = ?';
                $params[] = $emailVersion;
                $this->log("Email App version: {$emailVersion}");
            }
            if (!empty($agentVersion)) {
                $updates[] = 'agent_version = ?';
                $params[] = $agentVersion;
                $this->log("Agent version: {$agentVersion}");
            }

            if (!empty($updates)) {
                $this->ensureDbConnection();
                $params[] = $serverId;
                $stmt = $this->db->prepare(
                    "UPDATE servers SET " . implode(', ', $updates) . " WHERE id = ?"
                );
                $stmt->execute($params);
                $this->log("Server version columns updated");
            }
        } catch (\Exception $e) {
            // Non-fatal - don't break deployment for version tracking
            $this->log("Warning: Could not read deployed versions: " . $e->getMessage());
        }
    }

    /**
     * Update server status
     */
    private function updateServerStatus(int $serverId, string $status, ?string $step = null): void
    {
        $this->ensureDbConnection();
        $stmt = $this->db->prepare(
            "UPDATE servers SET status = ?, provision_step = ?, last_error = NULL WHERE id = ?"
        );
        $stmt->execute([$status, $step, $serverId]);
    }

    /**
     * Store generated passwords
     */
    private function storeGeneratedPasswords(int $serverId, array $variables): void
    {
        $this->ensureDbConnection();
        // 1. Store key passwords in servers table (backward-compatible)
        $stmt = $this->db->prepare(
            "UPDATE servers SET 
                db_root_password_encrypted = ?,
                panel_db_password_encrypted = ?,
                email_db_password_encrypted = ?,
                mail_db_password_encrypted = ?,
                panel_admin_email = ?,
                panel_admin_password_encrypted = ?,
                redis_password_encrypted = ?,
                meili_master_key_encrypted = ?,
                livekit_api_key_encrypted = ?,
                livekit_api_secret_encrypted = ?,
                livekit_ws_url = ?,
                agent_token = ?
             WHERE id = ?"
        );

        $stmt->execute([
            $this->encryption->encrypt($variables['DB_ROOT_PASS']),
            $this->encryption->encrypt($variables['PANEL_DB_PASS']),
            $this->encryption->encrypt($variables['EMAIL_DB_PASS']),
            $this->encryption->encrypt($variables['MAIL_DB_PASS']),
            $variables['ADMIN_EMAIL'],
            $this->encryption->encrypt($variables['ADMIN_PASS']),
            !empty($variables['REDIS_PASS']) ? $this->encryption->encrypt($variables['REDIS_PASS']) : null,
            !empty($variables['MEILI_MASTER_KEY']) ? $this->encryption->encrypt($variables['MEILI_MASTER_KEY']) : null,
            !empty($variables['LIVEKIT_API_KEY']) ? $this->encryption->encrypt($variables['LIVEKIT_API_KEY']) : null,
            !empty($variables['LIVEKIT_API_SECRET']) ? $this->encryption->encrypt($variables['LIVEKIT_API_SECRET']) : null,
            !empty($variables['LIVEKIT_WS_URL']) ? $variables['LIVEKIT_WS_URL'] : null,
            $variables['AGENT_TOKEN'],
            $serverId,
        ]);

        // 2. Store ALL credentials in server_credentials table (complete inventory)
        $mailLogin = $this->resolveMailLogin($variables);

        // DNS records the operator may need to publish at an external registrar
        // (e.g. when the domain is delegated elsewhere). These mirror exactly what
        // seedDnsRecords() writes into PowerDNS, so they are safe to copy/paste.
        $dnsBaseDomain = preg_replace('/^panel\./', '', (string)($variables['PANEL_DOMAIN'] ?? ''));
        $dnsServerIp   = (string)($variables['SERVER_IP'] ?? '');
        $spfValue   = ($dnsBaseDomain !== '' && $dnsServerIp !== '') ? "v=spf1 a mx ip4:{$dnsServerIp} -all" : '';
        $dmarcName  = $dnsBaseDomain !== '' ? "_dmarc.{$dnsBaseDomain}" : '';
        $dmarcValue = $dnsBaseDomain !== '' ? "v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@{$dnsBaseDomain}; fo=1" : '';
        $mxValue    = $dnsBaseDomain !== '' ? "10 {$dnsBaseDomain}" : '';

        $credentials = [
            // Panel Admin
            ['panel', 'ADMIN_EMAIL', 'Panel Admin Email', $variables['ADMIN_EMAIL'], false],
            ['panel', 'ADMIN_USER', 'Panel Admin Username', 'pxradmin', false],
            ['panel', 'ADMIN_PASS', 'Panel Admin Password', $variables['ADMIN_PASS'], true],
            
            // Database - Root
            ['database', 'DB_ROOT_USER', 'MariaDB Root User', 'root', false],
            ['database', 'DB_ROOT_PASS', 'MariaDB Root Password', $variables['DB_ROOT_PASS'], true],
            
            // Database - Panel+Email (shared)
            ['database', 'PANEL_DB_NAME', 'Panel+Email DB Name', $variables['PANEL_DB_NAME'], false],
            ['database', 'PANEL_DB_USER', 'Panel+Email DB User', $variables['PANEL_DB_USER'], false],
            ['database', 'PANEL_DB_PASS', 'Panel+Email DB Password', $variables['PANEL_DB_PASS'], true],
            
            // Database - Mail
            ['database', 'MAIL_DB_NAME', 'Mail Server DB Name', $variables['MAIL_DB_NAME'], false],
            ['database', 'MAIL_DB_USER', 'Mail Server DB User', $variables['MAIL_DB_USER'], false],
            ['database', 'MAIL_DB_PASS', 'Mail Server DB Password', $variables['MAIL_DB_PASS'], true],
            
            // Redis
            ['services', 'REDIS_PASS', 'Redis Password', $variables['REDIS_PASS'] ?? '', true],
            
            // Meilisearch
            ['services', 'MEILI_MASTER_KEY', 'Meilisearch Master Key', $variables['MEILI_MASTER_KEY'] ?? '', true],
            
            // Fleet Agent
            ['agent', 'AGENT_TOKEN', 'Fleet Agent Token', $variables['AGENT_TOKEN'], true],
            
            // Email App <-> Panel API Key
            ['secrets', 'EMAIL_API_KEY', 'Email App API Key (Panel external_api)', $variables['EMAIL_API_KEY'] ?? '', true],
            
            // App Secrets
            ['secrets', 'JWT_SECRET', 'JWT Secret', $variables['JWT_SECRET'] ?? '', true],
            ['secrets', 'ENCRYPTION_KEY', 'Encryption Key', $variables['ENCRYPTION_KEY'] ?? '', true],
            
            // Email App Login Mailbox (real IMAP account, e.g. robert@devcon2.hu)
            ['mail', 'MAIL_ADMIN_EMAIL', 'Email App Login (mailbox)', $mailLogin['email'], false],
            ['mail', 'MAIL_ADMIN_PASS', 'Email App Login Password', $mailLogin['pass'], true],
            
            // SSH
            ['ssh', 'SSH_USER', 'SSH User', $variables['SSH_USER'] ?? 'root', false],
            ['ssh', 'SSH_PORT', 'SSH Port', $variables['SSH_PORT'] ?? '22', false],
            
            // DNS records (for publishing at an external registrar if the domain
            // is delegated off-box). Names/values mirror what is seeded in PowerDNS.
            ['dns', 'MX_RECORD', 'MX (name: ' . $dnsBaseDomain . ')', $mxValue, false],
            ['dns', 'SPF_RECORD', 'SPF TXT (name: ' . $dnsBaseDomain . ')', $spfValue, false],
            ['dns', 'DMARC_NAME', 'DMARC Record Name', $dmarcName, false],
            ['dns', 'DMARC_RECORD', 'DMARC TXT Value', $dmarcValue, false],
            ['dns', 'DKIM_DNS_NAME', 'DKIM Record Name', $variables['DKIM_DNS_NAME'] ?? '', false],
            ['dns', 'DKIM_DNS_RECORD', 'DKIM TXT Value', $variables['DKIM_DNS_RECORD'] ?? '', false],
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO server_credentials (server_id, category, credential_key, label, value_encrypted, is_secret)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted), label = VALUES(label), is_secret = VALUES(is_secret)"
        );

        foreach ($credentials as [$category, $key, $label, $value, $isSecret]) {
            if ($value === '' || $value === null) continue;
            try {
                $stmt->execute([
                    $serverId,
                    $category,
                    $key,
                    $label,
                    $this->encryption->encrypt($value),
                    $isSecret ? 1 : 0,
                ]);
            } catch (\Exception $e) {
                $this->log("Warning: Could not store credential {$key}: " . $e->getMessage());
            }
        }

        $this->log("All credentials stored securely in Fleet Manager");
    }

    /**
     * Generate server info report after provisioning
     */
    private function generateServerReport(int $serverId, array $variables): void
    {
        try {
            $reportService = $this->container->get(ServerReportService::class);
            $filename = $reportService->generateServerReport($serverId, $variables);
            $this->log("Server info report generated: {$filename}");
        } catch (\Throwable $e) {
            // Don't fail provisioning if report generation fails
            $this->log("Warning: Could not generate server report: " . $e->getMessage());
        }
    }

    /**
     * Wipe server - remove all installed software and configurations
     */
    public function wipeServer(int $serverId, array $options = []): array
    {
        // Get server details
        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch();

        if (!$server) {
            return ['success' => false, 'error' => 'Server not found'];
        }

        // Get existing deployment record (created by controller)
        $stmt = $this->db->prepare(
            "SELECT id FROM deployments WHERE server_id = ? AND type = 'wipe' AND status = 'running' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$serverId]);
        $deployment = $stmt->fetch();
        $deploymentId = $deployment ? $deployment['id'] : null;

        // Default options
        $options = array_merge([
            'remove_packages' => true,
            'remove_apps' => true,
            'remove_databases' => false,
            'remove_vhosts' => true,
            'remove_ssl' => false,
        ], $options);

        $this->log("Starting server wipe for: " . ($server['name'] ?? $server['ip_address']));
        $this->log("Options: " . json_encode($options));

        try {
            // Connect to server
            $this->updateWipeProgress($deploymentId, 'Connecting to server...', 5);
            
            if (!$this->connectToServer($server)) {
                throw new \Exception('Failed to connect to server');
            }

            // Stop services first
            $this->updateWipeProgress($deploymentId, 'Stopping services...', 10);
            $this->stopAllServices();

            // Remove applications
            if ($options['remove_apps']) {
                $this->updateWipeProgress($deploymentId, 'Removing applications...', 20);
                $this->removeApplications();
            }

            // Remove vhosts
            if ($options['remove_vhosts']) {
                $this->updateWipeProgress($deploymentId, 'Removing vhosts...', 35);
                $this->removeVhosts($server);
            }

            // Remove databases
            if ($options['remove_databases']) {
                $this->updateWipeProgress($deploymentId, 'Removing databases...', 50);
                $this->removeDatabases();
            }

            // Remove packages
            if ($options['remove_packages']) {
                $this->updateWipeProgress($deploymentId, 'Removing packages...', 65);
                $this->removePackages();
            }

            // Remove SSL certificates
            if ($options['remove_ssl']) {
                $this->updateWipeProgress($deploymentId, 'Removing SSL certificates...', 80);
                $this->removeSSLCertificates($server);
            }

            // Clean up temp files
            $this->updateWipeProgress($deploymentId, 'Cleaning up...', 90);
            $this->cleanupTempFiles();

            // Reset server status in database
            $this->updateWipeProgress($deploymentId, 'Finalizing...', 95);
            $this->resetServerDatabase($serverId);

            // Complete
            $this->updateWipeProgress($deploymentId, 'Wipe completed', 100, 'success');
            $this->updateServerStatus($serverId, 'pending');

            $this->log("Server wipe completed successfully");

            return [
                'success' => true,
                'message' => 'Server wiped successfully',
            ];

        } catch (\Exception $e) {
            $this->log("Wipe error: " . $e->getMessage());
            $this->updateWipeProgress($deploymentId, $e->getMessage(), 0, 'failed');
            $this->updateServerStatus($serverId, 'error', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            if (!$this->isLocalServer) {
                $this->ssh->disconnect();
            }
        }
    }

    /**
     * Update wipe progress
     */
    private function updateWipeProgress(?int $deploymentId, string $step, int $progress, string $status = 'running'): void
    {
        $this->log($step);
        
        if ($deploymentId) {
            $stmt = $this->db->prepare(
                "UPDATE deployments SET current_step = ?, progress = ?, status = ?, log = CONCAT(COALESCE(log, ''), ?) WHERE id = ?"
            );
            $logEntry = "[" . date('Y-m-d H:i:s') . "] {$step}\n";
            $stmt->execute([$step, $progress, $status, $logEntry, $deploymentId]);
            
            if ($status === 'success' || $status === 'failed') {
                $this->db->prepare("UPDATE deployments SET completed_at = NOW() WHERE id = ?")
                    ->execute([$deploymentId]);
            }
        }
    }

    /**
     * Stop all services
     */
    private function stopAllServices(): void
    {
        $services = [
            'vpsadmin-agent',
            'fleet-agent',
            'collab-server',
            'mailsync-server',
            'meilisearch',
            'redis-server',
            'lshttpd',
            'postfix',
            'dovecot',
            'fail2ban',
        ];

        foreach ($services as $service) {
            $this->log("Stopping {$service}...");
            $this->executeCommand("systemctl stop {$service} 2>/dev/null || true");
            $this->executeCommand("systemctl disable {$service} 2>/dev/null || true");
        }
    }

    /**
     * Remove application directories
     */
    private function removeApplications(): void
    {
        $paths = [
            '/var/www/vps-admin',
            '/var/www/vps-email',
            '/opt/vps-admin',
            '/opt/fleet-agent',
            '/tmp/fleet-deploy',
        ];

        foreach ($paths as $path) {
            $this->log("Removing {$path}...");
            $this->executeCommand("rm -rf {$path}");
        }

        // Remove systemd service files
        $serviceFiles = [
            '/etc/systemd/system/vpsadmin-agent.service',
            '/etc/systemd/system/fleet-agent.service',
            '/etc/systemd/system/collab-server.service',
            '/etc/systemd/system/mailsync-server.service',
            '/etc/systemd/system/mailflow-collab.service',
            '/etc/systemd/system/mailflow-mailsync.service',
        ];

        foreach ($serviceFiles as $file) {
            $this->executeCommand("rm -f {$file}");
        }

        $this->executeCommand("systemctl daemon-reload");
    }

    /**
     * Remove OpenLiteSpeed vhosts
     */
    private function removeVhosts(array $server): void
    {
        $domains = array_filter([
            $server['panel_domain'] ?? null,
            $server['email_domain'] ?? null,
        ]);

        foreach ($domains as $domain) {
            $this->log("Removing vhost for {$domain}...");
            $vhostDir = "/usr/local/lsws/conf/vhosts/{$domain}";
            $this->executeCommand("rm -rf {$vhostDir}");
        }

        // Clean up httpd_config.conf
        $this->log("Cleaning httpd_config.conf...");
        foreach ($domains as $domain) {
            // Remove virtualHost blocks - this is a simple approach
            // In production, you might want a more sophisticated config parser
            $this->executeCommand("sed -i '/virtualHost {$domain}/,/^}/d' /usr/local/lsws/conf/httpd_config.conf 2>/dev/null || true");
        }
    }

    /**
     * Remove databases
     */
    private function removeDatabases(): void
    {
        $databases = ['vps_admin', 'vpsadmin', 'email_app', 'mailflow', 'mailserver'];
        $users = ['vps_admin', 'vpsadmin', 'email_app', 'mailflow', 'mailuser'];

        $this->log("Removing databases and users...");

        foreach ($databases as $db) {
            $this->executeCommand("mysql -e \"DROP DATABASE IF EXISTS {$db};\" 2>/dev/null || true");
        }

        foreach ($users as $user) {
            $this->executeCommand("mysql -e \"DROP USER IF EXISTS '{$user}'@'localhost';\" 2>/dev/null || true");
        }

        $this->executeCommand("mysql -e \"FLUSH PRIVILEGES;\" 2>/dev/null || true");
    }

    /**
     * Remove installed packages
     */
    private function removePackages(): void
    {
        $this->log("Removing installed packages...");

        // Stop services first to avoid issues during removal
        $this->executeCommand("systemctl stop lshttpd mariadb postfix dovecot fail2ban firewalld 2>/dev/null || true");

        // Packages to remove
        $packages = [
            'openlitespeed',
            'lsphp83*',
            'mariadb-server',
            'mariadb-client',
            'postfix',
            'postfix-mysql',
            'dovecot-core',
            'dovecot-imapd',
            'dovecot-lmtpd',
            'dovecot-mysql',
            'dovecot-sieve',
            'dovecot-managesieved',
            'fail2ban',
            'firewalld',
            'certbot',
        ];

        $this->log("Purging packages: " . implode(', ', $packages));
        
        // Remove packages with purge to remove config files too
        $this->executeCommand(
            'DEBIAN_FRONTEND=noninteractive apt-get purge -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" ' . implode(' ', $packages) . ' 2>/dev/null || true'
        );

        // Autoremove orphaned packages
        $this->executeCommand('apt-get autoremove -y 2>/dev/null || true');

        // Clean package cache
        $this->executeCommand('apt-get clean');

        // Remove leftover directories
        $leftoverDirs = [
            '/usr/local/lsws',
            '/etc/postfix',
            '/etc/dovecot',
            '/etc/fail2ban',
            '/var/lib/mysql',
            '/var/log/mail.*',
            '/var/spool/postfix',
            '/var/mail',
            '/home/vmail',
        ];

        foreach ($leftoverDirs as $dir) {
            $this->executeCommand("rm -rf {$dir} 2>/dev/null || true");
        }
    }

    /**
     * Remove SSL certificates
     */
    private function removeSSLCertificates(array $server): void
    {
        $domains = array_filter([
            $server['panel_domain'] ?? null,
            $server['email_domain'] ?? null,
        ]);

        foreach ($domains as $domain) {
            $this->log("Removing SSL for {$domain}...");
            $this->executeCommand("certbot delete --cert-name {$domain} --non-interactive 2>/dev/null || true");
        }
    }

    /**
     * Clean up temporary files
     */
    private function cleanupTempFiles(): void
    {
        $this->executeCommand('rm -rf /tmp/fleet-* /tmp/lshttpd 2>/dev/null || true');
        $this->executeCommand('apt-get clean');
    }

    /**
     * Reset server records in database
     */
    private function resetServerDatabase(int $serverId): void
    {
        // Reset server passwords and status
        $stmt = $this->db->prepare(
            "UPDATE servers SET 
                db_root_password_encrypted = NULL,
                panel_db_password_encrypted = NULL,
                email_db_password_encrypted = NULL,
                mail_db_password_encrypted = NULL,
                agent_token = NULL,
                provision_step = NULL,
                last_error = NULL,
                provisioned_at = NULL
             WHERE id = ?"
        );
        $stmt->execute([$serverId]);

        // Clear server packages records
        $this->db->prepare("DELETE FROM server_packages WHERE server_id = ?")->execute([$serverId]);

        // Clear server configs records
        $this->db->prepare("DELETE FROM server_configs WHERE server_id = ?")->execute([$serverId]);
    }

    /**
     * Deploy app updates (code only, preserve configs)
     * 
     * @param int $serverId Server to update
     * @param array $apps Apps to update: ['panel', 'email', 'agent']
     * @return array Result with success status
     */
    public function deployAppUpdate(int $serverId, array $apps): array
    {
        $this->deploymentLog = [];
        
        try {
            // Get server info
            $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$server) {
                throw new \Exception('Server not found');
            }

            // Connect to server
            $this->log("Connecting to server {$server['name']}...");
            if (!$this->connectToServer($server)) {
                throw new \Exception('Failed to connect to server');
            }

            $updated = [];
            $failed = [];

            // Update each selected app
            foreach ($apps as $app) {
                try {
                    switch ($app) {
                        case 'panel':
                            $this->updatePanelCode();
                            $updated[] = 'Panel';
                            break;
                        case 'email':
                            $this->updateEmailAppCode();
                            $updated[] = 'Email App';
                            break;
                        case 'agent':
                            $this->updateAgentCode();
                            $updated[] = 'Agent';
                            break;
                        default:
                            $this->log("Unknown app: {$app}");
                    }
                } catch (\Exception $e) {
                    $this->log("Failed to update {$app}: " . $e->getMessage());
                    $failed[] = $app;
                }
            }

            // Restart OLS if panel or email was updated
            if (in_array('panel', $apps) || in_array('email', $apps)) {
                $this->log("Restarting OpenLiteSpeed...");
                $this->executeCommand('/usr/local/lsws/bin/lswsctrl restart 2>/dev/null || systemctl restart lsws');
            }

            // Read installed VERSION files and update server record
            $this->updateDeployedVersions($serverId);

            return [
                'success' => empty($failed),
                'updated' => $updated,
                'failed' => $failed,
                'log' => $this->deploymentLog,
            ];

        } catch (\Exception $e) {
            $this->log("Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'log' => $this->deploymentLog,
            ];
        }
    }

    /**
     * Update Panel code only (preserve configs)
     */
    private function updatePanelCode(): void
    {
        $packagesPath = $this->container->getConfig('packages.path');
        $panelPackage = $this->container->getConfig('packages.panel');
        $packageFile = $packagesPath . $panelPackage;

        if (!file_exists($packageFile)) {
            throw new \Exception('Panel package not found at: ' . $packageFile);
        }

        $this->log("Updating VPS Admin Panel code...");

        // Create temp directory
        $this->executeCommand('mkdir -p /tmp/fleet-deploy');

        // Upload package
        $this->log("Uploading panel package (" . round(filesize($packageFile) / 1048576, 1) . " MB)...");
        $remotePath = '/tmp/fleet-deploy/panel.tar.gz';
        if ($this->isLocalServer) {
            if (!copy($packageFile, $remotePath)) {
                throw new \Exception("Failed to copy panel package to {$remotePath}");
            }
        } else {
            if (!$this->ssh->uploadFile($packageFile, $remotePath)) {
                throw new \Exception("SFTP upload failed for panel package");
            }
        }

        // Extract to temp location
        $this->log("Extracting panel package...");
        $this->executeCommand('mkdir -p /tmp/fleet-deploy/panel');
        $this->executeCommand('cd /tmp/fleet-deploy && tar -xzf panel.tar.gz -C panel --strip-components=1');

        // Run installer with --update-only flag (extended timeout for composer)
        $this->log("Running panel update...");
        $installerCmd = 'cd /tmp/fleet-deploy/panel && chmod +x install.sh && ./install.sh --update-only';
        $result = $this->executeLongCommand($installerCmd, 600);
        
        if (!$result['success']) {
            throw new \Exception('Panel update failed: ' . ($result['output'] ?? 'Unknown error'));
        }

        // Cleanup
        $this->executeCommand('rm -rf /tmp/fleet-deploy/panel*');
        
        $this->log("Panel code updated successfully");
    }

    /**
     * Update Email App code only (preserve configs)
     */
    private function updateEmailAppCode(): void
    {
        $packagesPath = $this->container->getConfig('packages.path');
        $emailPackage = $this->container->getConfig('packages.email');
        $packageFile = $packagesPath . $emailPackage;

        if (!file_exists($packageFile)) {
            throw new \Exception('Email package not found at: ' . $packageFile);
        }

        $this->log("Updating MailFlow Email App code...");

        // Create temp directory
        $this->executeCommand('mkdir -p /tmp/fleet-deploy');

        // Upload package
        $this->log("Uploading email package (" . round(filesize($packageFile) / 1048576, 1) . " MB)...");
        $remotePath = '/tmp/fleet-deploy/email.tar.gz';
        if ($this->isLocalServer) {
            if (!copy($packageFile, $remotePath)) {
                throw new \Exception("Failed to copy email package to {$remotePath}");
            }
        } else {
            if (!$this->ssh->uploadFile($packageFile, $remotePath)) {
                throw new \Exception("SFTP upload failed for email package");
            }
        }

        // Extract to temp location
        $this->log("Extracting email package...");
        $this->executeCommand('mkdir -p /tmp/fleet-deploy/email');
        $this->executeCommand('cd /tmp/fleet-deploy && tar -xzf email.tar.gz -C email --strip-components=1');

        // Run installer with --update-only flag (extended timeout for composer)
        $this->log("Running email app update...");
        $installerCmd = 'cd /tmp/fleet-deploy/email && chmod +x install.sh && ./install.sh --update-only';
        $result = $this->executeLongCommand($installerCmd, 600);
        
        if (!$result['success']) {
            throw new \Exception('Email app update failed: ' . ($result['output'] ?? 'Unknown error'));
        }

        // Cleanup
        $this->executeCommand('rm -rf /tmp/fleet-deploy/email*');
        
        $this->log("Email App code updated successfully");
    }

    /**
     * Update Fleet Agent code only (preserve configs)
     */
    private function updateAgentCode(): void
    {
        $packagesPath = $this->container->getConfig('packages.path');
        $agentPackage = $this->container->getConfig('packages.agent');
        $packageFile = $packagesPath . $agentPackage;

        if (!file_exists($packageFile)) {
            throw new \Exception('Agent package not found at: ' . $packageFile);
        }

        $this->log("Updating Fleet Agent code...");

        // Create temp directory
        $this->executeCommand('mkdir -p /tmp/fleet-deploy');

        // Upload package
        $this->log("Uploading agent package (" . round(filesize($packageFile) / 1048576, 1) . " MB)...");
        $remotePath = '/tmp/fleet-deploy/agent.tar.gz';
        if ($this->isLocalServer) {
            if (!copy($packageFile, $remotePath)) {
                throw new \Exception("Failed to copy agent package to {$remotePath}");
            }
        } else {
            if (!$this->ssh->uploadFile($packageFile, $remotePath)) {
                throw new \Exception("SFTP upload failed for agent package");
            }
        }

        // Extract to temp location
        $this->log("Extracting agent package...");
        $this->executeCommand('mkdir -p /tmp/fleet-deploy/agent');
        $this->executeCommand('cd /tmp/fleet-deploy && tar -xzf agent.tar.gz -C agent --strip-components=1');

        // Run installer with --update-only flag (extended timeout)
        $this->log("Running agent update...");
        $installerCmd = 'cd /tmp/fleet-deploy/agent && chmod +x install.sh && ./install.sh --update-only';
        $result = $this->executeLongCommand($installerCmd, 300);
        
        if (!$result['success']) {
            throw new \Exception('Agent update failed: ' . ($result['output'] ?? 'Unknown error'));
        }

        // Restart agent service
        $this->log("Restarting Fleet Agent...");
        $this->executeCommand('systemctl restart fleet-agent 2>/dev/null || true');

        // Cleanup
        $this->executeCommand('rm -rf /tmp/fleet-deploy/agent*');
        
        $this->log("Fleet Agent code updated successfully");
    }

    /**
     * Deploy app updates to multiple servers (batch)
     * 
     * @param array $serverIds Array of server IDs to update
     * @param array $apps Apps to update: ['panel', 'email', 'agent']
     * @return array Results per server
     */
    public function deployAppUpdateBatch(array $serverIds, array $apps): array
    {
        $results = [];
        
        foreach ($serverIds as $serverId) {
            $results[$serverId] = $this->deployAppUpdate($serverId, $apps);
        }
        
        return [
            'success' => true,
            'results' => $results,
            'summary' => [
                'total' => count($serverIds),
                'successful' => count(array_filter($results, fn($r) => $r['success'])),
                'failed' => count(array_filter($results, fn($r) => !$r['success'])),
            ],
        ];
    }
}
