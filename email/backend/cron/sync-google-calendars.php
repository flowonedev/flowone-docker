<?php
/**
 * Phase 3.3 — Server-side Google Calendar sync
 *
 * Runs on a cron schedule (every 5 minutes recommended) and:
 *   1. Walks every calendar_sync_state row where sync_enabled = 1 and
 *      runs syncFromGoogle (or syncFromGoogleConnection for calendar_only
 *      connections). This pulls down any remote changes using the stored
 *      nextSyncToken so it is cheap when nothing has changed.
 *   2. Drains the calendar_push_queue produced by Phase 3.2 so any local
 *      change that failed to push inline gets a retry.
 *
 * Without this cron, calendars only sync when the user actually opens the
 * calendar view — webhooks (Phase 3.6) close the rest of the gap but this
 * cron is the safety net for when the browser tab is closed and the watch
 * channel has expired.
 *
 * Pre-flight: php cli, openssl, curl, pdo_mysql + bootstrap to populate
 * .env. We do a SHOW TABLES check before touching calendar_push_queue
 * because that table is created by migration 170 and we do not want the
 * cron to die if migrations have not yet run on a fresh box.
 *
 * Crontab line (5 minute cadence):
 *   star/5 star star star star /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/sync-google-calendars.php >> \
 *     /var/www/vps-email/backend/storage/logs/calendar-sync-cron.log 2>&1
 *
 * Flags:
 *   --help                    Show this banner
 *   --verbose                 One log line per sync state and queue item
 *   --dry-run                 Report what would run, do not call the API
 *   --only=pull,push          Restrict to one phase
 *   --max-queue=N             Cap on queue items drained per run (default 200)
 *   --max-attempts=N          Drop queue items above this attempt count (default 10)
 *
 * Exit codes:
 *   0  success (per-item failures are tolerated; the cron itself is healthy)
 *   1  setup error
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Addons\Calendar\Services\GoogleCalendarService;

$opts = getopt('', ['help', 'verbose', 'dry-run', 'only::', 'max-queue::', 'max-attempts::']);

if (isset($opts['help'])) {
    echo "sync-google-calendars.php — server-side Google Calendar sync\n";
    echo "  --verbose         per-row log lines\n";
    echo "  --dry-run         no API calls, report only\n";
    echo "  --only=pull,push  restrict to pull or push phase\n";
    echo "  --max-queue=N     queue items per run (default 200)\n";
    echo "  --max-attempts=N  give up on queue rows past N attempts (default 10)\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$dryRun = isset($opts['dry-run']);
$only = isset($opts['only']) ? array_filter(array_map('trim', explode(',', (string)$opts['only']))) : ['pull', 'push'];
$maxQueue = max(1, (int)($opts['max-queue'] ?? 200));
$maxAttempts = max(1, (int)($opts['max-attempts'] ?? 10));

foreach (['openssl', 'curl', 'pdo_mysql'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[calendar-sync] Missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[calendar-sync] DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'calendar-sync-' . date('Ymd') . '.log';

$log = function (string $msg) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

if (empty($config['google_oauth']['client_id'])) {
    $log('google_oauth.client_id not configured; nothing to do');
    exit(0);
}

try {
    $gcal = new GoogleCalendarService($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[calendar-sync] GoogleCalendarService init failed: " . $e->getMessage() . "\n");
    exit(1);
}

$log("calendar-sync start phases=" . implode(',', $only) . " dryRun=" . ($dryRun ? '1' : '0'));

$pullCount = 0;
$pullFailed = 0;
$pushDrained = 0;
$pushFailed = 0;

// -------- Pull phase --------
if (in_array('pull', $only, true)) {
    try {
        $rows = $db->query("
            SELECT s.id, s.oauth_account_id, s.connection_type, s.google_calendar_id, s.local_calendar_id
            FROM calendar_sync_state s
            WHERE s.sync_enabled = 1
            ORDER BY s.id ASC
        ")->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $log('SELECT calendar_sync_state failed: ' . $e->getMessage());
        $rows = [];
    }

    foreach ($rows as $row) {
        $syncStateId = (int)$row['id'];
        $accountOrConnId = (int)$row['oauth_account_id'];
        $type = (string)($row['connection_type'] ?? 'oauth');
        $gcalId = (string)$row['google_calendar_id'];

        // Resolve owner email for the API call. For 'oauth' connection_type
        // this comes from webmail_oauth_tokens.primary_email; for
        // 'calendar_only' it comes from calendar_connections.primary_email.
        $primaryEmail = null;
        try {
            if ($type === 'oauth') {
                $stmt = $db->prepare("SELECT primary_email FROM webmail_oauth_tokens WHERE id = ? LIMIT 1");
                $stmt->execute([$accountOrConnId]);
                $primaryEmail = $stmt->fetchColumn() ?: null;
            } else {
                $stmt = $db->prepare("SELECT primary_email FROM calendar_connections WHERE id = ? LIMIT 1");
                $stmt->execute([$accountOrConnId]);
                $primaryEmail = $stmt->fetchColumn() ?: null;
            }
        } catch (\Throwable $e) {
            $log("syncState={$syncStateId}: owner lookup failed: " . $e->getMessage());
            $pullFailed++;
            continue;
        }

        if (!$primaryEmail) {
            if ($verbose) {
                $log("syncState={$syncStateId}: no owner email found, skipped");
            }
            continue;
        }

        if ($dryRun) {
            $pullCount++;
            $log("dry-run pull syncState={$syncStateId} type={$type} gcal={$gcalId} owner={$primaryEmail}");
            continue;
        }

        try {
            $result = $type === 'calendar_only'
                ? $gcal->syncFromGoogleConnection($primaryEmail, $accountOrConnId, $gcalId)
                : $gcal->syncFromGoogle($primaryEmail, $accountOrConnId, $gcalId);
            $pullCount++;
            if ($verbose) {
                $imp = (int)($result['imported'] ?? 0);
                $upd = (int)($result['updated'] ?? 0);
                $errs = isset($result['errors']) && is_array($result['errors']) ? count($result['errors']) : 0;
                $log("pull syncState={$syncStateId} type={$type}: imported={$imp} updated={$upd} errors={$errs}");
            }
        } catch (\Throwable $e) {
            $pullFailed++;
            $log("pull FAILED syncState={$syncStateId} type={$type}: " . $e->getMessage());
        }
    }
}

// -------- Push (queue drain) phase --------
if (in_array('push', $only, true)) {
    $hasQueue = false;
    try {
        $hasQueue = (bool)$db->query("SHOW TABLES LIKE 'calendar_push_queue'")->fetchColumn();
    } catch (\Throwable $e) {
        $log('SHOW TABLES check failed: ' . $e->getMessage());
    }

    if ($hasQueue) {
        try {
            $stmt = $db->prepare("
                SELECT q.id, q.sync_state_id, q.local_event_id, q.op, q.attempts,
                       s.oauth_account_id, s.connection_type
                FROM calendar_push_queue q
                JOIN calendar_sync_state s ON s.id = q.sync_state_id
                WHERE s.sync_enabled = 1
                  AND s.connection_type = 'oauth'
                  AND (q.next_attempt_at IS NULL OR q.next_attempt_at <= NOW())
                  AND q.attempts < ?
                ORDER BY q.next_attempt_at ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, $maxAttempts, \PDO::PARAM_INT);
            $stmt->bindValue(2, $maxQueue, \PDO::PARAM_INT);
            $stmt->execute();
            $queue = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $log('SELECT calendar_push_queue failed: ' . $e->getMessage());
            $queue = [];
        }

        foreach ($queue as $item) {
            $qid = (int)$item['id'];
            $syncStateId = (int)$item['sync_state_id'];
            $oauthAccountId = (int)$item['oauth_account_id'];
            $localEventId = $item['local_event_id'] !== null ? (int)$item['local_event_id'] : null;
            $op = (string)$item['op'];

            if ($localEventId === null) {
                // Nothing we can do without a target; drop.
                $db->prepare("DELETE FROM calendar_push_queue WHERE id = ?")->execute([$qid]);
                continue;
            }

            try {
                $stmt = $db->prepare("SELECT primary_email FROM webmail_oauth_tokens WHERE id = ? LIMIT 1");
                $stmt->execute([$oauthAccountId]);
                $primaryEmail = $stmt->fetchColumn() ?: null;
            } catch (\Throwable $e) {
                $log("queue id={$qid}: owner lookup failed: " . $e->getMessage());
                $primaryEmail = null;
            }

            if (!$primaryEmail) {
                if ($verbose) {
                    $log("queue id={$qid}: no owner, dropping");
                }
                $db->prepare("DELETE FROM calendar_push_queue WHERE id = ?")->execute([$qid]);
                continue;
            }

            if ($dryRun) {
                $pushDrained++;
                $log("dry-run push queue id={$qid} op={$op} event={$localEventId} acct={$oauthAccountId}");
                continue;
            }

            $ok = false;
            $err = null;
            try {
                if ($op === 'delete') {
                    $ok = $gcal->deleteFromGoogle($primaryEmail, $oauthAccountId, $localEventId);
                } else {
                    $gid = $gcal->syncToGoogle($primaryEmail, $oauthAccountId, $localEventId);
                    $ok = $gid !== null;
                }
            } catch (\Throwable $e) {
                $ok = false;
                $err = $e->getMessage();
            }

            if ($ok) {
                $db->prepare("DELETE FROM calendar_push_queue WHERE id = ?")->execute([$qid]);
                $pushDrained++;
                if ($verbose) {
                    $log("push OK queue id={$qid} op={$op} event={$localEventId}");
                }
            } else {
                $pushFailed++;
                try {
                    $upd = $db->prepare("
                        UPDATE calendar_push_queue
                        SET attempts = attempts + 1,
                            last_error = ?,
                            next_attempt_at = DATE_ADD(NOW(), INTERVAL LEAST(60, POW(2, LEAST(attempts, 6))) MINUTE),
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $upd->execute([$err !== null ? mb_substr($err, 0, 500) : 'unknown', $qid]);
                } catch (\Throwable $e2) {
                    $log("queue id={$qid}: backoff update failed: " . $e2->getMessage());
                }
                $log("push FAILED queue id={$qid} op={$op} event={$localEventId}: " . ($err ?? 'unknown'));
            }
        }

        // Clear pending_push flag on any sync_state that no longer has open queue rows.
        try {
            $db->exec("
                UPDATE calendar_sync_state s
                LEFT JOIN (
                    SELECT sync_state_id, COUNT(*) AS cnt
                    FROM calendar_push_queue
                    GROUP BY sync_state_id
                ) q ON q.sync_state_id = s.id
                SET s.pending_push = 0
                WHERE s.pending_push = 1
                  AND (q.cnt IS NULL OR q.cnt = 0)
            ");
        } catch (\Throwable $e) {
            $log('pending_push flag reset failed: ' . $e->getMessage());
        }
    } elseif ($verbose) {
        $log('calendar_push_queue table not present; skipping push drain');
    }
}

$log("calendar-sync done pull={$pullCount} pull_failed={$pullFailed} push={$pushDrained} push_failed={$pushFailed}");
exit(0);
