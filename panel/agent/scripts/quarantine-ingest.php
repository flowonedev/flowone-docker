#!/usr/bin/env php
<?php
/**
 * Postfix pipe transport for DEVCON Mail Security quarantine.
 *
 * Stores the raw message in the quarantine spool and indexes it in
 * mail_quarantine. Only invoked when Postfix routes to the
 * devcon-quarantine transport (not wired into default delivery until canary).
 *
 * Usage (Postfix master.cf):
 *   argv=.../quarantine-ingest.php ${recipient}
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

const SPOOL_DIR = '/var/spool/devcon-mailsec/quarantine';

$raw = stream_get_contents(STDIN);
if ($raw === false || $raw === '') {
    exit(75); // EX_TEMPFAIL
}

$envelopeRecipient = isset($argv[1]) ? strtolower(trim($argv[1])) : null;

try {
    $pdo = connectPanelDb();
    if (!$pdo) {
        fwrite(STDERR, "quarantine-ingest: database unavailable\n");
        exit(75);
    }

    if (!is_dir(SPOOL_DIR) && !@mkdir(SPOOL_DIR, 0750, true)) {
        fwrite(STDERR, "quarantine-ingest: cannot create spool dir\n");
        exit(75);
    }

    $id = bin2hex(random_bytes(16));
    $spoolPath = SPOOL_DIR . '/' . $id . '.eml';

    if (file_put_contents($spoolPath, $raw, LOCK_EX) === false) {
        exit(75);
    }
    @chmod($spoolPath, 0640);

    $sender = extractHeader($raw, 'From') ?? '';
    $recipient = $envelopeRecipient ?: (extractHeader($raw, 'To') ?? '');
    $subject = extractHeader($raw, 'Subject') ?? '';
    $messageId = extractHeader($raw, 'Message-ID');
    $reason = extractHeader($raw, 'X-Devcon-Quarantine-Reason')
        ?? extractHeader($raw, 'X-Rspamd-Action')
        ?? 'quarantine';
    $score = parseScore($raw);

    $headersBlob = substr($raw, 0, min(8192, strpos($raw, "\r\n\r\n") ?: 8192));

    $stmt = $pdo->prepare(
        'INSERT INTO mail_quarantine
            (message_id, sender, recipient, subject, spam_score, reason, headers, spool_path, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $messageId,
        truncate($sender, 320),
        truncate($recipient, 320),
        truncate($subject, 998),
        $score,
        truncate($reason, 255),
        $headersBlob,
        $spoolPath,
        'quarantined',
    ]);

    $domain = null;
    if ($recipient !== '' && str_contains($recipient, '@')) {
        $domain = substr($recipient, strrpos($recipient, '@') + 1);
    }

    $evt = $pdo->prepare(
        'INSERT INTO mail_security_events (event_type, sender, recipient, domain, score, symbol)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $evt->execute([
        'quarantine',
        truncate(stripAngleAddr($sender), 320),
        truncate(stripAngleAddr($recipient), 320),
        $domain ? truncate($domain, 255) : null,
        $score,
        truncate($reason, 255),
    ]);

    exit(0);
} catch (Throwable $e) {
    if (isset($spoolPath) && file_exists($spoolPath)) {
        @unlink($spoolPath);
    }
    fwrite(STDERR, 'quarantine-ingest: ' . $e->getMessage() . "\n");
    exit(75);
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

function extractHeader(string $raw, string $name): ?string
{
    $pattern = '/^' . preg_quote($name, '/') . ':\s*(.+?)(?:\r?\n(?![\t ]|\r?\n)|$)/mis';
    if (preg_match($pattern, $raw, $m)) {
        return trim(preg_replace('/\r?\n[\t ]+/', ' ', $m[1]) ?? $m[1]);
    }
    return null;
}

function parseScore(string $raw): ?float
{
    foreach (['X-Rspamd-Score', 'X-Spam-Score', 'X-Spamd-Result'] as $h) {
        $v = extractHeader($raw, $h);
        if ($v !== null && preg_match('/([0-9]+(?:\.[0-9]+)?)/', $v, $m)) {
            return (float)$m[1];
        }
    }
    return null;
}

function stripAngleAddr(string $s): string
{
    if (preg_match('/<([^>]+)>/', $s, $m)) {
        return strtolower(trim($m[1]));
    }
    return strtolower(trim($s));
}

function truncate(?string $s, int $max): ?string
{
    if ($s === null) {
        return null;
    }
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
}
