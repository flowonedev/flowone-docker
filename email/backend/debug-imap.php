<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    die("Config not found at: $configPath\n");
}
$config = require $configPath;

$imapHost = $config['imap']['host'] ?? 'localhost';
$imapPort = $config['imap']['port'] ?? 993;
$enc = $config['imap']['encryption'] ?? 'ssl';

$email = $argv[1] ?? '';
$pass = $argv[2] ?? '';
$targetUid = (int)($argv[3] ?? 0);

if (!$email || !$pass) {
    die("Usage: php debug-imap.php <email> <password> [uid]\n");
}

$mailbox = "{{$imapHost}:{$imapPort}/imap/{$enc}}";
echo "Connecting to: {$mailbox}\n";

$conn = @imap_open($mailbox . 'INBOX', $email, $pass);
if (!$conn) {
    die("IMAP FAILED: " . implode(', ', imap_errors() ?: []) . "\n");
}

$check = imap_check($conn);
echo "INBOX has {$check->Nmsgs} messages\n\n";

// List all UIDs
$overview = @imap_fetch_overview($conn, '1:*', 0);
if ($overview) {
    echo "=== Messages in INBOX ===\n";
    foreach ($overview as $m) {
        $subj = isset($m->subject) ? @iconv_mime_decode($m->subject, 0, 'UTF-8') : '(no subject)';
        echo "  UID {$m->uid}: {$subj}\n";
    }
} else {
    echo "No messages found in INBOX\n";
}

// List folders
$folders = imap_list($conn, $mailbox, '*');
echo "\n=== All Folders ===\n";
if ($folders) {
    foreach ($folders as $f) {
        $name = str_replace($mailbox, '', $f);
        $sub = @imap_open($mailbox . $name, $email, $pass);
        if ($sub) {
            $c = imap_check($sub);
            echo "  {$name} ({$c->Nmsgs} messages)\n";
            imap_close($sub);
        } else {
            echo "  {$name} (cannot open)\n";
        }
    }
}

// If target UID specified, try to find it in all folders
if ($targetUid) {
    echo "\n=== Searching for UID {$targetUid} ===\n";
    if ($folders) {
        foreach ($folders as $f) {
            $name = str_replace($mailbox, '', $f);
            $sub = @imap_open($mailbox . $name, $email, $pass);
            if ($sub) {
                $msgno = @imap_msgno($sub, $targetUid);
                if ($msgno > 0) {
                    $struct = imap_fetchstructure($sub, $targetUid, FT_UID);
                    $header = imap_headerinfo($sub, $msgno);
                    $subj = isset($header->subject) ? @iconv_mime_decode($header->subject, 0, 'UTF-8') : '';
                    echo "  FOUND in [{$name}] msgno={$msgno}\n";
                    echo "  Subject: {$subj}\n";
                    echo "  MIME Structure:\n";
                    printStructure($struct, '    ');
                }
                imap_close($sub);
            }
        }
    }
}

imap_close($conn);

function printStructure($part, $indent = '', $partNum = '') {
    $types = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER'];
    $type = $types[$part->type] ?? 'UNKNOWN';
    $sub = strtolower($part->subtype ?? '?');
    $bytes = $part->bytes ?? '?';
    $label = $partNum ?: 'ROOT';
    echo "{$indent}[{$label}] {$type}/{$sub} ({$bytes} bytes)";
    if (isset($part->disposition)) echo " disposition={$part->disposition}";
    if (isset($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'name' || strtolower($p->attribute) === 'charset') {
                echo " {$p->attribute}={$p->value}";
            }
        }
    }
    echo "\n";
    if (isset($part->parts) && $part->parts) {
        foreach ($part->parts as $i => $child) {
            $childPart = $partNum ? "$partNum." . ($i + 1) : (string)($i + 1);
            printStructure($child, $indent . '  ', $childPart);
        }
    }
}
