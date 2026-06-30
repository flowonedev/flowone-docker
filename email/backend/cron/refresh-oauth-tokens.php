<?php
/**
 * Phase 2.7 - Proactive OAuth Token Refresh
 *
 * Runs on a cron schedule (every 15 minutes recommended) and refreshes any
 * webmail_oauth_tokens row whose access_token is expiring within the next 30
 * minutes. Without this cron, refresh happens lazily on the first request
 * that needs IMAP/SMTP; concurrent requests can trigger a thundering herd
 * against Google's token endpoint, and a token that expires between cron
 * passes can break a background sync run mid-flight.
 *
 * Pre-flight: requires PHP, openssl, curl, PDO + the project's bootstrap to
 * load .env so OAUTH_KEYS / GOOGLE_CLIENT_* are populated.
 *
 * Crontab line:
 *   star/15 star star star star /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/refresh-oauth-tokens.php >> \
 *     /var/www/vps-email/backend/storage/logs/refresh-oauth-cron.log 2>&1
 *
 * Flags:
 *   --help         Show this banner
 *   --verbose      Per-row log lines
 *   --dry-run      Report rows that would be refreshed; do nothing
 *   --window=MIN   Refresh tokens expiring within N minutes (default 30)
 *
 * Exit codes:
 *   0  success (any failures were per-row; cron is healthy)
 *   1  setup error (cannot connect to DB, missing config, etc.)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\GoogleOAuthService;
use Webmail\Services\MicrosoftOAuthService;
use Webmail\Addons\Calendar\Services\CalendarConnectionService;

$opts = getopt('', ['help', 'verbose', 'dry-run', 'window::']);

if (isset($opts['help'])) {
    echo "refresh-oauth-tokens.php - proactively refresh expiring OAuth tokens\n";
    echo "  --verbose      one log line per row\n";
    echo "  --dry-run      report only, no token endpoint calls\n";
    echo "  --window=MIN   refresh tokens expiring within N minutes (default 30)\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);
$windowMinutes = max(5, (int)($opts['window'] ?? 30));

// Prevent overlapping runs. Without this, two cron invocations that
// land within the same window can both attempt to refresh the same
// (provider, primary, oauth) row simultaneously - and since Google
// rate-limits refresh attempts per refresh-token, that thrash can
// turn a healthy token into a 'revoked' one.
$lockFile = sys_get_temp_dir() . '/flowone-refresh-oauth-tokens.lock';
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[refresh-oauth] Another instance is already running; exiting\n");
    exit(0);
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[refresh-oauth] DB connect failed: " . $e->getMessage() . "\n");
    flock($lockFp, LOCK_UN);
    exit(1);
}

// Pre-flight extension check
foreach (['openssl', 'curl', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[refresh-oauth] Missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'refresh-oauth-' . date('Ymd') . '.log';

$log = function (string $msg) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

$log("refresh-oauth start window={$windowMinutes}min dryRun=" . ($dryRun ? '1' : '0'));

// Pull rows that are healthy AND expiring within the window. Rows already
// marked revoked/quarantined are skipped (a separate health cron handles
// those).
try {
    $stmt = $db->prepare("
        SELECT id, primary_email, oauth_email, provider, token_expires_at, COALESCE(health, 'healthy') AS health
        FROM webmail_oauth_tokens
        WHERE COALESCE(health, 'healthy') = 'healthy'
          AND token_expires_at IS NOT NULL
          AND token_expires_at < (NOW() + INTERVAL ? MINUTE)
        ORDER BY token_expires_at ASC
    ");
    $stmt->execute([$windowMinutes]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, "[refresh-oauth] SELECT failed: " . $e->getMessage() . "\n");
    $log('SELECT failed: ' . $e->getMessage());
    flock($lockFp, LOCK_UN);
    exit(1);
}

$total = count($rows);
$refreshed = 0;
$failed = 0;

if ($total === 0) {
    $log("no tokens within window");
    flock($lockFp, LOCK_UN);
    exit(0);
}

$googleSvc = !empty($config['google_oauth']['client_id']) ? new GoogleOAuthService($config) : null;
$msSvc = !empty($config['microsoft_oauth']['client_id']) ? new MicrosoftOAuthService($config) : null;

foreach ($rows as $row) {
    $primaryEmail = (string)$row['primary_email'];
    $oauthEmail = (string)$row['oauth_email'];
    $provider = (string)($row['provider'] ?? 'google');

    if ($dryRun) {
        $refreshed++;
        $log("dry-run would refresh {$provider} {$oauthEmail} (expires {$row['token_expires_at']})");
        continue;
    }

    try {
        $newToken = null;
        if ($provider === 'microsoft' && $msSvc) {
            $newToken = $msSvc->getValidAccessToken($primaryEmail, $oauthEmail);
        } elseif ($googleSvc) {
            $newToken = $googleSvc->getValidAccessToken($primaryEmail, $oauthEmail);
        }
        if ($newToken) {
            $refreshed++;
            if ($verbose) {
                $log("refreshed {$provider} {$oauthEmail}");
            }
        } else {
            $failed++;
            $log("FAILED refresh {$provider} {$oauthEmail} (getValidAccessToken returned null)");
        }
    } catch (\Throwable $e) {
        $failed++;
        $log("FAILED refresh {$provider} {$oauthEmail}: " . $e->getMessage());
    }
}

$log("refresh-oauth done total={$total} refreshed={$refreshed} failed={$failed}");

// Phase 3: calendar_connections coverage. Previously only webmail_oauth_tokens
// rows were refreshed proactively; the calendar OAuth tokens used the same
// thundering-herd pattern as email (lazy refresh on first request) which
// is what got us banned. We iterate them here so they hit the token
// endpoint on a smooth 15-minute schedule instead of in a burst.
$calRefreshed = 0;
$calFailed = 0;
$calTotal = 0;
if (!empty($config['google_oauth']['client_id'])) {
    try {
        // calendar_connections may not have the 'health' column on legacy
        // databases (migration 177 adds it). Fall back to a healthier query
        // if it does not exist - we still want the cron to run.
        try {
            $stmt = $db->prepare("
                SELECT id, primary_email, google_email, token_expires_at
                FROM calendar_connections
                WHERE COALESCE(health, 'healthy') = 'healthy'
                  AND token_expires_at IS NOT NULL
                  AND token_expires_at < (NOW() + INTERVAL ? MINUTE)
                ORDER BY token_expires_at ASC
            ");
            $stmt->execute([$windowMinutes]);
        } catch (\Throwable $e) {
            $stmt = $db->prepare("
                SELECT id, primary_email, google_email, token_expires_at
                FROM calendar_connections
                WHERE token_expires_at IS NOT NULL
                  AND token_expires_at < (NOW() + INTERVAL ? MINUTE)
                ORDER BY token_expires_at ASC
            ");
            $stmt->execute([$windowMinutes]);
        }
        $calRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $calTotal = count($calRows);
        if ($calTotal > 0) {
            $calSvc = new CalendarConnectionService($config);
            foreach ($calRows as $row) {
                $primaryEmail = (string)$row['primary_email'];
                $googleEmail = (string)$row['google_email'];

                if ($dryRun) {
                    $calRefreshed++;
                    $log("dry-run would refresh calendar {$googleEmail} (expires {$row['token_expires_at']})");
                    continue;
                }

                try {
                    $newToken = $calSvc->getValidAccessToken($primaryEmail, $googleEmail);
                    if ($newToken) {
                        $calRefreshed++;
                        if ($verbose) {
                            $log("refreshed calendar {$googleEmail}");
                        }
                    } else {
                        $calFailed++;
                        $reason = $calSvc->lastRefreshError ?? 'unknown';
                        $log("FAILED refresh calendar {$googleEmail} reason={$reason}");
                    }
                } catch (\Throwable $e) {
                    $calFailed++;
                    $log("FAILED refresh calendar {$googleEmail}: " . $e->getMessage());
                }
            }
        }
    } catch (\Throwable $e) {
        $log('calendar refresh SELECT failed: ' . $e->getMessage());
    }
}
$log("refresh-calendar done total={$calTotal} refreshed={$calRefreshed} failed={$calFailed}");

flock($lockFp, LOCK_UN);
@fclose($lockFp);
exit(0);
