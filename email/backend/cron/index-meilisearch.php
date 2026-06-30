<?php
/**
 * Phase 4.1 — Meilisearch full-mailbox backfill
 *
 * Walks every OAuth-eligible account (webmail_oauth_tokens.health = healthy),
 * connects via IMAP/XOAUTH2, lists folders, and for each folder fetches any
 * messages that are NOT yet present in universal_search_index (the joint
 * MySQL + Meilisearch index source of truth) and indexes them.
 *
 * Why this exists:
 *   The on-demand SearchIndexerService::indexEmailFromCache() only fires
 *   when a user actually opens a folder, so anything older than ~3 months
 *   of inbox usage is invisible to the all-folders search path. This cron
 *   closes that gap.
 *
 * Behaviour:
 *   * Resumable — last_indexed_uid is consulted per folder so each pass
 *     only fetches new UIDs. The first run for a given folder seeds the
 *     index against the recent N=2000 messages, then subsequent passes
 *     stream forward.
 *   * Bounded — each run touches at most --max-folders folders and
 *     --max-messages messages so the cron always finishes within a
 *     reasonable window.
 *   * Idempotent — indexEmailFromCache upserts on (user, source_type,
 *     source_id), so duplicates are safe.
 *
 * Pre-flight: imap, openssl, curl, pdo_mysql.
 *
 * Crontab line (hourly backfill):
 *   17 star/1 star star star /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/index-meilisearch.php >> \
 *     /var/www/vps-email/backend/storage/logs/index-meilisearch-cron.log 2>&1
 *
 * Flags:
 *   --help               Show this banner
 *   --verbose            Per-row log lines
 *   --dry-run            Report what would be indexed; do not write
 *   --email=USER         Limit to one primary_email
 *   --max-folders=N      Stop after N folders this run (default 200)
 *   --max-messages=N     Stop after N messages this run (default 5000)
 *   --batch=N            Page size per UID FETCH (default 500)
 *   --initial-window=N   Initial seed window for never-indexed folders (default 2000)
 *
 * Exit codes:
 *   0  success (per-folder failures tolerated)
 *   1  setup error
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
use Webmail\Services\SearchIndexerService;

$opts = getopt('', [
    'help', 'verbose', 'dry-run',
    'email::', 'max-folders::', 'max-messages::', 'batch::', 'initial-window::',
]);

if (isset($opts['help'])) {
    echo "index-meilisearch.php — backfill universal_search_index + Meilisearch from IMAP\n";
    echo "  --verbose            per-row log lines\n";
    echo "  --dry-run            no writes, report only\n";
    echo "  --email=USER         limit to one primary_email\n";
    echo "  --max-folders=N      max folders touched this run (default 200)\n";
    echo "  --max-messages=N     max messages indexed this run (default 5000)\n";
    echo "  --batch=N            UID FETCH page size (default 500)\n";
    echo "  --initial-window=N   first-pass seed window per folder (default 2000)\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);
$onlyEmail = isset($opts['email']) ? strtolower(trim((string)$opts['email'])) : null;
$maxFolders = max(1, (int)($opts['max-folders'] ?? 200));
$maxMessages = max(1, (int)($opts['max-messages'] ?? 5000));
$batchSize = max(50, min(1000, (int)($opts['batch'] ?? 500)));
$initialWindow = max(100, min(10000, (int)($opts['initial-window'] ?? 2000)));

foreach (['imap', 'openssl', 'curl', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[index-meili] missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[index-meili] DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $indexer = new SearchIndexerService($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[index-meili] SearchIndexerService init failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$indexer->isMeilisearchEnabled() && $verbose) {
    echo "[index-meili] Meilisearch is not enabled in config — MySQL-only mode\n";
}

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'index-meilisearch-' . date('Ymd') . '.log';
$log = function (string $msg) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

// Build provider clients lazily — only needed if we encounter rows for them.
$googleSvc = !empty($config['google_oauth']['client_id']) ? new GoogleOAuthService($config) : null;
$msSvc = !empty($config['microsoft_oauth']['client_id']) ? new MicrosoftOAuthService($config) : null;

$sql = "
    SELECT primary_email, oauth_email, provider
    FROM webmail_oauth_tokens
    WHERE COALESCE(health, 'healthy') = 'healthy'
";
$params = [];
if ($onlyEmail) {
    $sql .= " AND primary_email = ?";
    $params[] = $onlyEmail;
}
$sql .= " ORDER BY primary_email ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$log("index-meili start accounts=" . count($accounts) . " maxFolders={$maxFolders} maxMessages={$maxMessages} batch={$batchSize} dryRun=" . ($dryRun ? '1' : '0'));

$foldersTouched = 0;
$messagesIndexed = 0;

foreach ($accounts as $acct) {
    if ($foldersTouched >= $maxFolders || $messagesIndexed >= $maxMessages) {
        break;
    }
    $primaryEmail = (string)$acct['primary_email'];
    $oauthEmail = (string)$acct['oauth_email'];
    $provider = (string)($acct['provider'] ?? 'google');

    try {
        $token = null;
        if ($provider === 'microsoft' && $msSvc) {
            $token = $msSvc->getValidAccessToken($primaryEmail, $oauthEmail);
        } elseif ($googleSvc) {
            $token = $googleSvc->getValidAccessToken($primaryEmail, $oauthEmail);
        }
        if (!$token) {
            $log("skip {$primaryEmail}/{$oauthEmail} ({$provider}): no valid access token");
            continue;
        }
    } catch (\Throwable $e) {
        $log("token fail {$primaryEmail}: " . $e->getMessage());
        continue;
    }

    // Use Gmail IMAP host for Google. For Microsoft this script defers
    // to outlook.office365.com as in the rest of the codebase.
    $imapConfig = [
        'host' => $provider === 'microsoft' ? 'outlook.office365.com' : 'imap.gmail.com',
        'port' => 993,
        'encryption' => 'ssl',
    ];

    try {
        $imap = new ImapService($imapConfig);
        if (!$imap->connectWithOAuth($oauthEmail, $token)) {
            $log("imap connect FAILED {$oauthEmail}: " . ($imap->getLastError() ?? 'unknown'));
            continue;
        }
    } catch (\Throwable $e) {
        $log("imap connect EXC {$oauthEmail}: " . $e->getMessage());
        continue;
    }

    try {
        $folders = $imap->listFolders();
    } catch (\Throwable $e) {
        $log("listFolders EXC {$oauthEmail}: " . $e->getMessage());
        continue;
    }

    // Skip trash/spam/drafts/sent for indexing — these are noisy and
    // not what users typically want to search (sent is a separate view).
    $eligible = array_filter($folders, function ($f) {
        $type = strtolower((string)($f['type'] ?? ''));
        return !in_array($type, ['drafts', 'trash', 'spam'], true);
    });

    foreach ($eligible as $f) {
        if ($foldersTouched >= $maxFolders || $messagesIndexed >= $maxMessages) {
            break 2;
        }
        $folderName = (string)$f['name'];
        $foldersTouched++;

        try {
            $stmt = $db->prepare("SELECT last_indexed_uid FROM webmail_folder_index WHERE user_email = ? AND folder = ? LIMIT 1");
            $stmt->execute([$primaryEmail, $folderName]);
            $lastIndexed = (int)($stmt->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            $lastIndexed = 0;
        }

        try {
            if ($lastIndexed > 0) {
                // Forward fill — anything past the high water mark.
                $result = $imap->getMessagesSince($folderName, $lastIndexed, $batchSize);
                $messages = $result['messages'] ?? [];
            } else {
                // First pass — seed with the most recent $initialWindow messages.
                $result = $imap->getMessages($folderName, 1, $initialWindow);
                $messages = $result['messages'] ?? [];
            }
        } catch (\Throwable $e) {
            $log("fetch EXC {$folderName}: " . $e->getMessage());
            continue;
        }

        if (empty($messages)) {
            if ($verbose) {
                $log("{$primaryEmail} folder={$folderName}: nothing new");
            }
            continue;
        }

        if ($dryRun) {
            $log("dry-run {$primaryEmail} folder={$folderName}: would index " . count($messages) . " messages");
            $messagesIndexed += count($messages);
            continue;
        }

        $folderIndexed = 0;
        $newMaxUid = $lastIndexed;
        foreach ($messages as $msg) {
            if ($messagesIndexed >= $maxMessages) {
                break;
            }
            try {
                $ok = $indexer->indexEmailFromCache($primaryEmail, $msg, $folderName);
                if ($ok) {
                    $folderIndexed++;
                    $messagesIndexed++;
                    $uid = (int)($msg['uid'] ?? 0);
                    if ($uid > $newMaxUid) {
                        $newMaxUid = $uid;
                    }
                }
            } catch (\Throwable $e) {
                $log("index EXC {$folderName} uid={$msg['uid']}: " . $e->getMessage());
            }
        }

        if ($folderIndexed > 0 && $newMaxUid > $lastIndexed) {
            try {
                $upsert = $db->prepare("
                    INSERT INTO webmail_folder_index (user_email, folder, is_indexed, last_indexed_uid, message_count)
                    VALUES (?, ?, 1, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        is_indexed = 1,
                        last_indexed_uid = GREATEST(last_indexed_uid, VALUES(last_indexed_uid)),
                        message_count = VALUES(message_count),
                        indexed_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                ");
                $upsert->execute([$primaryEmail, $folderName, $newMaxUid, $folderIndexed]);
            } catch (\Throwable $e) {
                $log("webmail_folder_index upsert EXC {$folderName}: " . $e->getMessage());
            }
        }

        if ($verbose) {
            $log("{$primaryEmail} folder={$folderName}: indexed={$folderIndexed} newMaxUid={$newMaxUid}");
        }
    }
}

$log("index-meili done folders={$foldersTouched} messages={$messagesIndexed}");
exit(0);
