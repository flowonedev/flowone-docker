<?php
/**
 * refresh-unread-counts.php  (Phase 1 of the OAuth + IMAP ground-up rewrite)
 *
 * Populates the Redis unread-count cache so the GET /accounts/unread-counts
 * endpoint can respond instantly without opening an IMAP / XOAUTH2 connection
 * on every poll. Without this cron the frontend's 60-second unread poll fans
 * out one fresh `AUTHENTICATE XOAUTH2` per OAuth account per minute - exactly
 * the request pattern that gets the VPS IP banned by CPGuard.
 *
 * For each (primary_email, account) tuple we open one IMAP connection, ask
 * INBOX for STATUS UNSEEN, store the result in Redis, and disconnect. The
 * connection is short-lived and only one per account per run, so total
 * traffic per cron pass is bounded by the number of accounts on the box.
 *
 * Recommended schedule (every 2 minutes):
 *   star/2 star star star star /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/refresh-unread-counts.php >> \
 *     /var/www/vps-email/backend/storage/logs/refresh-unread-cron.log 2>&1
 *
 * Flags:
 *   --help              Show this banner
 *   --verbose           Per-account log lines
 *   --user=EMAIL        Only refresh counts for this one primary_email
 *   --skip-send         Skip actual IMAP traffic; just log what would happen
 *   --json              Emit a JSON summary on stdout instead of human lines
 *   --smoke             Connectivity check: DB + Redis only, no IMAP, exit 0
 *   --only=oauth|imap   Limit to one account category
 *   --timeout=SECONDS   Per-account IMAP timeout (default 12)
 *
 * Exit codes:
 *   0  success (per-account failures are tolerated)
 *   1  setup error (DB unreachable, Redis down, missing config, lock contention)
 *
 * Safety: this cron NEVER modifies mail state. It only reads STATUS
 * UNSEEN on INBOX. All test data prefixes ([FLOWONE-TEST]) are honoured
 * by being read-only.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\GoogleOAuthService;
use Webmail\Services\MicrosoftOAuthService;
use Webmail\Services\ImapService;
use Webmail\Services\AccountService;
use Webmail\Services\UnreadCountCache;

// ---------- CLI parsing ----------

$opts = getopt('', ['help', 'verbose', 'user::', 'skip-send', 'json', 'smoke', 'only::', 'timeout::']);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 2200);
    exit(0);
}

$verbose      = isset($opts['verbose']);
$smoke        = isset($opts['smoke']);
$skipSend     = isset($opts['skip-send']);
$emitJson     = isset($opts['json']);
$onlyKind     = isset($opts['only']) ? strtolower((string)$opts['only']) : null; // oauth | imap | null
$timeoutSecs  = max(3, (int)($opts['timeout'] ?? 12));
$onlyUser     = isset($opts['user']) ? strtolower((string)$opts['user']) : null;

// ---------- Pre-flight ----------

foreach (['openssl', 'curl', 'pdo_mysql', 'redis'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[refresh-unread] Missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[refresh-unread] DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

$cache = new UnreadCountCache($config);
if (!$cache->isAvailable()) {
    fwrite(STDERR, "[refresh-unread] Redis unavailable; nothing to write\n");
    exit(1);
}

// ---------- Lock to prevent overlapping runs ----------

$lockFile = sys_get_temp_dir() . '/flowone-refresh-unread-counts.lock';
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[refresh-unread] Another instance is already running; exiting\n");
    exit(1);
}

// ---------- Logging ----------

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'refresh-unread-' . date('Ymd') . '.log';

$log = function (string $msg) use ($logFile, $emitJson, $verbose): void {
    if ($emitJson && !$verbose) return; // suppress prose in json mode unless verbose
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

$log("refresh-unread start smoke=" . ($smoke ? '1' : '0') . " onlyUser=" . ($onlyUser ?? '-') . " onlyKind=" . ($onlyKind ?? '-'));

if ($smoke) {
    $log('smoke OK: DB + Redis reachable');
    flock($lockFp, LOCK_UN);
    exit(0);
}

// ---------- Discover users to refresh ----------

$primaryEmails = [];

try {
    // Users with OAuth accounts
    $stmt = $db->prepare('SELECT DISTINCT primary_email FROM webmail_oauth_tokens WHERE COALESCE(health, "healthy") = "healthy"');
    $stmt->execute();
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $email) {
        $primaryEmails[strtolower((string)$email)] = true;
    }
} catch (\Throwable $e) {
    $log("WARN: oauth user discovery failed: " . $e->getMessage());
}

try {
    // Users with secondary IMAP accounts
    $stmt = $db->prepare('SELECT DISTINCT primary_email FROM webmail_accounts');
    $stmt->execute();
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $email) {
        $primaryEmails[strtolower((string)$email)] = true;
    }
} catch (\Throwable $e) {
    $log("WARN: imap user discovery failed: " . $e->getMessage());
}

if ($onlyUser !== null) {
    $primaryEmails = isset($primaryEmails[$onlyUser]) ? [$onlyUser => true] : [];
}

$primaryEmails = array_keys($primaryEmails);
sort($primaryEmails);

$log('users to refresh: ' . count($primaryEmails));

// ---------- Per-user refresh ----------

$accountService = new AccountService($config);
$googleSvc = !empty($config['google_oauth']['client_id']) ? new GoogleOAuthService($config) : null;
$msSvc = !empty($config['microsoft_oauth']['client_id']) ? new MicrosoftOAuthService($config) : null;

$summary = [
    'users' => 0,
    'accounts_total' => 0,
    'accounts_ok' => 0,
    'accounts_failed' => 0,
    'started_at' => time(),
];

$probeInbox = function (ImapService $imap, string $kind, string $accountEmail) use (&$summary, $log, $verbose): int {
    $summary['accounts_total']++;
    try {
        $count = $imap->getUnreadCount('INBOX');
        $imap->disconnect();
        $summary['accounts_ok']++;
        if ($verbose) $log("  ok {$kind} {$accountEmail} -> {$count}");
        return max(0, (int)$count);
    } catch (\Throwable $e) {
        $summary['accounts_failed']++;
        if ($verbose) $log("  FAIL {$kind} {$accountEmail}: " . $e->getMessage());
        try { $imap->disconnect(); } catch (\Throwable $e2) {}
        return 0;
    }
};

foreach ($primaryEmails as $userEmail) {
    $counts = [];
    $summary['users']++;

    // --- Secondary password-based IMAP accounts ---
    if ($onlyKind === null || $onlyKind === 'imap') {
        try {
            $imapAccounts = $accountService->getAccounts($userEmail);
            foreach ($imapAccounts as $acc) {
                $key = UnreadCountCache::accountKey('imap', (int)$acc['id']);
                if ($skipSend) { $counts[$key] = 0; continue; }
                $full = $accountService->getAccountWithCredentials($userEmail, (int)$acc['id']);
                if (!$full || empty($full['password'])) {
                    $counts[$key] = 0;
                    continue;
                }
                $imapCfg = array_merge($config, [
                    'imap' => [
                        'host' => $full['imap_host'] ?? null,
                        'port' => $full['imap_port'] ?? 993,
                        'encryption' => $full['imap_encryption'] ?? 'ssl',
                        'validate_cert' => false,
                        'timeout' => $timeoutSecs,
                    ],
                ]);
                $imap = new ImapService($imapCfg);
                if (!$imap->connect($full['account_email'], $full['password'])) {
                    $counts[$key] = 0;
                    $summary['accounts_total']++;
                    $summary['accounts_failed']++;
                    if ($verbose) $log("  FAIL imap connect {$full['account_email']}");
                    continue;
                }
                $counts[$key] = $probeInbox($imap, 'imap', (string)$full['account_email']);
            }
        } catch (\Throwable $e) {
            $log("WARN imap iteration {$userEmail}: " . $e->getMessage());
        }
    }

    // --- Google OAuth accounts ---
    if (($onlyKind === null || $onlyKind === 'oauth') && $googleSvc) {
        try {
            $oauthAccounts = $googleSvc->getOAuthAccounts($userEmail);
            foreach ($oauthAccounts as $acc) {
                $key = UnreadCountCache::accountKey('google', (int)$acc['id']);
                if ($skipSend) { $counts[$key] = 0; continue; }
                $accessToken = $googleSvc->getValidAccessToken($userEmail, $acc['account_email']);
                if (!$accessToken) {
                    $counts[$key] = 0;
                    if ($verbose) $log("  skip google {$acc['account_email']} (" . ($googleSvc->getLastFailureReason() ?? 'no_token') . ')');
                    continue;
                }
                $imap = new ImapService(array_merge($config, [
                    'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => false, 'timeout' => $timeoutSecs],
                ]));
                if (!$imap->connectWithOAuth($acc['account_email'], $accessToken)) {
                    $counts[$key] = 0;
                    $summary['accounts_total']++;
                    $summary['accounts_failed']++;
                    if ($verbose) $log("  FAIL google connect {$acc['account_email']}");
                    continue;
                }
                $counts[$key] = $probeInbox($imap, 'google', (string)$acc['account_email']);
            }
        } catch (\Throwable $e) {
            $log("WARN google iteration {$userEmail}: " . $e->getMessage());
        }
    }

    // --- Microsoft OAuth accounts ---
    if (($onlyKind === null || $onlyKind === 'oauth') && $msSvc) {
        try {
            $msAccounts = $msSvc->getOAuthAccounts($userEmail);
            foreach ($msAccounts as $acc) {
                $key = UnreadCountCache::accountKey('microsoft', (int)$acc['id']);
                if ($skipSend) { $counts[$key] = 0; continue; }
                $accessToken = $msSvc->getValidAccessToken($userEmail, $acc['account_email']);
                if (!$accessToken) {
                    $counts[$key] = 0;
                    if ($verbose) $log("  skip microsoft {$acc['account_email']} (" . ($msSvc->getLastFailureReason() ?? 'no_token') . ')');
                    continue;
                }
                $imap = new ImapService(array_merge($config, [
                    'imap' => [
                        'host' => MicrosoftOAuthService::IMAP_HOST,
                        'port' => MicrosoftOAuthService::IMAP_PORT,
                        'encryption' => MicrosoftOAuthService::IMAP_ENCRYPTION,
                        'validate_cert' => false,
                        'timeout' => $timeoutSecs,
                    ],
                ]));
                if (!$imap->connectWithOAuth($acc['account_email'], $accessToken)) {
                    $counts[$key] = 0;
                    $summary['accounts_total']++;
                    $summary['accounts_failed']++;
                    if ($verbose) $log("  FAIL microsoft connect {$acc['account_email']}");
                    continue;
                }
                $counts[$key] = $probeInbox($imap, 'microsoft', (string)$acc['account_email']);
            }
        } catch (\Throwable $e) {
            $log("WARN microsoft iteration {$userEmail}: " . $e->getMessage());
        }
    }

    if (!empty($counts)) {
        // Preserve any existing 'primary' value (we can't refresh the
        // primary mailbox from cron because we don't have the user's
        // session password). The controller still owns 'primary'.
        $existing = $cache->get($userEmail);
        if (isset($existing['counts']['primary'])) {
            $counts['primary'] = (int)$existing['counts']['primary'];
        }
        $cache->setAll($userEmail, $counts);
        if ($verbose) $log("user {$userEmail} -> " . count($counts) . " accounts cached");
    }
}

$summary['elapsed_ms'] = (int)((microtime(true) - $summary['started_at']) * 1000);

if ($emitJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
}

$log(sprintf(
    'refresh-unread done users=%d accounts=%d ok=%d failed=%d elapsed=%dms',
    $summary['users'],
    $summary['accounts_total'],
    $summary['accounts_ok'],
    $summary['accounts_failed'],
    $summary['elapsed_ms']
));

flock($lockFp, LOCK_UN);
@fclose($lockFp);
exit(0);
