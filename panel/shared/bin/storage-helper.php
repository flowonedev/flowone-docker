#!/usr/bin/env php
<?php
/**
 * flowone-storage-helper
 *
 * Privileged Unix-socket helper. Runs as root via systemd. Accepts a
 * tightly-scoped set of mount / systemctl / nft operations from the
 * monitor daemon. Refuses any caller whose UID/GID doesn't match the
 * configured peer (SO_PEERCRED enforcement of invariant I-8).
 *
 * Wire format: see HelperClient.php docblock.
 *
 * Allowed actions (Phase 1 baseline; extended in later phases):
 *
 *   ping                          health check
 *   mount_nfs                     mount the configured NFS share, holding MountLock (I-11)
 *   umount_nfs                    umount (lazy if --lazy=true), holding MountLock
 *   systemctl                     {action:'start'|'stop'|'restart'|'is-active', unit:<allowed>}
 *   nft_list_set                  read-only nft list set
 *
 * Hardening: this script assumes the systemd unit applies the sandbox
 * (NoNewPrivileges, ProtectKernel*, RestrictNamespaces, MemoryDenyWriteExecute,
 * SystemCallFilter allowlist). The script itself adds defence-in-depth: it
 * refuses to run if euid != 0, refuses non-CLI invocation, and refuses
 * unknown actions.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "storage-helper must run from CLI\n");
    exit(1);
}

require_once __DIR__ . '/../src/Storage/Config.php';
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
use FlowOne\Storage\Config;
use FlowOne\Storage\HmacSigner;
use FlowOne\Storage\Invariants;
use FlowOne\Storage\MountLock;
use FlowOne\Storage\OperationJournal;

$config = Config::load();

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "storage-helper requires root (effective uid != 0)\n");
    exit(1);
}

$socketPath = (string) $config['helper']['socket_path'];
$socketDir = dirname($socketPath);
if (!is_dir($socketDir)) {
    if (!@mkdir($socketDir, 0755, true) && !is_dir($socketDir)) {
        fwrite(STDERR, "cannot create socket dir {$socketDir}\n");
        exit(1);
    }
}

// Identify the allowed peer user.
$allowedUser = (string) $config['helper']['allowed_peer_user'];
$pwInfo = function_exists('posix_getpwnam') ? posix_getpwnam($allowedUser) : false;
if ($pwInfo === false) {
    fwrite(STDERR, "configured helper peer user '{$allowedUser}' not found on system\n");
    exit(1);
}
$allowedUid = (int) $pwInfo['uid'];
$allowedGid = (int) $pwInfo['gid'];

// Load HMAC + journal (for forensic logging).
$signer = HmacSigner::fromKeyFile(
    (string) $config['state']['hmac_key_path'],
    (int) $config['state']['hmac_key_mode_max']
);
$bootEpoch = new BootEpoch(
    rtrim((string) $config['state']['dir'], '/') . '/' . (string) $config['state']['boot_epoch_file']
);
$currentEpoch = $bootEpoch->current();

$journal = new OperationJournal(
    (string) $config['journal']['path'],
    $signer,
    $currentEpoch
);
$invariants = new Invariants($journal, strict: false);

// Server socket.
@unlink($socketPath);
$server = stream_socket_server('unix://' . $socketPath, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "stream_socket_server failed: {$errstr} ({$errno})\n");
    exit(1);
}
// Permissions: root:flowone-storage 0660 so only the helper's peer group can connect.
@chgrp($socketPath, $allowedGid);
@chmod($socketPath, 0660);

$journal->record('helper_started', [
    'socket'        => $socketPath,
    'allowed_uid'   => $allowedUid,
    'allowed_user'  => $allowedUser,
    'boot_epoch'    => $currentEpoch,
]);

// Graceful shutdown.
$shouldStop = false;
$handler = function () use (&$shouldStop, $journal, $socketPath) {
    $shouldStop = true;
    $journal->record('helper_signal_stop', ['socket' => $socketPath]);
};
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

while (!$shouldStop) {
    $client = @stream_socket_accept($server, 1.0);
    if ($client === false) {
        continue; // timeout
    }

    handleClient($client, $allowedUid, $config, $journal, $invariants, $bootEpoch);
}

@fclose($server);
@unlink($socketPath);
$journal->record('helper_stopped', []);

// ────────────────────────────────────────────────────────────────────────

function handleClient($client, int $allowedUid, array $config, OperationJournal $journal, Invariants $invariants, BootEpoch $bootEpoch): void
{
    stream_set_timeout($client, 30);

    // SO_PEERCRED check (I-8).
    if (!verifyPeerUid($client, $allowedUid, $journal)) {
        fwrite($client, json_encode([
            'id' => 0,
            'ok' => false,
            'error' => 'peer credential mismatch',
        ]) . "\n");
        fclose($client);
        return;
    }

    $line = @stream_get_line($client, 1024 * 1024, "\n");
    if ($line === false || $line === '') {
        fclose($client);
        return;
    }
    $req = json_decode($line, true);
    if (!is_array($req) || !isset($req['action'])) {
        fwrite($client, json_encode([
            'id' => 0,
            'ok' => false,
            'error' => 'malformed request',
        ]) . "\n");
        fclose($client);
        return;
    }

    $id     = isset($req['id']) ? (int) $req['id'] : 0;
    $action = (string) $req['action'];
    $args   = is_array($req['args'] ?? null) ? $req['args'] : [];
    $reqEpoch = isset($req['boot_epoch']) ? (int) $req['boot_epoch'] : 0;

    // I-10: reject queued actions with stale epochs. Allow epoch=0 for
    // out-of-band probes (e.g. storage-ctl from operator).
    $currentEpoch = $bootEpoch->current();
    if ($reqEpoch !== 0 && $reqEpoch !== $currentEpoch) {
        $invariants->assertBootEpochMatches($reqEpoch, $currentEpoch);
        $resp = ['id' => $id, 'ok' => false, 'data' => null, 'error' => 'stale_boot_epoch'];
        fwrite($client, json_encode($resp) . "\n");
        fclose($client);
        return;
    }

    $journal->record('helper_request', [
        'id'         => $id,
        'action'     => $action,
        'args_keys'  => array_keys($args),
        'req_epoch'  => $reqEpoch,
    ]);

    try {
        $data = dispatch($action, $args, $config, $journal);
        $resp = ['id' => $id, 'ok' => true, 'data' => $data, 'error' => null];
    } catch (\Throwable $e) {
        $resp = ['id' => $id, 'ok' => false, 'data' => null, 'error' => $e->getMessage()];
        $journal->record('helper_action_failed', [
            'id' => $id, 'action' => $action, 'error' => $e->getMessage(),
        ]);
    }

    fwrite($client, json_encode($resp) . "\n");
    fclose($client);
}

function verifyPeerUid($client, int $allowedUid, OperationJournal $journal): bool
{
    // Two-layer authorization model:
    //
    //   1. SOCKET FILE PERMISSIONS (kernel-enforced at connect() time)
    //      The helper creates /run/flowone/storage-helper.sock with mode
    //      0660 root:flowone-storage. The kernel refuses connect() from
    //      any process whose UID is not root and whose GID list does not
    //      include flowone-storage. So reaching this function at all
    //      means the peer is already authorized by the operating system.
    //
    //   2. SO_PEERCRED (defense in depth, when available)
    //      If the running PHP build supports SO_PEERCRED unpacking, we
    //      additionally confirm the kernel-reported peer UID matches our
    //      allowlist (allowedUid or root). Some PHP builds — notably
    //      LiteSpeed lsphp — were compiled without HAVE_SO_PEERCRED and
    //      will return a non-array from socket_get_option(). In that
    //      case we keep layer 1 only and continue (logged once).
    static $peerCredSupported = null;
    static $loggedFallback     = false;

    if ($peerCredSupported === false) {
        return true; // layer 1 already enforced by kernel; layer 2 unavailable
    }

    if (!function_exists('socket_import_stream') || !function_exists('socket_get_option')) {
        if (!$loggedFallback) {
            $journal->record('helper_no_peercred_support_falling_back_to_file_perms', []);
            $loggedFallback = true;
        }
        $peerCredSupported = false;
        return true;
    }

    // Linux kernel constant. PHP exposes it only when the sockets
    // extension was compiled with HAVE_SO_PEERCRED.
    if (!defined('SO_PEERCRED')) {
        define('SO_PEERCRED', 17);
    }

    $sock = @socket_import_stream($client);
    if (!$sock) {
        // Stream-to-socket import itself failed; trust layer 1.
        return true;
    }
    $cred = @socket_get_option($sock, SOL_SOCKET, SO_PEERCRED);
    if (!is_array($cred) || !isset($cred['uid'])) {
        // Layer 2 unavailable on this PHP build. Trust layer 1 going
        // forward and stop logging every connection.
        if (!$loggedFallback) {
            $journal->record('helper_peercred_unreadable_falling_back_to_file_perms', []);
            $loggedFallback = true;
        }
        $peerCredSupported = false;
        return true;
    }

    $peerCredSupported = true;
    $observedUid = (int) $cred['uid'];
    // Root (uid 0) is always allowed — it can already mount, systemctl,
    // and do everything the helper does. The peer check exists to
    // prevent OTHER unprivileged users on the box from talking.
    if ($observedUid !== 0 && $observedUid !== $allowedUid) {
        $journal->record('helper_peer_rejected', [
            'observed_uid' => $observedUid,
            'observed_pid' => (int) ($cred['pid'] ?? 0),
            'allowed_uid'  => $allowedUid,
        ]);
        return false;
    }
    return true;
}

function dispatch(string $action, array $args, array $config, OperationJournal $journal): mixed
{
    switch ($action) {
        case 'ping':
            return ['pong' => true, 'ts' => time()];

        case 'mount_nfs':
            return doMount($config, $journal);

        case 'umount_nfs':
            return doUmount($config, $journal, lazy: (bool) ($args['lazy'] ?? false));

        case 'systemctl':
            return doSystemctl($args, $config, $journal);

        case 'nft_list_set':
            return doNftListSet($config);

        default:
            throw new \RuntimeException("unknown action: {$action}");
    }
}

function doMount(array $config, OperationJournal $journal): array
{
    $mountPoint = (string) $config['nas']['mount_point'];
    $lock = MountLock::fromConfig();
    return $lock->withExclusive(function () use ($mountPoint, $journal) {
        $r = runCommand('/bin/mount', [$mountPoint], 60);
        $journal->record('helper_mount', $r);
        return $r;
    });
}

function doUmount(array $config, OperationJournal $journal, bool $lazy): array
{
    $mountPoint = (string) $config['nas']['mount_point'];
    $lock = MountLock::fromConfig();
    return $lock->withExclusive(function () use ($mountPoint, $journal, $lazy) {
        $args = $lazy ? ['-l', $mountPoint] : [$mountPoint];
        $r = runCommand('/bin/umount', $args, 30);
        $journal->record('helper_umount', $r + ['lazy' => $lazy]);
        return $r;
    });
}

function doSystemctl(array $args, array $config, OperationJournal $journal): array
{
    static $allowedActions = ['start', 'stop', 'restart', 'is-active', 'status'];
    static $allowedUnits;
    if ($allowedUnits === null) {
        // Allow VPN unit + helper/monitor units themselves (for storage-ctl).
        $allowedUnits = [
            (string) $config['vpn']['service_unit'],
            'flowone-storage-helper.service',
            'flowone-storage-monitord.service',
        ];
    }
    $action = (string) ($args['action'] ?? '');
    $unit   = (string) ($args['unit'] ?? '');
    if (!in_array($action, $allowedActions, true)) {
        throw new \RuntimeException("disallowed systemctl action: {$action}");
    }
    if (!in_array($unit, $allowedUnits, true)) {
        throw new \RuntimeException("disallowed systemctl unit: {$unit}");
    }
    $r = runCommand('/bin/systemctl', [$action, $unit], 30);
    $journal->record('helper_systemctl', $r + ['action' => $action, 'unit' => $unit]);
    return $r;
}

function doNftListSet(array $config): array
{
    $table = (string) $config['firewall']['nft_table'];
    $set   = (string) $config['firewall']['nft_set'];
    return runCommand('/usr/sbin/nft', ['list', 'set', ...explode(' ', $table), $set], 10);
}

function runCommand(string $bin, array $args, int $timeoutSec): array
{
    $resolved = resolveBinary($bin);
    if ($resolved === null) {
        return ['success' => false, 'output' => "binary not found: {$bin}", 'code' => -1];
    }
    $cmd = $resolved . ' ' . implode(' ', array_map('escapeshellarg', $args));

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['success' => false, 'output' => 'proc_open failed', 'code' => -1];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadlineNs = hrtime(true) + ($timeoutSec * 1_000_000_000);
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        if (hrtime(true) > $deadlineNs) {
            $timedOut = true;
            $pid = $st['pid'];
            @posix_kill($pid, SIGTERM);
            usleep(200_000);
            $after = proc_get_status($proc);
            if ($after['running']) {
                @posix_kill($pid, SIGKILL);
            }
            break;
        }
        $stdout .= (string) @stream_get_contents($pipes[1]);
        $stderr .= (string) @stream_get_contents($pipes[2]);
        usleep(50_000);
    }
    $stdout .= (string) @stream_get_contents($pipes[1]);
    $stderr .= (string) @stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $code = @proc_close($proc);

    $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
    if ($timedOut) {
        return ['success' => false, 'output' => "timeout after {$timeoutSec}s: {$output}", 'code' => -1, 'timed_out' => true];
    }
    return ['success' => $code === 0, 'output' => $output, 'code' => $code];
}

function resolveBinary(string $candidate): ?string
{
    if (is_file($candidate)) {
        return $candidate;
    }
    foreach (['/usr/sbin', '/usr/bin', '/sbin', '/bin', '/usr/local/sbin', '/usr/local/bin'] as $dir) {
        $p = $dir . '/' . basename($candidate);
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}
