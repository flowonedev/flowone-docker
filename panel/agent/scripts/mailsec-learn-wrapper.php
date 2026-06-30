#!/usr/bin/env php
<?php
/**
 * DEVCON Mail Security - IMAPSieve learn wrapper.
 *
 * Invoked by Dovecot's sieve_extprograms (sieve_pipe) whenever a user moves a
 * message INTO or OUT OF their Spam/Junk folder via any IMAP client (Outlook,
 * Apple Mail, Thunderbird, etc.). Feeds the message to Rspamd's Bayes
 * classifier (learn_spam / learn_ham) and drops a JSON event into the panel's
 * learn-events spool so the dashboard can show what users are training.
 *
 * Runs as the vmail user inside the user's IMAP session. It MUST always exit
 * zero: any failure here would surface to the user as a failed Junk-drag in
 * their mail client. All errors are swallowed and logged via the event record.
 *
 * Argv:
 *   $1  direction  "spam" | "ham"
 *   $2  username   the mailbox owner (passed by the sieve script from imap.user)
 * Stdin:
 *   the raw RFC822 message (capped at 50 MB; larger payloads are truncated by
 *   Dovecot's sieve_pipe anyway)
 *
 * Spool:
 *   /var/spool/devcon-mailsec/learn-events/<unixts>-<rand>.json
 *
 * Per-user opt-out:
 *   /etc/devcon-mailsec/learn-optouts.txt (one lowercase email per line)
 *   - Refreshed every minute by the panel's event-sync ingester from
 *     webmail_spam_settings.auto_training_enabled, so toggling the existing
 *     webmail "Spam Filter Training" switch silences IMAP feedback too.
 */

if (php_sapi_name() !== 'cli') {
    exit(0);
}

const SPOOL_DIR  = '/var/spool/devcon-mailsec/learn-events';
const OPTOUT_FILE = '/etc/devcon-mailsec/learn-optouts.txt';
const RSPAMC     = '/usr/bin/rspamc';
const CONTROLLER = '127.0.0.1:11334';
const MAX_BODY   = 50 * 1024 * 1024;

$direction = strtolower((string)($argv[1] ?? ''));
$username  = strtolower(trim((string)($argv[2] ?? '')));
if (!in_array($direction, ['spam', 'ham'], true)) {
    exit(0);
}

$rawMessage = stream_get_contents(STDIN, MAX_BODY);
if (!is_string($rawMessage) || $rawMessage === '') {
    exit(0);
}

// Respect the per-user "auto_training_enabled" toggle. The ingester rewrites
// this file from MailFlow's webmail_spam_settings so the existing webmail
// setting now governs IMAP feedback too. We never block delivery on a missing
// file - if it cannot be read, default to "training enabled" (the same default
// the webmail path uses for users with no settings row).
$optedOut = false;
if ($username !== '' && is_readable(OPTOUT_FILE)) {
    $list = @file(OPTOUT_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($list)) {
        foreach ($list as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line === $username) {
                $optedOut = true;
                break;
            }
        }
    }
}

// Feed Rspamd unless the user opted out. We still record an event so the panel
// can show "this user moved a message but training was skipped".
$rspamcRc = -1;
if (!$optedOut) {
    $rspamcRc = feedRspamd($rawMessage, $direction);
}

[$sender, $msgId] = parseHeaders($rawMessage);

@mkdir(SPOOL_DIR, 0775, true);
$ts   = time();
$base = sprintf('%d-%06d', $ts, random_int(0, 999999));
$tmp  = SPOOL_DIR . '/.' . $base . '.json.tmp';
$dst  = SPOOL_DIR . '/' . $base . '.json';

$event = [
    'ts'         => $ts,
    'direction'  => $direction,
    'user'       => mb_substr($username, 0, 320),
    'sender'     => mb_substr(strtolower(trim($sender)), 0, 320),
    'message_id' => mb_substr(trim($msgId), 0, 255),
    'source'     => 'imapsieve',
    'rspamc_rc'  => $rspamcRc,
    'opted_out'  => $optedOut,
];

$encoded = json_encode($event, JSON_UNESCAPED_SLASHES);
if ($encoded !== false && @file_put_contents($tmp, $encoded . "\n") !== false) {
    @chmod($tmp, 0640);
    @rename($tmp, $dst);
}

exit(0);

// ---------------------------------------------------------------------------

/**
 * Pipe the message to rspamc and return its exit code. Returns -1 if the
 * process could not even be started so the ingester can tell "Rspamd was
 * unreachable" apart from "Rspamd said no".
 */
function feedRspamd(string $message, string $direction): int
{
    if (!file_exists(RSPAMC)) {
        return -1;
    }
    $cmd = sprintf(
        '%s -h %s -t 10 %s',
        escapeshellcmd(RSPAMC),
        escapeshellarg(CONTROLLER),
        $direction === 'spam' ? 'learn_spam' : 'learn_ham'
    );
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return -1;
    }
    fwrite($pipes[0], $message);
    fclose($pipes[0]);
    @stream_get_contents($pipes[1]);
    @stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

/**
 * Pull From: and Message-ID: out of the first 64 KB of headers. Tolerant of
 * bare LF as well as CRLF (some IMAP clients submit one style or the other).
 * Returns [sender, messageId] with empty strings for misses.
 */
function parseHeaders(string $raw): array
{
    $block = substr($raw, 0, 65536);
    $end = strpos($block, "\r\n\r\n");
    if ($end === false) {
        $end = strpos($block, "\n\n");
    }
    if ($end !== false) {
        $block = substr($block, 0, $end);
    }

    $sender = '';
    $msgId  = '';
    foreach (preg_split('/\r?\n/', $block) as $line) {
        if ($sender === '' && stripos($line, 'from:') === 0) {
            $val = trim(substr($line, 5));
            if (preg_match('/<([^>]+)>/', $val, $m)) {
                $val = $m[1];
            }
            $sender = $val;
        } elseif ($msgId === '' && stripos($line, 'message-id:') === 0) {
            $val = trim(substr($line, 11));
            if (preg_match('/<([^>]+)>/', $val, $m)) {
                $val = $m[1];
            }
            $msgId = $val;
        }
        if ($sender !== '' && $msgId !== '') {
            break;
        }
    }
    return [$sender, $msgId];
}
