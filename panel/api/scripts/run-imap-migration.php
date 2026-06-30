<?php
/**
 * IMAP Migration Runner Script
 *
 * Runs in the background to execute imapsync migrations for a single
 * imap_migrations row. Designed to be safe to re-run (delta sync): imapsync
 * is idempotent and we never pass --delete1/--delete2, so re-running only
 * copies new messages and never removes anything on either side.
 *
 * Usage: php run-imap-migration.php <migration_id>
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

if ($argc < 2) {
    die("Usage: php run-imap-migration.php <migration_id>\n");
}

$migrationId = (int) $argv[1];

// Load config
$configFile = dirname(__DIR__) . '/config.php';
$localConfigFile = dirname(__DIR__) . '/config.local.php';

if (!file_exists($configFile)) {
    die("Config file not found\n");
}

$config = require $configFile;
if (file_exists($localConfigFile)) {
    $localConfig = require $localConfigFile;
    $config = array_replace_recursive($config, $localConfig);
}

// Connect to database
try {
    $dbConfig = $config['database'];
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4",
        $dbConfig['user'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Get migration record
$stmt = $pdo->prepare("SELECT * FROM imap_migrations WHERE id = ? AND status IN ('pending', 'running')");
$stmt->execute([$migrationId]);
$migration = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$migration) {
    die("Migration not found or already processed\n");
}

// Atomically claim the job: only the process that flips 'pending' -> 'running'
// proceeds. This lets the web launcher AND the systemd dispatcher both try to
// start a migration without ever double-running imapsync — the loser simply
// exits here. A row already 'running' is owned by another runner.
$pid = getmypid();
$claim = $pdo->prepare("UPDATE imap_migrations SET status = 'running', pid = ?, started_at = NOW() WHERE id = ? AND status = 'pending'");
$claim->execute([$pid, $migrationId]);
if ($claim->rowCount() !== 1) {
    die("Migration {$migrationId} already claimed by another runner\n");
}

$accounts = json_decode($migration['accounts'], true) ?: [];
$logFile = $migration['log_file'];
$totalAccounts = count($accounts);
$completedAccounts = 0;

// Ensure log directory exists
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

/**
 * Append a line to the migration log.
 */
$log = function (string $line) use ($logFile) {
    file_put_contents($logFile, $line, FILE_APPEND);
};

/**
 * Pull the first integer captured by any of the given regexes out of the
 * imapsync output. Returns null when nothing matched (older/newer imapsync
 * builds word their statistics block slightly differently).
 */
$grabInt = function (string $haystack, array $patterns): ?int {
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $haystack, $m)) {
            return (int) $m[1];
        }
    }
    return null;
};

// Resolve the imapsync binary. The runner is often launched by the web SAPI
// (LiteSpeed/lsphp), whose PATH frequently omits /usr/local/bin where imapsync
// installs — so a bare `imapsync` would fail with "command not found". Find an
// absolute path, falling back to PATH only as a last resort.
$imapsyncBin = trim((string) shell_exec('command -v imapsync 2>/dev/null'));
if ($imapsyncBin === '' || !is_executable($imapsyncBin)) {
    $imapsyncBin = '';
    foreach (['/usr/local/bin/imapsync', '/usr/bin/imapsync', '/bin/imapsync', '/opt/imapsync/imapsync'] as $cand) {
        if (is_executable($cand)) {
            $imapsyncBin = $cand;
            break;
        }
    }
}
if ($imapsyncBin === '') {
    $imapsyncBin = 'imapsync';
}

// Build imapsync SSL options from the stored booleans.
$sslOpt1 = $migration['source_ssl'] ? '--ssl1' : '';
$sslOpt2 = $migration['dest_ssl'] ? '--ssl2' : '';
$sourcePort = (int) ($migration['source_port'] ?? 993);
$destPort = (int) ($migration['dest_port'] ?? 993);

// Folder exclude. Gmail/Workspace expose virtual [Gmail] folders: "All Mail"
// holds every message (copying it duplicates everything), and Important /
// Starred aren't real folders. For Gmail we skip those plus Spam/Trash but
// KEEP "Sent Mail" and "Drafts" — --automap maps them to the dest's specials
// and --skipcrossduplicates de-dupes multi-labelled messages. For every other
// source we keep the original blunt exclude (harmless if there is no [Gmail]).
$isGmailSource = (bool) preg_match('/gmail|googlemail/i', (string) $migration['source_host']);
$excludeOpt = $isGmailSource
    ? '--exclude "^\\[Gmail\\]/(All Mail|Important|Starred|Spam|Trash|Bin)$"'
    : '--exclude "^\\[Gmail\\]"';

$log("=== IMAP Migration Started ===\n");
$log("Migration ID: {$migrationId}\n");
$log("Source: {$migration['source_host']}:{$sourcePort}\n");
$log("Destination: {$migration['dest_host']}:{$destPort}\n");
$log("Accounts: {$totalAccounts}\n");
$log("Started: " . date('Y-m-d H:i:s') . "\n\n");

$hasErrors = false;
$aggMessagesTotal = 0;     // messages present on source
$aggMessagesTransferred = 0; // messages actually copied this run
$aggBytesTransferred = 0;  // bytes copied this run
$allVerified = true;

foreach ($accounts as $i => &$account) {
    // Support both the current schema (source_password / dest_email /
    // dest_password) and the older one (old_password / new_password,
    // same address on both sides) so in-flight rows keep working.
    $email = $account['email'];
    $destEmail = $account['dest_email'] ?? $email;
    $sourcePassword = $account['source_password'] ?? $account['old_password'] ?? '';
    $destPassword = $account['dest_password'] ?? $account['new_password'] ?? $sourcePassword;

    $log("\n--- Migrating: {$email} -> {$destEmail} ---\n");

    // Mark this account as in-flight and reset its per-run counters.
    $account['status'] = 'running';
    $account['current_folder'] = '';
    $account['messages_total'] = $account['messages_total'] ?? 0;
    $account['messages_done'] = $account['messages_done'] ?? 0;
    $account['bytes_transferred'] = $account['bytes_transferred'] ?? 0;
    $account['error'] = null;

    $pdo->prepare("UPDATE imap_migrations SET current_account = ?, accounts = ? WHERE id = ?")
        ->execute([$email, json_encode($accounts), $migrationId]);

    // Build imapsync command.
    //
    // Safety: no --delete1 / --delete2 — we never remove messages on either
    // side, which keeps delta/final/sweep re-runs non-destructive.
    // --skipcrossduplicates + --automap make repeated runs idempotent.
    $cmd = sprintf(
        '%s --host1 %s --port1 %d %s --user1 %s --password1 %s ' .
        '--host2 %s --port2 %d %s --user2 %s --password2 %s ' .
        '--automap --skipcrossduplicates --nofoldersizes --nofoldersizesatend ' .
        '%s 2>&1',
        escapeshellarg($imapsyncBin),
        escapeshellarg($migration['source_host']),
        $sourcePort,
        $sslOpt1,
        escapeshellarg($email),
        escapeshellarg($sourcePassword),
        escapeshellarg($migration['dest_host']),
        $destPort,
        $sslOpt2,
        escapeshellarg($destEmail),
        escapeshellarg($destPassword),
        $excludeOpt
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    $outputStr = implode("\n", $output);
    $log($outputStr . "\n");

    // ---- Parse counts / bytes from the imapsync statistics block ----
    $sourceMessages = $grabInt($outputStr, [
        '/Host1\s+Nb\s+messages(?:\s+selected)?\s*:?\s*(\d+)/i',
        '/(\d+)\s+messages?\s+in\s+(?:all\s+folders\s+on\s+)?host1/i',
        '/Messages?\s+found\s+on\s+host1\s*:?\s*(\d+)/i',
    ]);
    $destMessages = $grabInt($outputStr, [
        '/Host2\s+Nb\s+messages(?:\s+selected)?\s*:?\s*(\d+)/i',
        '/(\d+)\s+messages?\s+in\s+(?:all\s+folders\s+on\s+)?host2/i',
        '/Messages?\s+found\s+on\s+host2\s*:?\s*(\d+)/i',
    ]);
    $transferred = $grabInt($outputStr, [
        '/Messages?\s+transferred\s*:?\s*(\d+)/i',
        '/Total\s+messages?\s+transferred\s*:?\s*(\d+)/i',
    ]) ?? 0;
    $skipped = $grabInt($outputStr, [
        '/Messages?\s+skipped\s*:?\s*(\d+)/i',
    ]) ?? 0;
    $bytes = $grabInt($outputStr, [
        '/Total\s+bytes\s+transferred\s*:?\s*(\d+)/i',
        '/Total\s+bytes\s+sent\s*:?\s*(\d+)/i',
    ]) ?? 0;

    // Source total is the best "denominator" we have. Fall back to
    // transferred + skipped when imapsync did not print a host1 count.
    $messagesTotal = $sourceMessages ?? ($transferred + $skipped);

    $account['messages_total'] = $messagesTotal;
    $account['messages_done'] = $transferred + $skipped;
    $account['messages_transferred'] = $transferred;
    $account['messages_skipped'] = $skipped;
    $account['bytes_transferred'] = $bytes;
    $account['dest_messages'] = $destMessages;

    // ---- Validation: did everything land on the destination? ----
    // Prefer comparing the destination count to the source count. When the
    // destination count is unavailable, fall back to transferred+skipped
    // covering the source total.
    if ($destMessages !== null && $messagesTotal > 0) {
        $verified = $destMessages >= $messagesTotal;
    } else {
        $verified = $messagesTotal > 0 && ($transferred + $skipped) >= $messagesTotal;
    }
    $account['verified'] = $verified;

    if ($exitCode === 0) {
        $account['status'] = 'completed';
        $account['progress'] = 100;
        $completedAccounts++;
        $log("SUCCESS: {$email} migrated (transferred {$transferred}, skipped {$skipped}, source {$messagesTotal}, dest " . ($destMessages ?? '?') . ")\n");
        if (!$verified) {
            $allVerified = false;
            $log("WARNING: {$email} counts do not match — destination not yet verified\n");
        }
    } else {
        $account['status'] = 'failed';
        $account['error'] = "Exit code: {$exitCode}";
        $account['verified'] = false;
        $hasErrors = true;
        $allVerified = false;
        $log("FAILED: {$email} - Exit code: {$exitCode}\n");
    }

    $aggMessagesTotal += $messagesTotal;
    $aggMessagesTransferred += $transferred;
    $aggBytesTransferred += $bytes;

    // Update overall progress (account-weighted) + aggregate counters.
    $progress = (int) round(($i + 1) / max(1, $totalAccounts) * 100);
    $pdo->prepare("
        UPDATE imap_migrations
        SET completed_accounts = ?, progress = ?, accounts = ?,
            total_messages = ?, transferred_messages = ?, transferred_bytes = ?
        WHERE id = ?
    ")->execute([
        $completedAccounts,
        $progress,
        json_encode($accounts),
        $aggMessagesTotal,
        $aggMessagesTransferred,
        $aggBytesTransferred,
        $migrationId,
    ]);
}
unset($account);

// Update final status
$finalStatus = $hasErrors ? ($completedAccounts > 0 ? 'completed' : 'failed') : 'completed';
$errorMessage = $hasErrors ? "Completed with errors: {$completedAccounts}/{$totalAccounts} accounts migrated" : null;
$verifiedFlag = ($allVerified && !$hasErrors) ? 1 : 0;

$pdo->prepare("
    UPDATE imap_migrations
    SET status = ?, error_message = ?, current_account = NULL, completed_at = NOW(), pid = NULL,
        verified = ?, total_messages = ?, transferred_messages = ?, transferred_bytes = ?
    WHERE id = ?
")->execute([
    $finalStatus,
    $errorMessage,
    $verifiedFlag,
    $aggMessagesTotal,
    $aggMessagesTransferred,
    $aggBytesTransferred,
    $migrationId,
]);

$log("\n=== Migration Completed ===\n");
$log("Status: {$finalStatus}\n");
$log("Accounts: {$completedAccounts}/{$totalAccounts}\n");
$log("Messages transferred this run: {$aggMessagesTransferred}\n");
$log("Source messages total: {$aggMessagesTotal}\n");
$log("Bytes transferred this run: {$aggBytesTransferred}\n");
$log("Verified: " . ($verifiedFlag ? 'yes' : 'no') . "\n");
$log("Finished: " . date('Y-m-d H:i:s') . "\n");

echo "Migration {$migrationId} completed with status: {$finalStatus}\n";
