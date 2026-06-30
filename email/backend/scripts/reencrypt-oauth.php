#!/usr/bin/env php
<?php
/**
 * Re-encrypt OAuth tokens under a target key version.
 *
 * Walks every row in `webmail_oauth_tokens` with non-empty refresh/access tokens,
 * decrypts them with the OAuthCryptor (which understands all configured key
 * versions PLUS the legacy CBC format), and re-encrypts under `--target-version`.
 *
 * Server run command:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/scripts/reencrypt-oauth.php \
 *       --target-version=1 --verbose
 *
 * Flags:
 *   --target-version=N   The key version to re-encrypt under (required for a real run)
 *   --dry-run            Decrypt only; do not write any rows
 *   --quarantine-mode    Mark un-decryptable rows as health='broken' (disaster recovery)
 *   --only=group1,group2 Run only the listed test groups
 *   --smoke              Quick config/connectivity check, no row writes
 *   --json               Emit final results as JSON
 *   --verbose            Extra debug output
 *   --help               Show this help
 *
 * Logs to: <repo>/storage/logs/reencrypt-oauth-YYYYMMDD-HHMMSS.log
 * Exit 0 = all OK; Exit 1 = failures or pre-flight problems.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Services\OAuthCryptor;

// ---------------------------------------------------------------------------
// CLI args
// ---------------------------------------------------------------------------
$opts = getopt('', [
    'help',
    'verbose',
    'dry-run',
    'quarantine-mode',
    'smoke',
    'json',
    'only::',
    'target-version::',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1800));
    exit(0);
}

$verbose       = array_key_exists('verbose', $opts);
$dryRun        = array_key_exists('dry-run', $opts);
$quarantine    = array_key_exists('quarantine-mode', $opts);
$smoke         = array_key_exists('smoke', $opts);
$jsonOutput    = array_key_exists('json', $opts);
$onlyGroups    = isset($opts['only']) && $opts['only'] !== false
    ? array_filter(array_map('trim', explode(',', (string)$opts['only'])))
    : [];
$targetVersion = isset($opts['target-version']) && $opts['target-version'] !== false
    ? (int)$opts['target-version']
    : 0;

// ---------------------------------------------------------------------------
// Logging + safety helpers
// ---------------------------------------------------------------------------
$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logPath = $logDir . '/reencrypt-oauth-' . date('Ymd-His') . '.log';
$logFh = fopen($logPath, 'a');

$counts = [
    'total'              => 0,
    'reencrypted'        => 0,
    'recovered'          => 0,
    'already_current'    => 0,
    'quarantined'        => 0,
    'skipped_empty'      => 0,
    'skipped_login_only' => 0,
    'errors'             => 0,
];

$failures = [];

function color(string $code, string $msg): string
{
    $ttyCli = function_exists('stream_isatty') ? stream_isatty(STDOUT) : true;
    return $ttyCli ? "\033[{$code}m{$msg}\033[0m" : $msg;
}

function logLine(string $level, string $msg, $logFh, bool $alsoStdout = true): void
{
    $line = '[' . date('H:i:s') . '] [' . str_pad($level, 4) . '] ' . $msg;
    if ($alsoStdout) {
        $colored = match ($level) {
            'PASS' => color('32', $line),
            'FAIL' => color('31', $line),
            'WARN' => color('33', $line),
            default => $line,
        };
        fwrite(STDOUT, $colored . "\n");
    }
    if ($logFh) {
        fwrite($logFh, $line . "\n");
    }
}

function shouldRun(array $only, string $group): bool
{
    return $only === [] || in_array($group, $only, true);
}

// Signal cleanup so we never leave half-written state
$cleanupCalled = false;
$shutdown = function () use (&$cleanupCalled, $logFh) {
    if ($cleanupCalled) return;
    $cleanupCalled = true;
    if ($logFh) {
        fwrite($logFh, "[" . date('H:i:s') . "] [INFO] Shutdown handler ran\n");
        fclose($logFh);
    }
};
register_shutdown_function($shutdown);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use ($shutdown) { $shutdown(); exit(130); });
    pcntl_signal(SIGTERM, function () use ($shutdown) { $shutdown(); exit(143); });
}

// ---------------------------------------------------------------------------
// Load config and OAuthCryptor
// ---------------------------------------------------------------------------
$config = require __DIR__ . '/../src/config.php';

logLine('INFO', "reencrypt-oauth starting (dry-run=" . ($dryRun ? 'yes' : 'no')
    . ", quarantine-mode=" . ($quarantine ? 'yes' : 'no')
    . ", target-version=" . ($targetVersion ?: 'auto-current')
    . ", smoke=" . ($smoke ? 'yes' : 'no') . ")", $logFh);
logLine('INFO', "Log file: {$logPath}", $logFh);

// ---------------------------------------------------------------------------
// Pre-flight checks
// ---------------------------------------------------------------------------
function preflight(array $config, $logFh, bool $verbose): void
{
    $required = ['openssl', 'pdo_mysql'];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            logLine('FAIL', "Missing required PHP extension: {$ext}", $logFh);
            exit(1);
        }
    }
    logLine('PASS', "PHP extensions OK (openssl, pdo_mysql)", $logFh);

    if (empty($config['db']['user']) || empty($config['db']['name'])) {
        logLine('FAIL', "Database config incomplete (db.user / db.name missing)", $logFh);
        exit(1);
    }

    try {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->query('SELECT 1');
        logLine('PASS', "Database connection OK", $logFh);
    } catch (\Throwable $e) {
        logLine('FAIL', "Database connection failed: " . $e->getMessage(), $logFh);
        exit(1);
    }

    if (empty($config['oauth_encryption']['keys'])) {
        logLine('FAIL', "No oauth_encryption.keys configured (set OAUTH_KEYS or IMAP_ENCRYPTION_KEY)", $logFh);
        exit(1);
    }
    logLine('PASS', "OAuth keys configured: versions=" . implode(',', array_keys($config['oauth_encryption']['keys'])), $logFh);

    $logDir = dirname((function () use ($config) {
        return __DIR__ . '/../storage/logs/x.log';
    })());
    if (!is_writable($logDir) && !@mkdir($logDir, 0775, true)) {
        logLine('FAIL', "Log dir is not writable: {$logDir}", $logFh);
        exit(1);
    }
    logLine('PASS', "Log directory writable: {$logDir}", $logFh);
}

preflight($config, $logFh, $verbose);

// ---------------------------------------------------------------------------
// Smoke mode: stop after pre-flight + canary check
// ---------------------------------------------------------------------------
try {
    $cryptor = new OAuthCryptor($config);
} catch (\Throwable $e) {
    logLine('FAIL', "OAuthCryptor init failed: " . $e->getMessage(), $logFh);
    exit(1);
}

if ($targetVersion === 0) {
    $targetVersion = $cryptor->currentVersion();
    logLine('INFO', "No --target-version supplied; defaulting to currentVersion={$targetVersion}", $logFh);
}

if (!isset($config['oauth_encryption']['keys'][$targetVersion])) {
    logLine('FAIL', "Target version v{$targetVersion} is not configured in OAUTH_KEYS", $logFh);
    exit(1);
}

if (shouldRun($onlyGroups, 'canary')) {
    try {
        OAuthCryptor::canaryCheck($config);
        logLine('PASS', "Canary decrypts successfully", $logFh);
    } catch (\Throwable $e) {
        logLine('FAIL', "Canary decrypt failed: " . $e->getMessage(), $logFh);
        exit(1);
    }
}

if ($smoke) {
    logLine('INFO', "--smoke mode complete", $logFh);
    exit(0);
}

// ---------------------------------------------------------------------------
// Walk webmail_oauth_tokens
// ---------------------------------------------------------------------------
$db = \Webmail\Core\Database::getConnection($config);

// Detect health columns (added by migration 151_oauth_health.sql)
$hasHealthColumn = false;
try {
    $check = $db->query("SHOW COLUMNS FROM webmail_oauth_tokens LIKE 'health'");
    $hasHealthColumn = $check && $check->fetchColumn() !== false;
} catch (\Throwable $e) {
    $hasHealthColumn = false;
}

$envelopePrefix = 'v' . $targetVersion . ':';

$selectColumns = "id, primary_email, oauth_email, provider, refresh_token_encrypted, access_token_encrypted";
if ($hasHealthColumn) {
    $selectColumns .= ", health";
}

$rowsStmt = $db->query("SELECT {$selectColumns} FROM webmail_oauth_tokens ORDER BY id ASC");
$rows = $rowsStmt->fetchAll(\PDO::FETCH_ASSOC);
$counts['total'] = count($rows);

logLine('INFO', "Found {$counts['total']} OAuth token rows to inspect", $logFh);

foreach ($rows as $row) {
    $rowId   = (int)$row['id'];
    $owner   = $row['primary_email'];
    $oauth   = $row['oauth_email'];
    $current = $row['health'] ?? 'healthy';

    if ($current !== 'healthy' && !$quarantine) {
        if ($verbose) {
            logLine('INFO', "Row {$rowId} skipped (health={$current})", $logFh);
        }
        continue;
    }

    $refreshIn = $row['refresh_token_encrypted'] ?? '';
    $accessIn  = $row['access_token_encrypted'] ?? '';

    if ($refreshIn === '' && $accessIn === '') {
        $counts['skipped_empty']++;
        if ($verbose) {
            logLine('INFO', "Row {$rowId} skipped (both tokens empty)", $logFh);
        }
        continue;
    }

    // Login-only rows: have no refresh token by design (storeTokensForLogin).
    // The runtime fallback in BaseController::getOAuthAccountByEmail() handles them.
    // Don't try to re-encrypt — there's nothing meaningful in the access slot for IMAP.
    if ($refreshIn === '') {
        $counts['skipped_login_only']++;
        if ($verbose) {
            logLine('INFO', "Row {$rowId} ({$owner} -> {$oauth}) skipped (login-only, no refresh token)", $logFh);
        }
        continue;
    }

    $allAlreadyCurrent = true;
    foreach ([$refreshIn, $accessIn] as $envIn) {
        if ($envIn === '' || $envIn === null) continue;
        if (strpos($envIn, $envelopePrefix) !== 0) {
            $allAlreadyCurrent = false;
            break;
        }
    }
    if ($allAlreadyCurrent) {
        $counts['already_current']++;
        if ($verbose) {
            logLine('INFO', "Row {$rowId} already at v{$targetVersion}", $logFh);
        }
        continue;
    }

    // Decrypt with the full cryptor (knows v1..vN + legacy CBC)
    $refreshPlain = $refreshIn !== '' ? $cryptor->decrypt($refreshIn) : '';
    $accessPlain  = $accessIn  !== '' ? $cryptor->decrypt($accessIn)  : '';

    $refreshOk = ($refreshIn === '' || ($refreshPlain !== null && $refreshPlain !== ''));
    $accessOk  = ($accessIn  === '' || ($accessPlain  !== null && $accessPlain  !== ''));

    // Decision tree:
    // - refresh ok + access ok        -> clean re-encrypt
    // - refresh ok + access broken    -> recover: re-encrypt refresh, blank access (ephemeral; next call refreshes)
    // - refresh broken                -> quarantine (cannot recover without re-consent)
    if (!$refreshOk) {
        $counts['quarantined']++;
        $failures[] = [
            'id' => $rowId,
            'primary_email' => $owner,
            'oauth_email' => $oauth,
            'refresh_ok' => false,
            'access_ok' => $accessOk,
            'action' => 'quarantine',
        ];
        logLine('WARN', "Row {$rowId} ({$owner} -> {$oauth}) refresh token un-decryptable -> quarantine", $logFh);

        if (!$dryRun && $quarantine && $hasHealthColumn) {
            try {
                $upd = $db->prepare("
                    UPDATE webmail_oauth_tokens
                    SET health='broken',
                        health_reason='decrypt_failed',
                        health_updated_at=NOW()
                    WHERE id = ?
                ");
                $upd->execute([$rowId]);
            } catch (\Throwable $e) {
                logLine('WARN', "Row {$rowId} quarantine update failed: " . $e->getMessage(), $logFh);
            }
        }
        continue;
    }

    $needsRecovery = !$accessOk;
    $action = $needsRecovery ? 'recover' : 'reencrypt';

    if ($dryRun) {
        if ($needsRecovery) {
            $counts['recovered']++;
            logLine('INFO', "[DRY] Row {$rowId} ({$owner} -> {$oauth}) would RECOVER (re-encrypt refresh under v{$targetVersion}, blank stale access)", $logFh);
        } else {
            $counts['reencrypted']++;
            logLine('INFO', "[DRY] Row {$rowId} ({$owner} -> {$oauth}) would re-encrypt to v{$targetVersion}", $logFh);
        }
        continue;
    }

    // Re-encrypt under target version and persist atomically.
    try {
        $newRefresh = $cryptor->encryptWithVersion($targetVersion, (string)$refreshPlain);
        $newAccess  = $needsRecovery
            ? ''
            : ($accessIn !== '' ? $cryptor->encryptWithVersion($targetVersion, (string)$accessPlain) : '');

        $db->beginTransaction();

        $sets = ['refresh_token_encrypted = ?'];
        $params = [$newRefresh];

        if ($needsRecovery) {
            // Blank the stale access token and back-date the expiry so the next
            // call to getValidAccessToken() refreshes via the (now-current) refresh token.
            // Use NOW() - INTERVAL to avoid TIMESTAMP-range issues across server timezones.
            $sets[] = 'access_token_encrypted = ?';
            $params[] = '';
            $sets[] = 'token_expires_at = (NOW() - INTERVAL 1 DAY)';
        } elseif ($accessIn !== '') {
            $sets[] = 'access_token_encrypted = ?';
            $params[] = $newAccess;
        }

        if ($hasHealthColumn) {
            $sets[] = "health = 'healthy'";
            $sets[] = "health_reason = NULL";
            $sets[] = "health_updated_at = NOW()";
        }
        $sets[] = 'updated_at = NOW()';
        $params[] = $rowId;

        $sql = 'UPDATE webmail_oauth_tokens SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $db->commit();
        if ($needsRecovery) {
            $counts['recovered']++;
            logLine('PASS', "Row {$rowId} ({$owner} -> {$oauth}) RECOVERED (refresh -> v{$targetVersion}, access cleared)", $logFh);
        } else {
            $counts['reencrypted']++;
            logLine('PASS', "Row {$rowId} ({$owner} -> {$oauth}) re-encrypted to v{$targetVersion}", $logFh);
        }
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $counts['errors']++;
        $failures[] = [
            'id' => $rowId,
            'primary_email' => $owner,
            'oauth_email' => $oauth,
            'error' => $e->getMessage(),
            'action' => $action,
        ];
        logLine('FAIL', "Row {$rowId} {$action} failed: " . $e->getMessage(), $logFh);
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$summary = [
    'log'              => $logPath,
    'target_version'   => $targetVersion,
    'dry_run'          => $dryRun,
    'quarantine_mode'  => $quarantine,
    'total'            => $counts['total'],
    'reencrypted'        => $counts['reencrypted'],
    'recovered'          => $counts['recovered'],
    'already_current'    => $counts['already_current'],
    'quarantined'        => $counts['quarantined'],
    'skipped_empty'      => $counts['skipped_empty'],
    'skipped_login_only' => $counts['skipped_login_only'],
    'errors'             => $counts['errors'],
    'failures'         => $failures,
];

if ($jsonOutput) {
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT) . "\n");
} else {
    logLine('INFO', "--- SUMMARY ---", $logFh);
    foreach ($summary as $k => $v) {
        if (is_array($v)) continue;
        logLine('INFO', sprintf("%-18s %s", $k, $v), $logFh);
    }
    if ($failures !== []) {
        logLine('INFO', "--- FAILURES ---", $logFh);
        foreach ($failures as $f) {
            logLine('FAIL', json_encode($f), $logFh);
        }
    }
}

exit(($counts['errors'] === 0 && ($counts['quarantined'] === 0 || $quarantine)) ? 0 : 1);
