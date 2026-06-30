#!/usr/bin/env php
<?php
/**
 * Register email attachments into webmail_email_attachments cache.
 *
 * The downstream cron (index-attachments.php) extracts file contents from
 * rows in webmail_email_attachments, but nothing automatically populates
 * that table — historically you had to run a manual JWT-based script.
 * This cron closes that gap.
 *
 * Strategy:
 *   1. Find messages in webmail_conversation_members where has_attachment = 1
 *      but no row exists in webmail_email_attachments (or the cache row has
 *      no part info).
 *   2. Connect to IMAP using credentials stored on the user's most recent
 *      active session (webmail_sessions.encrypted_password), or OAuth tokens
 *      from email_accounts, or an explicit --imap-password.
 *   3. Fetch each message's MIME structure via ImapService::getMessage() and
 *      insert one row per attachment.
 *
 * Self-healing:
 *   The upstream `has_attachment` flag is set by a permissive heuristic
 *   (ImapService::hasAttachments) that flags inline images, signature
 *   images, calendar invites, and any part with a name parameter as
 *   "attachments". When this cron's stricter IMAP fetch confirms a flagged
 *   message has zero REAL attachments, it clears has_attachment back to 0
 *   so the next run doesn't waste IMAP fetches on the same messages.
 *   Orphan messages (IMAP returns null) are left alone — reconcile-mailboxes
 *   is responsible for cleaning those up.
 *
 * Run via cron every 15 minutes:
 *   star/15 star star star star /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/cron/register-attachments.php
 *
 * Options:
 *   --user=EMAIL         Only this user (default: all users w/ active sessions)
 *   --imap-password=PWD  Override password lookup (single-user mode only)
 *   --limit=N            Max messages to scan per user (default: 200)
 *   --days=N             Only scan messages from last N days (default: 365)
 *   --dry-run            Show what would be inserted without doing it
 *   --verbose            Verbose output
 *   --help               Show this help
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\ImapService;
use Webmail\Services\SessionService;
use Webmail\Services\FolderImapResolver;

$opts = getopt('', ['user::', 'imap-password::', 'limit::', 'days::', 'dry-run', 'verbose', 'help']);

if (isset($opts['help'])) {
    echo "register-attachments.php — populate webmail_email_attachments from IMAP\n\n";
    echo "Options:\n";
    echo "  --user=EMAIL         Only this user\n";
    echo "  --imap-password=PWD  Override password (single user mode)\n";
    echo "  --limit=N            Max messages per user (default 200)\n";
    echo "  --days=N             Only last N days (default 365)\n";
    echo "  --dry-run            Show what would be inserted\n";
    echo "  --verbose            Verbose output\n";
    exit(0);
}

$userFilter   = $opts['user'] ?? null;
$imapPwdFlag  = $opts['imap-password'] ?? null;
$limit        = (int)($opts['limit'] ?? 200);
$daysLimit    = (int)($opts['days'] ?? 365);
$dryRun       = isset($opts['dry-run']);
$verbose      = isset($opts['verbose']);

if ($imapPwdFlag !== null && !$userFilter) {
    fwrite(STDERR, "--imap-password requires --user=EMAIL\n");
    exit(2);
}

$config = require __DIR__ . '/../src/config.php';

// IMAP_ENCRYPTION_KEY is required to decrypt session passwords
if (empty($config['imap_encryption_key'])) {
    $envKey = getenv('IMAP_ENCRYPTION_KEY');
    if ($envKey) {
        $config['imap_encryption_key'] = $envKey;
    }
}

// DB connection
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db']['host'] ?? '127.0.0.1', $config['db']['name'] ?? 'devc_vps_dash');
    $db = new PDO($dsn, $config['db']['user'] ?? '', $config['db']['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "[FATAL] DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── User selection ────────────────────────────────────────────────────

$users = [];
if ($userFilter) {
    $users[] = strtolower($userFilter);
} else {
    // All users with an active session (so we can decrypt their IMAP password)
    $stmt = $db->prepare("
        SELECT DISTINCT email
        FROM webmail_sessions
        WHERE is_valid = 1
          AND expires_at > NOW()
          AND encrypted_password IS NOT NULL
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    log_msg("Discovered " . count($users) . " users with active sessions");
}

if (empty($users)) {
    log_msg("No users to process. Exit.");
    exit(0);
}

$dateCutoff = date('Y-m-d H:i:s', strtotime("-{$daysLimit} days"));

$totalUsers = 0;
$totalMessagesScanned = 0;
$totalAttachmentsInserted = 0;
$totalSkippedUsers = 0;
$totalFalsePositivesCleared = 0;
$totalOrphans = 0;

foreach ($users as $userEmail) {
    $userEmail = strtolower($userEmail);
    log_msg("─── User: {$userEmail} ───");

    // 1. Resolve IMAP credentials
    $creds = resolveCredentials($db, $userEmail, $imapPwdFlag, $config);
    if (!$creds) {
        log_msg("  Skipped: no IMAP credentials available");
        $totalSkippedUsers++;
        continue;
    }

    // 2. Connect IMAP
    $imap = new ImapService($config['imap'] ?? []);
    try {
        if (!empty($creds['oauth_provider'])) {
            $ok = $imap->connectWithOAuth($creds['email'], $creds['access_token']);
        } else {
            $ok = $imap->connect($creds['email'], $creds['password']);
        }
        if (!$ok) {
            log_msg("  Skipped: IMAP connect failed: " . ($imap->getLastError() ?? 'unknown'));
            $totalSkippedUsers++;
            continue;
        }
    } catch (\Throwable $e) {
        log_msg("  Skipped: IMAP exception: " . $e->getMessage());
        $totalSkippedUsers++;
        continue;
    }

    $totalUsers++;

    // 3. Find candidate messages
    $candidates = findCandidates($db, $userEmail, $dateCutoff, $limit);
    if (empty($candidates)) {
        log_msg("  No new attachment messages to register");
        $imap->disconnect();
        continue;
    }
    log_msg("  Found " . count($candidates) . " messages with unregistered attachments");

    // Canonicalize folder paths via the folder-identity system. This cron
    // is the WRITER for webmail_email_attachments, so resolving here means
    // every new row is stored with the server's real-case path from the
    // start -- no more lowercase pollution accumulating in the cache table.
    // Per-user resolver so the cache is naturally scoped to one IMAP session.
    $folderResolver = new FolderImapResolver($config);

    // 4. Walk each message via IMAP and INSERT rows
    $inserted = 0;
    $scanned  = 0;
    $clearedFalsePositives = 0;
    $orphans = 0;
    foreach ($candidates as $msg) {
        $scanned++;
        $totalMessagesScanned++;
        
        $canonicalFolder = $folderResolver->resolveForImap($userEmail, (string)$msg['folder']);
        
        if ($verbose) {
            $folderNote = $canonicalFolder !== $msg['folder']
                ? "{$msg['folder']} -> {$canonicalFolder}"
                : $msg['folder'];
            log_msg("    [{$scanned}/" . count($candidates) . "] {$folderNote} uid={$msg['uid']}");
        }

        try {
            // Per-message soft timeout — one slow IMAP fetch shouldn't kill the run
            set_time_limit(30);

            $full = $imap->getMessage($canonicalFolder, (int)$msg['uid']);

            // Case A: message not found in IMAP (orphan row or transient IMAP error).
            // Leave has_attachment alone — reconcile-mailboxes.php is the right place
            // to delete the orphan; we shouldn't silently clear flags here.
            if ($full === null) {
                $orphans++;
                $totalOrphans++;
                if ($verbose) log_msg("      IMAP fetch returned null (orphan or transient)");
                continue;
            }

            // Case B: IMAP succeeded but there are no real attachments — the
            // upstream `has_attachment` flag was a false positive (set by the
            // permissive hasAttachments() heuristic for inline images, signature
            // images, etc.). Clear the flag so we don't rescan forever.
            if (empty($full['attachments'])) {
                if (!$dryRun) {
                    try {
                        $clear = $db->prepare("
                            UPDATE webmail_conversation_members
                            SET has_attachment = 0
                            WHERE user_email = ? AND folder_id = ? AND uid = ?
                        ");
                        $clear->execute([$userEmail, $msg['folder_id'], (int)$msg['uid']]);
                        $clearedFalsePositives++;
                        $totalFalsePositivesCleared++;
                    } catch (PDOException $e) {
                        // non-fatal — worst case we rescan next tick
                        if ($verbose) log_msg("      [WARN] could not clear has_attachment: " . $e->getMessage());
                    }
                }
                if ($verbose) log_msg("      no real attachments — cleared false-positive flag");
                continue;
            }

            foreach ($full['attachments'] as $att) {
                $filename = $att['filename'] ?? 'Unknown';
                $mimeType = $att['type'] ?? $att['mime_type'] ?? 'application/octet-stream';
                $size     = (int)($att['size'] ?? 0);
                $part     = (string)($att['part'] ?? '1');

                if ($dryRun) {
                    log_msg("      [DRY] would insert: {$filename} ({$mimeType}, {$size}b, part={$part})");
                    continue;
                }

                try {
                    $insert = $db->prepare("
                        INSERT INTO webmail_email_attachments
                        (user_email, folder, uid, filename, part, mime_type, size,
                         from_email, from_name, subject, message_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            part = VALUES(part),
                            mime_type = VALUES(mime_type),
                            size = VALUES(size)
                    ");
                    // Store the canonical (server real-case) path, NOT the
                    // raw path from webmail_conversation_members. This is
                    // the writer for the attachments cache; every new row
                    // should land in canonical form so the downstream
                    // indexer never has to guess.
                    $insert->execute([
                        $userEmail,
                        $canonicalFolder,
                        (int)$msg['uid'],
                        $filename,
                        $part,
                        $mimeType,
                        $size,
                        $msg['from_email'] ?? '',
                        $msg['from_name']  ?? '',
                        $msg['subject']    ?? '',
                        $msg['message_date'] ?? null,
                    ]);
                    $inserted++;
                    $totalAttachmentsInserted++;
                    if ($verbose) {
                        log_msg("      + {$filename} ({$mimeType}, " . fmtBytes($size) . ")");
                    }
                } catch (PDOException $e) {
                    log_msg("      [ERR] insert failed for {$filename}: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            log_msg("    [ERR] fetch failed for uid={$msg['uid']}: " . $e->getMessage());
        }

        usleep(50000); // 50ms — be kind to IMAP
    }

    log_msg(sprintf(
        "  Scanned: %d  Inserted: %d  False-positives cleared: %d  Orphans: %d",
        $scanned, $inserted, $clearedFalsePositives, $orphans
    ));
    $imap->disconnect();
}

log_msg("════════════════════════════════════════════");
log_msg("SUMMARY");
log_msg("  Users processed:           {$totalUsers}");
log_msg("  Users skipped:             {$totalSkippedUsers}");
log_msg("  Messages scanned:          {$totalMessagesScanned}");
log_msg("  Attachment rows added:     {$totalAttachmentsInserted}" . ($dryRun ? "  (DRY RUN)" : ""));
log_msg("  False-positive flags cleared: {$totalFalsePositivesCleared}");
log_msg("  Orphan messages (left alone): {$totalOrphans}");

exit(0);

// ─────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

function fmtBytes(int $b): string
{
    if ($b < 1024) return $b . 'B';
    if ($b < 1048576) return round($b / 1024, 1) . 'KB';
    return round($b / 1048576, 1) . 'MB';
}

/**
 * Find messages that have has_attachment = 1 but no rows in
 * webmail_email_attachments (or have rows without `part` info).
 *
 * @return array<array<string,mixed>>
 */
function findCandidates(PDO $db, string $userEmail, string $dateCutoff, int $limit): array
{
    // Skip these folders by name — never index trash/spam/draft attachments
    $skipFolders = ['trash', 'deleted items', 'deleted', 'spam', 'junk', 'drafts'];

    // Project the path via the identity table; the attachments cache
    // keeps its own `folder` string column so we still compare on path.
    // We also surface m.folder_id so the caller can issue stable UPDATEs
    // back into webmail_conversation_members.
    $stmt = $db->prepare("
        SELECT m.folder_id, fi.current_path AS folder, m.uid, m.subject,
               m.from_email, m.from_name, m.message_date
        FROM webmail_conversation_members m
        LEFT JOIN webmail_folder_identity fi ON fi.id = m.folder_id
        WHERE m.user_email = ?
          AND m.has_attachment = 1
          AND m.message_date >= ?
          AND fi.current_path IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM webmail_email_attachments a
              WHERE a.user_email = m.user_email
                AND a.folder     = fi.current_path
                AND a.uid        = m.uid
                AND a.part IS NOT NULL
                AND a.part != ''
          )
        ORDER BY m.message_date DESC
        LIMIT " . (int)$limit . "
    ");
    $stmt->execute([$userEmail, $dateCutoff]);
    $rows = $stmt->fetchAll();

    // Filter skip folders client-side (simpler than building a NOT LIKE chain)
    return array_values(array_filter($rows, function ($r) use ($skipFolders) {
        $f = strtolower($r['folder'] ?? '');
        foreach ($skipFolders as $skip) {
            if (strpos($f, $skip) !== false) return false;
        }
        return true;
    }));
}

/**
 * Returns one of:
 *   ['email' => ..., 'password' => '...']                       (password auth)
 *   ['email' => ..., 'access_token' => ..., 'oauth_provider'=>] (OAuth)
 *   null
 */
function resolveCredentials(PDO $db, string $userEmail, ?string $explicitPwd, array $config): ?array
{
    if ($explicitPwd !== null && $explicitPwd !== '') {
        return ['email' => $userEmail, 'password' => $explicitPwd];
    }

    // 1. OAuth accounts (Gmail / Outlook etc.)
    try {
        $stmt = $db->prepare("
            SELECT email, access_token, refresh_token, provider AS oauth_provider
            FROM email_accounts
            WHERE owner_email = ? AND email = ? AND access_token IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$userEmail, $userEmail]);
        $oauth = $stmt->fetch();
        if ($oauth && !empty($oauth['access_token'])) {
            return $oauth;
        }
    } catch (PDOException $e) {
        // table may not exist on minimal installs — fall through
    }

    // 2. Most recent active session — decrypt IMAP password
    try {
        $stmt = $db->prepare('
            SELECT encrypted_password FROM webmail_sessions
            WHERE email = ? AND is_valid = 1 AND expires_at > NOW()
            ORDER BY last_active_at DESC
            LIMIT 1
        ');
        $stmt->execute([$userEmail]);
        $session = $stmt->fetch();
        if (!$session || empty($session['encrypted_password'])) {
            return null;
        }

        $key = $config['imap_encryption_key'] ?? '';
        if ($key === '') {
            log_msg("  WARN: IMAP_ENCRYPTION_KEY not set, cannot decrypt session password for {$userEmail}");
            return null;
        }
        $sessionService = new SessionService($config['jwt'] ?? [], $key);
        $password = $sessionService->decryptPassword($session['encrypted_password']);
        if (!$password) {
            return null;
        }
        return ['email' => $userEmail, 'password' => $password];
    } catch (\Throwable $e) {
        error_log("register-attachments resolveCredentials session error: " . $e->getMessage());
        return null;
    }
}
