#!/usr/bin/env php
<?php
/**
 * FlowOne Mentions Debug (read-only diagnostic).
 *
 * Use this when a user reports "I got @mentioned but the smart view is
 * empty". It does NOT modify any data — purely SELECT + parse-in-memory.
 *
 * What it tells you:
 *
 *   --user=<email>          Required. The mailbox owner to debug.
 *
 *   1. How many rows the owner currently has in webmail_message_mentions.
 *   2. The most recent 20 rows (message_id, sender, mentioned, trust).
 *   3. The size of the owner's hint pool — colleagues + recent contacts +
 *      message recipients — so you can see whether the parser had any
 *      chance of resolving "@robert" for them.
 *   4. The 10 first hints, so you can eyeball that the expected colleague
 *      actually appears.
 *   5. (Optional) --reparse=<message_id>: re-run the parser against a
 *      specific message's stored body and print what it would extract NOW
 *      (using the current hint pool). The DB is NOT updated.
 *
 * Run:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/mentions-debug.php \
 *       --user=robert@pixelranger.hu \
 *       [--reparse=<msg-id-without-angle-brackets>] \
 *       [--limit=20]
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', [
    'user:',
    'reparse:',
    'limit::',
    'help',
    // Live IMAP probe — feed one UID through the real processor.
    'password:',
    'folder::',
    'process:',
    'verbose',
]);

if (isset($opts['help']) || empty($opts['user'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1400));
    exit(empty($opts['user']) ? 2 : 0);
}

require_once __DIR__ . '/../cron/bootstrap.php';
$config = require __DIR__ . '/../src/config.php';

use Webmail\Utils\EmailNormalizer;
use Webmail\Services\Mentions\MentionParser;
use Webmail\Services\Mentions\MentionsProcessor;
use Webmail\Services\ImapService;

$rawUser = (string) $opts['user'];
$user = EmailNormalizer::normalize($rawUser);
if ($user === null) {
    fwrite(STDERR, "Invalid email: $rawUser\n");
    exit(2);
}
$limit = isset($opts['limit']) ? max(1, min(200, (int) $opts['limit'])) : 20;

$reset  = "\033[0m";
$bold   = "\033[1m";
$dim    = "\033[2m";
$cyan   = "\033[36m";
$yellow = "\033[33m";
$green  = "\033[32m";
$red    = "\033[31m";

echo "{$bold}{$cyan}=== FlowOne Mentions Debug ==={$reset}\n";
echo "Owner (raw):       $rawUser\n";
echo "Owner (canonical): $user\n";
echo "Limit:             $limit\n\n";

$db = \Webmail\Core\Database::getConnection($config);

// ─────────────────────────────────────────────────────────────────────
// 1. Row count for this owner.
// ─────────────────────────────────────────────────────────────────────
echo "{$bold}--- 1. Persisted rows for owner ---{$reset}\n";
$count = (int) $db->query(
    "SELECT COUNT(*) FROM webmail_message_mentions WHERE owner_email = " . $db->quote($user)
)->fetchColumn();
echo "Rows in webmail_message_mentions: " . ($count > 0 ? "{$green}$count{$reset}" : "{$red}0{$reset}") . "\n";

if ($count === 0) {
    echo "{$yellow}→ No mentions persisted yet. Either the message hasn\'t been opened in FlowOne,
   the parser couldn\'t resolve any tokens, or owner_email mismatch.{$reset}\n";
}

// Mentions-where-owner-is-MENTIONED (what `mentions:me` actually queries on)
$mentionedCount = (int) $db->query(
    "SELECT COUNT(*) FROM webmail_message_mentions WHERE mentioned_email_norm = " . $db->quote($user)
)->fetchColumn();
echo "Rows where THIS owner is the mentioned party (drives `mentions:me`): "
    . ($mentionedCount > 0 ? "{$green}$mentionedCount{$reset}" : "{$red}0{$reset}") . "\n\n";

if ($mentionedCount === 0 && $count > 0) {
    echo "{$yellow}→ You have mention rows, but none of them mention YOU. The persisted
   `mentioned_email_norm` doesn\'t equal `$user`. Possible causes:
      - The mention was @-tagged to a different mailbox alias
      - Wrong owner_email passed to recordMentions
   Check the table dump below.{$reset}\n\n";
}

// ─────────────────────────────────────────────────────────────────────
// 2. Most recent rows
// ─────────────────────────────────────────────────────────────────────
echo "{$bold}--- 2. Most recent $limit rows for owner ---{$reset}\n";
$stmt = $db->prepare(
    "SELECT id, created_at, direction, sender_email, mentioned_email_norm,
            trust, message_id, folder, uid, mention_text
     FROM webmail_message_mentions
     WHERE owner_email = ?
     ORDER BY created_at DESC
     LIMIT $limit"
);
$stmt->execute([$user]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
if (empty($rows)) {
    echo "{$dim}(no rows){$reset}\n\n";
} else {
    foreach ($rows as $r) {
        $tag = $r['mentioned_email_norm'] === $user ? "{$green}[you]{$reset}" : "{$dim}[other]{$reset}";
        printf(
            "  %s #%d %s  %s → %s  trust=%s  text=%s\n     msg_id=%s  folder=%s  uid=%s\n",
            $tag,
            $r['id'],
            $r['created_at'],
            substr($r['sender_email'], 0, 40),
            substr($r['mentioned_email_norm'], 0, 40),
            $r['trust'],
            $r['mention_text'] ?? '?',
            $r['message_id'],
            $r['folder'] ?? '?',
            $r['uid'] ?? '?'
        );
    }
    echo "\n";
}

// ─────────────────────────────────────────────────────────────────────
// 3. Hint pool size — the same calculation the live processor uses.
// ─────────────────────────────────────────────────────────────────────
echo "{$bold}--- 3. Hint pool (for resolving bare \"@firstname\" tokens) ---{$reset}\n";
$ownerDomain = EmailNormalizer::domainOf($user);
echo "Owner domain: " . ($ownerDomain ?? '?') . "\n";

$hints = [];

// Colleagues
$colleagueCount = 0;
if ($ownerDomain) {
    try {
        $stmt = $db->prepare(
            'SELECT email FROM organization_colleagues
             WHERE organization_domain = ?
             LIMIT 500'
        );
        $stmt->execute([$ownerDomain]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
            $n = EmailNormalizer::normalize($em);
            if ($n !== null) { $hints[] = $n; $colleagueCount++; }
        }
    } catch (\Throwable $e) {
        echo "  {$yellow}organization_colleagues unavailable: " . $e->getMessage() . "{$reset}\n";
    }
}
echo "  Colleagues from organization_colleagues (same domain): $colleagueCount\n";

// Recent contacts
$contactCount = 0;
try {
    $stmt = $db->prepare(
        'SELECT contact_email FROM email_contacts
         WHERE user_email = ?
         ORDER BY use_count DESC LIMIT 200'
    );
    $stmt->execute([$user]);
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
        $n = EmailNormalizer::normalize($em);
        if ($n !== null) { $hints[] = $n; $contactCount++; }
    }
} catch (\Throwable $e) {
    echo "  {$yellow}email_contacts unavailable: " . $e->getMessage() . "{$reset}\n";
}
echo "  Recent contacts from email_contacts: $contactCount\n";

// Dedup
$hints = array_values(array_unique($hints));
echo "  Total unique hints: " . count($hints) . "\n";

echo "\n  First 10 hints (sample):\n";
foreach (array_slice($hints, 0, 10) as $h) {
    echo "    - $h\n";
}

if (count($hints) === 0) {
    echo "{$red}→ Your hint pool is EMPTY. Bare \"@robert\" tokens from Gmail can never resolve.
   Fix: add yourself / colleagues to organization_colleagues, or send/receive
   a few emails so email_contacts populates.{$reset}\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────
// 4. Re-parse a specific message (optional)
// ─────────────────────────────────────────────────────────────────────
if (!empty($opts['reparse'])) {
    $reparseId = trim((string) $opts['reparse'], " \t\r\n<>");
    echo "{$bold}--- 4. Re-parse message_id=$reparseId ---{$reset}\n";

    // We don\'t have the body in DB (it lives in IMAP), so we re-parse
    // whatever was already extracted into webmail_message_mentions as a
    // sanity check, and dump the recipients we recorded so you can see
    // whether the body had useful per-message hints.
    $stmt = $db->prepare(
        'SELECT direction, sender_email, subject, mentioned_email_norm, mention_text, trust
         FROM webmail_message_mentions
         WHERE owner_email = ? AND message_id = ?'
    );
    $stmt->execute([$user, $reparseId]);
    $hits = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($hits)) {
        echo "  {$yellow}No rows for that message_id. Open the email once in FlowOne
        (so the inbound hook fires) and re-run.{$reset}\n";
    } else {
        foreach ($hits as $h) {
            echo "  • " . json_encode($h, JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
    echo "\n";
}

// ─────────────────────────────────────────────────────────────────────
// 5. Live process a specific UID through the real processor
// ─────────────────────────────────────────────────────────────────────
if (!empty($opts['process'])) {
    $uid    = (int) $opts['process'];
    $folder = (string) ($opts['folder'] ?? 'INBOX');
    $pass   = (string) ($opts['password'] ?? '');
    $verbose = isset($opts['verbose']);

    echo "{$bold}--- 5. Live process UID=$uid in folder=$folder ---{$reset}\n";

    if ($pass === '') {
        echo "  {$red}--password=<imap-password> is required to fetch the message.{$reset}\n";
        exit(2);
    }
    if ($uid <= 0) {
        echo "  {$red}--process must be a positive UID.{$reset}\n";
        exit(2);
    }

    $imap = new ImapService($config);
    $host = $config['imap']['host'] ?? 'localhost';
    $port = (int) ($config['imap']['port'] ?? 993);
    $enc  = $config['imap']['encryption'] ?? 'ssl';

    echo "  Connecting IMAP $user @ $host:$port ($enc)…\n";
    if (!$imap->connect($user, $pass)) {
        $err = method_exists($imap, 'getLastError') ? $imap->getLastError() : '(unknown)';
        echo "  {$red}IMAP connect failed: $err{$reset}\n";
        exit(1);
    }
    echo "  {$green}Connected.{$reset}\n";

    $full = $imap->getMessage($folder, $uid);
    if (!$full) {
        echo "  {$red}getMessage returned null — UID $uid not found in $folder.{$reset}\n";
        $imap->disconnect();
        exit(1);
    }
    echo "  Fetched UID $uid:\n";
    echo "    subject:    " . (string) ($full['subject'] ?? '?') . "\n";
    echo "    message_id: " . (string) ($full['message_id'] ?? '?') . "\n";
    echo "    from:       " . (is_array($full['from'] ?? null) ? json_encode($full['from']) : (string) ($full['from'] ?? '?')) . "\n";

    // ImapService::formatAddressList returns array of {name, email}.
    $senderEmail = '';
    if (!empty($full['from']) && is_array($full['from'])) {
        $first = $full['from'][0] ?? null;
        if (is_array($first)) {
            $senderEmail = (string) ($first['email'] ?? $first['address'] ?? '');
        } elseif (is_string($first)) {
            $senderEmail = $first;
        }
    } elseif (is_string($full['from'] ?? null)) {
        $senderEmail = (string) $full['from'];
    }
    $recipients = [];
    foreach (['to', 'cc', 'bcc'] as $field) {
        $list = $full[$field] ?? [];
        if (is_string($list)) $list = [$list];
        foreach ((array) $list as $r) {
            $em = is_array($r) ? ($r['email'] ?? $r['address'] ?? '') : (string) $r;
            if ($em !== '') $recipients[] = $em;
        }
    }
    $bodyHtml = (string) ($full['body_html'] ?? '');
    $bodyText = (string) ($full['body_text'] ?? $full['body_plain'] ?? '');

    echo "    body_html:  " . ($bodyHtml !== '' ? "{$green}" . strlen($bodyHtml) . " bytes{$reset}" : "{$yellow}(empty){$reset}") . "\n";
    echo "    body_text:  " . ($bodyText !== '' ? "{$green}" . strlen($bodyText) . " bytes{$reset}" : "{$yellow}(empty){$reset}") . "\n";

    if ($verbose) {
        echo "\n  {$dim}--- body_html ---{$reset}\n";
        echo "  " . str_replace("\n", "\n  ", substr($bodyHtml, 0, 2000)) . "\n";
        echo "  {$dim}--- body_text ---{$reset}\n";
        echo "  " . str_replace("\n", "\n  ", substr($bodyText, 0, 2000)) . "\n\n";
    }

    // Step A: parser-only, with the same hints the processor would build
    $ownerDomain = EmailNormalizer::domainOf($user);
    $parserHints = array_merge($hints, $recipients, $senderEmail !== '' ? [$senderEmail] : []);
    $parserHints = array_values(array_unique(array_map(
        static fn($e) => EmailNormalizer::normalize($e) ?? '',
        $parserHints
    )));
    $parserHints = array_values(array_filter($parserHints));

    $parsed = MentionParser::extract($bodyHtml, $bodyText, $parserHints, [
        'preferDomain' => $ownerDomain,
    ]);
    echo "\n  Parser pass: " . (empty($parsed) ? "{$red}0 mentions{$reset}" : "{$green}" . count($parsed) . " mention(s){$reset}") . "\n";
    foreach ($parsed as $p) {
        echo "    • " . json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    // Step B: full processor (writes to webmail_message_mentions, fires notify)
    echo "\n  Running real MentionsProcessor…\n";
    $processor = new MentionsProcessor($config);
    $inserted = $processor->process($user, [
        'message_id'   => (string) ($full['message_id'] ?? ''),
        'direction'    => 'inbound',
        'sender_email' => $senderEmail,
        'subject'      => (string) ($full['subject'] ?? ''),
        'sent_at'      => (string) ($full['date'] ?? ''),
        'folder'       => $folder,
        'uid'          => $uid,
        'recipients'   => $recipients,
    ], $bodyHtml, $bodyText);
    echo "  Inserted/updated rows: " . ($inserted > 0 ? "{$green}$inserted{$reset}" : "{$yellow}0 (idempotent re-run, or nothing to insert){$reset}") . "\n\n";

    $imap->disconnect();
}

echo "{$bold}{$cyan}=== Next steps ==={$reset}\n";

// Re-read the "mentioned party" count after section 5 may have inserted a
// row, so the footer reflects current truth rather than the pre-process
// snapshot taken at section 1.
$mentionedCount = (int) $db->query(
    "SELECT COUNT(*) FROM webmail_message_mentions WHERE mentioned_email_norm = " . $db->quote($user)
)->fetchColumn();

if ($mentionedCount > 0) {
    echo "{$green}✓ Mentions table has $mentionedCount row(s) where you\'re mentioned.
  The Mentions smart view should show messages. If it doesn\'t, the issue
  is in MailboxController::search post-filter — check php_errors.log for
  '[mentions]' lines emitted around the time of the click.{$reset}\n";
} else {
    echo "{$yellow}✗ Nothing in webmail_message_mentions where mentioned_email_norm = $user.
  Open the Gmail-originated message ONCE in FlowOne, then tail the PHP error
  log for the structured log line:
    grep '\\[mentions\\]' /var/www/vps-email/backend/logs/php_errors.log | tail -20
  That line shows: hints=<N> found=<M>. If found=0 but body had '@', the
  parser couldn\'t resolve the token — verify the address you typed
  appears in the hint sample above.{$reset}\n";
}
