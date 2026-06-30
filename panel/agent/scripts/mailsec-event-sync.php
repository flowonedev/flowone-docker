#!/usr/bin/env php
<?php
/**
 * DEVCON Mail Security - event ingestion.
 *
 * Drains the Rspamd controller scan history (/history) into the panel's
 * mail_security_events table, which is the source for the dashboard widgets,
 * Threat Center and reports. Runs every minute from
 * /etc/cron.d/devcon-mailsec-events (as www-data) and is also invokable on
 * demand by the agent (mailsec.syncEvents).
 *
 * For every scanned message newer than the stored high-water mark we record:
 *   - ONE verdict row  (clean | spam | quarantine | reject | virus | phish)
 *   - PLUS an auth-fail row per failed check (spf_fail | dkim_fail | dmarc_fail)
 * so the auth widgets can be counted independently of the delivery verdict.
 *
 * De-duplication is by a monotonic watermark (max unix_time already ingested),
 * stored in mail_security_settings.events_sync_watermark. The first run with an
 * empty watermark backfills whatever is currently in the history ring buffer.
 *
 * Self-contained (its own panel-DB connection, like quarantine-ingest.php) so it
 * works from cron without the panel API. Always exits 0 on a handled run; only a
 * hard failure (no DB) exits non-zero. It NEVER touches mail flow - it only
 * reads history and writes rows.
 *
 * Usage:
 *   mailsec-event-sync.php [--json] [--dump] [--reset]
 *     --json   machine-readable summary on stdout
 *     --dump   print the first raw history row (field-name debugging) and exit
 *     --reset  ignore the stored watermark for this run (re-scan whole buffer)
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

const CONTROLLER_URL  = 'http://127.0.0.1:11334';
const WATERMARK_KEY   = 'events_sync_watermark';
/** Spool written by the IMAPSieve learn wrapper (as vmail); drained here. */
const LEARN_SPOOL_DIR  = '/var/spool/devcon-mailsec/learn-events';
/**
 * Opt-out list consulted by the wrapper. Re-synced every run from MailFlow's
 * webmail_spam_settings.auto_training_enabled so toggling the existing webmail
 * "Spam Filter Training" switch silences IMAP feedback within ~60 seconds.
 */
const LEARN_OPTOUT_FILE = '/etc/devcon-mailsec/learn-optouts.txt';

$opts   = getopt('', ['json', 'dump', 'reset']);
$asJson = isset($opts['json']);
$dump   = isset($opts['dump']);
$reset  = isset($opts['reset']);

$summary = [
    'success'        => false,
    'fetched'        => 0,   // rows returned by /history
    'considered'     => 0,   // rows newer than the watermark
    'inserted'       => 0,   // total event rows written (verdict + auth-fail)
    'by_type'        => [],   // event_type => count
    'watermark_in'   => null,
    'watermark_out'  => null,
    'learn_drained'  => 0,    // learn-event spool files processed
    'learn_inserted' => 0,    // learn rows written to mail_security_learn_events
    'optouts_synced' => 0,    // users with auto_training_enabled=0 written to opt-out file
    'errors'         => [],
];

try {
    $rows = fetchHistory();
    $summary['fetched'] = count($rows);

    if ($dump) {
        fwrite(STDOUT, json_encode($rows[0] ?? null, JSON_PRETTY_PRINT) . "\n");
        exit(0);
    }

    $pdo = connectPanelDb();
    if (!$pdo) {
        fwrite(STDERR, "mailsec-event-sync: database unavailable\n");
        exit(75);
    }

    // One-time fix-up for rows written by older builds (runs at most once).
    reclassifyLegacyVirusRows($pdo);

    $watermark = $reset ? 0.0 : (float) getSetting($pdo, WATERMARK_KEY, '0');
    $summary['watermark_in'] = $watermark;
    $maxTs = $watermark;

    $insert = $pdo->prepare(
        "INSERT INTO mail_security_events (ts, event_type, sender, recipient, domain, score, symbol)
         VALUES (FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?)"
    );

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ts = rowTimestamp($row);
        if ($ts <= 0 || $ts <= $watermark) {
            continue; // already ingested (or undatable)
        }
        $summary['considered']++;
        if ($ts > $maxTs) {
            $maxTs = $ts;
        }

        $names     = symbolNames($row['symbols'] ?? []);
        $action    = strtolower(trim((string) ($row['action'] ?? '')));
        $score     = isset($row['score']) ? (float) $row['score'] : null;
        $sender    = pickAddress($row, ['sender_smtp', 'from', 'sender_mime', 'user']);
        $recipient = pickAddress($row, ['rcpt_smtp', 'rcpt', 'rcpt_mime']);
        $domain    = addressDomain($recipient);

        // 1. Primary delivery verdict (exactly one per message).
        [$type, $sym] = classifyVerdict($action, $names);
        // For virus rows, prefer the actual malware name (e.g.
        // "Win.Test.EICAR_HDB-1") carried in the antivirus symbol's options so
        // the Antivirus tab can show WHICH virus was caught, not just "CLAM".
        if ($type === 'virus') {
            $sig = virusSignature($row['symbols'] ?? []);
            if ($sig !== null && $sig !== '') {
                $sym = $sig;
            }
        }
        if (insertEvent($insert, $ts, $type, $sender, $recipient, $domain, $score, $sym)) {
            $summary['inserted']++;
            $summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + 1;
        }

        // 2. Authentication failures (independent of the verdict, 0..3 rows).
        foreach (authFailures($names) as [$authType, $authSym]) {
            if (insertEvent($insert, $ts, $authType, $sender, $recipient, $domain, $score, $authSym)) {
                $summary['inserted']++;
                $summary['by_type'][$authType] = ($summary['by_type'][$authType] ?? 0) + 1;
            }
        }
    }

    if ($maxTs > $watermark) {
        putSetting($pdo, WATERMARK_KEY, rtrim(rtrim(sprintf('%.3f', $maxTs), '0'), '.'));
    }
    $summary['watermark_out'] = $maxTs;

    // Drain the IMAPSieve learn-event spool into mail_security_learn_events.
    // This is what makes the reactive learning visible in the Mail Security
    // dashboard: every drag-to-Junk in any IMAP client lands in this table.
    [$drained, $inserted] = drainLearnSpool($pdo);
    $summary['learn_drained'] = $drained;
    $summary['learn_inserted'] = $inserted;

    // Refresh the wrapper's opt-out file from MailFlow's webmail_spam_settings.
    // The webmail "Spam Filter Training" toggle therefore now governs both the
    // webmail path (existing) AND the IMAP path (new) - one source of truth.
    $summary['optouts_synced'] = syncOptouts($pdo);

    $summary['success'] = true;
} catch (Throwable $e) {
    $summary['errors'][] = $e->getMessage();
    fwrite(STDERR, 'mailsec-event-sync: ' . $e->getMessage() . "\n");
}

if ($asJson) {
    fwrite(STDOUT, json_encode($summary) . "\n");
} else {
    fwrite(STDOUT, sprintf(
        "mailsec-event-sync: fetched=%d considered=%d inserted=%d learn=%d/%d optouts=%d (%s)%s\n",
        $summary['fetched'],
        $summary['considered'],
        $summary['inserted'],
        $summary['learn_inserted'],
        $summary['learn_drained'],
        $summary['optouts_synced'],
        implode(' ', array_map(
            static fn($k, $v) => "{$k}={$v}",
            array_keys($summary['by_type']),
            array_values($summary['by_type'])
        )) ?: 'none',
        $summary['errors'] ? ' ERR:' . implode(';', $summary['errors']) : ''
    ));
}

exit($summary['success'] ? 0 : 1);

// ---------------------------------------------------------------------------

/**
 * Fetch the Rspamd controller history as an array of rows. Handles both the
 * {"rows":[...]} envelope (current) and a bare array (older builds).
 */
function fetchHistory(): array
{
    $raw = @file_get_contents(CONTROLLER_URL . '/history', false, stream_context_create([
        'http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"],
    ]));
    if ($raw === false) {
        throw new RuntimeException('Rspamd history endpoint unreachable');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Rspamd history returned non-JSON');
    }
    $rows = $data['rows'] ?? $data;
    return is_array($rows) ? array_values($rows) : [];
}

/**
 * Best-effort message scan time as a float unix timestamp. Prefers the numeric
 * unix_time field; falls back to parsing the human "time" string.
 */
function rowTimestamp(array $row): float
{
    if (isset($row['unix_time']) && is_numeric($row['unix_time'])) {
        return (float) $row['unix_time'];
    }
    if (!empty($row['time']) && is_string($row['time'])) {
        $t = strtotime($row['time']);
        if ($t !== false) {
            return (float) $t;
        }
    }
    return 0.0;
}

/**
 * Flatten the symbols container to an UPPERCASE list of symbol names. Rspamd
 * may return either a name-keyed object or a list of {name:...} entries.
 */
function symbolNames($symbols): array
{
    $names = [];
    if (is_array($symbols)) {
        foreach ($symbols as $key => $val) {
            if (is_string($key) && $key !== '') {
                $names[] = strtoupper($key);
            } elseif (is_array($val) && isset($val['name'])) {
                $names[] = strtoupper((string) $val['name']);
            } elseif (is_string($val) && $val !== '') {
                $names[] = strtoupper($val);
            }
        }
    }
    return $names;
}

/** True if any symbol name contains one of the given needles (already upper). */
function hasSymbol(array $names, array $needles): bool
{
    foreach ($names as $n) {
        foreach ($needles as $needle) {
            if (strpos($n, $needle) !== false) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Map an Rspamd action + symbol set to a single delivery verdict event_type and
 * a representative symbol. Order matters: virus > attachment policy > phishing >
 * spam (header/subject rewrite) > clean.
 */
function classifyVerdict(string $action, array $names): array
{
    $virus = firstVirusName($names);
    if ($virus !== null) {
        return ['virus', $virus];
    }
    if (hasSymbol($names, ['MAILSEC_ATTACH_QUARANTINE', 'MAILSEC_BANNED_ATTACHMENT'])) {
        return ['quarantine', firstMatch($names, ['MAILSEC_ATTACH_QUARANTINE', 'MAILSEC_BANNED_ATTACHMENT'])];
    }
    if (hasSymbol($names, ['MAILSEC_ATTACH_REJECT'])) {
        return ['reject', 'MAILSEC_ATTACH_REJECT'];
    }
    if (hasSymbol($names, ['PHISH', 'MAILSEC_CEO_SPOOF', 'MAILSEC_INTERNAL_SPOOF', 'MAILSEC_LOOKALIKE_DOMAIN'])) {
        return ['phish', firstMatch($names, ['MAILSEC_CEO_SPOOF', 'MAILSEC_INTERNAL_SPOOF', 'MAILSEC_LOOKALIKE_DOMAIN', 'PHISH']) ?? 'PHISHING'];
    }
    if ($action === 'reject') {
        return ['reject', firstMatch($names, ['REJECT', 'GTUBE']) ?? 'REJECT'];
    }
    if ($action === 'add header' || $action === 'rewrite subject') {
        return ['spam', firstMatch($names, ['BAYES_SPAM', 'MAILSEC_BL', 'SPAM']) ?? 'SPAM'];
    }
    // no action / greylist / soft reject -> delivered cleanly.
    return ['clean', null];
}

/** Auth-failure event rows derived from symbol names (0..3). */
function authFailures(array $names): array
{
    $out = [];
    if (hasSymbol($names, ['R_SPF_FAIL', 'R_SPF_SOFTFAIL'])) {
        $out[] = ['spf_fail', firstMatch($names, ['R_SPF_FAIL', 'R_SPF_SOFTFAIL'])];
    }
    if (hasSymbol($names, ['R_DKIM_REJECT', 'DKIM_REJECT'])) {
        $out[] = ['dkim_fail', firstMatch($names, ['R_DKIM_REJECT', 'DKIM_REJECT'])];
    }
    if (hasSymbol($names, ['DMARC_REJECT', 'DMARC_FAIL'])) {
        $out[] = ['dmarc_fail', firstMatch($names, ['DMARC_REJECT', 'DMARC_FAIL'])];
    }
    return $out;
}

/**
 * True ONLY for genuine antivirus detections. 'CLAM' matches anywhere
 * (CLAM_VIRUS, CLAMAV_*); 'VIRUS' matches only as a whole underscore-delimited
 * token, so reputation symbols such as RBL_VIRUSFREE_BOTNET are NOT mistaken
 * for malware (the substring "VIRUS" inside "VIRUSFREE" must not count).
 */
function isVirusSymbolName(string $name): bool
{
    $u = strtoupper($name);
    if (strpos($u, 'CLAM') !== false) {
        return true;
    }
    return (bool) preg_match('/(^|_)VIRUS(_|$)/', $u);
}

/** First symbol name that denotes a real virus, or null. */
function firstVirusName(array $names): ?string
{
    foreach ($names as $n) {
        if (is_string($n) && isVirusSymbolName($n)) {
            return $n;
        }
    }
    return null;
}

/**
 * Extract the real malware/signature name from the antivirus symbol's options.
 * Rspamd reports e.g. CLAM_VIRUS with options ["Win.Test.EICAR_HDB-1"]; this
 * pulls that first option so the stored event names the actual threat rather
 * than the generic symbol. Returns null when no antivirus symbol/option exists.
 */
function virusSignature($symbols): ?string
{
    if (!is_array($symbols)) {
        return null;
    }
    foreach ($symbols as $key => $val) {
        $name = is_string($key) ? $key : (string) ($val['name'] ?? '');
        if ($name === '' || !isVirusSymbolName($name)) {
            continue;
        }
        $opts = (is_array($val) && isset($val['options']) && is_array($val['options']))
            ? $val['options']
            : [];
        foreach ($opts as $o) {
            if (is_string($o) && trim($o) !== '') {
                return mb_substr(trim($o), 0, 255);
            }
        }
    }
    return null;
}

/** First symbol name containing any needle, or null. */
function firstMatch(array $names, array $needles): ?string
{
    foreach ($names as $n) {
        foreach ($needles as $needle) {
            if (strpos($n, $needle) !== false) {
                return $n;
            }
        }
    }
    return null;
}

/** Pull the first non-empty address from a set of candidate fields. */
function pickAddress(array $row, array $keys): ?string
{
    foreach ($keys as $key) {
        if (!isset($row[$key])) {
            continue;
        }
        $val = $row[$key];
        if (is_array($val)) {
            $val = $val[0] ?? null;
        }
        if (is_array($val)) {
            // e.g. {addr:"a@b", name:"..."}
            $val = $val['addr'] ?? ($val['address'] ?? null);
        }
        if (is_string($val)) {
            $val = trim($val, " \t<>");
            if ($val !== '' && strcasecmp($val, 'unknown') !== 0) {
                return mb_substr(strtolower($val), 0, 320);
            }
        }
    }
    return null;
}

/** Domain part of an email address, or null. */
function addressDomain(?string $addr): ?string
{
    if ($addr === null) {
        return null;
    }
    $at = strrpos($addr, '@');
    if ($at === false) {
        return null;
    }
    $domain = substr($addr, $at + 1);
    return $domain !== '' ? mb_substr($domain, 0, 255) : null;
}

function insertEvent(PDOStatement $stmt, float $ts, string $type, ?string $sender, ?string $recipient, ?string $domain, ?float $score, ?string $symbol): bool
{
    try {
        $stmt->execute([
            (int) $ts,
            $type,
            $sender,
            $recipient,
            $domain,
            $score,
            $symbol !== null ? mb_substr($symbol, 0, 255) : null,
        ]);
        return true;
    } catch (Throwable $e) {
        return false; // a single bad row must not abort the whole drain
    }
}

function getSetting(PDO $pdo, string $key, string $default): string
{
    try {
        $stmt = $pdo->prepare('SELECT v FROM mail_security_settings WHERE k = ? LIMIT 1');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function putSetting(PDO $pdo, string $key, string $value): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mail_security_settings (k, v, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$key, $value, 'event-sync']);
    } catch (Throwable $e) {
        // best-effort; a missed watermark only re-scans the buffer next run
    }
}

/**
 * Older builds mis-filed reputation symbols that merely CONTAIN the substring
 * "VIRUS" (e.g. RBL_VIRUSFREE_BOTNET) as 'virus' events, so botnet/RBL hits
 * showed up under "Detected viruses". This one-time, idempotent pass moves
 * those legacy rows to 'reject' (they are blocked bad mail, not malware),
 * leaving genuine ClamAV detections (CLAM* / standalone VIRUS token) untouched.
 * Guarded by a settings flag so it runs at most once per database.
 */
function reclassifyLegacyVirusRows(PDO $pdo): void
{
    if (getSetting($pdo, 'virus_reclass_v1', '0') === '1') {
        return;
    }
    try {
        $pdo->exec(
            "UPDATE mail_security_events
             SET event_type = 'reject'
             WHERE event_type = 'virus'
               AND UPPER(COALESCE(symbol, '')) NOT LIKE '%CLAM%'
               AND UPPER(COALESCE(symbol, '')) NOT REGEXP '(^|_)VIRUS(_|\$)'"
        );
        putSetting($pdo, 'virus_reclass_v1', '1');
    } catch (Throwable $e) {
        // Best-effort: leave the flag unset so a later run can retry.
    }
}

function connectPanelDb(): ?PDO
{
    $configFile = '/var/www/vps-admin/api/config.php';
    $localConfigFile = '/var/www/vps-admin/api/config.local.php';
    if (!file_exists($configFile)) {
        return null;
    }
    $config = require $configFile;
    if (file_exists($localConfigFile)) {
        $local = require $localConfigFile;
        $config = array_replace_recursive($config, $local);
    }
    $db = $config['database'] ?? [];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'] ?? 'localhost',
        $db['port'] ?? 3306,
        $db['name'] ?? 'devc_vps_dash'
    );
    return new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

/**
 * Drain the learn-event spool (one JSON file per drag-to-Junk / drag-out-of-Junk
 * via any IMAP client) into mail_security_learn_events. Returns [filesRead,
 * rowsInserted]. Bad files are deleted unread so the spool can never grow
 * unbounded; partial-write files (still tmp) are left for the next pass.
 */
function drainLearnSpool(PDO $pdo): array
{
    if (!is_dir(LEARN_SPOOL_DIR)) {
        return [0, 0];
    }
    $files = @glob(rtrim(LEARN_SPOOL_DIR, '/') . '/*.json');
    if (!is_array($files) || !$files) {
        return [0, 0];
    }

    // The table is panel-owned; if it's missing (older deploy not yet
    // migrated) skip silently. Endpoints self-heal the schema on first call.
    try {
        $pdo->query('SELECT 1 FROM mail_security_learn_events LIMIT 0');
    } catch (Throwable $e) {
        return [count($files), 0];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO mail_security_learn_events
            (ts, direction, source, user_email, sender, message_id, rspamc_rc, opted_out)
         VALUES (FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?, ?)'
    );

    $read = 0;
    $inserted = 0;
    foreach ($files as $path) {
        $read++;
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            @unlink($path);
            continue;
        }
        $row = json_decode(trim($raw), true);
        if (!is_array($row)) {
            @unlink($path);
            continue;
        }

        $direction = strtolower((string) ($row['direction'] ?? ''));
        if (!in_array($direction, ['spam', 'ham'], true)) {
            @unlink($path);
            continue;
        }
        $source = strtolower((string) ($row['source'] ?? 'imapsieve'));
        if (!in_array($source, ['imapsieve', 'webmail', 'autolearn', 'admin'], true)) {
            $source = 'imapsieve';
        }

        try {
            $stmt->execute([
                (int) ($row['ts'] ?? time()),
                $direction,
                $source,
                isset($row['user']) ? mb_substr(strtolower((string) $row['user']), 0, 320) : null,
                isset($row['sender']) ? mb_substr(strtolower((string) $row['sender']), 0, 320) : null,
                isset($row['message_id']) ? mb_substr((string) $row['message_id'], 0, 255) : null,
                isset($row['rspamc_rc']) ? (int) $row['rspamc_rc'] : null,
                !empty($row['opted_out']) ? 1 : 0,
            ]);
            $inserted++;
            @unlink($path);
        } catch (Throwable $e) {
            // Leave the file for a future pass; if it's structurally broken we
            // already filtered above, so the most likely cause is a transient
            // DB error. Don't unlink so we don't lose the event.
        }
    }
    return [$read, $inserted];
}

/**
 * Rewrite the per-user opt-out file from MailFlow's webmail_spam_settings.
 * Users with auto_training_enabled = 0 are listed (lowercase, one per line);
 * the wrapper consults this file before calling rspamc. Atomic via temp +
 * rename so the wrapper never reads a half-written file.
 *
 * Returns the number of opted-out users written (0 is a valid value).
 */
function syncOptouts(PDO $pdo): int
{
    // MailFlow lives in the same DB; if the table is missing the file is just
    // left empty (= everyone is opted in, which matches the table's default).
    $emails = [];
    try {
        $stmt = $pdo->query(
            "SELECT user_email FROM webmail_spam_settings
             WHERE auto_training_enabled = 0
             ORDER BY user_email"
        );
        if ($stmt) {
            while (($e = $stmt->fetchColumn()) !== false) {
                $e = strtolower(trim((string) $e));
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $e;
                }
            }
        }
    } catch (Throwable $e) {
        return 0;
    }

    @mkdir(dirname(LEARN_OPTOUT_FILE), 0755, true);

    $body = "# Managed by DEVCON Mail Security. Users in this list have webmail\n"
        . "# spam-filter training disabled; their IMAP Junk drags will not train\n"
        . "# Rspamd. Rewritten by mailsec-event-sync from webmail_spam_settings.\n";
    foreach ($emails as $e) {
        $body .= $e . "\n";
    }

    // Atomic rewrite: tmp + rename. The wrapper opens the file briefly each
    // run so a half-written file would be silently parsed as garbage.
    $tmp = LEARN_OPTOUT_FILE . '.tmp';
    if (@file_put_contents($tmp, $body) !== false) {
        @chmod($tmp, 0644);
        @rename($tmp, LEARN_OPTOUT_FILE);
    }

    return count($emails);
}
