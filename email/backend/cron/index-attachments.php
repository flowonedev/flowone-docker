#!/usr/bin/env php
<?php
/**
 * Background Attachment Content Indexer
 * 
 * Processes email attachments in the background, extracting text content
 * from PDFs, Word documents, and text files for full-text search.
 * 
 * Run via cron every 5 minutes (add to crontab -e):
 *   [star]/5 [star] [star] [star] [star] php /var/www/vps-email/backend/cron/index-attachments.php
 * 
 * Options:
 *   --limit=N        Process N attachments per run (default: 50)
 *   --user=email     Process only a specific user
 *   --days=N         Only process attachments from last N days (default: 90)
 *   --dry-run        Show what would be processed without actually doing it
 *   --verbose        Show detailed progress
 * 
 * Environment Variables:
 *   MEILI_MASTER_KEY  Required for Meilisearch indexing
 */

// Ensure CLI execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Load autoloader
require_once __DIR__ . '/bootstrap.php';

// Parse command line arguments
$options = getopt('', ['limit::', 'user::', 'days::', 'imap-password::', 'dry-run', 'verbose', 'help']);

if (isset($options['help'])) {
    echo "Usage: php index-attachments.php [options]\n";
    echo "Options:\n";
    echo "  --limit=N            Process N attachments per run (default: 50)\n";
    echo "  --user=email         Process only a specific user\n";
    echo "  --days=N             Only process attachments from last N days (default: 90)\n";
    echo "  --imap-password=PWD  Use this password instead of DB session lookup (requires --user)\n";
    echo "  --dry-run            Show what would be processed without doing it\n";
    echo "  --verbose            Show detailed progress\n";
    exit(0);
}

$limit       = (int)($options['limit'] ?? 50);
$userFilter  = $options['user'] ?? null;
$daysLimit   = (int)($options['days'] ?? 90);
$imapPwdFlag = $options['imap-password'] ?? null;
$dryRun      = isset($options['dry-run']);
$verbose     = isset($options['verbose']);

if ($imapPwdFlag !== null && !$userFilter) {
    fwrite(STDERR, "--imap-password requires --user=EMAIL\n");
    exit(2);
}

// Load configuration
$config = require __DIR__ . '/../src/config.php';

// Override with environment variables
if (getenv('MEILI_MASTER_KEY')) {
    $config['meilisearch']['master_key'] = getenv('MEILI_MASTER_KEY');
}
if (getenv('MEILI_HOST')) {
    $config['meilisearch']['host'] = getenv('MEILI_HOST');
}
if (empty($config['imap_encryption_key'])) {
    $envKey = getenv('IMAP_ENCRYPTION_KEY');
    if ($envKey) {
        $config['imap_encryption_key'] = $envKey;
    }
}

use Webmail\Services\SearchIndexerService;
use Webmail\Services\ImapService;
use Webmail\Services\SessionService;
use Webmail\Services\FolderImapResolver;
use Webmail\Services\GoogleOAuthService;
use Webmail\Services\MicrosoftOAuthService;

// Initialize database
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $config['db']['host'] ?? '127.0.0.1',
    $config['db']['name'] ?? 'devc_vps_dash'
);

try {
    $db = new PDO($dsn, $config['db']['user'] ?? '', $config['db']['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    log_message("Database connection failed: " . $e->getMessage(), true);
    exit(1);
}

// Initialize indexer service
$indexer = new SearchIndexerService($config);

// Check if Meilisearch is enabled
if (!$indexer->isMeilisearchEnabled()) {
    log_message("Warning: Meilisearch is not enabled. Content will only be indexed in MySQL.");
}

// Extractable MIME types — single source of truth lives in SearchIndexerService
// so cron and the runtime indexer never drift apart.
$extractableMimeTypes = SearchIndexerService::EXTRACTABLE_MIME_TYPES;
$mimeTypePlaceholders = implode(',', array_fill(0, count($extractableMimeTypes), '?'));

// Date cutoff
$dateCutoff = date('Y-m-d H:i:s', strtotime("-{$daysLimit} days"));

// Build query to find unindexed attachments
$sql = "
    SELECT * FROM webmail_email_attachments 
    WHERE content_indexed = 0 
    AND mime_type IN ($mimeTypePlaceholders)
    AND size > 0 
    AND size <= 10485760
    AND message_date >= ?
";
$params = array_merge($extractableMimeTypes, [$dateCutoff]);

if ($userFilter) {
    $sql .= " AND user_email = ?";
    $params[] = $userFilter;
}

$sql .= " ORDER BY message_date DESC LIMIT " . (int)$limit;

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $attachments = $stmt->fetchAll();
} catch (PDOException $e) {
    log_message("Query failed: " . $e->getMessage(), true);
    exit(1);
}

$totalFound = count($attachments);
log_message("Found $totalFound attachments to process" . ($dryRun ? " (dry run)" : ""));

if ($totalFound === 0) {
    exit(0);
}

if ($dryRun) {
    foreach ($attachments as $att) {
        log_message("Would process: {$att['filename']} ({$att['mime_type']}, " . formatBytes($att['size']) . ") - {$att['user_email']}");
    }
    exit(0);
}

// Process attachments
$processed = 0;
$success = 0;
$errors = 0;
$skipped = 0;

// Group attachments by user for efficient IMAP connection reuse
$byUser = [];
foreach ($attachments as $att) {
    $byUser[$att['user_email']][] = $att;
}

foreach ($byUser as $userEmail => $userAttachments) {
    if ($verbose) {
        log_message("Processing " . count($userAttachments) . " attachments for $userEmail");
    }
    
    // Get user's IMAP credentials (OAuth tokens > DB session > --imap-password override)
    $credentials = getUserImapCredentials($db, $userEmail, $config, $imapPwdFlag, $userFilter);
    if (!$credentials) {
        log_message("Skipping $userEmail - no IMAP credentials found (no OAuth token, no active session, and no --imap-password). Have the user log in once, or pass --imap-password=PWD for ad-hoc runs.");
        $skipped += count($userAttachments);
        continue;
    }
    
    // Connect to IMAP (ImapService takes the host/port via constructor config)
    $imap = new ImapService($config['imap'] ?? []);
    $connected = false;

    try {
        if (!empty($credentials['oauth_provider'])) {
            $connected = $imap->connectWithOAuth(
                $credentials['email'],
                $credentials['access_token']
            );
        } else {
            $connected = $imap->connect(
                $credentials['email'],
                $credentials['password']
            );
        }
    } catch (Exception $e) {
        log_message("IMAP connection failed for $userEmail: " . $e->getMessage());
        $skipped += count($userAttachments);
        continue;
    }
    
    if (!$connected) {
        log_message("IMAP connection failed for $userEmail");
        $skipped += count($userAttachments);
        continue;
    }
    
    // Canonicalize folder paths via the folder-identity system before every
    // IMAP call. Crons bypass BaseController::getResolvedFolder, so without
    // this they'd hit Dovecot with whatever (possibly stale-cased) string
    // is in webmail_email_attachments.folder. One resolver per user so the
    // cache is naturally scoped.
    $folderResolver = new FolderImapResolver($config);
    
    // Process each attachment
    foreach ($userAttachments as $att) {
        $processed++;
        
        try {
            if ($verbose) {
                log_message("[$processed/$totalFound] Processing: {$att['filename']}");
            }
            
            $canonicalFolder = $folderResolver->resolveForImap($userEmail, (string)$att['folder']);
            
            // Guard: skip rows whose folder is unreachable on IMAP (stale
            // case, deleted, renamed). Without this, deep imap_* calls can
            // throw \TypeError which bypasses the catch below.
            if (!$imap->selectFolder($canonicalFolder)) {
                if ($verbose) {
                    log_message("  - folder unreachable, skipping: '{$att['folder']}' (canonical='{$canonicalFolder}')");
                }
                markAsIndexed($db, $att['id'], false);
                $errors++;
                continue;
            }
            
            // Download attachment
            $attachmentData = $imap->getAttachment($canonicalFolder, $att['uid'], $att['part'] ?? '1');
            
            if (!$attachmentData || empty($attachmentData['content'])) {
                if ($verbose) {
                    log_message("  - Could not download attachment");
                }
                markAsIndexed($db, $att['id'], false);
                $errors++;
                continue;
            }
            
            // Index with content extraction
            $indexData = [
                'filename' => $att['filename'],
                'mime_type' => $att['mime_type'],
                'from_email' => $att['from_email'],
                'from_name' => $att['from_name'],
                'subject' => $att['subject'],
                'folder' => $att['folder'],
                'uid' => $att['uid'],
                'part' => $att['part'] ?? '1',
                'size' => $att['size'],
                'message_date' => $att['message_date'],
                'content' => $attachmentData['content'], // Binary content for extraction
            ];
            
            $result = $indexer->indexEmailAttachment($userEmail, $indexData);
            
            if ($result) {
                markAsIndexed($db, $att['id'], true);
                $success++;
                if ($verbose) {
                    log_message("  - Successfully indexed");
                }
            } else {
                markAsIndexed($db, $att['id'], false);
                $errors++;
                if ($verbose) {
                    log_message("  - Indexing failed");
                }
            }
            
        } catch (\Throwable $e) {
            // Catch \Throwable: PHP 8 \TypeError from imap_* on missing
            // folders/structures is an \Error, not \Exception. Letting it
            // escape would abort the cron and leave subsequent users unscanned.
            log_message("Error processing {$att['filename']}: " . $e->getMessage());
            markAsIndexed($db, $att['id'], false);
            $errors++;
        }
        
        // Small delay to avoid overwhelming IMAP server
        usleep(100000); // 100ms
    }
    
    // Disconnect IMAP
    $imap->disconnect();
}

// Summary
log_message("Completed: $success success, $errors errors, $skipped skipped out of $totalFound");

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function log_message(string $message, bool $isError = false): void
{
    $timestamp = date('Y-m-d H:i:s');
    $prefix = $isError ? '[ERROR]' : '[INFO]';
    echo "[$timestamp] $prefix $message\n";
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/**
 * Resolve IMAP credentials for a user. Tries (in order):
 *   1. Explicit --imap-password=PWD CLI flag (only when --user matches)
 *   2. OAuth tokens in email_accounts
 *   3. Encrypted IMAP password on the user's most recent active session,
 *      decrypted with IMAP_ENCRYPTION_KEY
 *
 * Returns:
 *   ['email' => ..., 'password' => '...']                     for password auth
 *   ['email' => ..., 'access_token' => ..., 'oauth_provider'] for OAuth
 *   null if nothing works
 */
function getUserImapCredentials(PDO $db, string $userEmail, array $config, ?string $explicitPwd = null, ?string $userFilter = null): ?array
{
    // 1. Explicit override
    if ($explicitPwd !== null && $explicitPwd !== '' && $userFilter && strtolower($userFilter) === strtolower($userEmail)) {
        return ['email' => $userEmail, 'password' => $explicitPwd];
    }

    // 2. OAuth accounts (Gmail, Outlook, ...) — Phase 2.5 fix.
    //
    // The old code queried a phantom `email_accounts` table with plaintext
    // access_token; that table doesn't exist in this project. Tokens live
    // in `webmail_oauth_tokens` (AES-256-GCM). GoogleOAuthService /
    // MicrosoftOAuthService handle decrypt + lazy refresh.
    try {
        $stmt = $db->prepare("
            SELECT id, oauth_email, provider
            FROM webmail_oauth_tokens
            WHERE primary_email = ? AND oauth_email = ?
              AND COALESCE(health, 'healthy') != 'revoked'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$userEmail, $userEmail]);
        $row = $stmt->fetch();
        if ($row) {
            $provider = $row['provider'] ?? 'google';
            $accessToken = null;
            try {
                if ($provider === 'microsoft' && !empty($config['microsoft_oauth']['client_id'])) {
                    $svc = new MicrosoftOAuthService($config);
                    $accessToken = $svc->getValidAccessToken($userEmail, $row['oauth_email']);
                } elseif (!empty($config['google_oauth']['client_id'])) {
                    $svc = new GoogleOAuthService($config);
                    $accessToken = $svc->getValidAccessToken($userEmail, $row['oauth_email']);
                }
            } catch (\Throwable $e) {
                log_message("getUserImapCredentials OAuth refresh failed for {$userEmail}: " . $e->getMessage(), true);
            }
            if ($accessToken) {
                return [
                    'email' => $row['oauth_email'],
                    'access_token' => $accessToken,
                    'oauth_provider' => $provider,
                ];
            }
        }
    } catch (PDOException $e) {
        log_message("getUserImapCredentials OAuth lookup failed for {$userEmail}: " . $e->getMessage(), true);
    }

    // 3. Most recent active session — decrypt stored IMAP password
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
            log_message("IMAP_ENCRYPTION_KEY not set — cannot decrypt session password for {$userEmail}", true);
            return null;
        }
        $sessionService = new SessionService($config['jwt'] ?? [], $key);
        $password = $sessionService->decryptPassword($session['encrypted_password']);
        if (!$password) {
            return null;
        }
        return ['email' => $userEmail, 'password' => $password];
    } catch (\Throwable $e) {
        log_message("getUserImapCredentials session lookup failed for {$userEmail}: " . $e->getMessage(), true);
        return null;
    }
}

function markAsIndexed(PDO $db, int $attachmentId, bool $success): void
{
    try {
        $stmt = $db->prepare("
            UPDATE webmail_email_attachments 
            SET content_indexed = ?, content_indexed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$success ? 1 : -1, $attachmentId]); // -1 = failed
    } catch (PDOException $e) {
        // Ignore errors
    }
}

