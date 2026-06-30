<?php
/**
 * FlowOne shared storage configuration.
 *
 * Single source of truth for everything that touches the NAS/VPN/helper chain.
 * All consumers (email backend, panel API, panel agent, daemons, CLI) load
 * this file via FlowOne\Storage\Config::load(). Never duplicate these values.
 *
 * Override per-host by creating /etc/flowone/storage.local.php that returns
 * an array which is merged on top of this one (array_replace_recursive).
 */

declare(strict_types=1);

return [
    // ─── Identity ────────────────────────────────────────────────────────
    'cluster' => getenv('FLOWONE_CLUSTER') ?: 'default',

    // ─── NAS / VPN connectivity ──────────────────────────────────────────
    'nas' => [
        'lan_ip'         => '192.168.1.106',
        'ddns_hostname'  => 'pixelranger.synology.me',
        'mount_point'    => '/mnt/nas-drive',
        'health_file'    => '.healthcheck',
        'driver'         => 'nfs',
    ],
    'vpn' => [
        'name'           => 'synology',
        'port'           => 1194,
        'service_unit'   => 'openvpn-client@synology',
        'tun_interface'  => 'tun0',
    ],
    'firewall' => [
        'nft_table'      => 'inet cpguard_fw',
        'nft_set'        => 'tcp_out',
    ],

    // ─── Authoritative state files (triple-file durable) ────────────────
    'state' => [
        // Daemon-published health state. Triple-file durable
        // (current + tmp + bak). HMAC-signed payload.
        'dir'            => '/var/lib/flowone',
        'current_file'   => 'storage-health.json',
        'tmp_suffix'     => '.tmp',
        'bak_suffix'     => '.bak',
        // Boot epoch counter (persisted across daemon restarts).
        'boot_epoch_file' => 'storage-boot-epoch',
        // Operator freeze flag. Presence == frozen.
        'freeze_flag'    => 'freeze.flag',
        // Chaos enabled flag. Presence == chaos harness allowed to run.
        'chaos_flag'     => 'chaos.enabled',
        // HMAC secret. 0640 root:flowone-storage. Never world-readable.
        'hmac_key_path'  => '/etc/flowone/state.key',
        // Required permissions check (octal). Daemon refuses to start
        // if the key file mode is wider than this.
        'hmac_key_mode_max' => 0640,
    ],

    // ─── Helper Unix-socket RPC ──────────────────────────────────────────
    'helper' => [
        'socket_path'    => '/run/flowone/storage-helper.sock',
        'allowed_peer_uid'  => null, // populated by daemon at startup from passwd
        'allowed_peer_user' => 'flowone-storage',
        'rpc_timeout_sec'   => 30,
    ],

    // ─── Mount lock (I-11) ───────────────────────────────────────────────
    'mount_lock' => [
        'path'           => '/var/lock/flowone-mount.lock',
        'wait_timeout_sec' => 60,
    ],

    // ─── Operation journal ───────────────────────────────────────────────
    'journal' => [
        'path'           => '/var/log/flowone/storage-journal.jsonl',
        'rotate_size_mb' => 256,
        'rotate_keep'    => 14,
    ],

    // ─── Probe + publish cadence ─────────────────────────────────────────
    'probe' => [
        'interval_sec'         => 10,
        'slow_probe_threshold' => 2.0,   // seconds; above this -> degraded
        'rw_probe_bytes'       => 64,    // random bytes for fingerprint probe
        'fingerprint_keys'     => ['fs_type', 'mount_source', 'device_inode', 'mount_options'],
    ],

    // ─── Circuit breakers ────────────────────────────────────────────────
    // (Implementations land in Phase 2; configuration lives here from Phase 1
    // so the values are reviewed in one place.)
    'recovery_breaker' => [
        'attempts_per_quarantine'   => 5,
        'quarantine_window_sec'     => 600,
        'quarantines_before_permanent' => 3,
        'permanent_window_sec'      => 86400,
    ],
    'read_breaker' => [
        'p95_threshold_sec'  => 2.0,
        'error_rate_threshold' => 0.05,
        'evaluation_window_sec' => 30,
        'hard_cap_sec'       => 600,
    ],

    // ─── Automatic recovery driver (storage-monitord) ────────────────────
    // Used by RecoveryOrchestrator when phases.phase_auto_recovery is on.
    'recovery' => [
        // Seconds to wait after a VPN restart before re-attempting the NFS
        // mount, giving the tunnel time to come up before the remount.
        'vpn_settle_sec' => 5,
    ],

    // ─── Stability gate (I-1, see INVARIANTS.md) ────────────────────────
    'stability_gate' => [
        'min_stable_sec' => 60,
    ],

    // ─── Tiered storage tuning (Phase 5a / 5b / 5c / 6a) ────────────────
    'tier' => [
        // Hours a row must sit in `cold` state before the destructive
        // sweep is allowed to unlink its VPS shadow. Conservative default
        // gives an operator a full day to spot a bad tier-down before
        // bytes are gone from VPS. Lower this only in tests.
        'destructive_grace_hours' => 24,
        // Max wall-clock seconds the sweep pass is allowed to consume on
        // a single worker invocation (the worker also enforces an outer
        // cap via --max-seconds; whichever is smaller wins).
        'destructive_sweep_max_sec' => 240,

        // Phase 6a: storage budget accounting. Consumed by admission
        // control (6b), the reclaim daemon (6c), and `storage-ctl budget`.
        // Watermarks are independent for the OS layer (df against the
        // VPS mount point) and the logical layer (SUM(size) of HOT +
        // TIERING drive_files vs the drive_quota); the worst level wins.
        'budget' => [
            // df() target. Use the mount point that hosts the drive
            // storage path. "/" is a safe default on most LSWS boxes
            // where /var/www lives on the root filesystem.
            'vps_mount_point'    => '/',
            // Logical cap for total HOT+TIERING bytes across drive_files.
            // 0 disables the logical layer; only OS-layer watermarks
            // apply. Set this to a fraction of the actual VPS disk so
            // we tier-down BEFORE the OS runs out.
            'drive_quota_bytes'  => 100 * 1024 * 1024 * 1024, // 100 GiB
            // OS-layer hard floor. If VPS free bytes drop below this,
            // watermark goes critical immediately regardless of pct.
            'min_free_bytes'     => 5 * 1024 * 1024 * 1024,    // 5 GiB
            // OS-layer percentage watermarks.
            'warn_vps_pct'       => 70,
            'high_vps_pct'       => 80,
            'critical_vps_pct'   => 90,
            // Logical-layer percentage watermarks (only meaningful when
            // drive_quota_bytes > 0 AND a PDO is provided).
            'warn_drive_pct'     => 70,
            'high_drive_pct'     => 85,
            'critical_drive_pct' => 95,
            // Process-local cache TTL. Short enough that the reclaim
            // daemon sees fresh numbers; long enough that admission
            // control doesn't pay df() cost per request.
            'cache_ttl_sec'      => 30,
            // Override only in tests.
            'table_name'         => 'drive_files',
        ],

        // Phase 6d: LRU-aware tier-down candidate selection.
        'lru' => [
            // Minimum seconds between last_read_at stamps for the same
            // file_id. Coalesces tight read loops (chunked downloads,
            // thumbnail polling) into one DB write per window. The
            // conditional UPDATE also enforces this at the DB layer so
            // a stale process-local memo can't cause stale data.
            'min_touch_interval_sec' => 60,
        ],

        // Phase 6c: reclaim daemon. Long-running systemd service that
        // proactively triggers tier-down + sweep when StorageBudget
        // watermark crosses WM_HIGH, instead of waiting for the hourly
        // cron. Runs alongside the cron — never replaces it. Daemon
        // refuses to start when phase6c_reclaim_daemon is OFF, so it's
        // safe to deploy the unit file before flipping the flag.
        'reclaim' => [
            // Poll cadences per state. The daemon polls cheap things
            // (StorageBudget snapshot is cached for 30s) so even the
            // active reclaim cadence is fine for the DB.
            'poll_idle_sec'         => 60,   // WM_CLEAR / WM_WARN
            'poll_warming_sec'      => 15,   // WM_HIGH (about to reclaim)
            'poll_reclaiming_sec'   => 5,    // WM_CRITICAL (actively reclaiming)
            'cooldown_sec'          => 300,  // Min idle time after a reclaim before next one
            // Per-cycle caps. The daemon cannot run away — it will
            // stop at whichever cap is hit first within one cycle.
            'max_bytes_per_cycle'   => 1024 * 1024 * 1024, // 1 GiB
            'max_seconds_per_cycle' => 60,
            'max_candidates_per_cycle' => 50,
            // Tier-down query params reused from the cron defaults.
            'age_days'              => 30,
            'min_file_bytes'        => 1024 * 1024,        // 1 MiB
            'order_by'              => 'lru',              // 'age' | 'lru'
            // Sweep pass tuning.
            'sweep_batch'           => 25,
            // Operator pause flag. When the file exists, the daemon
            // logs and skips all reclaim work but keeps polling state.
            // Path is relative to state.dir.
            'pause_flag'            => 'reclaim.paused',
            // Daemon state file (DurableJson, HMAC-signed). Lives next
            // to storage-health.json under state.dir.
            'state_file'            => 'reclaim-daemon.json',
            // PID file path. Used by systemd Type=simple isn't strictly
            // necessary, but lets storage-ctl show "is the daemon up?".
            'pid_file'              => 'reclaim-daemon.pid',
        ],
    ],

    // ─── Tenants ─────────────────────────────────────────────────────────
    // Concrete tenant rows live in MySQL nas_connections; this is the
    // canonical set of types the system understands.
    'tenants' => [
        'panel-backups' => [
            'subpath'         => 'backups',
            'retention_days'  => 30,
        ],
        'email-drive' => [
            'subpath'         => 'drive',
            'retention_days'  => null,
        ],
        // Synthetic tenant used by the chaos harness on live VPS.
        'chaos-test' => [
            'subpath'         => 'chaos-test',
            'retention_days'  => 1,
            'is_synthetic'    => true,
        ],
    ],

    // ─── Redis cache (the daemon publishes the same payload here) ────────
    // Cache only. The signed JSON file remains authoritative (I-9).
    'redis' => [
        'host'           => '127.0.0.1',
        'port'           => 6379,
        'password'       => null,
        'database'       => 0,
        'prefix'         => 'flowone:storage:',
        'status_key'     => 'status',
        'status_ttl_sec' => 60,
        'pubsub_channel' => 'flowone.storage.events',
    ],

    // ─── Phase 7: Backup pipeline ────────────────────────────────────────
    // rsync-based daily snapshots from /mnt/nas-drive to /mnt/vps-backup
    // with hardlink dedup (--link-dest), HMAC-signed per-snapshot
    // manifests, automated retention rotation, and quarterly restore
    // drills. Designed so a single rsync run can be killed mid-flight
    // and resumed safely on the next cron tick (rsync handles partial
    // transfers natively; the manifest is only written after a clean
    // exit, so verifier never sees a half-built snapshot).
    'backup' => [
        // Filesystem layout. The runner refuses to write anywhere
        // outside destination_root, and refuses to start if the
        // mount point or healthcheck file is missing.
        'source_root'        => '/mnt/nas-drive',          // read-only source (NAS)
        'destination_root'   => '/mnt/vps-backup/drive-snapshots', // snapshot destination
        'destination_mount'  => '/mnt/vps-backup',         // for mount-presence check
        'healthcheck_file'   => '.healthcheck',            // relative to destination_mount
        // Which tenants under source_root to back up. Each entry
        // is the tenant subpath (NOT the absolute path) — same key
        // used everywhere else for tenant resolution.
        'tenants'            => ['drive'],                 // email-drive tenant; add others as they appear
        // rsync invocation.
        'rsync_path'         => '/usr/bin/rsync',
        // Default flags. Operators can append via rsync_flags_extra
        // (e.g. ['--bwlimit=20000'] to throttle on saturated links).
        // -a   archive (preserves perms, times, symlinks, devices)
        // -H   preserve hardlinks (matters when --link-dest is used)
        // --numeric-ids   don't try to resolve uid/gid on destination
        // --delete   destination mirrors source within the snapshot
        // --stats    machine-readable totals for the journal
        // --itemize-changes   per-file decision (for verbose mode only)
        'rsync_flags'        => ['-aH', '--numeric-ids', '--delete', '--stats'],
        'rsync_flags_extra'  => [],
        // Per-run caps. Backup is allowed to be slow but must NEVER
        // run forever (a runaway rsync would hold the lock and block
        // tomorrow's snapshot). max_seconds=0 means "no wall-clock cap"
        // (operator opt-in for the initial seed run).
        'caps' => [
            'max_seconds' => 4 * 3600,   // 4 hours
            'max_bytes'   => 0,          // unlimited
        ],
        // Retention policy. Snapshots are first written as daily; a
        // post-snapshot rotation step promotes selected dailies to
        // weekly + monthly via rename (zero-copy). Pruning is
        // additive — only snapshots beyond the keep count of their
        // kind are removed.
        'retention' => [
            'keep_daily'        => 7,
            'keep_weekly'       => 4,
            'keep_monthly'      => 12,
            'weekly_anchor_dow' => 0,   // 0=Sunday (ISO: also Sunday)
            'monthly_anchor_dom'=> 1,   // 1st of the month
        ],
        // Manifest. One per snapshot, signed with the shared HMAC key.
        // Format is JSON; manifests carry size + mtime by default and
        // md5 only when full_checksum=true (default false for cost).
        // The standalone verifier always recomputes md5 regardless.
        'manifest' => [
            'name'           => 'manifest.json.sig',
            'full_checksum'  => false,  // include md5 inline in the manifest (slow on large trees)
        ],
        // Verifier. When called without --full it spot-checks N random
        // files from the manifest; --full recomputes every md5. The
        // sample_size is the default for the non-full mode.
        'verify' => [
            'sample_size'    => 50,
        ],
        // Restore drill. Picks a random file from a random recent
        // snapshot, restores to a tmp path, verifies md5, deletes.
        // Runs on cron (operator-installed) and journals every outcome.
        'restore_drill' => [
            'tmp_dir'        => '/tmp/flowone-restore-drill',
            'max_snapshots' => 7,   // pick from the last N snapshots
        ],
        // Operator pause flag. When present (file under state.dir),
        // nas-backup.php refuses to write a snapshot. Useful during
        // NAS maintenance windows.
        'pause_flag'         => 'backup.paused',
        // Lock + state files.
        'lock_file'          => 'backup.lock',       // exclusive lock for the runner
        'state_file'         => 'nas-backup.json',   // DurableJson published state
    ],

    // ─── Kill switches (phase rollback) ──────────────────────────────────
    // Every phase ships behind a flag here. Flipping false reverts to the
    // pre-phase behaviour without code changes.
    'phases' => [
        'phase1_shared_health'   => true,   // Use shared StorageHealth in wrappers.
        'phase2_state_model'     => true,   // 6-state status enum + stability gate + breakers.
        'phase3_tenant_layout'   => true,   // Two-tenant /drive and /backups layout.
        'phase4_drive_schema'    => true,   // tier_state schema in drive_files.
        'phase5_tier_down_shadow' => true,   // VPS-first uploads, shadow tier-down.
        // Phase 5b: wire DriveService to consult tier_state and recall cold files
        // synchronously. Defaults OFF — flip only after phase 5a worker has been
        // observed in production for at least a few clean cron cycles AND the
        // shared FlowOne\Storage\TierRecallService class is deployed.
        'phase5b_drive_recall'    => false,
        // Phase 5c: actually unlink the VPS copy after a successful tier-down.
        // Defaults OFF — leave shadow mode active for at least a week of clean
        // worker runs against real candidates before flipping this on.
        'phase5_tier_down_destructive' => false,
        // Phase 6b: refuse Drive uploads when StorageBudget reports critical
        // pressure (drive_quota_bytes overrun, OS free-bytes floor breached,
        // or watermark>=critical). Defaults OFF — flip only after StorageBudget
        // numbers have been observed clean for a few days. When ON, refused
        // uploads return HTTP 503 + Retry-After.
        'phase6b_admission_control' => false,
        // Phase 6d: LRU-aware tier-down candidate selection. When ON,
        // findTierDownCandidates() orders by
        // COALESCE(last_read_at, tier_changed_at) ASC instead of pure
        // age. Requires migration 168. Safe to flip on the moment 168
        // has run — files with NULL last_read_at fall back to the
        // age ordering naturally, so behaviour matches the OFF state
        // until reads start populating last_read_at. DriveService also
        // gates last_read_at stamping on this flag, so flipping it OFF
        // freezes the column entirely.
        'phase6d_lru_selection'  => false,
        // Phase 6c: enable the long-running reclaim daemon. When ON,
        // /etc/systemd/system/flowone-reclaim-daemon.service is expected
        // to be enabled + started. When OFF, the daemon refuses to enter
        // its main loop (logs and exits 0) — so it's safe to ship the
        // unit file before flipping the flag. The hourly cron continues
        // to run regardless; the daemon supplements it under pressure.
        'phase6c_reclaim_daemon' => false,
        // Phase 7: NAS backup pipeline. When ON, the nas-backup.php
        // cron is expected to run daily and emits HMAC-signed
        // snapshots + manifests under backup.destination_root. When
        // OFF, the CLI refuses to write (exits 0 with a clear log
        // message). Verifier + restore drill obey the same flag so
        // we never spot-check a snapshot from a feature that isn't
        // supposed to be producing them.
        'phase7_nas_backup'      => false,
        'phase6_hot_gc'          => false,  // Reclaim daemon + admission + scheduler (umbrella, retained for legacy reads).
        'phase7_backup_rsync'    => false,  // rsync-based backup pipeline.
        'phase8_frontend_signals' => false, // New banners + badges.
        // Auto-recovery: when ON, storage-monitord asks the privileged
        // helper to remount NFS / restart the VPN as soon as a probe shows
        // the chain is down (bounded by recovery_breaker), so a NAS that
        // comes back after an outage reconnects with NO operator input. When
        // OFF, recovery only happens via an operator pressing Mount/Test in
        // the Panel (the pre-this-change behaviour).
        'phase_auto_recovery'    => true,
    ],

    // ─── Logging ─────────────────────────────────────────────────────────
    'log' => [
        'dir'            => '/var/log/flowone',
        'monitor_file'   => 'storage-monitor.log',
        'helper_file'    => 'storage-helper.log',
        'level'          => 'info', // debug|info|warn|error
    ],

    // ─── Strict mode ─────────────────────────────────────────────────────
    // When true, Invariants:: methods throw on violation instead of logging.
    // Turn on in CI and during chaos runs.
    'strict_invariants' => (bool) (getenv('STORAGE_STRICT_INVARIANTS') ?: false),
];
