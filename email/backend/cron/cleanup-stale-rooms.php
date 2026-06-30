#!/usr/bin/env php
<?php
/**
 * Poll LiveKit for empty rooms older than 15 minutes and mark matching portal_calls as ended.
 *
 * Run (server):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/cleanup-stale-rooms.php --verbose
 */
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$verbose = in_array('--verbose', $argv, true);
$smoke = in_array('--smoke', $argv, true);
$jsonOut = in_array('--json', $argv, true);
$skipSend = in_array('--skip-send', $argv, true);
$only = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = array_map('trim', explode(',', substr($a, 7)));
    }
}
if (in_array('--help', $argv, true)) {
    echo "Usage: cleanup-stale-rooms.php [--verbose] [--smoke] [--json] [--skip-send] [--only=livekit,portal,attachments]\n";
    exit(0);
}

require_once __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/cleanup-stale-rooms-' . gmdate('Ymd-His') . '.log';
$passed = 0;
$failed = 0;
$warnings = 0;

function log_line(string $logFile, string $line): void
{
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
}

function want_group(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

// Pre-flight
if (!extension_loaded('curl')) {
    fwrite(STDERR, "FAIL: curl extension required\n");
    exit(1);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "FAIL: pdo_mysql extension required\n");
    exit(1);
}

if ($smoke) {
    echo "[SMOKE] config + extensions OK\n";
    exit(0);
}

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL: DB ' . $e->getMessage() . "\n");
    exit(1);
}

$staleRooms = [];
if (want_group($only, 'livekit')) {
    try {
        $lk = new \Webmail\Services\LiveKitAdminService($config);
        $now = time();
        foreach ($lk->listRooms() as $room) {
            $name = (string)($room['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $created = (int)($room['creation_time'] ?? 0);
            if ($created <= 0) {
                continue;
            }
            if ($now - $created < 900) {
                continue;
            }
            $parts = $lk->listParticipants($name);
            if (is_array($parts) && count($parts) > 0) {
                continue;
            }
            $staleRooms[] = $name;
        }
    } catch (\Throwable $e) {
        $failed++;
        log_line($logFile, '[FAIL] LiveKit list: ' . $e->getMessage());
        if ($verbose) {
            fwrite(STDERR, $e->getMessage() . "\n");
        }
    }
}

$updated = 0;
if (want_group($only, 'portal') && !$skipSend && $staleRooms !== []) {
    foreach ($staleRooms as $roomName) {
        try {
            $u = $db->prepare("
                UPDATE portal_calls
                SET status = 'ended', ended_at = COALESCE(ended_at, NOW())
                WHERE room_name = ? AND status NOT IN ('ended', 'cancelled')
            ");
            $u->execute([$roomName]);
            $updated += $u->rowCount();
            $passed++;
            log_line($logFile, '[PASS] portal_calls stale room ' . $roomName);
        } catch (\Throwable $e) {
            $failed++;
            log_line($logFile, '[FAIL] portal update ' . $roomName . ': ' . $e->getMessage());
        }
    }
} elseif ($skipSend && $staleRooms !== []) {
    $warnings++;
    log_line($logFile, '[WARN] --skip-send: would touch ' . count($staleRooms) . ' stale rooms');
}

// Purge expired in-call chat attachments (files already delivered via the
// transcript email; retained 7 days for manual re-download).
$attachmentsDeleted = 0;
if (want_group($only, 'attachments')) {
    if ($skipSend) {
        $warnings++;
        log_line($logFile, '[WARN] --skip-send: attachment cleanup skipped');
    } else {
        try {
            $att = new \Webmail\Services\GuestCallAttachmentService($config);
            $attachmentsDeleted = $att->cleanupOlderThan();
            $passed++;
            log_line($logFile, "[PASS] guest_call_attachments purged: {$attachmentsDeleted}");
        } catch (\Throwable $e) {
            $failed++;
            log_line($logFile, '[FAIL] attachment cleanup: ' . $e->getMessage());
            if ($verbose) {
                fwrite(STDERR, $e->getMessage() . "\n");
            }
        }
    }
}

if ($jsonOut) {
    echo json_encode([
        'passed' => $passed,
        'failed' => $failed,
        'warnings' => $warnings,
        'stale_room_count' => count($staleRooms),
        'portal_rows_updated' => $updated,
        'attachments_deleted' => $attachmentsDeleted,
    ], JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "Stale empty rooms: " . count($staleRooms) . ", portal rows updated: {$updated}, attachments purged: {$attachmentsDeleted}\n";
}

exit($failed > 0 ? 1 : 0);
