#!/usr/bin/env php
<?php
/**
 * drain-outbox.php  (Phase 2 of the DB-as-truth refactor — PHP drainer)
 *
 * The server-side half of the durable write queue. The PHP request path
 * commits every user-initiated state change (mark-read, move, delete,
 * rename) to MariaDB *and* an `imap_outbox` row inside one transaction;
 * this drainer claims those rows and executes them against IMAP, then
 * marks them done / failed (with exponential backoff).
 *
 * WHY PHP AND NOT THE NODE MAILSYNC WORKER:
 *   The Node worker cannot authenticate IMAP for the bulk of accounts:
 *     - OAuth refresh tokens are AES-256-GCM encrypted with a key that
 *       only lives in the PHP config, so Node cannot mint fresh bearer
 *       tokens.
 *     - Password sessions store an AES-encrypted IMAP password that, again,
 *       only PHP can decrypt.
 *   ImapCredentialResolver + ImapService already solve both cases and are
 *   battle-tested by index-attachments.php / refresh-unread-counts.php, so
 *   the drainer reuses them rather than re-implementing crypto in Node.
 *
 * Real-time UX is unaffected: the controller already publishes the
 * optimistic FLAGS_CHANGED / MESSAGE_MOVED event to every connected device
 * synchronously at request time. This drainer only (a) pushes the change to
 * IMAP so other IMAP clients and a future reindex agree, and (b) backfills
 * the post-MOVE UID. Neither is latency-critical, so a ~per-minute cron with
 * an internal drain loop is sufficient.
 *
 * Recommended schedule (every minute; the script self-loops for ~55s):
 *   * * * * * /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/drain-outbox.php >> \
 *     /var/www/vps-email/backend/storage/logs/drain-outbox-cron.log 2>&1
 *
 * Flags:
 *   --help              Show this banner
 *   --verbose           Per-row log lines
 *   --once              Single claim+drain pass, then exit (no self-loop)
 *   --max-seconds=N     Self-loop budget in seconds (default 55)
 *   --batch=N           Rows claimed per pass (default OutboxService default)
 *   --user=EMAIL        Only drain rows owned by this user_email
 *   --json              Emit a JSON summary on stdout
 *   --smoke             DB + Redis connectivity check only, no IMAP, exit 0
 *
 * Exit codes:
 *   0  success (per-row failures are tolerated and retried via backoff)
 *   1  setup error (DB unreachable, Redis down, lock contention)
 *
 * Safety: only touches mail state that the user explicitly requested (the
 * outbox row is the durable record of that intent). Idempotency keys +
 * the per-row claim make repeated runs safe.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\OutboxService;
use Webmail\Services\ImapCredentialResolver;
use Webmail\Services\ImapService;
use Webmail\Services\RedisCacheService;

// ---------- CLI parsing ----------

$opts = getopt('', ['help', 'verbose', 'once', 'max-seconds::', 'batch::', 'user::', 'json', 'smoke']);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 2600);
    exit(0);
}

$verbose    = isset($opts['verbose']);
$once       = isset($opts['once']);
$maxSeconds = max(1, (int)($opts['max-seconds'] ?? 55));
$batch      = max(1, (int)($opts['batch'] ?? OutboxService::DEFAULT_BATCH_SIZE));
$onlyUser   = isset($opts['user']) ? strtolower((string)$opts['user']) : null;
$emitJson   = isset($opts['json']);
$smoke      = isset($opts['smoke']);

// ---------- Pre-flight ----------

foreach (['pdo_mysql', 'redis', 'openssl', 'curl'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[drain-outbox] Missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, '[drain-outbox] DB connect failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// This drainer holds one connection across a self-loop with slow IMAP work
// between DB writes. The server's GLOBAL wait_timeout is an aggressive 120s
// (it reaps idle *web* pool connections); without bumping our SESSION value
// the connection would drop mid-pass and a real user write (mark-read / move
// / delete) would be needlessly backed off. CLI cron connections close on
// exit, so a generous SESSION timeout here is safe and keeps the write path
// flowing; $healDb() remains the safety net for a genuine MySQL restart.
try { $db->exec('SET SESSION wait_timeout=900, interactive_timeout=900'); } catch (\Throwable $e) {}

$cache = new RedisCacheService($config);

// ---------- Logging ----------

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'drain-outbox-' . date('Ymd') . '.log';

$log = function (string $msg) use ($logFile, $emitJson, $verbose): void {
    if ($emitJson && !$verbose) return;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

if ($smoke) {
    $log('smoke OK: DB reachable, Redis ' . ($cache->isAvailable() ? 'reachable' : 'UNAVAILABLE'));
    exit(0);
}

// ---------- Lock to prevent overlapping runs ----------

$lockFile = sys_get_temp_dir() . '/flowone-drain-outbox.lock';
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[drain-outbox] Another instance is already running; exiting\n");
    exit(1);
}

// ---------- Services ----------

$outbox   = new OutboxService($config);
$resolver = new ImapCredentialResolver($config, $db);
$workerId = 'drain-' . (gethostname() ?: 'host') . '-' . getmypid();

$summary = [
    'passes'    => 0,
    'claimed'   => 0,
    'done'      => 0,
    'failed'    => 0,
    'skipped'   => 0,
    'reaped'    => 0,
    'started_at' => microtime(true),
];

// Reset rows orphaned by a previous crashed worker before we start.
try {
    $summary['reaped'] = $outbox->reapStuck();
    if ($summary['reaped'] > 0) {
        $log("reaped {$summary['reaped']} stuck row(s)");
    }
} catch (\Throwable $e) {
    $log('WARN reapStuck failed: ' . $e->getMessage());
}

// Janitor: keep the table small. Done rows are history; dead rows linger a
// week so the user can notice the sync-issues banner before they vanish.
try {
    $purged = $outbox->purge();
    if ($purged > 0) {
        $log("purged {$purged} old row(s)");
    }
} catch (\Throwable $e) {
    $log('WARN purge failed: ' . $e->getMessage());
}

$deadline = microtime(true) + $maxSeconds;

// A long, IMAP-heavy pass (or a mid-run MySQL restart) can leave the cached
// DB connection idle past MariaDB's wait_timeout; it then throws 2006 "MySQL
// server has gone away" on the next query. $resolver->resolve() catches that
// and returns null, which the loop MISLABELS as "no usable IMAP credentials"
// and fails the row with backoff - meaning a user's mark-read / move / delete
// silently stops reaching IMAP (and in IMAP-truth mode that read-state then
// looks like it "un-sticks"). $healDb() heals the connection before each DB
// touch - a cheap `SELECT 1` when alive, a reconnect when dead - and on an
// actual reconnect rebuilds the services that cached the now-dead PDO.
$healDb = function () use (&$db, &$outbox, &$resolver, $config, $log): void {
    $fresh = \Webmail\Core\Database::pingOrReconnect($db, $config);
    if ($fresh !== $db) {
        $db = $fresh;
        try { $db->exec('SET SESSION wait_timeout=900, interactive_timeout=900'); } catch (\Throwable $e) {}
        $outbox   = new OutboxService($config);
        $resolver = new ImapCredentialResolver($config, $db);
        $log('DB connection dropped mid-run; reconnected + rebuilt services');
    }
};

do {
    $summary['passes']++;

    // Heal the DB connection at the top of every pass (protects claim()).
    $healDb();

    try {
        $rows = $outbox->claim($workerId, $batch);
    } catch (\Throwable $e) {
        $log('ERROR claim failed: ' . $e->getMessage());
        break;
    }

    if ($onlyUser !== null) {
        $rows = array_values(array_filter($rows, fn($r) => strtolower((string)$r['user_email']) === $onlyUser));
    }

    if (empty($rows)) {
        if ($once) break;
        usleep(1500000); // 1.5s idle backoff between empty polls
        continue;
    }

    $summary['claimed'] += count($rows);

    // Group by account so we open one IMAP connection per account per pass.
    $byAccount = [];
    foreach ($rows as $r) {
        $byAccount[strtolower((string)$r['account_email'])][] = $r;
    }

    foreach ($byAccount as $accountEmail => $accountRows) {
        // A prior account's IMAP work may have idled the DB past wait_timeout;
        // heal it before resolving so a dead connection isn't mistaken for
        // missing credentials (which would needlessly backoff a real write).
        $healDb();

        $creds = $resolver->resolve($accountEmail);
        if (!$creds) {
            // No usable credentials right now (user logged out, OAuth
            // revoked). Fail with backoff; succeeds once they reconnect.
            foreach ($accountRows as $r) {
                $outbox->fail((int)$r['id'], 'no usable IMAP credentials for ' . $accountEmail);
                $summary['failed']++;
            }
            continue;
        }

        $imap = makeImapForCreds($config, $creds);
        $connected = false;
        try {
            if (!empty($creds['oauth_provider'])) {
                $connected = $imap->connectWithOAuth($creds['email'], $creds['access_token']);
            } else {
                $connected = $imap->connect($creds['email'], $creds['password']);
            }
        } catch (\Throwable $e) {
            $connected = false;
        }

        if (!$connected) {
            $err = 'IMAP connect failed for ' . $accountEmail . ($imap->getLastError() ? (': ' . $imap->getLastError()) : '');
            foreach ($accountRows as $r) {
                $outbox->fail((int)$r['id'], $err);
                $summary['failed']++;
            }
            continue;
        }

        foreach ($accountRows as $r) {
            try {
                $resultUid = runRow($imap, $db, $cache, $r, $verbose, $log);
                $outbox->complete((int)$r['id'], $resultUid);
                $summary['done']++;
                if ($verbose) {
                    $log("done id={$r['id']} op={$r['op']} user={$r['user_email']}");
                }
            } catch (\Throwable $e) {
                $outbox->fail((int)$r['id'], $e->getMessage());
                $summary['failed']++;
                $log("FAIL id={$r['id']} op={$r['op']}: " . $e->getMessage());
            }
        }

        try { $imap->disconnect(); } catch (\Throwable $e) {}
    }
} while (!$once && microtime(true) < $deadline);

$summary['elapsed_ms'] = (int)((microtime(true) - $summary['started_at']) * 1000);

if ($emitJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
}

$log(sprintf(
    'drain-outbox done passes=%d claimed=%d done=%d failed=%d reaped=%d elapsed=%dms',
    $summary['passes'],
    $summary['claimed'],
    $summary['done'],
    $summary['failed'],
    $summary['reaped'],
    $summary['elapsed_ms']
));

flock($lockFp, LOCK_UN);
@fclose($lockFp);
exit(0);

// ============================================================================
// HELPERS
// ============================================================================

/**
 * Build an ImapService configured for the resolved credentials. OAuth needs
 * the provider-specific host/port at the top level (connectWithOAuth reads
 * $config['host']); password uses the primary Dovecot config under 'imap'.
 */
function makeImapForCreds(array $config, array $creds): ImapService
{
    if (!empty($creds['oauth_provider'])) {
        return new ImapService([
            'host'          => $creds['imap_host'] ?? 'imap.gmail.com',
            'port'          => $creds['imap_port'] ?? 993,
            'encryption'    => 'ssl',
            'validate_cert' => false,
            'timeout'       => 20,
        ]);
    }
    return new ImapService($config['imap'] ?? []);
}

/**
 * Execute one outbox row against IMAP and publish the post-confirmation
 * event. Returns the new UID for moves (NULL otherwise). Throws on failure
 * so the caller can record it via OutboxService::fail (backoff/dead).
 */
function runRow(ImapService $imap, PDO $db, RedisCacheService $cache, array $row, bool $verbose, callable $log): ?int
{
    $op       = (string)$row['op'];
    $uid      = $row['uid'] !== null ? (int)$row['uid'] : 0;
    $payload  = is_array($row['payload']) ? $row['payload'] : [];
    $user     = (string)$row['user_email'];

    switch ($op) {
        case 'set_flag':
        case 'clear_flag':
            $folder = (string)($payload['source_path'] ?? '');
            $flag   = (string)($payload['flag'] ?? 'seen');
            $value  = ($op === 'set_flag');
            if ($folder === '' || $uid <= 0) {
                throw new \RuntimeException('set_flag missing folder/uid');
            }
            if (!$imap->setFlag($folder, $uid, $flag, $value)) {
                throw new \RuntimeException('IMAP setFlag failed' . ($imap->getLastError() ? ': ' . $imap->getLastError() : ''));
            }
            $imapFlag = '\\' . ucfirst(strtolower($flag));
            $cache->publishFlagsChanged($user, $folder, $uid, [
                'flag'      => $flag,
                'value'     => $value,
                'imapFlags' => $value ? [$imapFlag] : [],
                'confirmed' => true,
            ]);
            return null;

        case 'move':
            $source = (string)($payload['source_path'] ?? '');
            $target = (string)($payload['target_path'] ?? '');
            if ($source === '' || $target === '' || $uid <= 0) {
                throw new \RuntimeException('move missing source/target/uid');
            }
            if (!$imap->moveMessage($source, $uid, $target)) {
                throw new \RuntimeException('IMAP move failed' . ($imap->getLastError() ? ': ' . $imap->getLastError() : ''));
            }
            $newUid = $imap->getLastMoveNewUid();
            if ($newUid !== null && !empty($row['target_folder_id'])) {
                // Backfill the post-MOVE UID so readers stop pointing at the
                // placeholder. message_id remains the stable identity, so a
                // brief window with the old UID is harmless.
                try {
                    $upd = $db->prepare(
                        'UPDATE webmail_conversation_members
                            SET uid = ?
                          WHERE user_email = ? AND folder_id = ? AND uid = ?'
                    );
                    $upd->execute([$newUid, $user, $row['target_folder_id'], $uid]);
                } catch (\Throwable $e) {
                    $log('WARN move UID backfill failed id=' . $row['id'] . ': ' . $e->getMessage());
                }
            }
            $cache->publishMessageMoved($user, $source, $target, $uid, $newUid);
            return $newUid;

        case 'delete':
            $folder = (string)($payload['source_path'] ?? '');
            if ($folder === '' || $uid <= 0) {
                throw new \RuntimeException('delete missing folder/uid');
            }
            if (!$imap->deleteMessage($folder, $uid)) {
                throw new \RuntimeException('IMAP delete failed' . ($imap->getLastError() ? ': ' . $imap->getLastError() : ''));
            }
            $cache->publishMessageDeleted($user, $folder, $uid, true);
            return null;

        case 'rename_folder':
            $oldPath = (string)($payload['old_path'] ?? '');
            $newPath = (string)($payload['new_path'] ?? '');
            if ($oldPath === '' || $newPath === '') {
                throw new \RuntimeException('rename_folder missing old_path/new_path');
            }
            if (!$imap->renameFolder($oldPath, $newPath)) {
                throw new \RuntimeException('IMAP renameFolder failed' . ($imap->getLastError() ? ': ' . $imap->getLastError() : ''));
            }
            $cache->publishFolderChanged($user, 'renamed', $oldPath, $newPath);
            return null;

        default:
            throw new \RuntimeException('unsupported op: ' . $op);
    }
}
