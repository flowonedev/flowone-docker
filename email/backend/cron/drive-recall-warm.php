#!/usr/bin/env php
<?php
/**
 * Drive Cold-File Recall Warmer (single file).
 *
 * Fired in the background by DriveService::triggerBackgroundRecall() when
 * a user requests download of a large cold file. The HTTP request returns
 * 202 immediately and this script does the actual NAS->VPS recall so the
 * user's next retry hits a warm file.
 *
 * Contract:
 *   - Take a single --file-id=N argument.
 *   - Resolve the row, check tier_state, run TierRecallService::recallCold()
 *     if state is 'cold'.
 *   - Never throw; always exit 0 (this is best-effort - if it fails, the
 *     next click triggers another warmer, and the user-visible download
 *     endpoint already handles the failure case).
 *
 * Concurrency:
 *   - TierRecallService uses a per-file MountLock so two concurrent
 *     warmers for the same file are safe (the second one waits, then
 *     no-ops when it sees tier_state has flipped).
 *
 * Logging:
 *   - All output is dropped by the spawning shell_exec (redirected to
 *     /dev/null) so logs only land in error_log / php_errors.log.
 *
 * Usage:
 *   php drive-recall-warm.php --file-id=123 [--verbose]
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "drive-recall-warm must run from CLI\n");
    exit(2);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Core\Database;
use FlowOne\Storage\Config as StorageConfig;
use FlowOne\Storage\TierRecallService;
use FlowOne\Storage\TierState;

$opts = parseOpts($argv);
if ($opts['help']) {
    printHelp();
    exit(0);
}

if ($opts['file_id'] <= 0) {
    fwrite(STDERR, "must specify --file-id=N\n");
    exit(2);
}

try {
    $appConfig = require __DIR__ . '/../src/config.php';
    $pdo = Database::getConnection($appConfig);

    $stmt = $pdo->prepare("SELECT id, tier_state FROM drive_files WHERE id = ?");
    $stmt->execute([$opts['file_id']]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        if ($opts['verbose']) {
            fwrite(STDERR, "[warm] file_id={$opts['file_id']} not in drive_files; exiting\n");
        }
        exit(0);
    }

    $tier = $row['tier_state'] ?? null;
    // Already hot/tiering/lost - nothing to warm. The 'recalling' case is
    // also safe to no-op because TierRecallService is idempotent and a
    // concurrent recall is already moving bytes.
    if ($tier !== TierState::COLD) {
        if ($opts['verbose']) {
            fwrite(STDERR, "[warm] file_id={$opts['file_id']} state={$tier}; nothing to do\n");
        }
        exit(0);
    }

    // Preflight: storage library must be deployed and the kill switch must
    // be on. If either is false, recall is unavailable and the download
    // endpoint will fall back to the legacy resolveFilePath() flow on the
    // user's next retry.
    if (!class_exists(TierRecallService::class) || !class_exists(StorageConfig::class)) {
        fwrite(STDERR, "[warm] FlowOne storage library not installed; exiting\n");
        exit(0);
    }
    $storageConfig = StorageConfig::load();
    if (!($storageConfig['phases']['phase5b_drive_recall'] ?? false)) {
        if ($opts['verbose']) {
            fwrite(STDERR, "[warm] phase5b_drive_recall is OFF; exiting\n");
        }
        exit(0);
    }

    $vpsBase = (string) ($appConfig['drive']['storage_path']
        ?? '/var/www/vps-email/storage/drive');

    $svc = TierRecallService::build(
        pdo:           $pdo,
        tenant:        'email-drive',
        vpsBasePath:   $vpsBase,
        storageConfig: $storageConfig,
    );

    $start = microtime(true);
    $path = $svc->recallCold($opts['file_id']);
    $elapsed = (int) ((microtime(true) - $start) * 1000);

    if ($opts['verbose']) {
        fwrite(STDERR, "[warm] file_id={$opts['file_id']} recalled in {$elapsed}ms -> {$path}\n");
    }
} catch (\Throwable $e) {
    error_log("[drive-recall-warm] file_id={$opts['file_id']} failed: " . $e->getMessage());
}

exit(0);

// ────────────────────────────────────────────────────────────────────────

function parseOpts(array $argv): array
{
    $opts = ['help' => false, 'verbose' => false, 'file_id' => 0];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $opts['help'] = true; continue; }
        if ($arg === '--verbose' || $arg === '-v') { $opts['verbose'] = true; continue; }
        if (str_starts_with($arg, '--file-id=')) {
            $opts['file_id'] = max(0, (int) substr($arg, strlen('--file-id=')));
        }
    }
    return $opts;
}

function printHelp(): void
{
    echo <<<TXT
Drive Cold-File Recall Warmer

Usage:
  drive-recall-warm.php --file-id=N           recall a single cold file
  drive-recall-warm.php --file-id=N --verbose log progress to stderr

Fires NAS->VPS recall for a single drive_files row whose tier_state is
'cold'. Spawned in the background by DriveService when the user requests
download of a large cold file. Idempotent; safe to invoke concurrently.

TXT;
}
