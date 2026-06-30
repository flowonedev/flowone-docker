#!/usr/bin/env php
<?php
/**
 * Scheduled Email Processor
 * 
 * Sends emails that were scheduled for a future time.
 * Run via cron every minute:
 *   * * * * * php /var/www/vps-email/backend/cron/process-scheduled-emails.php
 * 
 * Options:
 *   --batch=N     Process N emails per run (default: 5)
 *   --verbose     Show detailed progress
 *   --cleanup     Also cleanup old records
 *   --help        Show this help message
 * 
 * How it works:
 * 1. Finds scheduled emails where scheduled_at <= NOW()
 * 2. Locks each email (status = 'sending')
 * 3. Sends via SMTP using stored credentials
 * 4. Updates status to 'sent' or retries on failure
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\ScheduledEmailService;
use Webmail\Services\SmtpService;
use Webmail\Addons\EmailTracking\Services\TrackingService;
use Webmail\Services\SessionService;
use Webmail\Services\GoogleOAuthService;
use Webmail\Services\MicrosoftOAuthService;

$options = getopt('', ['batch:', 'verbose', 'cleanup', 'help']);

if (isset($options['help'])) {
    echo "Scheduled Email Processor\n";
    echo "=========================\n\n";
    echo "Sends emails scheduled for future delivery.\n\n";
    echo "Usage: php process-scheduled-emails.php [options]\n\n";
    echo "Options:\n";
    echo "  --batch=N     Process N emails per run (default: 5)\n";
    echo "  --verbose     Show detailed progress\n";
    echo "  --cleanup     Also cleanup old records (30+ days)\n";
    echo "  --help        Show this help message\n\n";
    echo "Cron setup (every minute):\n";
    echo "  * * * * * php /var/www/vps-email/backend/cron/process-scheduled-emails.php\n\n";
    exit(0);
}

$batchSize = (int)($options['batch'] ?? 5);
$verbose = isset($options['verbose']);
$doCleanup = isset($options['cleanup']);

$configFile = __DIR__ . '/../src/config.php';
if (!file_exists($configFile)) {
    die("Config file not found: {$configFile}\n");
}
$config = require $configFile;

$timestamp = date('Y-m-d H:i:s');
$logFile = __DIR__ . '/../storage/logs/scheduled-emails.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function logMsg(string $msg, string $logFile, bool $verbose) {
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    if ($verbose) echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function cleanupScheduledAttachments(array $attachments, string $logFile, bool $verbose): void {
    $schedDir = realpath(__DIR__ . '/../storage/scheduled-attachments');
    if (!$schedDir) return;

    foreach ($attachments as $att) {
        if (empty($att['path'])) continue;
        $real = realpath($att['path']);
        if ($real && str_starts_with($real, $schedDir) && file_exists($real)) {
            @unlink($real);
            logMsg("Cleaned up scheduled attachment: " . basename($real), $logFile, $verbose);
        }
    }
}

/**
 * Get secondary account credentials for the cron context
 * Mirrors BaseController::getSecondaryAccountCredentials but standalone
 */
function getSecondaryAccountCredentials(int $accountId, string $primaryEmail, array $config): ?array
{
    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db']['host'] ?? '127.0.0.1',
            $config['db']['name'] ?? 'devc_vps_dash'
        );
        
        $db = new \PDO(
            $dsn,
            $config['db']['user'] ?? 'vpsadmin',
            $config['db']['pass'] ?? '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        
        $primaryEmail = strtolower($primaryEmail);
        
        // Check regular accounts first
        $stmt = $db->prepare('SELECT * FROM webmail_accounts WHERE id = ? AND primary_email = ?');
        $stmt->execute([$accountId, $primaryEmail]);
        $account = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($account) {
            // Decrypt password
            $imapKey = $config['imap_encryption_key'] ?? '';
            $keySource = $imapKey ?: ($config['jwt']['secret'] ?? 'default_key');
            $encryptionKey = hash('sha256', $keySource, true);
            
            $password = null;
            if (!empty($account['credentials_encrypted'])) {
                $data = base64_decode($account['credentials_encrypted']);
                if ($data && strlen($data) > 28) {
                    $iv = substr($data, 0, 12);
                    $tag = substr($data, 12, 16);
                    $ciphertext = substr($data, 28);
                    $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
                    if ($decrypted !== false) {
                        $password = $decrypted;
                    }
                }
            }
            
            return [
                'email' => $account['account_email'],
                'password' => $password,
                'imap_host' => $account['imap_host'] ?? null,
                'imap_port' => $account['imap_port'] ?? null,
                'imap_encryption' => $account['imap_encryption'] ?? null,
                'smtp_host' => $account['smtp_host'] ?? null,
                'smtp_port' => $account['smtp_port'] ?? null,
                'smtp_encryption' => $account['smtp_encryption'] ?? null,
                'is_oauth' => false,
            ];
        }
        
        // Check OAuth accounts
        $stmt = $db->prepare('SELECT * FROM webmail_oauth_tokens WHERE id = ? AND primary_email = ?');
        $stmt->execute([$accountId, $primaryEmail]);
        $oauthAccount = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($oauthAccount) {
            $provider = $oauthAccount['provider'] ?? 'google';
            $oauthEmail = $oauthAccount['oauth_email'];
            
            // Get valid access token via OAuth service
            $accessToken = null;
            try {
                if ($provider === 'microsoft' && !empty($config['microsoft_oauth']['client_id'])) {
                    $oauthService = new MicrosoftOAuthService($config);
                    $accessToken = $oauthService->getValidAccessToken($primaryEmail, $oauthEmail);
                } elseif (!empty($config['google_oauth']['client_id'])) {
                    $oauthService = new GoogleOAuthService($config);
                    $accessToken = $oauthService->getValidAccessToken($primaryEmail, $oauthEmail);
                }
            } catch (\Exception $e) {
                error_log("Cron: Failed to get OAuth token for {$oauthEmail}: " . $e->getMessage());
            }
            
            // SMTP/IMAP settings based on provider
            $smtpHost = $provider === 'microsoft' ? 'smtp.office365.com' : 'smtp.gmail.com';
            $smtpPort = 587;
            $smtpEncryption = 'tls';
            $imapHost = $provider === 'microsoft' ? 'outlook.office365.com' : 'imap.gmail.com';
            $imapPort = 993;
            $imapEncryption = 'ssl';
            
            return [
                'email' => $oauthEmail,
                'password' => null,
                'is_oauth' => true,
                'provider' => $provider,
                'access_token' => $accessToken,
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_encryption' => $smtpEncryption,
                'imap_host' => $imapHost,
                'imap_port' => $imapPort,
                'imap_encryption' => $imapEncryption,
            ];
        }
        
        return null;
    } catch (\Exception $e) {
        error_log("Cron getSecondaryAccountCredentials error: " . $e->getMessage());
        return null;
    }
}

try {
    $scheduledService = new ScheduledEmailService($config);
    $sessionService = new SessionService($config['jwt'], $config['imap_encryption_key'] ?? '');
    
    // Get due emails
    $dueEmails = $scheduledService->getDueEmails($batchSize);
    
    if (empty($dueEmails)) {
        if ($verbose) echo "[{$timestamp}] No scheduled emails due.\n";
        
        // Cleanup if requested
        if ($doCleanup) {
            $cleaned = $scheduledService->cleanup();
            if ($cleaned > 0) {
                logMsg("Cleaned up {$cleaned} old records", $logFile, $verbose);
            }
        }
        exit(0);
    }
    
    logMsg("Processing " . count($dueEmails) . " scheduled email(s)...", $logFile, $verbose);
    
    $sent = 0;
    $failed = 0;
    
    foreach ($dueEmails as $email) {
        $scheduleId = $email['schedule_id'];
        $userEmail = $email['user_email'];
        
        // Lock the email
        if (!$scheduledService->markSending($email['id'])) {
            logMsg("SKIP {$scheduleId}: Already being processed", $logFile, $verbose);
            continue;
        }
        
        try {
            $payload = json_decode($email['email_payload'], true);
            if (!$payload) {
                throw new \Exception("Invalid email payload");
            }
            
            logMsg("Sending {$scheduleId} for {$userEmail}: " . ($payload['subject'] ?? '(no subject)'), $logFile, $verbose);
            
            // Get primary SMTP credentials from payload
            $smtpPassword = null;
            if (!empty($payload['_encrypted_password'])) {
                try {
                    $smtpPassword = $sessionService->decryptPassword($payload['_encrypted_password']);
                } catch (\Exception $e) {
                    throw new \Exception("Failed to decrypt credentials: " . $e->getMessage());
                }
            }
            
            $oauthToken = $payload['_oauth_token'] ?? null;
            $oauthProvider = $payload['_oauth_provider'] ?? null;
            
            if (!$smtpPassword && !$oauthToken) {
                throw new \Exception("No credentials available for sending");
            }
            
            // Check if sending from a secondary/linked account
            $fromAccountId = $payload['from_account_id'] ?? null;
            $fromEmail = $payload['from_email'] ?? null;
            $actualSenderEmail = $userEmail;
            $secondarySmtp = null;
            $secondaryImapConfig = null;
            $secondaryImapPassword = null;
            $secondaryOAuthToken = null;
            $secondaryOAuthProvider = null;
            
            if ($fromAccountId && $fromEmail) {
                // Look up secondary account credentials
                $secondaryAccount = getSecondaryAccountCredentials(
                    (int)$fromAccountId, $userEmail, $config
                );
                
                if ($secondaryAccount) {
                    $actualSenderEmail = $secondaryAccount['email'];
                    
                    // Build SMTP config for secondary account
                    $smtpConfig = $config['smtp'];
                    if (!empty($secondaryAccount['smtp_host'])) {
                        $smtpConfig['host'] = $secondaryAccount['smtp_host'];
                    }
                    if (!empty($secondaryAccount['smtp_port'])) {
                        $smtpConfig['port'] = (int)$secondaryAccount['smtp_port'];
                    }
                    if (isset($secondaryAccount['smtp_encryption'])) {
                        $smtpConfig['encryption'] = $secondaryAccount['smtp_encryption'];
                    }
                    
                    $smtp = new SmtpService($smtpConfig);
                    
                    if (!empty($secondaryAccount['is_oauth']) && $secondaryAccount['is_oauth']) {
                        if (!empty($secondaryAccount['access_token'])) {
                            $provider = $secondaryAccount['provider'] ?? 'google';
                            $smtp->setOAuthCredentials($secondaryAccount['email'], $secondaryAccount['access_token'], $provider);
                            $secondaryOAuthToken = $secondaryAccount['access_token'];
                            $secondaryOAuthProvider = $provider;
                            // Set IMAP config for secondary OAuth account
                            $secondaryImapConfig = [
                                'host' => $secondaryAccount['imap_host'] ?? ($provider === 'microsoft' ? 'outlook.office365.com' : 'imap.gmail.com'),
                                'port' => $secondaryAccount['imap_port'] ?? 993,
                                'encryption' => $secondaryAccount['imap_encryption'] ?? 'ssl',
                            ];
                        } else {
                            logMsg("WARN {$scheduleId}: OAuth token unavailable for secondary account {$secondaryAccount['email']}, falling back to primary", $logFile, $verbose);
                            $smtp = new SmtpService($config['smtp']);
                            if ($oauthToken) {
                                $smtp->setOAuthCredentials($userEmail, $oauthToken, $oauthProvider ?? 'google');
                            } else {
                                $smtp->setCredentials($userEmail, $smtpPassword);
                            }
                            $actualSenderEmail = $userEmail;
                        }
                    } else {
                        if (!empty($secondaryAccount['password'])) {
                            $smtp->setCredentials($secondaryAccount['email'], $secondaryAccount['password']);
                            $secondaryImapPassword = $secondaryAccount['password'];
                            $secondaryImapConfig = [
                                'host' => $secondaryAccount['imap_host'] ?? ($config['imap']['host'] ?? 'localhost'),
                                'port' => $secondaryAccount['imap_port'] ?? 993,
                                'encryption' => $secondaryAccount['imap_encryption'] ?? 'ssl',
                            ];
                        } else {
                            logMsg("WARN {$scheduleId}: No password for secondary account, falling back to primary", $logFile, $verbose);
                            $smtp = new SmtpService($config['smtp']);
                            if ($oauthToken) {
                                $smtp->setOAuthCredentials($userEmail, $oauthToken, $oauthProvider ?? 'google');
                            } else {
                                $smtp->setCredentials($userEmail, $smtpPassword);
                            }
                            $actualSenderEmail = $userEmail;
                        }
                    }
                    
                    logMsg("Sending from secondary account: {$actualSenderEmail} (account_id: {$fromAccountId})", $logFile, $verbose);
                } else {
                    logMsg("WARN {$scheduleId}: Secondary account {$fromAccountId} not found, sending from primary", $logFile, $verbose);
                    $smtp = new SmtpService($config['smtp']);
                    if ($oauthToken) {
                        $smtp->setOAuthCredentials($userEmail, $oauthToken, $oauthProvider ?? 'google');
                    } else {
                        $smtp->setCredentials($userEmail, $smtpPassword);
                    }
                }
            } else {
                // Primary account
                $smtp = new SmtpService($config['smtp']);
                if ($oauthToken) {
                    $smtp->setOAuthCredentials($userEmail, $oauthToken, $oauthProvider ?? 'google');
                } else {
                    $smtp->setCredentials($userEmail, $smtpPassword);
                }
            }
            
            // Prepare recipients
            $to = $payload['to'] ?? [];
            $cc = $payload['cc'] ?? [];
            $bcc = $payload['bcc'] ?? [];
            $allRecipients = array_merge($to, $cc, $bcc);
            
            $bodyHtml = $payload['body_html'] ?? '';
            $subject = $payload['subject'] ?? '';
            $fromName = $payload['from_name'] ?? '';
            // High-priority flag, preserved from the original compose payload.
            $importance = !empty($payload['important']) ? 'high' : null;
            $attachments = $payload['attachments'] ?? [];
            
            // Validate attachment files still exist on disk
            $validAttachments = [];
            $missingAttachments = [];
            foreach ($attachments as $att) {
                if (!empty($att['path']) && file_exists($att['path'])) {
                    $validAttachments[] = $att;
                    logMsg("Attachment OK: {$att['name']} (" . filesize($att['path']) . " bytes)", $logFile, $verbose);
                } else {
                    $missingAttachments[] = $att['name'] ?? 'unknown';
                    logMsg("WARN {$scheduleId}: Attachment file MISSING: " . ($att['path'] ?? 'no path') . " ({$att['name']})", $logFile, $verbose);
                }
            }
            if (!empty($missingAttachments)) {
                logMsg("WARN {$scheduleId}: " . count($missingAttachments) . " attachment(s) missing: " . implode(', ', $missingAttachments), $logFile, $verbose);
            }
            $attachments = $validAttachments;
            $inReplyTo = $payload['in_reply_to'] ?? null;
            if (is_array($inReplyTo)) {
                $inReplyTo = $inReplyTo[0] ?? null;
            }
            $inReplyTo = $inReplyTo ? (string)$inReplyTo : null;

            $references = $payload['references'] ?? null;
            if (is_array($references)) {
                $references = implode(' ', $references);
            }
            $references = $references ? (string)$references : null;
            
            // Generate shared Message-ID
            $sharedMessageId = '<' . bin2hex(random_bytes(16)) . '@' . (gethostname() ?: 'webmail') . '>';
            
            // Optional: tracking
            $baseUrl = $payload['_base_url'] ?? '';
            $recipientTokens = [];
            $enableTracking = $payload['track_read'] ?? true;
            
            if ($enableTracking && !empty($bodyHtml) && !empty($baseUrl)) {
                try {
                    $trackingService = new TrackingService($config);
                    $trackingId = $trackingService->generateTrackingId();
                    $recipientTokens = $trackingService->createTracking(
                        $userEmail,
                        $trackingId,
                        $subject,
                        $allRecipients
                    );
                } catch (\Exception $e) {
                    error_log("Tracking error for scheduled email: " . $e->getMessage());
                    $enableTracking = false;
                }
            }
            
            // Build display strings so each recipient sees who else got the email
            $buildRecipientStr = function(array $recipients): string {
                return implode(', ', array_map(function($r) {
                    $email = is_array($r) ? ($r['email'] ?? '') : $r;
                    $name = is_array($r) ? ($r['name'] ?? '') : '';
                    return $name ? "{$name} <{$email}>" : $email;
                }, $recipients));
            };
            
            $displayTo = $buildRecipientStr($to);
            $displayCc = $buildRecipientStr($cc);

            // Build the real (visible) To/Cc header lists for individual
            // delivery. Each recipient gets their own copy delivered only to
            // them, but these headers carry the full recipient list so everyone
            // sees who else got the email and "reply all" keeps the Cc. Dedupe
            // within each list, then across lists (To wins). Bcc is excluded.
            $emailOf = function($r): string {
                $email = is_array($r) ? ($r['email'] ?? '') : (string)$r;
                return strtolower(trim($email));
            };
            $dedupeRecipients = function(array $recipients) use ($emailOf): array {
                $seen = [];
                $out = [];
                foreach ($recipients as $r) {
                    $e = $emailOf($r);
                    if ($e === '' || isset($seen[$e])) continue;
                    $seen[$e] = true;
                    $out[] = $r;
                }
                return $out;
            };
            $headerTo = $dedupeRecipients($to);
            $headerCc = $dedupeRecipients($cc);
            $headerToEmails = [];
            foreach ($headerTo as $r) { $headerToEmails[$emailOf($r)] = true; }
            $headerCc = array_values(array_filter($headerCc, function($r) use ($emailOf, $headerToEmails) {
                return !isset($headerToEmails[$emailOf($r)]);
            }));

            $sentCount = 0;
            $firstRawMessage = null;
            
            // Send individual emails per recipient for accurate per-recipient
            // read tracking. Each gets a UNIQUE Message-ID to prevent receiving
            // servers (Gmail, Outlook) from de-duplicating and dropping emails.
            foreach ($allRecipients as $recipient) {
                $recipientEmail = is_array($recipient) ? ($recipient['email'] ?? '') : $recipient;
                $recipientName = is_array($recipient) ? ($recipient['name'] ?? '') : '';
                
                if (empty($recipientEmail)) continue;
                
                // Unique Message-ID per send
                $individualMessageId = '<' . bin2hex(random_bytes(16)) . '@' . (gethostname() ?: 'webmail') . '>';
                
                // Add tracking pixel if enabled
                $recipientBody = $bodyHtml;
                if ($enableTracking && !empty($recipientTokens)) {
                    $token = $recipientTokens[strtolower($recipientEmail)] ?? null;
                    if ($token && !empty($baseUrl)) {
                        $pixel = '<img src="' . $baseUrl . '/api/track/' . $token . '.gif" width="1" height="1" style="display:none" alt="" />';
                        if (stripos($recipientBody, '</body>') !== false) {
                            $recipientBody = str_ireplace('</body>', $pixel . '</body>', $recipientBody);
                        } else {
                            $recipientBody .= $pixel;
                        }
                    }
                }
                
                $params = [
                    // Visible headers list every To/Cc recipient; delivery is
                    // restricted to this single envelope recipient (SmtpService).
                    'header_to' => $headerTo,
                    'header_cc' => $headerCc,
                    'envelope_to' => ['email' => $recipientEmail, 'name' => $recipientName],
                    'subject' => $subject,
                    'body_html' => $recipientBody,
                    'body_text' => $payload['body_text'] ?? '',
                    'from_name' => $fromName,
                    'message_id' => $individualMessageId,
                    'in_reply_to' => $inReplyTo,
                    'references' => $references,
                    'attachments' => $attachments,
                    'display_to' => $displayTo,
                    'display_cc' => $displayCc,
                    'importance' => $importance,
                ];
                
                $result = $smtp->send($params);
                
                if ($result['success']) {
                    $sentCount++;
                    if (!$firstRawMessage) {
                        $firstRawMessage = $result['raw_message'] ?? null;
                    }
                    logMsg("OK -> {$recipientEmail}", $logFile, $verbose);
                } else {
                    $smtpError = $result['error'] ?? 'Unknown SMTP error';
                    logMsg("SMTP FAIL -> {$recipientEmail}: {$smtpError}", $logFile, $verbose);
                    error_log("Scheduled email: failed to send to {$recipientEmail}: {$smtpError}");
                }
            }
            
            if ($sentCount === 0) {
                $attachInfo = count($attachments) > 0
                    ? ' (with ' . count($attachments) . ' attachment(s), total ' . array_sum(array_map(fn($a) => $a['size'] ?? 0, $attachments)) . ' bytes)'
                    : ' (no attachments)';
                throw new \Exception("Failed to send to any recipient{$attachInfo}. Last SMTP error: " . ($smtpError ?? 'none captured'));
            }
            
            // Build a clean copy for the Sent folder (without tracking pixel)
            $sentMessage = null;
            try {
                $sentSmtp = new SmtpService($config['smtp']);
                $sentSmtp->setCredentials($actualSenderEmail, $smtpPassword ?? 'dummy');
                $sentMessage = $sentSmtp->buildDraftMessage([
                    'to' => $to,
                    'cc' => $cc,
                    'bcc' => $bcc,
                    'subject' => $subject,
                    'body_html' => $bodyHtml, // Original body without tracking pixels
                    'body_text' => $payload['body_text'] ?? '',
                    'from_name' => $fromName,
                    'message_id' => $sharedMessageId,
                    'in_reply_to' => $inReplyTo,
                    'references' => $references,
                    'attachments' => $attachments,
                    'importance' => $importance,
                ]);
            } catch (\Exception $e) {
                logMsg("WARN {$scheduleId}: Could not build master sent message: " . $e->getMessage(), $logFile, $verbose);
            }
            
            // Use master message if available, otherwise fall back to first raw message
            $messageToSave = $sentMessage ?: $firstRawMessage;
            
            // Save to Sent folder via IMAP
            if ($messageToSave) {
                try {
                    $imap = new \Webmail\Services\ImapService($config['imap'] ?? []);
                    $imapConnected = false;
                    
                    if ($secondaryImapConfig && $secondaryOAuthToken) {
                        // Secondary OAuth account - connect to its IMAP with OAuth
                        $secondaryImap = new \Webmail\Services\ImapService($secondaryImapConfig);
                        $imapConnected = $secondaryImap->connectWithOAuth($actualSenderEmail, $secondaryOAuthToken);
                        if ($imapConnected) $imap = $secondaryImap;
                    } elseif ($secondaryImapConfig && $secondaryImapPassword) {
                        // Secondary password account - connect to its IMAP
                        $secondaryImap = new \Webmail\Services\ImapService($secondaryImapConfig);
                        $imapConnected = $secondaryImap->connect($actualSenderEmail, $secondaryImapPassword);
                        if ($imapConnected) $imap = $secondaryImap;
                    }
                    
                    // Fall back to primary account IMAP
                    if (!$imapConnected) {
                        if ($smtpPassword) {
                            $imapConnected = $imap->connect($userEmail, $smtpPassword);
                        } elseif ($oauthToken) {
                            $imapConnected = $imap->connectWithOAuth($userEmail, $oauthToken);
                        }
                    }
                    
                    if ($imapConnected) {
                        // Find the Sent folder
                        $sentFolder = 'Sent';
                        $folders = $imap->listFolders();
                        foreach ($folders as $f) {
                            // Check by type first (IMAP special-use attribute)
                            $type = is_array($f) ? ($f['type'] ?? '') : '';
                            if ($type === 'sent') {
                                $sentFolder = is_array($f) ? $f['name'] : (string)$f;
                                break;
                            }
                            // Fall back to name matching
                            $name = is_array($f) ? ($f['name'] ?? '') : (string)$f;
                            if (in_array(strtolower($name), ['sent', 'sent items', 'sent messages'])) {
                                $sentFolder = $name;
                                break;
                            }
                        }
                        
                        $saveResult = $imap->saveToSent($messageToSave, $sentFolder);
                        if ($saveResult) {
                            logMsg("Saved to Sent folder '{$sentFolder}' for {$actualSenderEmail}", $logFile, $verbose);
                        } else {
                            logMsg("WARN {$scheduleId}: saveToSent returned false for folder '{$sentFolder}'", $logFile, $verbose);
                        }
                    } else {
                        logMsg("WARN {$scheduleId}: IMAP connect failed, skipping Sent folder save", $logFile, $verbose);
                    }
                } catch (\Exception $e) {
                    logMsg("WARN {$scheduleId}: Failed to save to Sent: " . $e->getMessage(), $logFile, $verbose);
                }
            } else {
                logMsg("WARN {$scheduleId}: No message content available to save to Sent folder", $logFile, $verbose);
            }
            
            $scheduledService->markSent($email['id']);
            $sent++;
            logMsg("SENT {$scheduleId}: {$sentCount} recipient(s)", $logFile, $verbose);
            cleanupScheduledAttachments($attachments, $logFile, $verbose);
            
        } catch (\Throwable $e) {
            $scheduledService->markFailed($email['id'], $e->getMessage());
            $failed++;
            logMsg("FAILED {$scheduleId}: " . get_class($e) . ': ' . $e->getMessage(), $logFile, $verbose);
            logMsg("  at " . $e->getFile() . ':' . $e->getLine(), $logFile, $verbose);
        }
    }
    
    logMsg("Complete: sent={$sent}, failed={$failed}", $logFile, $verbose);
    
    // Cleanup if requested
    if ($doCleanup) {
        $cleaned = $scheduledService->cleanup();
        if ($cleaned > 0) {
            logMsg("Cleaned up {$cleaned} old records", $logFile, $verbose);
        }
    }
    
    exit($failed > 0 && $sent === 0 ? 1 : 0);
    
} catch (\Throwable $e) {
    $error = "FATAL: " . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    logMsg($error, $logFile, $verbose);
    exit(1);
}

