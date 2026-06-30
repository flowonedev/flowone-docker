#!/usr/bin/env php
<?php
/**
 * INBOX Flicker Diagnostic
 * ========================
 *
 * Finds the source of the "INBOX shows 2 unread then disappears" flicker.
 *
 * Inspects, in parallel:
 *   1. Redis cache: folder_list, folder_status, message-list keys
 *   2. Live IMAP: imap_status() and imap_num_msg() for INBOX
 *   3. Redis pub/sub: any FOLDER_COUNTS / FLAGS_CHANGED events the backend emits
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/inbox-flicker-diagnostic.php \
 *       --email=robert@pixelranger.hu --password='YOUR_PASS'
 *
 * Modes (combine any):
 *   --snapshot               One-shot dump of state right now (default)
 *   --watch=180              Poll IMAP + Redis every 5s for N seconds, log changes
 *   --watch-interval=5       Override watch interval (seconds, default 5)
 *   --trace=180              Subscribe to Redis pub/sub and log all events for N seconds
 *   --inspect-keys           List every Redis key for this user (verbose mode)
 *
 * Options:
 *   --email=EMAIL            Account to diagnose (required)
 *   --password=PASS          Account password (required)
 *   --folder=INBOX           Folder to inspect (default INBOX)
 *   --verbose                Show extra debug info
 *   --help                   Show this help
 *
 * Safety: read-only. Never writes to IMAP, Redis, or the database.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$opts = getopt('', [
    'email:', 'password:', 'folder:',
    'snapshot', 'watch::', 'watch-interval::', 'trace::',
    'inspect-keys', 'verbose', 'help',
]);

if (isset($opts['help']) || empty($opts['email']) || empty($opts['password'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1800);
    exit(1);
}

$testEmail    = $opts['email'];
$testPassword = $opts['password'];
$folder       = $opts['folder'] ?? 'INBOX';
$verbose      = isset($opts['verbose']);
$inspectKeys  = isset($opts['inspect-keys']);

// Mode selection
$doSnapshot   = isset($opts['snapshot']) || (!isset($opts['watch']) && !isset($opts['trace']));
$watchSec     = isset($opts['watch']) ? (int)($opts['watch'] ?: 180) : 0;
$watchEvery   = (int)($opts['watch-interval'] ?? 5);
$traceSec     = isset($opts['trace']) ? (int)($opts['trace'] ?: 180) : 0;

if ($watchEvery < 1) $watchEvery = 1;

// ── Logging ──────────────────────────────────────────────────────

$logFile = __DIR__ . '/../storage/logs/inbox-flicker-' . date('Ymd-His') . '.log';
$logDir  = dirname($logFile);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

function out(string $msg = ''): void {
    global $logFile;
    $line = $msg . "\n";
    echo $line;
    @file_put_contents($logFile, date('[H:i:s] ') . $line, FILE_APPEND | LOCK_EX);
}

function color(string $code, string $text): string {
    return "\033[{$code}m{$text}\033[0m";
}

function pass(string $msg): void { out(color('32', '  [OK]   ') . $msg); }
function warn(string $msg): void { out(color('33', '  [WARN] ') . $msg); }
function fail(string $msg): void { out(color('31', '  [FAIL] ') . $msg); }
function info(string $msg): void { out(color('36', '  [INFO] ') . $msg); }
function section(string $title): void { out(); out(color('1;35', "═══ {$title} ═══")); }

// ── Header ───────────────────────────────────────────────────────

section('INBOX FLICKER DIAGNOSTIC');
out("Started:  " . date('Y-m-d H:i:s T'));
out("Account:  {$testEmail}");
out("Folder:   {$folder}");
out("Log file: {$logFile}");
out("Modes:    " . implode(', ', array_filter([
    $doSnapshot ? 'snapshot' : null,
    $watchSec   ? "watch={$watchSec}s every {$watchEvery}s" : null,
    $traceSec   ? "trace={$traceSec}s" : null,
])));

// ── Build services ───────────────────────────────────────────────

use Webmail\Services\ImapService;
use Webmail\Services\RedisCacheService;

section('CONNECTING');

$cache = new RedisCacheService($config);
if (!$cache->isAvailable()) {
    fail('Redis cache unavailable - cannot continue');
    exit(2);
}
pass('Redis cache connected');

$imap = new ImapService($config, $testEmail, $testPassword);
$conn = $imap->connect($testEmail, $testPassword);
if (!$conn) {
    fail('IMAP connect failed for ' . $testEmail);
    exit(2);
}
pass('IMAP connected as ' . $testEmail);

// Direct Redis handle for low-level inspection (pub/sub, KEYS, etc.)
$redis = new \Redis();
$redisHost    = $config['redis']['host']     ?? '127.0.0.1';
$redisPort    = $config['redis']['port']     ?? 6379;
$redisPrefix  = $config['redis']['prefix']   ?? 'webmail:';
$redisPass    = $config['redis']['password'] ?? null;
$redisDb      = $config['redis']['database'] ?? 0;
if (!$redis->connect($redisHost, $redisPort, 2.0)) {
    fail('Direct Redis connect failed - pub/sub trace will not work');
    exit(2);
}
if ($redisPass) $redis->auth($redisPass);
if ($redisDb > 0) $redis->select($redisDb);

$userHash       = $cache->getUserHash($testEmail);
$folderSafe     = str_replace(['/', '\\', ':'], '_', $folder);
$folderListKey  = $redisPrefix . $userHash . ':folders';
$folderStatKey  = $redisPrefix . $userHash . ':folder:' . $folderSafe . ':status';
$messageListKey = $redisPrefix . $userHash . ':msglist:' . $folderSafe . ':p1';
$pubsubChannel  = $redisPrefix . 'mailbox:' . $testEmail;

info("User hash:       {$userHash}");
info("folder_list key: {$folderListKey}");
info("folder:status:   {$folderStatKey}");
info("msglist key:     {$messageListKey}");
info("pub/sub channel: {$pubsubChannel}");

// ── Helper: read live IMAP + live Redis state ────────────────────

function readLiveState(\Redis $redis, ImapService $imap, string $folder,
                       string $folderListKey, string $folderStatKey): array
{
    $result = [
        't'                  => microtime(true),
        'imap_status'        => null,
        'imap_num_msg'       => null,
        'redis_folder_list'  => null,
        'redis_folder_stat'  => null,
        'errors'             => [],
    ];

    // Live IMAP STATUS
    try {
        $status = $imap->getFolderStatus($folder);
        $result['imap_status'] = $status;
    } catch (\Throwable $e) {
        $result['errors'][] = 'imap_status: ' . $e->getMessage();
    }

    // Live imap_num_msg (just for reference)
    try {
        // listFolders includes counts via imap_status under the hood;
        // we already get those above. imap_num_msg requires open folder.
        if ($imap->selectFolder($folder)) {
            $reflection = new \ReflectionClass($imap);
            $prop = $reflection->getProperty('connection');
            $prop->setAccessible(true);
            $conn = $prop->getValue($imap);
            if ($conn) {
                $result['imap_num_msg'] = @imap_num_msg($conn);
            }
        }
    } catch (\Throwable $e) {
        $result['errors'][] = 'num_msg: ' . $e->getMessage();
    }

    // Redis folder_list (compact view of just our folder)
    try {
        $raw = $redis->get($folderListKey);
        if ($raw !== false && $raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $f) {
                    if (($f['name'] ?? null) === $folder) {
                        $result['redis_folder_list'] = [
                            'total'       => $f['total']       ?? null,
                            'unread'      => $f['unread']      ?? null,
                            'uidnext'     => $f['uidnext']     ?? null,
                            'uidvalidity' => $f['uidvalidity'] ?? null,
                        ];
                        break;
                    }
                }
                $result['redis_folder_list_size'] = count($decoded);
            }
        }
    } catch (\Throwable $e) {
        $result['errors'][] = 'redis_folder_list: ' . $e->getMessage();
    }

    // Redis folder_status (per-folder)
    try {
        $raw = $redis->get($folderStatKey);
        if ($raw !== false && $raw !== null) {
            $decoded = json_decode($raw, true);
            $result['redis_folder_stat'] = $decoded;
        }
    } catch (\Throwable $e) {
        $result['errors'][] = 'redis_folder_status: ' . $e->getMessage();
    }

    return $result;
}

function fmtCounts(?array $a): string {
    if ($a === null || $a === []) return color('90', 'null/empty');
    // imap_status returns "unseen" / "messages"; folder_list cache uses "unread" / "total".
    // Look at both, report whichever exists.
    $u = $a['unread']      ?? $a['unseen']    ?? '?';
    $t = $a['total']       ?? $a['messages']  ?? '?';
    $n = $a['uidnext']     ?? '?';
    $v = $a['uidvalidity'] ?? '?';
    return sprintf('unread=%s total=%s uidnext=%s uidvalidity=%s', $u, $t, $n, $v);
}

function diffCounts(?array $a, ?array $b): array {
    $diffs = [];
    if (!$a || !$b) return $diffs;
    // Compare normalized unread/total (across imap_status vs folder_list naming).
    $aU = $a['unread']   ?? $a['unseen']   ?? null;
    $bU = $b['unread']   ?? $b['unseen']   ?? null;
    $aT = $a['total']    ?? $a['messages'] ?? null;
    $bT = $b['total']    ?? $b['messages'] ?? null;
    if ($aU !== $bU) $diffs[] = "unread: " . var_export($aU, true) . " ≠ " . var_export($bU, true);
    if ($aT !== $bT) $diffs[] = "total: "  . var_export($aT, true) . " ≠ " . var_export($bT, true);
    foreach (['uidnext', 'uidvalidity'] as $k) {
        $av = $a[$k] ?? null;
        $bv = $b[$k] ?? null;
        if ($av !== $bv) $diffs[] = "{$k}: " . var_export($av, true) . " ≠ " . var_export($bv, true);
    }
    return $diffs;
}

// ── 1. SNAPSHOT ──────────────────────────────────────────────────

if ($doSnapshot) {
    section('SNAPSHOT (one-shot)');

    $state = readLiveState($redis, $imap, $folder, $folderListKey, $folderStatKey);

    info('Live imap_status():       ' . fmtCounts($state['imap_status']));
    info('Live imap_num_msg():      ' . ($state['imap_num_msg'] ?? color('90', 'null')));
    info('Redis folder_list row:    ' . fmtCounts($state['redis_folder_list']));
    info('Redis folder_status:      ' . fmtCounts($state['redis_folder_stat']));

    // Cross-check
    $diffs = diffCounts($state['imap_status'], $state['redis_folder_list']);
    if ($diffs) {
        warn('IMAP vs Redis folder_list DIFFERS:');
        foreach ($diffs as $d) warn('    ' . $d);
    } else {
        pass('IMAP and Redis folder_list agree');
    }

    if ($state['imap_status'] && (int)($state['imap_status']['unread'] ?? -1) === 0
        && (int)($state['imap_status']['messages'] ?? 0) > 0) {
        warn('imap_status reported unread=0 but messages>0 - possible c-client flap captured in this snapshot');
    }
    if ($state['redis_folder_list']
        && (int)($state['redis_folder_list']['unread'] ?? -1) === 0
        && (int)($state['redis_folder_list']['total']  ?? 0) > 0) {
        warn('Redis folder_list has unread=0 but total>0 - cache contains a poisoned row');
    }

    foreach ($state['errors'] as $e) fail($e);
}

// ── 2. INSPECT KEYS (verbose) ─────────────────────────────────────

if ($inspectKeys) {
    section('REDIS KEYS FOR THIS USER');
    $pattern = $redisPrefix . $userHash . ':*';
    $cursor  = 0;
    $found   = 0;
    do {
        $batch = $redis->scan($cursor, $pattern, 100);
        if ($batch === false) break;
        foreach ($batch as $k) {
            $ttl = $redis->ttl($k);
            $type = $redis->type($k);
            $typeStr = ['none','string','set','list','zset','hash','stream'][$type] ?? '?';
            out(sprintf('  %s  (type=%s ttl=%ds)', $k, $typeStr, $ttl));
            $found++;
            if ($found > 200) {
                warn('... truncated at 200 keys');
                break 2;
            }
        }
    } while ($cursor !== 0);
    info("Total keys shown: {$found}");
}

// ── 3. WATCH ──────────────────────────────────────────────────────

if ($watchSec > 0) {
    section("WATCH (poll every {$watchEvery}s for {$watchSec}s)");
    out(sprintf('%-9s | %-32s | %-32s | %-32s',
        'time', 'imap_status', 'redis_folder_list', 'redis_folder_status'));
    out(str_repeat('-', 115));

    $end = time() + $watchSec;
    $prev = null;
    $flapCount = 0;
    $lastUnread = null;

    while (time() < $end) {
        $tick = readLiveState($redis, $imap, $folder, $folderListKey, $folderStatKey);

        $line = sprintf('%-9s | %-32s | %-32s | %-32s',
            date('H:i:s'),
            fmtCountsLine($tick['imap_status']),
            fmtCountsLine($tick['redis_folder_list']),
            fmtCountsLine($tick['redis_folder_stat']));
        out($line);

        // Flap detector (imap_status returns "unseen", folder_list cache uses "unread")
        $imapU = $tick['imap_status']['unread'] ?? $tick['imap_status']['unseen'] ?? null;
        if ($lastUnread !== null && $imapU !== null && $imapU !== $lastUnread) {
            // Zero flap?
            if ((int)$imapU === 0 || (int)$lastUnread === 0) {
                $flapCount++;
                warn(sprintf('  >>> FLAP CAPTURED: live unread %s -> %s', $lastUnread, $imapU));
            }
        }
        if ($imapU !== null) $lastUnread = $imapU;

        // Drift detector
        $drift = diffCounts($tick['imap_status'], $tick['redis_folder_list']);
        if ($drift) {
            warn('  >>> IMAP <> folder_list drift: ' . implode('; ', $drift));
        }

        foreach ($tick['errors'] as $e) fail($e);

        sleep($watchEvery);
    }

    out();
    if ($flapCount > 0) {
        fail("Captured {$flapCount} zero-flap events during watch - imap_status() is flaky on this server");
    } else {
        pass("No zero-flaps in {$watchSec}s. The IMAP server itself looks stable during this window.");
    }
}

function fmtCountsLine(?array $a): string {
    if ($a === null || $a === []) return 'null/empty';
    // imap_status returns "unseen"/"messages"; folder_list cache uses "unread"/"total"
    $u = $a['unread']  ?? $a['unseen']   ?? '?';
    $t = $a['total']   ?? $a['messages'] ?? '?';
    $n = $a['uidnext'] ?? '?';
    return "u={$u} t={$t} next={$n}";
}

// ── 4. TRACE (pub/sub) ────────────────────────────────────────────

if ($traceSec > 0) {
    section("TRACE Redis pub/sub channel for {$traceSec}s");
    info("Channel: {$pubsubChannel}");
    info('Anything published here gets relayed to the frontend over WebSocket.');
    info('If you see FOLDER_COUNTS with unread=0 below, that is the flicker source.');
    out('');

    // Use a separate Redis connection for blocking subscribe
    $sub = new \Redis();
    $sub->connect($redisHost, $redisPort, 2.0);
    if ($redisPass) $sub->auth($redisPass);
    if ($redisDb > 0) $sub->select($redisDb);
    $sub->setOption(\Redis::OPT_READ_TIMEOUT, $traceSec + 5);

    $eventCount = 0;
    $flickerEvents = 0;
    $startTrace = time();

    $callback = function ($redis, $channel, $message) use (&$eventCount, &$flickerEvents, $startTrace, $traceSec, $folder) {
        $eventCount++;
        $data = json_decode($message, true);
        if (!is_array($data)) {
            out('  [' . date('H:i:s') . '] raw: ' . substr($message, 0, 200));
            return;
        }
        $type    = $data['type']    ?? '?';
        $payload = $data['payload'] ?? [];

        $detail = '';
        if (isset($payload['folder']))      $detail .= ' folder=' . $payload['folder'];
        if (isset($payload['total']))       $detail .= ' total=' . $payload['total'];
        if (isset($payload['unread']))      $detail .= ' unread=' . $payload['unread'];
        if (isset($payload['uidnext']))     $detail .= ' uidnext=' . $payload['uidnext'];
        if (isset($payload['uid']))         $detail .= ' uid=' . $payload['uid'];
        if (isset($payload['flags']))       $detail .= ' flags=' . json_encode($payload['flags']);

        $line = sprintf('  [%s] %-18s%s', date('H:i:s'), $type, $detail);

        // Flag the smoking gun: FOLDER_COUNTS with unread=0 for our folder
        if ($type === 'folder.counts'
            && ($payload['folder'] ?? '') === $folder
            && (int)($payload['unread'] ?? -1) === 0
            && (int)($payload['total']  ?? 0) > 0) {
            $flickerEvents++;
            $line = color('31', $line . '  <-- POISONED EVENT (unread=0 with total>0)');
        }

        out($line);

        // Manual time check (event loop)
        if (time() - $startTrace >= $traceSec) {
            throw new \RuntimeException('trace_window_complete');
        }
    };

    try {
        $sub->subscribe([$pubsubChannel], $callback);
    } catch (\Throwable $e) {
        // Expected on time-out
        if (strpos($e->getMessage(), 'trace_window_complete') === false
            && strpos($e->getMessage(), 'timed out') === false
            && strpos($e->getMessage(), 'read error') === false) {
            warn('Trace ended with: ' . $e->getMessage());
        }
    }

    out();
    info("Trace captured {$eventCount} events in {$traceSec}s");
    if ($flickerEvents > 0) {
        fail("CONFIRMED FLICKER SOURCE: {$flickerEvents} pub/sub events published unread=0 with total>0");
        fail('Something on this server is still emitting FOLDER_COUNTS events with poisoned zero counts.');
        fail('Likely culprits:');
        fail('  - A stale MailboxController binary publishing FOLDER_COUNTS - check: ls -la /var/www/vps-email/backend/src/Controllers/MailboxController.php');
        fail('  - An old PHP-FPM worker process holding stale opcache - check: systemctl restart lsws');
    } else {
        pass("No poisoned FOLDER_COUNTS events observed during trace window.");
    }
}

// ── DONE ──────────────────────────────────────────────────────────

section('DONE');
out("Log saved to: {$logFile}");
out();
out('Common follow-ups:');
out('  Clear the folder_list cache:');
out("    redis-cli DEL {$folderListKey}");
out('  Watch live state for 3 minutes:');
out("    php " . __FILE__ . " --email={$testEmail} --password=... --watch=180");
out('  Subscribe to events for 3 minutes:');
out("    php " . __FILE__ . " --email={$testEmail} --password=... --trace=180");
out('  List every Redis key for this user:');
out("    php " . __FILE__ . " --email={$testEmail} --password=... --snapshot --inspect-keys");

exit(0);
