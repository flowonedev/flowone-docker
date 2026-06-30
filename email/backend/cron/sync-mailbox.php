#!/usr/bin/env php
<?php
/**
 * sync-mailbox.php  (Phase 2 of "Finish Gmail-like")
 *
 * The background IMAP -> DB mirror engine. This is the missing piece
 * that turns the half-built DB-as-truth migration into a real Gmail-like
 * system. Each pass:
 *
 *   1. Lists folders per (user_email, account_email) and registers any
 *      not-yet-tracked folders in webmail_folder_sync_state (status =
 *      pending).
 *   2. Claims due (user, folder) tuples ordered by priority
 *      (uidvalidity_reset > pending > initial_syncing > failed > synced).
 *   3. Opens one IMAP connection per (user, account), runs the right
 *      phase for each folder:
 *        - status=uidvalidity_reset  -> handleUidvalidityReset, then restart
 *        - status=pending            -> initialSyncBatch
 *        - status=initial_syncing    -> initialSyncBatch (more pages)
 *        - status=synced/failed      -> incrementalSync (+ throttled expunge sweep)
 *   4. Marks failures with exponential backoff so a sick folder doesn't
 *      burn the IMAP budget.
 *
 * Why PHP and not Node mailsync: the OAuth refresh token and the
 * encrypted IMAP session password are AES-decrypted with keys that only
 * live in the PHP config. Same reason cron/drain-outbox.php is PHP.
 *
 * Recommended cron (every 5 minutes; the script self-loops for ~4 minutes):
 *   star/5 star star star star /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/sync-mailbox.php >> \
 *     /var/www/vps-email/backend/storage/logs/sync-mailbox-cron.log 2>&1
 *
 * Flags:
 *   --help            Show this banner
 *   --verbose         Per-folder log lines
 *   --once            Single pass, then exit (no self-loop)
 *   --max-seconds=N   Self-loop budget in seconds (default 240)
 *   --batch=N         Folders claimed per pass (default 50)
 *   --user=EMAIL      Only sync folders owned by this user
 *   --account=EMAIL   Only sync folders for this IMAP account
 *   --folder=PATH     Only sync this one folder (matches folder_path)
 *   --phase=PHASE     Force a single phase: initial | incremental | expunge | reset
 *   --incremental-only Claim ONLY synced folders + force incremental. The fast
 *                     new-mail tick (run every minute) that can't be starved by
 *                     a big initial-sync backlog. Skips discovery/initial/expunge.
 *   --json            Emit a JSON summary on stdout
 *   --smoke           DB + Redis connectivity check only, no IMAP, exit 0
 *
 * Exit codes:
 *   0  success (per-folder failures are tolerated and retried via backoff)
 *   1  setup error (DB unreachable, lock contention)
 *
 * Safety: this cron NEVER changes user mail state. It only reads
 * envelope metadata via IMAP and writes the mirror in MariaDB.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\AccountService;
use Webmail\Services\FolderIndexService;
use Webmail\Services\GoogleOAuthService;
use Webmail\Services\ImapCredentialResolver;
use Webmail\Services\ImapService;
use Webmail\Services\MailboxSyncService;
use Webmail\Services\MicrosoftOAuthService;
use Webmail\Services\RedisCacheService;

// ---------- CLI parsing ----------

$opts = getopt('', [
    'help', 'verbose', 'once', 'max-seconds::', 'batch::',
    'user::', 'account::', 'folder::', 'phase::', 'incremental-only', 'json', 'smoke',
]);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 2400);
    exit(0);
}

$verbose      = isset($opts['verbose']);
$once         = isset($opts['once']);
$maxSeconds   = max(1, (int)($opts['max-seconds'] ?? 240));
$batch        = max(1, (int)($opts['batch'] ?? 50));
$onlyUser     = isset($opts['user']) ? strtolower((string)$opts['user']) : null;
$onlyAccount  = isset($opts['account']) ? strtolower((string)$opts['account']) : null;
$onlyFolder   = isset($opts['folder']) ? (string)$opts['folder'] : null;
$forcedPhase  = isset($opts['phase']) ? strtolower((string)$opts['phase']) : null;
$incrementalOnly = isset($opts['incremental-only']);
$emitJson     = isset($opts['json']);
$smoke        = isset($opts['smoke']);

// The dedicated incremental tick forces the incremental phase and (via
// claimDue below) claims ONLY already-synced folders, so steady-state
// new-mail detection can never be starved by a large initial-sync backlog.
if ($incrementalOnly) {
    $forcedPhase = 'incremental';
}

if ($forcedPhase !== null && !in_array($forcedPhase, ['initial', 'incremental', 'expunge', 'reset'], true)) {
    fwrite(STDERR, "[sync-mailbox] Invalid --phase; must be initial|incremental|expunge|reset\n");
    exit(1);
}

// ---------- Pre-flight ----------

foreach (['pdo_mysql', 'openssl', 'curl'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[sync-mailbox] Missing PHP extension: {$ext}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../src/config.php';

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[sync-mailbox] DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

// This cron holds one connection across a multi-minute, IMAP-heavy pass. The
// server's GLOBAL wait_timeout is an aggressive 120s (it reaps idle *web*
// pool connections), which would otherwise drop this connection mid-pass on
// every long IMAP gap. CLI cron connections are short-lived (closed on exit),
// so a generous SESSION timeout here is safe, keeps the connection alive for
// the whole pass, and reduces $healDb() below to a safety net for a genuine
// mid-run MySQL restart rather than a routine per-pass event.
try { $db->exec('SET SESSION wait_timeout=900, interactive_timeout=900'); } catch (\Throwable $e) {}

$cache = extension_loaded('redis') ? new RedisCacheService($config) : null;

// ---------- Logging ----------

$logDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . DIRECTORY_SEPARATOR . 'sync-mailbox-' . date('Ymd') . '.log';

$log = function (string $msg) use ($logFile, $emitJson, $verbose): void {
    if ($emitJson && !$verbose) return;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
};

if ($smoke) {
    $log('smoke OK: DB reachable, Redis ' . ($cache && $cache->isAvailable() ? 'reachable' : 'unavailable'));
    exit(0);
}

// ---------- Lock to prevent overlapping runs ----------
//
// The fast incremental tick and the full cache-warmer pass must use SEPARATE
// internal locks, otherwise the tick exits with "Another instance is already
// running" whenever the main */5 pass is mid-run (it self-loops ~4 of every 5
// minutes), which would defeat the whole point of the per-minute tick.
$lockName = $incrementalOnly ? 'flowone-sync-mailbox-incr.lock' : 'flowone-sync-mailbox.lock';
$lockFile = sys_get_temp_dir() . '/' . $lockName;
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[sync-mailbox] Another instance is already running; exiting\n");
    exit(1);
}

// ---------- Services ----------

// $cache is injected so MailboxSyncService::incrementalSync() can publish
// MESSAGE_NEW / FOLDER_COUNTS events when new inbound mail lands in the
// mirror. Without it, the browser only learns about new mail on manual
// refresh, tab refocus, or the 5-minute reconciliation tick.
$syncSvc      = new MailboxSyncService($config, $db, null, null, null, $cache);
$resolver     = new ImapCredentialResolver($config, $db);
$folderIndex  = new FolderIndexService($config);
$accountSvc   = new AccountService($config);
$googleSvc    = !empty($config['google_oauth']['client_id']) ? new GoogleOAuthService($config) : null;
$msSvc        = !empty($config['microsoft_oauth']['client_id']) ? new MicrosoftOAuthService($config) : null;

$convSvc = new \Webmail\Services\ConversationService($config);

// IMAP single source of truth: this cron is a CACHE WARMER + new-mail
// notifier only. It pages envelopes into the mirror, fans out MESSAGE_NEW /
// FOLDER_COUNTS (used by the UI purely as a "go re-read IMAP" trigger), and
// runs the expunge sweep. It does NOT reconcile read-state or counts into the
// mirror - those are served live from IMAP by the request path, so a second
// producer here could only ever re-introduce the count jumps we eliminated.
if ($incrementalOnly) {
    $log('incremental-only tick: synced folders only, new-mail notify (no discovery/initial/expunge)');
} else {
    $log('cache-warmer mode: envelope paging + new-mail notify + expunge only (no read-state reconcile)');
}

$summary = [
    'passes'        => 0,
    'discovered'    => 0,
    'claimed'       => 0,
    'initial_pages' => 0,
    'incremental'   => 0,
    'flag_changes'  => 0,
    'new_messages'  => 0,
    'expunged'      => 0,
    'idle_tombstones_drained' => 0,
    'failed'        => 0,
    'reset'         => 0,
    'orphan_cleaned' => 0,
    'started_at'    => microtime(true),
];

// ---------- Discovery: register folders for all known accounts ----------
//
// We only run the discovery pass once per script invocation - listing
// folders is cheap but adds up across many accounts. The cron is
// short-loop (a few minutes), so once per run is plenty.

// Discovery registers brand-new folders for INITIAL sync. The incremental
// tick only ever touches already-synced folders, so skip discovery entirely
// to keep the per-minute pass fast and lock-light.
if (!$incrementalOnly) {
    discoverFolders($db, $syncSvc, $resolver, $folderIndex, $accountSvc, $googleSvc, $msSvc, $config, $log, $verbose, $onlyUser, $onlyAccount, $summary);
}

// ---------- Drain loop ----------

$deadline = microtime(true) + $maxSeconds;

// A long, IMAP-heavy pass can leave the shared DB connection idle past
// MariaDB's wait_timeout (and a mid-run MySQL restart has the same effect);
// the cached PDO then throws 2006 "MySQL server has gone away" on the next
// query. resolveSyncCredentials catches that and returns null, which the
// loop MISLABELS as "no usable IMAP credentials" and flaps healthy folders
// to 'failed' (the robert@ symptom). $healDb() heals the connection before we
// touch the DB again - a cheap `SELECT 1` when it's alive, a full reconnect
// when it's dead - and on an actual reconnect rebuilds the services that
// cached the now-dead PDO so the rest of the run uses the live one.
$healDb = function () use (&$db, &$syncSvc, &$resolver, &$convSvc, $config, $cache, $log): void {
    $fresh = \Webmail\Core\Database::pingOrReconnect($db, $config);
    if ($fresh !== $db) {
        $db = $fresh;
        try { $db->exec('SET SESSION wait_timeout=900, interactive_timeout=900'); } catch (\Throwable $e) {}
        $syncSvc = new MailboxSyncService($config, $db, null, null, null, $cache);
        $resolver = new ImapCredentialResolver($config, $db);
        $convSvc = new \Webmail\Services\ConversationService($config);
        $log('DB connection dropped mid-run; reconnected + rebuilt services');
    }
};

do {
    $summary['passes']++;

    // Heal the DB connection at the top of every pass (protects claimDue +
    // the idle-tombstone drain below).
    $healDb();

    // Drain any IDLE-detected expunges the Node mailsync worker queued
    // since our last pass. This makes /delta's deletedUids reflect
    // real-time deletions even before the polling expungeReconcile
    // runs (which only fires every ~6 incremental passes per folder).
    try {
        $drained = drainIdleTombstones($cache, $syncSvc, $convSvc, $folderIndex, $log, $verbose);
        $summary['idle_tombstones_drained'] += $drained;
    } catch (\Throwable $e) {
        $log('WARN idle tombstone drain: ' . $e->getMessage());
    }

    try {
        $rows = $syncSvc->claimDue($batch, $onlyUser, $onlyAccount, $incrementalOnly);
    } catch (\Throwable $e) {
        $log('ERROR claimDue failed: ' . $e->getMessage());
        break;
    }

    if ($onlyFolder !== null) {
        $rows = array_values(array_filter($rows, fn($r) => (string)$r['folder_path'] === $onlyFolder));
    }

    if (empty($rows)) {
        if ($once) break;
        usleep(2000000); // 2s idle backoff between empty polls
        continue;
    }

    $summary['claimed'] += count($rows);

    // Group by (user, account) so we open one IMAP per group per pass.
    $byConn = [];
    foreach ($rows as $r) {
        $key = strtolower((string)$r['user_email']) . '|' . strtolower((string)$r['account_email']);
        $byConn[$key][] = $r;
    }

    foreach ($byConn as $key => $folderRows) {
        // The previous group's IMAP work (a slow remote connect, a large
        // initial page) may have idled the DB past wait_timeout - heal it
        // before resolving credentials so a dead connection isn't mistaken
        // for missing credentials.
        $healDb();

        [$userEmail, $accountEmail] = explode('|', $key, 2);
        $creds = resolveSyncCredentials($db, $config, $resolver, $userEmail, $accountEmail);
        if (!$creds) {
            // Distinguish a REMOVED account (no credential row anywhere) from a
            // TRANSIENT credential failure (row exists, token momentarily
            // unavailable). For a genuinely orphaned secondary mailbox we delete
            // its folder sync-state so the cron stops claiming it every pass and
            // stops republishing MESSAGE_NEW. This self-heals any account that
            // was removed before the teardown fix landed; the primary mailbox
            // (account == user) is never treated as an orphan.
            if (!accountCredentialRowExists($db, $userEmail, $accountEmail)) {
                $removed = $syncSvc->deleteAccountState($userEmail, $accountEmail);
                $summary['orphan_cleaned'] += $removed;
                if ($verbose) $log("  orphan-cleanup {$userEmail} / {$accountEmail} (removed {$removed} sync-state row(s); no credentials left)");
            } else {
                foreach ($folderRows as $r) {
                    $syncSvc->markFailure($userEmail, (string)$r['folder_id'], 'no usable IMAP credentials');
                    $summary['failed']++;
                }
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
            $err = 'IMAP connect failed' . ($imap->getLastError() ? (': ' . $imap->getLastError()) : '');
            foreach ($folderRows as $r) {
                $syncSvc->markFailure($userEmail, (string)$r['folder_id'], $err);
                $summary['failed']++;
            }
            continue;
        }

        foreach ($folderRows as $r) {
            $folder = (string)$r['folder_path'];
            $status = (string)$r['status'];
            $folderId = (string)$r['folder_id'];

            try {
                // UIDVALIDITY reset is highest priority - clear before
                // anything else.
                if ($status === 'uidvalidity_reset' || $forcedPhase === 'reset') {
                    $syncSvc->handleUidvalidityReset($userEmail, $folderId);
                    $summary['reset']++;
                    if ($verbose) $log("  reset {$userEmail} {$folder}");
                    continue;
                }

                // SELECT the folder once - the sync service rides on
                // this connection for all subsequent calls.
                if (!$imap->selectFolder($folder)) {
                    $syncSvc->markFailure($userEmail, $folderId, 'selectFolder failed');
                    $summary['failed']++;
                    continue;
                }

                if ($forcedPhase === 'expunge'
                    || ($status === 'synced' && $forcedPhase === null)) {
                    // Steady-state: incremental + throttled expunge
                    if ($forcedPhase !== 'expunge') {
                        $r2 = $syncSvc->incrementalSync($imap, $r);
                        $summary['incremental']++;
                        $summary['flag_changes'] += (int)$r2['flag_changes'];
                        $summary['new_messages'] += (int)$r2['new_messages'];
                    }
                    $expunged = $syncSvc->expungeReconcile($imap, $r, $forcedPhase === 'expunge');
                    $summary['expunged'] += $expunged;

                    if ($verbose) $log("  steady {$userEmail} {$folder} expunged={$expunged}");
                    continue;
                }

                if ($forcedPhase === 'incremental') {
                    $r2 = $syncSvc->incrementalSync($imap, $r);
                    $summary['incremental']++;
                    $summary['flag_changes'] += (int)$r2['flag_changes'];
                    $summary['new_messages'] += (int)$r2['new_messages'];
                    if ($verbose) $log("  incr   {$userEmail} {$folder} new=" . (int)$r2['new_messages']);
                    continue;
                }

                // Initial sync paging (pending OR initial_syncing OR
                // forced-initial).
                $persisted = $syncSvc->initialSyncBatch($imap, $r);
                $summary['initial_pages']++;
                if ($verbose) $log("  init   {$userEmail} {$folder} persisted={$persisted}");
            } catch (\Throwable $e) {
                $syncSvc->markFailure($userEmail, $folderId, $e->getMessage());
                $summary['failed']++;
                $log("  FAIL  {$userEmail} {$folder}: " . $e->getMessage());
            }
        }

        try { $imap->disconnect(); } catch (\Throwable $e) {}
    }
} while (!$once && microtime(true) < $deadline);

// Tombstone janitor: keep webmail_folder_tombstones bounded. Run once
// per script invocation so the per-pass loop above stays hot.
try {
    $purged = $syncSvc->purgeTombstones(7);
    if ($purged > 0) {
        $summary['tombstones_purged'] = $purged;
        if ($verbose) {
            $log("janitor: purged {$purged} tombstones older than 7 days");
        }
    }
} catch (\Throwable $e) {
    $log('WARN tombstone janitor: ' . $e->getMessage());
}

$summary['elapsed_ms'] = (int)((microtime(true) - $summary['started_at']) * 1000);
unset($summary['started_at']);

if ($emitJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
}

$log(sprintf(
    'sync-mailbox done passes=%d discovered=%d claimed=%d initial_pages=%d incremental=%d new_msgs=%d flag_changes=%d expunged=%d idle_tombs=%d reset=%d failed=%d orphan_cleaned=%d elapsed=%dms',
    $summary['passes'],
    $summary['discovered'],
    $summary['claimed'],
    $summary['initial_pages'],
    $summary['incremental'],
    $summary['new_messages'],
    $summary['flag_changes'],
    $summary['expunged'],
    $summary['idle_tombstones_drained'],
    $summary['reset'],
    $summary['failed'],
    $summary['orphan_cleaned'],
    $summary['elapsed_ms']
));

flock($lockFp, LOCK_UN);
@fclose($lockFp);
exit(0);

// ============================================================================
// HELPERS
// ============================================================================

/**
 * Drain the IDLE-tombstone queue the Node mailsync worker writes to.
 *
 * Contract:
 *   - Queue key: flowone:idle:tombstones (prefixed by RedisCacheService
 *     -> webmail:flowone:idle:tombstones)
 *   - Direction: Node LPUSHes, we RPOP -> FIFO
 *   - Payload: {user, folder, uid, ts, source}
 *
 * For each entry we:
 *   1. Resolve folder_id via FolderIndexService
 *   2. Delete the mirror row (ConversationService::deleteConversationMember
 *      already writes a durable tombstone via writeTombstones())
 *   3. As a belt-and-suspenders, also record a tombstone with
 *      source='imap_expunge' so /delta picks it up immediately
 *
 * Cap at 1000 entries per pass so a misbehaving IDLE connection
 * cannot starve the rest of the sync work.
 */
function drainIdleTombstones(
    ?\Webmail\Services\RedisCacheService $cache,
    MailboxSyncService $syncSvc,
    \Webmail\Services\ConversationService $convSvc,
    FolderIndexService $folderIndex,
    callable $log,
    bool $verbose
): int {
    if ($cache === null || !$cache->isAvailable()) {
        return 0;
    }

    $drained = 0;
    $max = 1000;
    while ($drained < $max) {
        $raw = $cache->rPop('flowone:idle:tombstones');
        if ($raw === null) {
            break;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            continue;
        }
        $userEmail = strtolower((string)($payload['user'] ?? ''));
        $folder    = (string)($payload['folder'] ?? '');
        $uid       = (int)($payload['uid'] ?? 0);
        if ($userEmail === '' || $folder === '' || $uid <= 0) {
            continue;
        }
        try {
            $row = $folderIndex->getByPath($userEmail, $folder);
            $folderId = $row['id'] ?? null;
            if (!$folderId) {
                if ($verbose) {
                    $log("idle-tombstone: unresolved folder {$userEmail}/{$folder}");
                }
                continue;
            }
            $convSvc->deleteConversationMember($userEmail, $folder, $uid);
            // Belt-and-suspenders: ensure a tombstone row exists with
            // source='imap_expunge' regardless of whether the mirror
            // row was already gone.
            $syncSvc->recordTombstones($userEmail, $folderId, [$uid], 'imap_expunge');
            $drained++;
        } catch (\Throwable $e) {
            $log('WARN idle-tombstone apply: ' . $e->getMessage());
        }
    }

    if ($verbose && $drained > 0) {
        $log("drained {$drained} IDLE tombstones");
    }
    return $drained;
}

/**
 * Discovery: walk all accounts and register every folder we see in
 * webmail_folder_sync_state. Idempotent - the registerFolder upsert
 * just refreshes folder_path/account_email for known rows.
 *
 * Three sources are unioned:
 *   1. webmail_oauth_tokens - OAuth-linked secondary accounts (Gmail,
 *      Microsoft). primary_email is the FlowOne owner, oauth_email is
 *      the linked mailbox.
 *   2. webmail_accounts     - password-linked secondary IMAP accounts.
 *   3. webmail_sessions     - primary login mailboxes on the local IMAP
 *      server (user_email == account_email), authenticated with the
 *      session password.
 */
function discoverFolders(
    PDO $db,
    MailboxSyncService $syncSvc,
    ImapCredentialResolver $resolver,
    FolderIndexService $folderIndex,
    AccountService $accountSvc,
    ?GoogleOAuthService $googleSvc,
    ?MicrosoftOAuthService $msSvc,
    array $config,
    callable $log,
    bool $verbose,
    ?string $onlyUser,
    ?string $onlyAccount,
    array &$summary
): void {
    // Build the (user, account) work list using the same union the
    // refresh-unread cron uses.
    $pairs = [];

    try {
        $stmt = $db->prepare('SELECT DISTINCT primary_email, oauth_email, provider FROM webmail_oauth_tokens
                              WHERE COALESCE(health, "healthy") != "revoked"');
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pairs[] = [
                'user_email'    => strtolower((string)$row['primary_email']),
                'account_email' => strtolower((string)$row['oauth_email']),
                'provider'      => (string)$row['provider'],
            ];
        }
    } catch (\Throwable $e) {
        $log('WARN oauth discovery: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare('SELECT DISTINCT primary_email, account_email FROM webmail_accounts');
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pairs[] = [
                'user_email'    => strtolower((string)$row['primary_email']),
                'account_email' => strtolower((string)$row['account_email']),
                'provider'      => 'imap',
            ];
        }
    } catch (\Throwable $e) {
        $log('WARN imap discovery: ' . $e->getMessage());
    }

    // 3. Primary login mailboxes (session-credential accounts in
    //    webmail_sessions) - the user's own FlowOne mailbox on the local IMAP
    //    server, authenticated with the password captured at login and
    //    decrypted by the shared ImapCredentialResolver (resolve order step 3).
    //    user_email == account_email for these.
    //
    //    Previously excluded: the password/localhost initialSync path persisted
    //    0 envelopes (getMessagesSince hit a c-client "UID n:*" search bug that
    //    returns empty on Dovecot), so these folders looped forever in
    //    'pending' and starved the OAuth/linked accounts. That bug is now fixed
    //    (ImapService::getMessagesSince falls back to an 'ALL' UID enumeration
    //    + client-side uid>sinceUid filter), so they advance to 'synced'
    //    normally. Conditions mirror ImapCredentialResolver::resolvePassword so
    //    we only enqueue mailboxes we can actually authenticate.
    try {
        $stmt = $db->prepare(
            'SELECT DISTINCT LOWER(email) AS email
               FROM webmail_sessions
              WHERE is_valid = 1
                AND expires_at > NOW()
                AND encrypted_password IS NOT NULL'
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $email = strtolower((string)($row['email'] ?? ''));
            if ($email === '') continue;
            $pairs[] = [
                'user_email'    => $email,
                'account_email' => $email,
                'provider'      => 'imap',
            ];
        }
    } catch (\Throwable $e) {
        $log('WARN session discovery: ' . $e->getMessage());
    }

    // Filter by --user / --account flags.
    if ($onlyUser !== null) {
        $pairs = array_values(array_filter($pairs, fn($p) => $p['user_email'] === $onlyUser));
    }
    if ($onlyAccount !== null) {
        $pairs = array_values(array_filter($pairs, fn($p) => $p['account_email'] === $onlyAccount));
    }

    // De-dup (user, account) pairs.
    $seen = [];
    $unique = [];
    foreach ($pairs as $p) {
        $key = $p['user_email'] . '|' . $p['account_email'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $p;
    }

    foreach ($unique as $p) {
        $userEmail = $p['user_email'];
        $accountEmail = $p['account_email'];
        try {
            $creds = resolveSyncCredentials($db, $config, $resolver, $userEmail, $accountEmail);
            if (!$creds) {
                continue;
            }
            $imap = makeImapForCreds($config, $creds);
            $ok = !empty($creds['oauth_provider'])
                ? $imap->connectWithOAuth($creds['email'], $creds['access_token'])
                : $imap->connect($creds['email'], $creds['password']);
            if (!$ok) {
                continue;
            }

            $folders = $imap->listFolders();
            if (!is_array($folders)) {
                try { $imap->disconnect(); } catch (\Throwable $e) {}
                continue;
            }

            // Resolve canonical folder_id for each. FolderIndexService
            // wants an account_id - we use account_email as a stable
            // surrogate when nothing else is set (matches the existing
            // upsertFromListing usage in MailboxController::folders).
            $accountId = $accountEmail;
            foreach ($folders as $info) {
                if (empty($info['path'])) continue;
                try {
                    $folderId = $folderIndex->upsertFromListing($accountId, $info);
                } catch (\Throwable $e) {
                    if ($verbose) $log("  WARN folder identity {$accountEmail}/{$info['path']}: " . $e->getMessage());
                    continue;
                }
                $syncSvc->registerFolder($userEmail, $accountEmail, $folderId, (string)$info['path']);
                $summary['discovered']++;
            }

            try { $imap->disconnect(); } catch (\Throwable $e) {}
        } catch (\Throwable $e) {
            $log('WARN discovery ' . $accountEmail . ': ' . $e->getMessage());
        }
    }
}

/**
 * Build an ImapService configured for the resolved credentials. Same
 * helper as drain-outbox.php - kept inline rather than shared to avoid
 * a require_once chain between two cron entry points.
 *
 * Honors per-account host/port/encryption when the resolver provides
 * them (e.g. for webmail_accounts-backed linked password accounts whose
 * IMAP server differs from the system default $config['imap']). Falls
 * back to the system default for primary session-credential accounts.
 */
function makeImapForCreds(array $config, array $creds): ImapService
{
    if (!empty($creds['oauth_provider'])) {
        return new ImapService([
            'host'          => $creds['imap_host'] ?? 'imap.gmail.com',
            'port'          => $creds['imap_port'] ?? 993,
            'encryption'    => 'ssl',
            'validate_cert' => false,
            'timeout'       => 25,
        ]);
    }
    if (!empty($creds['imap_host'])) {
        return new ImapService([
            'host'          => $creds['imap_host'],
            'port'          => $creds['imap_port'] ?? 993,
            'encryption'    => $creds['imap_encryption'] ?? 'ssl',
            'validate_cert' => false,
            'timeout'       => 25,
        ]);
    }
    return new ImapService($config['imap'] ?? []);
}

/**
 * Sync-specific credential resolver. Knows about linked secondary
 * accounts (which the shared ImapCredentialResolver does not - its
 * resolveOAuth requires primary_email = oauth_email, and its
 * resolvePassword only reads webmail_sessions, NOT
 * webmail_accounts.credentials_encrypted).
 *
 * Resolution order:
 *   1. Linked OAuth      - webmail_oauth_tokens row keyed on
 *                          (primary_email, oauth_email). Mints/refreshes
 *                          a Google or Microsoft access token.
 *   2. Linked IMAP pwd   - webmail_accounts row keyed on
 *                          (primary_email, account_email). Decrypts
 *                          credentials_encrypted via AccountService.
 *   3. Existing resolver - covers self-OAuth (primary=oauth) and
 *                          primary session-credential mailboxes.
 *
 * Returns null only when none of the three sources can satisfy the
 * request, in which case the caller falls through to its existing
 * "no usable IMAP credentials" failure path.
 */
function resolveSyncCredentials(
    PDO $db,
    array $config,
    \Webmail\Services\ImapCredentialResolver $fallback,
    string $userEmail,
    string $accountEmail
): ?array {
    $userLc = strtolower($userEmail);
    $accLc  = strtolower($accountEmail);

    // 1. Linked OAuth
    try {
        $stmt = $db->prepare(
            "SELECT oauth_email, provider
               FROM webmail_oauth_tokens
              WHERE LOWER(primary_email) = ? AND LOWER(oauth_email) = ?
                AND COALESCE(health, 'healthy') != 'revoked'
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$userLc, $accLc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $provider = (string)($row['provider'] ?? 'google');
            $oauthEmail = (string)$row['oauth_email'];
            $token = null;
            $host = null;
            $port = 993;
            if ($provider === 'microsoft' && !empty($config['microsoft_oauth']['client_id'])) {
                $token = (new \Webmail\Services\MicrosoftOAuthService($config))
                    ->getValidAccessToken($userEmail, $oauthEmail);
                $host = \Webmail\Services\MicrosoftOAuthService::IMAP_HOST;
                $port = \Webmail\Services\MicrosoftOAuthService::IMAP_PORT;
            } elseif (!empty($config['google_oauth']['client_id'])) {
                $token = (new \Webmail\Services\GoogleOAuthService($config))
                    ->getValidAccessToken($userEmail, $oauthEmail);
                $host = 'imap.gmail.com';
                $port = 993;
            }
            if ($token) {
                return [
                    'email'          => $oauthEmail,
                    'oauth_provider' => $provider,
                    'access_token'   => $token,
                    'imap_host'      => $host,
                    'imap_port'      => $port,
                ];
            }
        }
    } catch (\Throwable $e) {
        error_log('[sync-mailbox] linked-OAuth resolve ' . $accountEmail . ': ' . $e->getMessage());
    }

    // 2. Linked IMAP password (webmail_accounts.credentials_encrypted)
    try {
        $stmt = $db->prepare(
            'SELECT imap_host, imap_port, imap_encryption, credentials_encrypted
               FROM webmail_accounts
              WHERE LOWER(primary_email) = ? AND LOWER(account_email) = ?
              LIMIT 1'
        );
        $stmt->execute([$userLc, $accLc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['credentials_encrypted'])) {
            $svc = new \Webmail\Services\AccountService($config);
            $password = $svc->decryptPassword((string)$row['credentials_encrypted']);
            if ($password !== '') {
                return [
                    'email'           => $accountEmail,
                    'password'        => $password,
                    'imap_host'       => $row['imap_host'] ?? null,
                    'imap_port'       => (int)($row['imap_port'] ?? 993),
                    'imap_encryption' => $row['imap_encryption'] ?? 'ssl',
                ];
            }
        }
    } catch (\Throwable $e) {
        error_log('[sync-mailbox] linked-IMAP resolve ' . $accountEmail . ': ' . $e->getMessage());
    }

    // 3. Fall back to the shared resolver (self-OAuth + session password).
    return $fallback->resolve($accountEmail);
}

/**
 * Does ANY credential row still exist for this (user, account) pair?
 *
 * Used by the sync self-heal to tell a removed account (no row anywhere ->
 * delete its orphaned sync-state) apart from a transient credential failure
 * (row exists, token momentarily unavailable -> keep retrying with backoff).
 *
 * Checks every source resolveSyncCredentials() can draw from:
 *   - linked OAuth (primary -> oauth alias), ANY health (revoked still means
 *     the account exists and the user may reconnect, so it is NOT an orphan),
 *   - self-OAuth (primary == oauth == account),
 *   - linked IMAP password (webmail_accounts).
 *
 * The primary mailbox (account == user) always counts as existing - its creds
 * come from the live session and it must never be self-healed away. On any DB
 * error we fail SAFE (return true) so a transient outage can't trigger a delete.
 */
function accountCredentialRowExists(PDO $db, string $userEmail, string $accountEmail): bool
{
    $userLc = strtolower($userEmail);
    $accLc  = strtolower($accountEmail);

    if ($userLc === $accLc) {
        return true; // primary mailbox - never an orphan
    }

    try {
        // Linked OAuth (primary -> alias) OR self-OAuth (primary == alias).
        $stmt = $db->prepare(
            'SELECT 1 FROM webmail_oauth_tokens
              WHERE (LOWER(primary_email) = ? AND LOWER(oauth_email) = ?)
                 OR (LOWER(primary_email) = ? AND LOWER(oauth_email) = ?)
              LIMIT 1'
        );
        $stmt->execute([$userLc, $accLc, $accLc, $accLc]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        // Linked IMAP password.
        $stmt = $db->prepare(
            'SELECT 1 FROM webmail_accounts
              WHERE LOWER(primary_email) = ? AND LOWER(account_email) = ?
              LIMIT 1'
        );
        $stmt->execute([$userLc, $accLc]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (\Throwable $e) {
        error_log('[sync-mailbox] accountCredentialRowExists ' . $accountEmail . ': ' . $e->getMessage());
        return true; // fail safe: don't delete on a transient DB error
    }

    return false;
}
