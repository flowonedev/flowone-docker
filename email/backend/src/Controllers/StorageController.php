<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Core\Database;
use Webmail\Addons\Team\Services\ColleagueService;

/**
 * Phase 8 — Storage signals API + operator control plane.
 *
 * Read endpoints (existed since Phase 8 ship):
 *   GET  /api/storage/status            user-facing summary
 *   GET  /api/storage/files/{id}/tier   per-file tier_state (ownership-checked)
 *   GET  /api/admin/storage/dashboard   full admin payload (is_admin gate)
 *   GET  /api/admin/storage/infra       live NAS + VPN + DDNS health (admin gate)
 *
 * Write endpoints (admin gate, all idempotent / safe-to-retry):
 *   POST /api/admin/storage/reclaim/pause    write reclaim.paused flag
 *   POST /api/admin/storage/reclaim/resume   remove reclaim.paused flag
 *   POST /api/admin/storage/backup/pause     write backup.paused flag
 *   POST /api/admin/storage/backup/resume    remove backup.paused flag
 *   POST /api/admin/storage/freeze           write freeze.flag (global stop)
 *   POST /api/admin/storage/unfreeze         remove freeze.flag
 *   POST /api/admin/storage/backup/snapshot  queue an ad-hoc snapshot run
 *   POST /api/admin/storage/backup/verify    queue a verify run
 *   POST /api/admin/storage/backup/drill     queue a restore-drill run
 *   POST /api/admin/storage/reclaim/cycle    queue a forced reclaim cycle
 *
 * The pause/freeze ops touch flag files directly (fast).
 * The trigger ops (snapshot/verify/drill/cycle) write JSON request files
 * into state.dir/requests/ which the flowone-storage-dispatcher cron
 * (runs every minute) picks up and executes as the privileged daemon
 * user. This avoids granting sudo to the web user.
 *
 * All write endpoints require the active user to be an admin AND the
 * storage library to be present. The full action is logged to
 * /var/log/flowone/storage-control.jsonl for audit.
 */
class StorageController extends BaseController
{
    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    /**
     * GET /api/storage/status
     *
     * User-facing summary. Hides counters that would only matter to
     * an admin (per-tenant byte totals, reclaim daemon internal
     * timers). Cheap: the underlying StorageBudget snapshot is
     * cached for 30s server-side.
     */
    public function status(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->storageLibraryAvailable()) {
            return Response::success([
                'available' => false,
                'reason'    => 'storage_library_not_available',
            ]);
        }

        try {
            $cfg = \FlowOne\Storage\Config::load();
        } catch (\Throwable $e) {
            return Response::success([
                'available' => false,
                'reason'    => 'config_load_failed: ' . $e->getMessage(),
            ]);
        }

        $pdo = $this->openPdo();
        $signer = $this->loadSigner($cfg);

        $payload = [
            'available' => true,
            'budget'    => $this->safeBudget($cfg, $pdo),
            'reclaim'   => $this->reclaimSummary($cfg, $signer),
            'backup'    => $this->backupSummary($cfg, $signer),
        ];
        return Response::success($payload);
    }

    /**
     * GET /api/storage/files/{id}/tier
     *
     * Per-file tier_state lookup. The frontend uses this for the
     * TierBadge after a row has been clicked / inspected; bulk
     * lookups are NOT supported here — the drive list query already
     * returns tier_state inline for every row (Phase 4 schema).
     */
    public function fileTier(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $fileId = (int) $request->getParam('id');
        if ($fileId <= 0) {
            return Response::error('invalid file id', 400);
        }

        try {
            $pdo = $this->openPdo();
            $stmt = $pdo->prepare(
                "SELECT id, tier_state, storage_location, tier_changed_at, last_read_at
                 FROM drive_files WHERE id = :id AND user_email = :ue LIMIT 1"
            );
            $stmt->execute([':id' => $fileId, ':ue' => $email]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return Response::error('file not found', 404);
            }
            return Response::success([
                'id'               => (int) $row['id'],
                'tier_state'       => (string) ($row['tier_state']       ?? 'hot'),
                'storage_location' => (string) ($row['storage_location'] ?? 'local'),
                'tier_changed_at'  => $row['tier_changed_at'] ?? null,
                'last_read_at'     => $row['last_read_at']    ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('[StorageController.fileTier] ' . $e->getMessage());
            return Response::error('lookup failed', 500);
        }
    }

    /**
     * GET /api/admin/storage/dashboard
     *
     * Admin-only. Bundles the full set of signals operators need to
     * understand what the storage subsystem is doing right now,
     * without SSH access.
     */
    public function adminDashboard(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        if (!$this->isAdmin($email)) {
            return Response::error('admin only', 403);
        }

        if (!$this->storageLibraryAvailable()) {
            return Response::success([
                'available' => false,
                'reason'    => 'storage_library_not_available',
            ]);
        }

        try {
            $cfg = \FlowOne\Storage\Config::load();
        } catch (\Throwable $e) {
            return Response::success([
                'available' => false,
                'reason'    => 'config_load_failed: ' . $e->getMessage(),
            ]);
        }
        $signer = $this->loadSigner($cfg);
        $pdo = $this->openPdo();

        return Response::success([
            'available'      => true,
            'budget'         => $this->safeBudget($cfg, $pdo, bypassCache: true),
            'reclaim'        => $this->reclaimFull($cfg, $signer),
            'backup'         => $this->backupFull($cfg, $signer),
            'tier_counts'    => $this->tierCounts($pdo),
            'phase_flags'    => $this->phaseFlagsView($cfg),
            'paths' => [
                'state_dir'        => (string) ($cfg['state']['dir'] ?? ''),
                'reclaim_state'    => $this->reclaimStatePath($cfg),
                'backup_state'     => $this->backupStatePath($cfg),
                'backup_dest_root' => (string) ($cfg['backup']['destination_root'] ?? ''),
            ],
        ]);
    }

    /**
     * GET /api/admin/storage/infra
     *
     * Live infrastructure card payload — NAS mount, VPN tunnel, DDNS
     * resolution, last OpenVPN reconnect. Probes are bounded to keep
     * the dashboard responsive (~50ms target).
     */
    public function adminInfra(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        if (!$this->isAdmin($email)) {
            return Response::error('admin only', 403);
        }

        if (!$this->storageLibraryAvailable()) {
            return Response::success([
                'available' => false,
                'reason'    => 'storage_library_not_available',
            ]);
        }

        try {
            $cfg = \FlowOne\Storage\Config::load();
        } catch (\Throwable $e) {
            return Response::success([
                'available' => false,
                'reason'    => 'config_load_failed: ' . $e->getMessage(),
            ]);
        }

        return Response::success([
            'available'   => true,
            'nas'         => $this->probeNasMount($cfg),
            'backup_mount'=> $this->probeBackupMount($cfg),
            'vpn'         => $this->probeVpn($cfg),
            'ddns'        => $this->probeDdns($cfg),
            'requests'    => $this->probeRequestDir($cfg),
            'probed_at'   => time(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Write endpoints (admin gate)
    // ──────────────────────────────────────────────────────────────────

    public function reclaimPause(Request $r):  Response { return $this->writeFlag($r, 'reclaim_pause'); }
    public function reclaimResume(Request $r): Response { return $this->writeFlag($r, 'reclaim_resume'); }
    public function backupPause(Request $r):   Response { return $this->writeFlag($r, 'backup_pause'); }
    public function backupResume(Request $r):  Response { return $this->writeFlag($r, 'backup_resume'); }
    public function freeze(Request $r):        Response { return $this->writeFlag($r, 'freeze'); }
    public function unfreeze(Request $r):      Response { return $this->writeFlag($r, 'unfreeze'); }

    public function triggerSnapshot(Request $r): Response { return $this->queueRequest($r, 'snapshot'); }
    public function triggerVerify(Request $r):   Response { return $this->queueRequest($r, 'verify'); }
    public function triggerDrill(Request $r):    Response { return $this->queueRequest($r, 'drill'); }
    public function triggerCycle(Request $r):    Response { return $this->queueRequest($r, 'reclaim_cycle'); }

    /**
     * Write or remove a flag file under state.dir. Used for synchronous
     * pause/resume/freeze. Returns 503 if state.dir is not writable by
     * the web user — operator needs to add web user to flowone-storage
     * group + chmod 0775 state.dir.
     */
    private function writeFlag(Request $request, string $action): Response
    {
        $gate = $this->adminGate($request);
        if ($gate['error'] !== null) return $gate['error'];
        $cfg  = $gate['cfg'];
        $by   = $gate['email'];

        $stateDir = rtrim((string) ($cfg['state']['dir'] ?? '/var/lib/flowone'), '/');

        $map = [
            'reclaim_pause'  => ['op' => 'create', 'path' => $stateDir . '/' . ($cfg['tier']['reclaim']['pause_flag'] ?? 'reclaim.paused')],
            'reclaim_resume' => ['op' => 'remove', 'path' => $stateDir . '/' . ($cfg['tier']['reclaim']['pause_flag'] ?? 'reclaim.paused')],
            'backup_pause'   => ['op' => 'create', 'path' => $stateDir . '/' . ($cfg['backup']['pause_flag']        ?? 'backup.paused')],
            'backup_resume'  => ['op' => 'remove', 'path' => $stateDir . '/' . ($cfg['backup']['pause_flag']        ?? 'backup.paused')],
            'freeze'         => ['op' => 'create', 'path' => $stateDir . '/' . ($cfg['state']['freeze_flag']        ?? 'freeze.flag')],
            'unfreeze'       => ['op' => 'remove', 'path' => $stateDir . '/' . ($cfg['state']['freeze_flag']        ?? 'freeze.flag')],
        ];

        if (!isset($map[$action])) {
            return Response::error('unknown action: ' . $action, 400);
        }

        $entry  = $map[$action];
        $reason = (string) ($request->input('reason') ?? 'panel operator action');

        if ($entry['op'] === 'create') {
            $payload = json_encode([
                'created_at' => date('c'),
                'reason'     => $reason,
                'by'         => $by,
                'via'        => 'storage-admin-panel',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ok = @file_put_contents($entry['path'], $payload) !== false;
            if (!$ok) {
                $this->audit($action, $by, ['ok' => false, 'reason' => 'write_failed', 'path' => $entry['path']]);
                return Response::error(
                    'cannot write flag file (check state.dir perms: chmod 0775 + add web user to flowone-storage group)',
                    503
                );
            }
            @chmod($entry['path'], 0664);
        } else {
            // remove is idempotent — missing file is success
            if (is_file($entry['path']) && !@unlink($entry['path'])) {
                $this->audit($action, $by, ['ok' => false, 'reason' => 'unlink_failed', 'path' => $entry['path']]);
                return Response::error('cannot remove flag file', 503);
            }
        }

        $this->audit($action, $by, ['ok' => true, 'reason' => $reason, 'path' => $entry['path']]);
        return Response::success([
            'ok'      => true,
            'action'  => $action,
            'path'    => $entry['path'],
            'present' => is_file($entry['path']),
            'message' => $this->flagMessage($action),
        ]);
    }

    /**
     * Queue a long-running operation request (snapshot/verify/drill/cycle).
     * Writes a JSON request file under state.dir/requests/. The
     * flowone-storage-dispatcher cron (runs once a minute as the
     * privileged daemon user) picks it up, executes it, and removes
     * the file. Web request returns immediately with the queued path.
     */
    private function queueRequest(Request $request, string $kind): Response
    {
        $gate = $this->adminGate($request);
        if ($gate['error'] !== null) return $gate['error'];
        $cfg  = $gate['cfg'];
        $by   = $gate['email'];

        $allowed = ['snapshot', 'verify', 'drill', 'reclaim_cycle'];
        if (!in_array($kind, $allowed, true)) {
            return Response::error('unknown request kind', 400);
        }

        // Block trigger when the relevant phase is OFF — the dispatcher
        // would refuse anyway, but failing fast at the panel saves a
        // confusing "queued but never ran" experience.
        $phaseMap = [
            'snapshot'      => 'phase7_nas_backup',
            'verify'        => 'phase7_nas_backup',
            'drill'         => 'phase7_nas_backup',
            'reclaim_cycle' => 'phase6c_reclaim_daemon',
        ];
        $required = $phaseMap[$kind];
        if (empty($cfg['phases'][$required])) {
            return Response::error(
                "cannot trigger {$kind}: kill switch {$required} is OFF (flip it on first)",
                409
            );
        }

        $stateDir   = rtrim((string) ($cfg['state']['dir'] ?? '/var/lib/flowone'), '/');
        $requestDir = $stateDir . '/requests';
        if (!is_dir($requestDir)) {
            if (!@mkdir($requestDir, 0775, true)) {
                return Response::error('cannot create request dir ' . $requestDir, 503);
            }
        }

        // Filename includes nanos so two concurrent submissions never
        // collide. The dispatcher processes oldest-first by mtime.
        $id   = $kind . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $path = $requestDir . '/' . $id . '.json';

        $payload = json_encode([
            'id'         => $id,
            'kind'       => $kind,
            'queued_at'  => date('c'),
            'queued_unix'=> time(),
            'by'         => $by,
            'reason'     => (string) ($request->input('reason') ?? 'panel operator action'),
            'options'    => is_array($request->input('options')) ? $request->input('options') : [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (@file_put_contents($path, $payload) === false) {
            $this->audit('trigger_' . $kind, $by, ['ok' => false, 'reason' => 'write_failed', 'path' => $path]);
            return Response::error(
                'cannot write request file (check requests dir perms: 0775 owned by flowone-storage)',
                503
            );
        }
        @chmod($path, 0664);

        $this->audit('trigger_' . $kind, $by, ['ok' => true, 'id' => $id, 'path' => $path]);

        return Response::success([
            'ok'        => true,
            'queued'    => true,
            'kind'      => $kind,
            'id'        => $id,
            'path'      => $path,
            'message'   => "Queued {$kind}; dispatcher will pick it up within ~60s. Watch the dashboard or /var/log/flowone/dispatcher.log",
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Infra probes (live, bounded)
    // ──────────────────────────────────────────────────────────────────

    private function probeNasMount(array $cfg): array
    {
        $mount = (string) ($cfg['nas']['mount_point'] ?? '/mnt/nas-drive');
        $hc    = $mount . '/' . (string) ($cfg['nas']['health_file'] ?? '.healthcheck');
        return $this->probeMount($mount, $hc);
    }

    private function probeBackupMount(array $cfg): array
    {
        $mount = (string) ($cfg['backup']['destination_mount'] ?? '/mnt/vps-backup');
        $hc    = $mount . '/' . (string) ($cfg['backup']['healthcheck_file'] ?? '.healthcheck');
        return $this->probeMount($mount, $hc);
    }

    private function probeMount(string $mount, string $hcFile): array
    {
        $isMounted = $this->isPathMounted($mount);
        $free  = $isMounted ? @disk_free_space($mount)  : null;
        $total = $isMounted ? @disk_total_space($mount) : null;
        $hcOk  = $isMounted ? @is_file($hcFile) : false;
        return [
            'mount'         => $mount,
            'mounted'       => $isMounted,
            'healthcheck'   => $hcOk,
            'free_bytes'    => $free  !== false ? $free  : null,
            'total_bytes'   => $total !== false ? $total : null,
            'used_pct'      => ($total && $free !== null && $total > 0)
                                  ? round((1 - ($free / $total)) * 100, 1)
                                  : null,
        ];
    }

    private function isPathMounted(string $path): bool
    {
        // /proc/mounts is authoritative on Linux. We don't shell out
        // because that's slow + spawns a process per dashboard poll.
        if (!is_file('/proc/mounts')) {
            return is_dir($path);
        }
        $needle = rtrim($path, '/');
        $fh = @fopen('/proc/mounts', 'r');
        if (!$fh) return false;
        try {
            while (($line = fgets($fh)) !== false) {
                $parts = preg_split('/\s+/', $line);
                if (isset($parts[1]) && rtrim($parts[1], '/') === $needle) {
                    return true;
                }
            }
        } finally {
            fclose($fh);
        }
        return false;
    }

    private function probeVpn(array $cfg): array
    {
        $iface = (string) ($cfg['vpn']['tun_interface'] ?? 'tun0');
        $base  = '/sys/class/net/' . $iface;
        $up    = false;
        if (is_dir($base)) {
            // tun/tap interfaces report operstate="unknown" because they
            // have no carrier to sense — fall back to IFF_UP (0x1) in
            // /sys/.../flags which is authoritative for virtual ifaces.
            $op = @file_get_contents($base . '/operstate');
            if ($op !== false && trim((string) $op) === 'up') {
                $up = true;
            } else {
                $flags = @file_get_contents($base . '/flags');
                if ($flags !== false && trim((string) $flags) !== '') {
                    $n = intval(trim((string) $flags), 16);
                    $up = ($n & 0x1) === 0x1;
                }
            }
        }
        return [
            'interface' => $iface,
            'up'        => $up,
            'unit'      => (string) ($cfg['vpn']['service_unit'] ?? 'openvpn-client@synology'),
        ];
    }

    private function probeDdns(array $cfg): array
    {
        $host = (string) ($cfg['nas']['ddns_hostname'] ?? '');
        if ($host === '') return ['hostname' => null, 'resolved' => null, 'error' => 'no_hostname'];
        $rec = @gethostbynamel($host);
        return [
            'hostname'     => $host,
            'resolved'     => is_array($rec) ? $rec : null,
            'matches_lan'  => null,  // can't tell from VPS side (LAN IP is private)
        ];
    }

    private function probeRequestDir(array $cfg): array
    {
        $dir = rtrim((string) ($cfg['state']['dir'] ?? '/var/lib/flowone'), '/') . '/requests';
        $exists = is_dir($dir);
        $pending = [];
        if ($exists) {
            $entries = @scandir($dir) ?: [];
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') continue;
                if (substr($e, -5) !== '.json') continue;
                $pending[] = [
                    'name'      => $e,
                    'queued_at' => date('c', (int) @filemtime($dir . '/' . $e)),
                ];
            }
        }
        return [
            'dir'      => $dir,
            'exists'   => $exists,
            'writable' => $exists ? is_writable($dir) : false,
            'pending'  => $pending,
        ];
    }

    private function flagMessage(string $action): string
    {
        return match ($action) {
            'reclaim_pause'  => 'Reclaim daemon paused. The daemon will skip reclaim cycles until you resume.',
            'reclaim_resume' => 'Reclaim daemon resumed.',
            'backup_pause'   => 'Backup pipeline paused. Cron will skip snapshots until you resume.',
            'backup_resume'  => 'Backup pipeline resumed.',
            'freeze'         => 'GLOBAL STORAGE FROZEN. All NAS-touching subsystems will refuse to write until unfrozen.',
            'unfreeze'       => 'Global freeze lifted. Subsystems resume normal operation.',
            default          => 'OK',
        };
    }

    /**
     * Single admin gate used by every write endpoint. Returns
     * ['error' => Response|null, 'cfg' => array, 'email' => string].
     */
    private function adminGate(Request $request): array
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return ['error' => $authError, 'cfg' => [], 'email' => ''];
        }
        $email = $this->getActiveEmail();
        if (!$this->isAdmin($email)) {
            return ['error' => Response::error('admin only', 403), 'cfg' => [], 'email' => $email];
        }
        if (!$this->storageLibraryAvailable()) {
            return ['error' => Response::error('storage library not available', 503), 'cfg' => [], 'email' => $email];
        }
        try {
            $cfg = \FlowOne\Storage\Config::load();
        } catch (\Throwable $e) {
            return ['error' => Response::error('storage config load failed: ' . $e->getMessage(), 503), 'cfg' => [], 'email' => $email];
        }
        return ['error' => null, 'cfg' => $cfg, 'email' => $email];
    }

    /**
     * Append a single-line JSON record to the audit log. Best-effort —
     * if /var/log/flowone is not writable we just error_log() and move
     * on; audit log failure must never break a control operation.
     */
    private function audit(string $action, string $by, array $details): void
    {
        $logDir = '/var/log/flowone';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $line = json_encode([
            'ts'      => date('c'),
            'unix'    => time(),
            'action'  => $action,
            'by'      => $by,
            'via'     => 'storage-admin-panel',
            'remote'  => $_SERVER['REMOTE_ADDR'] ?? '',
            'details' => $details,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (@file_put_contents($logDir . '/storage-control.jsonl', $line, FILE_APPEND | LOCK_EX) === false) {
            error_log("[StorageController.audit] cannot write {$logDir}/storage-control.jsonl — action={$action} by={$by}");
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function storageLibraryAvailable(): bool
    {
        return class_exists(\FlowOne\Storage\Config::class)
            && class_exists(\FlowOne\Storage\StorageBudget::class);
    }

    private function loadSigner(array $cfg): ?\FlowOne\Storage\HmacSigner
    {
        try {
            return \FlowOne\Storage\HmacSigner::fromKeyFile(
                (string) ($cfg['state']['hmac_key_path']      ?? ''),
                (int)    ($cfg['state']['hmac_key_mode_max']  ?? 0640)
            );
        } catch (\Throwable $e) {
            error_log('[StorageController.loadSigner] ' . $e->getMessage());
            return null;
        }
    }

    private function openPdo(): \PDO
    {
        // Delegate to the shared singleton — it already knows how to
        // read $config['db'] and handles credential paths
        // consistently with the rest of the app.
        return Database::getConnection($this->config);
    }

    private function safeBudget(array $cfg, \PDO $pdo, bool $bypassCache = false): array
    {
        try {
            $budget = \FlowOne\Storage\StorageBudget::build($pdo, $cfg);
            $report = $budget->snapshot($bypassCache);
            return [
                'available'        => true,
                'watermark'        => $report->watermark,
                'vps_used_pct'     => $report->vpsUsedPct,
                'vps_free_bytes'   => $report->vpsFreeBytes,
                'vps_total_bytes'  => $report->vpsTotalBytes,
                'drive_used_pct'   => $report->driveUsedPct,
                'drive_quota_bytes'=> $report->driveQuotaBytes,
                'drive_used_bytes' => $report->driveUsedBytes,
                'drive_hot_rows'   => $report->driveHotRows,
                'reasons'          => $report->reasons,
                'computed_at'      => $report->computedAtUnix,
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => $e->getMessage()];
        }
    }

    private function reclaimSummary(array $cfg, ?\FlowOne\Storage\HmacSigner $signer): array
    {
        $read = $this->readStateFile($this->reclaimStatePath($cfg), $signer);
        $state = $read['payload'];
        return [
            'available'       => true,
            'enabled'         => (bool) ($cfg['phases']['phase6c_reclaim_daemon'] ?? false),
            'state'           => $state['state'] ?? null,
            'last_reclaim_at' => $state['last_reclaim_at'] ?? null,
            'paused'          => is_file($this->reclaimPauseFlag($cfg)),
            'verified'        => $read['verified'],
            'source'          => $read['source'],
        ];
    }

    private function reclaimFull(array $cfg, ?\FlowOne\Storage\HmacSigner $signer): array
    {
        $read = $this->readStateFile($this->reclaimStatePath($cfg), $signer);
        $state = $read['payload'];
        return [
            'available'      => true,
            'enabled'        => (bool) ($cfg['phases']['phase6c_reclaim_daemon'] ?? false),
            'paused'         => is_file($this->reclaimPauseFlag($cfg)),
            'state'          => $state['state'] ?? null,
            'last_decision'  => $state['last_decision'] ?? null,
            'last_reason'    => $state['last_reason'] ?? null,
            'last_reclaim_at'=> $state['last_reclaim_at'] ?? null,
            'last_cycle'     => $state['last_cycle_summary'] ?? null,
            'counters'       => $state['counters'] ?? null,
            'caps'           => $state['caps'] ?? null,
            'pid'            => $state['pid'] ?? null,
            'updated_at'     => $state['updated_at'] ?? null,
            'verified'       => $read['verified'],
            'source'         => $read['source'],
        ];
    }

    private function backupSummary(array $cfg, ?\FlowOne\Storage\HmacSigner $signer): array
    {
        $read = $this->readStateFile($this->backupStatePath($cfg), $signer);
        $state = $read['payload'];
        return [
            'available'         => true,
            'enabled'           => (bool) ($cfg['phases']['phase7_nas_backup'] ?? false),
            'paused'            => is_file($this->backupPauseFlag($cfg)),
            'last_snapshot_ok'  => $state['last_snapshot_ok']['date_key'] ?? null,
            'last_snapshot_at'  => $state['last_snapshot_ok']['started_at'] ?? null,
            'last_failure'      => isset($state['last_snapshot_failed'])
                                      ? ($state['last_snapshot_failed']['reason'] ?? null) : null,
            'last_verify_ok'    => $state['last_verify']['ok'] ?? null,
            'last_drill_ok'     => $state['last_drill']['ok'] ?? null,
            'verified'          => $read['verified'],
            'source'            => $read['source'],
        ];
    }

    private function backupFull(array $cfg, ?\FlowOne\Storage\HmacSigner $signer): array
    {
        $read = $this->readStateFile($this->backupStatePath($cfg), $signer);
        return [
            'available'  => true,
            'enabled'    => (bool) ($cfg['phases']['phase7_nas_backup'] ?? false),
            'paused'     => is_file($this->backupPauseFlag($cfg)),
            'state'      => $read['payload'],
            'caps'       => $cfg['backup']['caps']      ?? null,
            'retention'  => $cfg['backup']['retention'] ?? null,
            'tenants'    => $cfg['backup']['tenants']   ?? null,
            'verified'   => $read['verified'],
            'source'     => $read['source'],
        ];
    }

    /**
     * Read a daemon state file, with graceful fallback when the HMAC
     * signer is unavailable.
     *
     * The daemons write JSON envelopes shaped as
     *   { "payload": {...}, "sig": "...", "alg": "HS256" }
     *
     * For dashboard display we don't need cryptographic verification
     * to be a hard gate — operators just need to *see* what the daemon
     * is doing. So:
     *
     *   1. Try ReclaimDaemonStateStore / BackupStateStore (verifies sig)
     *      when a signer is available.
     *   2. If signer is null OR verification fails, fall back to reading
     *      the file directly and unwrapping the envelope. Mark the
     *      result `verified: false` so the UI can show a tiny warning.
     *   3. If the file doesn't exist yet (daemon never published —
     *      kill switch off, fresh install), return an empty payload
     *      with `source: 'absent'` so the dashboard shows "no state
     *      published yet" rather than an error.
     *
     * Returns: ['payload' => array, 'verified' => bool, 'source' => string].
     */
    private function readStateFile(string $path, ?\FlowOne\Storage\HmacSigner $signer): array
    {
        if (!is_file($path)) {
            return ['payload' => [], 'verified' => false, 'source' => 'absent'];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return ['payload' => [], 'verified' => false, 'source' => 'unreadable'];
        }
        if ($signer !== null) {
            $verified = $signer->verifyJson($raw);
            if (is_array($verified)) {
                return ['payload' => $verified, 'verified' => true, 'source' => 'verified'];
            }
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['payload' => [], 'verified' => false, 'source' => 'corrupt'];
        }
        if (isset($decoded['payload']) && is_array($decoded['payload'])) {
            return ['payload' => $decoded['payload'], 'verified' => false, 'source' => 'unverified'];
        }
        return ['payload' => $decoded, 'verified' => false, 'source' => 'unverified'];
    }

    private function tierCounts(\PDO $pdo): array
    {
        try {
            $stmt = $pdo->query(
                "SELECT tier_state, COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes
                 FROM drive_files GROUP BY tier_state"
            );
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['tier_state']] = [
                    'count' => (int) $r['n'],
                    'bytes' => (int) $r['bytes'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function phaseFlagsView(array $cfg): array
    {
        $relevant = ['phase4_drive_schema', 'phase5_tier_down_destructive', 'phase6_hot_gc',
                     'phase6a_admission_control', 'phase6b_admission_control', 'phase6c_reclaim_daemon',
                     'phase6d_lru_selection', 'phase7_nas_backup'];
        $out = [];
        $phases = (array) ($cfg['phases'] ?? []);
        foreach ($relevant as $k) {
            if (array_key_exists($k, $phases)) {
                $out[$k] = (bool) $phases[$k];
            }
        }
        return $out;
    }

    private function reclaimPauseFlag(array $cfg): string
    {
        $dir = rtrim((string) ($cfg['state']['dir'] ?? ''), '/');
        return $dir . '/' . (string) ($cfg['tier']['reclaim']['pause_flag'] ?? 'reclaim.paused');
    }

    private function backupPauseFlag(array $cfg): string
    {
        $dir = rtrim((string) ($cfg['state']['dir'] ?? ''), '/');
        return $dir . '/' . (string) ($cfg['backup']['pause_flag'] ?? 'backup.paused');
    }

    private function reclaimStatePath(array $cfg): string
    {
        $dir = rtrim((string) ($cfg['state']['dir'] ?? ''), '/');
        return $dir . '/' . (string) ($cfg['tier']['reclaim']['state_file'] ?? 'reclaim-daemon.json');
    }

    private function backupStatePath(array $cfg): string
    {
        $dir = rtrim((string) ($cfg['state']['dir'] ?? ''), '/');
        return $dir . '/' . (string) ($cfg['backup']['state_file'] ?? 'nas-backup.json');
    }

    private function isAdmin(string $email): bool
    {
        try {
            $svc = new ColleagueService($this->config);
            return $svc->isAdmin($email);
        } catch (\Throwable $e) {
            error_log('[StorageController.isAdmin] ' . $e->getMessage());
            return false;
        }
    }
}
