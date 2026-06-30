#!/usr/bin/env php
<?php
/**
 * flowone-storage-monitord
 *
 * Unprivileged monitor daemon. Probes the NAS chain at a regular cadence,
 * publishes the signed authoritative state file (triple-file durable),
 * mirrors to Redis as a cache, and writes structured events to the
 * operation journal.
 *
 * Does NOT perform mount or recovery actions — those go through the
 * privileged helper (see storage-helper.php). The monitor only ever
 * issues read-only probes and pushes results.
 *
 * Run via systemd as user `flowone-storage`. Bumps the boot epoch on
 * startup so any queued actions from a prior boot are invalidated.
 *
 * Phase 1 behaviour: produces healthy / degraded / offline based on the
 * probe outcomes. Phase 2 extends with the 6-state enum, breakers, and
 * full mount fingerprinting.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "storage-monitord must run from CLI\n");
    exit(1);
}

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'FlowOne\\Storage\\')) {
        return;
    }
    $relative = substr($class, strlen('FlowOne\\Storage\\'));
    $path = __DIR__ . '/../src/Storage/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use FlowOne\Storage\BootEpoch;
use FlowOne\Storage\Breakers\ReadBreaker;
use FlowOne\Storage\Breakers\RecoveryBreaker;
use FlowOne\Storage\Classifier;
use FlowOne\Storage\Config;
use FlowOne\Storage\DurableJson;
use FlowOne\Storage\HealthState;
use FlowOne\Storage\HelperClient;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\Invariants;
use FlowOne\Storage\MonotonicClock;
use FlowOne\Storage\OperationJournal;
use FlowOne\Storage\RecoveryOrchestrator;
use FlowOne\Storage\StabilityGate;
use FlowOne\Storage\TenantBootstrap;
use FlowOne\Storage\TenantProber;

$config = Config::load();

// Load HMAC key (refuses to start if mode is wider than configured).
$signer = HmacSigner::fromKeyFile(
    (string) $config['state']['hmac_key_path'],
    (int) $config['state']['hmac_key_mode_max']
);

// Bump boot epoch once at startup (I-10 enforcement: invalidate queued actions).
$bootEpoch = new BootEpoch(
    rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['boot_epoch_file']
);
$currentEpoch = $bootEpoch->bump();

$stateFile = new DurableJson(
    (string) $config['state']['dir'],
    (string) $config['state']['current_file'],
    (string) $config['state']['tmp_suffix'],
    (string) $config['state']['bak_suffix'],
);

$journal = new OperationJournal(
    (string) $config['journal']['path'],
    $signer,
    $currentEpoch
);
$invariants = new Invariants($journal, strict: false);

$helper = HelperClient::fromConfig();

$redis = connectRedis($config);

$journal->record('monitord_started', [
    'boot_epoch' => $currentEpoch,
    'pid'        => getmypid(),
    'config_dir' => (string) $config['state']['dir'],
]);

$shouldStop = false;
$handler = function (int $signo) use (&$shouldStop, $journal) {
    $shouldStop = true;
    $journal->record('monitord_signal_stop', ['signal' => $signo]);
};
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$generation = 0;
$previousStatus = HealthState::UNKNOWN;
$intervalSec = max(1, (int) $config['probe']['interval_sec']);

// Phase 2 state machine (only used when phase2_state_model kill switch is
// on). Constructed unconditionally so the cycle time is consistent across
// flag flips; behaviour is gated inside the loop.
$phase2On = (bool) ($config['phases']['phase2_state_model'] ?? false);
$stabilityGate    = StabilityGate::fromConfig($config);
$readBreaker      = ReadBreaker::fromConfig($config);
$recoveryBreaker  = RecoveryBreaker::fromConfig($config);
$classifier       = new Classifier($stabilityGate, $readBreaker, $recoveryBreaker);

// Automatic recovery driver. The monitor itself stays read-only; the
// orchestrator only *requests* privileged mount/VPN actions over the helper
// socket, bounded by the recovery breaker. Gated by a kill switch so it can
// be reverted to the prior manual-only behaviour without code changes.
$autoRecoveryOn = (bool) ($config['phases']['phase_auto_recovery'] ?? false);
$recoveryOrchestrator = new RecoveryOrchestrator(
    $helper,
    $recoveryBreaker,
    $journal,
    $currentEpoch,
    $config,
    $redis
);

// Phase 3: tenant layout. Bootstrap subdirectories once at startup,
// then run per-tenant rw probes in round-robin alongside the root
// probe. Behaviour gated by phases.phase3_tenant_layout.
$phase3On = (bool) ($config['phases']['phase3_tenant_layout'] ?? false);
$tenantProber = null;
if ($phase3On) {
    try {
        $bootstrap = TenantBootstrap::fromConfig($config, $journal);
        $bootstrapResults = $bootstrap->ensureAll();
        $journal->record('tenant_bootstrap_complete', [
            'attempted' => count($bootstrapResults),
            'results'   => $bootstrapResults,
        ]);
        $tenantProber = TenantProber::fromConfig($config, $journal);
    } catch (\Throwable $e) {
        $journal->record('tenant_bootstrap_failed', ['error' => $e->getMessage()]);
    }
}

while (!$shouldStop) {
    $loopStartMonoNs = MonotonicClock::nowNs();
    $generation++;

    $probe = runProbes($config, $helper, $tenantProber);
    if ($phase2On) {
        $payload = buildPhase2Payload($probe, $classifier, $previousStatus, $currentEpoch, $generation, $invariants);
    } else {
        $payload = buildPhase1Payload($probe, $currentEpoch, $generation);
    }

    // Auto-recovery: when the chain is down, ask the privileged helper to
    // remount / restart the VPN (bounded by the recovery breaker). Embed the
    // outcome in the payload so the dashboard surfaces it. On success we
    // shorten this cycle so the next probe republishes HEALTHY quickly.
    $justRecovered = false;
    if ($autoRecoveryOn) {
        $recovery = $recoveryOrchestrator->maybeRecover($probe, (string) $payload['status']);
        $payload['auto_recovery'] = $recovery;
        $justRecovered = !empty($recovery['attempted']) && ($recovery['success'] === true);
    }

    publish($payload, $stateFile, $signer, $redis, $config, $journal);

    if ($previousStatus !== $payload['status']) {
        $journal->record('nas_status_change', [
            'from' => $previousStatus,
            'to'   => $payload['status'],
            'generation' => $generation,
            'boot_epoch' => $currentEpoch,
            'root_cause' => $payload['root_cause'] ?? null,
        ]);
    }
    $previousStatus = $payload['status'];

    $elapsedNs = MonotonicClock::nowNs() - $loopStartMonoNs;
    $sleepSec = $intervalSec - ($elapsedNs / 1_000_000_000);
    if ($justRecovered) {
        // Re-probe almost immediately so the UI + consumers observe HEALTHY
        // instead of waiting a full interval after a successful recovery.
        $sleepSec = min($sleepSec, 2.0);
    }
    if ($sleepSec > 0 && !$shouldStop) {
        MonotonicClock::sleep($sleepSec);
    }
}

$journal->record('monitord_stopped', ['boot_epoch' => $currentEpoch]);
exit(0);

// ────────────────────────────────────────────────────────────────────────

function connectRedis(array $config): ?\Redis
{
    if (!class_exists(\Redis::class)) {
        error_log('[monitord] phpredis not installed; running without Redis publish');
        return null;
    }
    try {
        $r = new \Redis();
        $r->connect(
            (string) $config['redis']['host'],
            (int) $config['redis']['port'],
            2.0
        );
        if (!empty($config['redis']['password'])) {
            $r->auth((string) $config['redis']['password']);
        }
        if ((int) ($config['redis']['database'] ?? 0) > 0) {
            $r->select((int) $config['redis']['database']);
        }
        return $r;
    } catch (\Throwable $e) {
        error_log('[monitord] Redis connect failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Run all probes and return the raw outcomes. Pure data — no statelets,
 * no breakers, no state-machine. Both Phase 1 and Phase 2 payload
 * builders consume this.
 *
 * Returns:
 *   checks[]             keyed array of check records (for UI)
 *   read_ok bool         whether NAS reads succeeded
 *   write_ok bool        whether the rw probe completed
 *   latency_sec float    health-file probe latency
 *   slow bool            true when latency > slow_probe_threshold
 *   helper_up bool       helper socket ping result
 *   frozen bool          presence of freeze.flag
 *   primary_failure ?string  populated only when read_ok=false
 *
 * @return array<string,mixed>
 */
function runProbes(array $config, HelperClient $helper, ?TenantProber $tenantProber = null): array
{
    $checks = [];
    $mountPoint = (string) $config['nas']['mount_point'];
    $healthFile = rtrim($mountPoint, '/') . '/' . (string) $config['nas']['health_file'];

    // 1. NFS mount check.
    $probeStart = MonotonicClock::nowNs();
    $exists = @file_exists($healthFile);
    $probeSec = MonotonicClock::elapsedSec($probeStart);
    $checks['nas_health_file'] = [
        'status'       => $exists ? 'ok' : 'error',
        'message'      => $exists ? "{$healthFile} present" : "{$healthFile} missing or unreachable",
        'duration_sec' => round($probeSec, 4),
    ];

    // 2. R/W probe — only if read worked. Otherwise we have no NAS to write to.
    $writeOk = false;
    $writeMessage = 'skipped (read failed)';
    if ($exists) {
        $rwCheck = doRwProbe($mountPoint, (int) $config['probe']['rw_probe_bytes']);
        $checks['nas_read_write'] = $rwCheck;
        $writeOk = (($rwCheck['status'] ?? null) === 'ok');
        $writeMessage = $rwCheck['message'] ?? '';
    } else {
        $checks['nas_read_write'] = ['status' => 'skipped', 'message' => $writeMessage];
    }

    // 3. Per-tenant rw probe (Phase 3, round-robin). Only contributes
    // to the checks block — does NOT affect read_ok/write_ok yet, so
    // a single-tenant blip won't escalate the overall state in this
    // phase. Phase 5+ may promote tenant probe failure to DEGRADED.
    if ($tenantProber !== null && $exists) {
        $tenantResult = $tenantProber->probeNext();
        if ($tenantResult !== null) {
            $key = 'tenant_rw_' . $tenantResult['tenant'];
            $checks[$key] = [
                'status'       => $tenantResult['status'] === 'ok' ? 'ok'
                                  : ($tenantResult['status'] === 'skipped' ? 'skipped' : 'warning'),
                'message'      => $tenantResult['message'],
                'duration_sec' => $tenantResult['duration_sec'],
            ];
        }
    }

    // 4. Helper reachability.
    $helperUp = $helper->ping();
    $checks['helper_socket'] = [
        'status'  => $helperUp ? 'ok' : 'warning',
        'message' => $helperUp ? 'helper responding' : 'helper not reachable (recovery actions unavailable)',
    ];

    // 5. Operator freeze flag.
    $freezeFlag = rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['freeze_flag'];
    $frozen = is_file($freezeFlag);

    $slow = $exists && $probeSec > (float) $config['probe']['slow_probe_threshold'];

    return [
        'checks'          => $checks,
        'read_ok'         => $exists,
        'write_ok'        => $writeOk,
        'latency_sec'     => $probeSec,
        'slow'            => $slow,
        'helper_up'       => $helperUp,
        'frozen'          => $frozen,
        'primary_failure' => $exists ? null : "Cannot read {$healthFile}",
        'write_message'   => $writeMessage,
    ];
}

/**
 * Phase 1 classification: three statuses (healthy / degraded / offline).
 * Preserved verbatim so the kill switch reverts to the exact prior
 * behaviour.
 */
function buildPhase1Payload(array $probe, int $bootEpoch, int $generation): array
{
    $status = HealthState::HEALTHY;
    $rootCause = null;
    $rootCauseDetail = null;

    if (!$probe['read_ok']) {
        $status = HealthState::OFFLINE;
        $rootCause = 'nas_unreachable';
        $rootCauseDetail = $probe['primary_failure'];
    } elseif (!$probe['write_ok']) {
        $status = HealthState::OFFLINE;
        $rootCause = 'nas_rw_failed';
        $rootCauseDetail = $probe['write_message'];
    } elseif ($probe['slow']) {
        $status = HealthState::DEGRADED;
        $rootCause = 'nas_slow';
        $rootCauseDetail = sprintf('Health probe took %.2fs', $probe['latency_sec']);
    } elseif (!$probe['helper_up']) {
        $status = HealthState::DEGRADED;
        $rootCause = 'helper_unreachable';
        $rootCauseDetail = 'storage-helper socket did not respond to ping';
    }

    return [
        'status'            => $status,
        'boot_epoch'        => $bootEpoch,
        'generation'        => $generation,
        'published_at'      => time(),
        'published_at_iso'  => date('c'),
        'root_cause'        => $rootCause,
        'root_cause_detail' => $rootCauseDetail,
        'checks'            => $probe['checks'],
        'auto_recovery'     => null,
    ];
}

/**
 * Phase 2 classification: full 6-state machine, stability gate, and
 * breakers. Behaviour gated by phases.phase2_state_model in config.
 */
function buildPhase2Payload(
    array $probe,
    Classifier $classifier,
    string $previousStatus,
    int $bootEpoch,
    int $generation,
    Invariants $invariants
): array {
    $decision = $classifier->classify([
        Classifier::PROBE_READ_OK   => $probe['read_ok'],
        Classifier::PROBE_WRITE_OK  => $probe['write_ok'],
        Classifier::PROBE_LATENCY   => $probe['latency_sec'],
        Classifier::PROBE_SLOW      => $probe['slow'],
        Classifier::PROBE_HELPER_UP => $probe['helper_up'],
        Classifier::PROBE_FROZEN    => $probe['frozen'],
    ], $previousStatus);

    // Verify the chosen transition is in the FSM table. Logged via
    // journal if violated; in non-strict mode we keep going.
    $invariants->assertHealthStateTransitionAllowed($previousStatus, $decision['state']);

    return [
        'status'             => $decision['state'],
        'boot_epoch'         => $bootEpoch,
        'generation'         => $generation,
        'published_at'       => time(),
        'published_at_iso'   => date('c'),
        'root_cause'         => $decision['root_cause'],
        'root_cause_detail'  => $decision['root_cause_detail'],
        'checks'             => $probe['checks'],
        'auto_recovery'      => null,
        'phase2'             => [
            'stability_gate'    => $decision['gate'],
            'read_breaker'      => $decision['read_breaker'],
            'recovery_breaker'  => $decision['recovery_breaker'],
        ],
    ];
}

function doRwProbe(string $mountPoint, int $bytes): array
{
    if (!is_dir($mountPoint)) {
        return ['status' => 'error', 'message' => "mount point {$mountPoint} not a directory"];
    }
    $payload = random_bytes(max(8, $bytes));
    $tmpName = rtrim($mountPoint, '/') . '/.flowone_rw_probe_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $written = @file_put_contents($tmpName, $payload);
    if ($written !== strlen($payload)) {
        return ['status' => 'error', 'message' => "write failed at {$tmpName}"];
    }
    $readBack = @file_get_contents($tmpName);
    @unlink($tmpName);
    if ($readBack !== $payload) {
        return ['status' => 'error', 'message' => "read mismatch at {$tmpName}"];
    }
    return ['status' => 'ok', 'message' => 'rw probe ok', 'bytes' => strlen($payload)];
}

function publish(array $payload, DurableJson $stateFile, HmacSigner $signer, ?\Redis $redis, array $config, OperationJournal $journal): void
{
    try {
        $json = $signer->signToJson($payload);
    } catch (\Throwable $e) {
        $journal->record('publish_sign_failed', ['error' => $e->getMessage()]);
        return;
    }

    try {
        $stateFile->write($json);
    } catch (\Throwable $e) {
        $journal->record('publish_state_write_failed', ['error' => $e->getMessage()]);
    }

    if ($redis !== null) {
        try {
            $key = trim((string) $config['redis']['prefix'], ':') . ':' . (string) $config['redis']['status_key'];
            $redis->setex($key, (int) $config['redis']['status_ttl_sec'], $json);
            // pub/sub for future SSE: emit a tiny notification.
            $redis->publish((string) $config['redis']['pubsub_channel'], json_encode([
                'event' => 'storage_health_update',
                'generation' => $payload['generation'],
                'boot_epoch' => $payload['boot_epoch'],
                'status'     => $payload['status'],
            ]) ?: '{}');
        } catch (\Throwable $e) {
            $journal->record('publish_redis_failed', ['error' => $e->getMessage()]);
        }
    }
}
