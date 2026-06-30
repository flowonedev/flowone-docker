#!/usr/bin/env php
<?php
/**
 * DEVCON Mail Security - quarantine maintenance.
 *
 * Runs daily from /etc/cron.d/devcon-mailsec-maintenance (as www-data) and is
 * also invokable on demand by the agent (mailsec.maintainQuarantine). It does
 * two jobs, both safe to re-run:
 *
 *   1. RETENTION  - expire quarantined messages older than
 *      mail_security_settings.quarantine_retention_days (default 30): the held
 *      .eml is removed from the spool and the row is marked 'deleted'. Rows that
 *      are already released/deleted and older than the window are hard-purged so
 *      the table stays bounded, and orphaned spool files are swept.
 *
 *   2. DIGEST     - if mail_security_settings.quarantine_digest_to is set, email
 *      a 24h summary (new holds, currently held, top recipients/senders) to that
 *      address via the local MTA. Off by default (empty recipient = no mail), so
 *      this never sends anything unless an admin opts in.
 *
 * Self-contained (its own panel-DB connection, like quarantine-ingest.php) so it
 * works from cron without the panel API. Always exits 0 on a handled run; only
 * a hard failure (no DB) exits non-zero.
 *
 * Usage:
 *   quarantine-maintenance.php [--json] [--dry-run] [--purge-only] [--digest-only]
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

const SPOOL_DIR = '/var/spool/devcon-mailsec/quarantine';
const DEFAULT_RETENTION_DAYS = 30;
const DEFAULT_EVENT_RETENTION_DAYS = 90;

$opts        = getopt('', ['json', 'dry-run', 'purge-only', 'digest-only']);
$asJson      = isset($opts['json']);
$dryRun      = isset($opts['dry-run']);
$doPurge     = !isset($opts['digest-only']);
$doDigest    = !isset($opts['purge-only']);

$summary = [
    'success'        => false,
    'dry_run'        => $dryRun,
    'retention_days' => DEFAULT_RETENTION_DAYS,
    'expired'        => 0,   // quarantined -> deleted (spool removed)
    'purged_rows'    => 0,   // old released/deleted rows hard-deleted
    'orphans_swept'  => 0,   // spool files with no active row
    'events_purged'  => 0,   // old mail_security_events rows hard-deleted
    'digest_sent'    => false,
    'digest_to'      => null,
    'user_digests_sent'    => 0,   // per-recipient self-service digests emailed
    'user_messages_listed' => 0,   // held messages referenced across those digests
    'errors'         => [],
];

try {
    $pdo = connectPanelDb();
    if (!$pdo) {
        fwrite(STDERR, "quarantine-maintenance: database unavailable\n");
        exit(75);
    }

    $retentionDays = (int) getSetting($pdo, 'quarantine_retention_days', (string) DEFAULT_RETENTION_DAYS);
    if ($retentionDays < 1) {
        $retentionDays = DEFAULT_RETENTION_DAYS; // never allow an "expire everything now" misconfig
    }
    $summary['retention_days'] = $retentionDays;

    if ($doPurge) {
        runRetention($pdo, $retentionDays, $dryRun, $summary);

        // Keep the event log (dashboard/report source) bounded too.
        $eventDays = (int) getSetting($pdo, 'events_retention_days', (string) DEFAULT_EVENT_RETENTION_DAYS);
        if ($eventDays < 7) {
            $eventDays = DEFAULT_EVENT_RETENTION_DAYS; // never trim the log to near-nothing
        }
        purgeOldEvents($pdo, $eventDays, $dryRun, $summary);
    }

    if ($doDigest) {
        $digestTo = trim(getSetting($pdo, 'quarantine_digest_to', ''));
        $summary['digest_to'] = $digestTo !== '' ? $digestTo : null;
        if ($digestTo !== '' && filter_var($digestTo, FILTER_VALIDATE_EMAIL)) {
            $summary['digest_sent'] = sendDigest($pdo, $digestTo, $dryRun, $summary);
        }

        // Per-recipient self-service digest with signed release/delete links.
        // Off unless explicitly enabled AND a public base URL is configured, so
        // it can never email end users by accident.
        if (getSetting($pdo, 'quarantine_user_digest_enabled', '0') === '1') {
            $baseUrl = rtrim(trim(getSetting($pdo, 'quarantine_link_base', '')), '/');
            $ttlDays = (int) getSetting($pdo, 'quarantine_link_ttl_days', '7');
            if ($ttlDays < 1 || $ttlDays > 90) {
                $ttlDays = 7;
            }
            if ($baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                sendUserDigests($pdo, $baseUrl, $ttlDays, $dryRun, $summary);
            } else {
                $summary['errors'][] = 'user digest: quarantine_link_base is empty or not a valid URL';
            }
        }
    }

    $summary['success'] = true;
} catch (Throwable $e) {
    $summary['errors'][] = $e->getMessage();
    fwrite(STDERR, 'quarantine-maintenance: ' . $e->getMessage() . "\n");
}

if ($asJson) {
    fwrite(STDOUT, json_encode($summary) . "\n");
} else {
    fwrite(STDOUT, sprintf(
        "quarantine-maintenance: retention=%dd expired=%d purged=%d orphans=%d events_purged=%d digest=%s user_digests=%d/%d%s\n",
        $summary['retention_days'],
        $summary['expired'],
        $summary['purged_rows'],
        $summary['orphans_swept'],
        $summary['events_purged'],
        $summary['digest_sent'] ? ('sent->' . $summary['digest_to']) : 'off',
        $summary['user_digests_sent'],
        $summary['user_messages_listed'],
        $dryRun ? ' (dry-run)' : ''
    ));
}

exit($summary['success'] ? 0 : 1);

/**
 * Expire old held messages, hard-purge old terminal rows, and sweep orphan spool files.
 */
function runRetention(PDO $pdo, int $retentionDays, bool $dryRun, array &$summary): void
{
    // 1. Expire still-held messages older than the window: drop the .eml, mark deleted.
    $stmt = $pdo->prepare(
        "SELECT id, spool_path FROM mail_quarantine
         WHERE status = 'quarantined' AND created_at < (NOW() - INTERVAL ? DAY)"
    );
    $stmt->execute([$retentionDays]);
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expired as $row) {
        $path = (string) $row['spool_path'];
        if (!$dryRun) {
            if ($path !== '' && isManagedSpoolPath($path) && file_exists($path)) {
                @unlink($path);
            }
            $upd = $pdo->prepare("UPDATE mail_quarantine SET status = 'deleted' WHERE id = ? AND status = 'quarantined'");
            $upd->execute([(int) $row['id']]);
        }
        $summary['expired']++;
    }

    // 2. Hard-purge terminal rows (released/deleted) older than the window so the
    //    index does not grow forever. Spool files for these are already gone.
    if (!$dryRun) {
        $del = $pdo->prepare(
            "DELETE FROM mail_quarantine
             WHERE status IN ('released','deleted') AND created_at < (NOW() - INTERVAL ? DAY)"
        );
        $del->execute([$retentionDays]);
        $summary['purged_rows'] = $del->rowCount();
    } else {
        $cnt = $pdo->prepare(
            "SELECT COUNT(*) FROM mail_quarantine
             WHERE status IN ('released','deleted') AND created_at < (NOW() - INTERVAL ? DAY)"
        );
        $cnt->execute([$retentionDays]);
        $summary['purged_rows'] = (int) $cnt->fetchColumn();
    }

    // 3. Sweep orphan spool files: *.eml older than the window with no active
    //    'quarantined' row (e.g. a crash between write and DB insert).
    if (is_dir(SPOOL_DIR)) {
        $cutoff = time() - ($retentionDays * 86400);
        $check = $pdo->prepare("SELECT 1 FROM mail_quarantine WHERE spool_path = ? AND status = 'quarantined' LIMIT 1");
        foreach (glob(SPOOL_DIR . '/*.eml') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }
            $check->execute([$file]);
            if ($check->fetchColumn()) {
                continue; // still actively held
            }
            if (!$dryRun) {
                @unlink($file);
            }
            $summary['orphans_swept']++;
        }
    }
}

/**
 * Hard-purge mail_security_events rows older than the retention window so the
 * dashboard/report source table stays bounded. No-op if the table is absent.
 */
function purgeOldEvents(PDO $pdo, int $retentionDays, bool $dryRun, array &$summary): void
{
    try {
        if ($dryRun) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM mail_security_events WHERE ts < (NOW() - INTERVAL ? DAY)");
            $cnt->execute([$retentionDays]);
            $summary['events_purged'] = (int) $cnt->fetchColumn();
            return;
        }
        $del = $pdo->prepare("DELETE FROM mail_security_events WHERE ts < (NOW() - INTERVAL ? DAY)");
        $del->execute([$retentionDays]);
        $summary['events_purged'] = $del->rowCount();
    } catch (Throwable $e) {
        $summary['errors'][] = 'events purge: ' . $e->getMessage();
    }
}

/**
 * Build and send a 24h quarantine digest to the configured admin address.
 */
function sendDigest(PDO $pdo, string $to, bool $dryRun, array &$summary): bool
{
    $newHeld = (int) $pdo->query(
        "SELECT COUNT(*) FROM mail_quarantine WHERE created_at >= (NOW() - INTERVAL 1 DAY)"
    )->fetchColumn();

    $currentlyHeld = (int) $pdo->query(
        "SELECT COUNT(*) FROM mail_quarantine WHERE status = 'quarantined'"
    )->fetchColumn();

    $topRecipients = $pdo->query(
        "SELECT recipient, COUNT(*) AS cnt FROM mail_quarantine
         WHERE status = 'quarantined' AND recipient IS NOT NULL AND recipient <> ''
         GROUP BY recipient ORDER BY cnt DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $topSenders = $pdo->query(
        "SELECT sender, COUNT(*) AS cnt FROM mail_quarantine
         WHERE created_at >= (NOW() - INTERVAL 1 DAY) AND sender IS NOT NULL AND sender <> ''
         GROUP BY sender ORDER BY cnt DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $host = gethostname() ?: 'localhost';
    $lines = [];
    $lines[] = "DEVCON Mail Security - quarantine digest";
    $lines[] = "Host: {$host}";
    $lines[] = "Generated: " . date('Y-m-d H:i:s T');
    $lines[] = "";
    $lines[] = "New quarantined (last 24h): {$newHeld}";
    $lines[] = "Currently held (awaiting review): {$currentlyHeld}";
    $lines[] = "";

    if ($topRecipients) {
        $lines[] = "Top recipients currently held:";
        foreach ($topRecipients as $r) {
            $lines[] = sprintf("  %4d  %s", (int) $r['cnt'], $r['recipient']);
        }
        $lines[] = "";
    }
    if ($topSenders) {
        $lines[] = "Top senders (last 24h):";
        foreach ($topSenders as $s) {
            $lines[] = sprintf("  %4d  %s", (int) $s['cnt'], $s['sender']);
        }
        $lines[] = "";
    }
    $lines[] = "Review and release held mail from the DEVCON panel -> Mail Security -> Quarantine.";
    $body = implode("\n", $lines) . "\n";

    $subject = sprintf('[Mail Security] Quarantine digest - %d new, %d held', $newHeld, $currentlyHeld);
    $from    = 'root@' . $host;

    if ($dryRun) {
        return true;
    }
    return sendMailViaSendmail($to, $from, $subject, $body);
}

/**
 * Per-recipient self-service digest: email each recipient who has held mail a
 * summary of THEIR currently-quarantined messages, each with a signed link to a
 * confirmation page where they can release/delete/allow without logging in.
 *
 * Only recipients with at least one message held in the last 24h are mailed
 * (so the same backlog is not re-sent every day), but the digest lists all of
 * that recipient's currently-held mail so older items can still be actioned.
 */
function sendUserDigests(PDO $pdo, string $baseUrl, int $ttlDays, bool $dryRun, array &$summary): void
{
    $secret = quarantineLinkSecret($pdo);
    if ($secret === '') {
        $summary['errors'][] = 'user digest: could not obtain signing secret';
        return;
    }

    $recips = $pdo->query(
        "SELECT recipient,
                SUM(created_at >= (NOW() - INTERVAL 1 DAY)) AS new24,
                COUNT(*) AS total
         FROM mail_quarantine
         WHERE status = 'quarantined' AND recipient IS NOT NULL AND recipient <> ''
         GROUP BY recipient
         HAVING new24 > 0"
    )->fetchAll(PDO::FETCH_ASSOC);

    $host = gethostname() ?: 'localhost';
    $from = 'mailsecurity-noreply@' . $host;
    $exp  = time() + ($ttlDays * 86400);

    $msgStmt = $pdo->prepare(
        "SELECT id, sender, subject, spam_score, created_at
         FROM mail_quarantine
         WHERE status = 'quarantined' AND recipient = ?
         ORDER BY created_at DESC
         LIMIT 100"
    );

    foreach ($recips as $r) {
        $rcpt = (string) $r['recipient'];
        if (!filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $msgStmt->execute([$rcpt]);
        $msgs = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$msgs) {
            continue;
        }

        $lines = [];
        $lines[] = "DEVCON Mail Security - held mail for {$rcpt}";
        $lines[] = "";
        $lines[] = sprintf("%d message(s) are being held as suspected spam or unsafe.", count($msgs));
        $lines[] = "Open a message's link to review it and choose Release, Allow sender, or Delete.";
        $lines[] = sprintf("Links expire in %d day(s).", $ttlDays);
        $lines[] = "";

        $i = 0;
        foreach ($msgs as $m) {
            $i++;
            $token = makeQuarantineToken((int) $m['id'], $exp, $rcpt, $secret);
            $link  = $baseUrl . '/api/mailsec-q?token=' . rawurlencode($token);
            $sender  = trim((string) ($m['sender'] ?? '')) ?: '(unknown sender)';
            $subject = trim((string) ($m['subject'] ?? '')) ?: '(no subject)';
            $lines[] = sprintf("[%d] From:    %s", $i, $sender);
            $lines[] = sprintf("    Subject: %s", $subject);
            $lines[] = sprintf("    Held:    %s", (string) ($m['created_at'] ?? ''));
            $lines[] = "    Review:  " . $link;
            $lines[] = "";
        }

        $lines[] = "If you did not expect these messages, you can safely ignore this email;";
        $lines[] = "held mail is removed automatically after the retention period.";
        $body = implode("\n", $lines) . "\n";

        $subject = sprintf('[Mail Security] %d message(s) held for you', count($msgs));

        if (!$dryRun) {
            if (!sendMailViaSendmail($rcpt, $from, $subject, $body)) {
                $summary['errors'][] = 'user digest: send failed for ' . $rcpt;
                continue;
            }
        }
        $summary['user_digests_sent']++;
        $summary['user_messages_listed'] += count($msgs);
    }
}

/**
 * Stable HMAC secret for self-service links, shared with the panel API via
 * mail_security_settings. Created once; never overwritten if already present.
 */
function quarantineLinkSecret(PDO $pdo): string
{
    $s = trim(getSetting($pdo, 'quarantine_link_secret', ''));
    if ($s !== '') {
        return $s;
    }
    $s = bin2hex(random_bytes(32));
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO mail_security_settings (k, v, updated_by)
             VALUES ('quarantine_link_secret', ?, 'maintenance')
             ON DUPLICATE KEY UPDATE v = v"
        );
        $stmt->execute([$s]);
        // Re-read in case a concurrent writer (or the panel) created it first.
        $winner = trim(getSetting($pdo, 'quarantine_link_secret', ''));
        if ($winner !== '') {
            return $winner;
        }
    } catch (Throwable $e) {
        // fall through to the locally generated value
    }
    return $s;
}

/** base64url(HMAC-SHA256(id|exp|recipient)). MUST match MailSecurityController. */
function makeQuarantineToken(int $id, int $exp, string $recipient, string $secret): string
{
    $raw = hash_hmac('sha256', $id . '.' . $exp . '.' . strtolower(trim($recipient)), $secret, true);
    $sig = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return $id . '.' . $exp . '.' . $sig;
}

/**
 * Send a plain-text mail through the local MTA (this box is a mail server).
 * Falls back to PHP mail() if sendmail is not where we expect it.
 */
function sendMailViaSendmail(string $to, string $from, string $subject, string $body): bool
{
    $sendmail = null;
    foreach (['/usr/sbin/sendmail', '/usr/lib/sendmail'] as $cand) {
        if (is_file($cand)) {
            $sendmail = $cand;
            break;
        }
    }

    $message = "To: {$to}\r\n"
        . "From: DEVCON Mail Security <{$from}>\r\n"
        . "Subject: {$subject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Auto-Submitted: auto-generated\r\n"
        . "\r\n"
        . str_replace("\n", "\r\n", $body);

    if ($sendmail !== null) {
        $fh = @popen(escapeshellarg($sendmail) . ' -t -i', 'w');
        if ($fh !== false) {
            fwrite($fh, $message);
            $code = pclose($fh);
            return $code === 0;
        }
    }

    return @mail($to, $subject, $body, "From: DEVCON Mail Security <{$from}>\r\nContent-Type: text/plain; charset=utf-8");
}

function isManagedSpoolPath(string $path): bool
{
    return str_starts_with($path, SPOOL_DIR . '/') && str_ends_with($path, '.eml');
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
