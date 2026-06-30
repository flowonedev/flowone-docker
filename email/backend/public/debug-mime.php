<?php
/**
 * Standalone MIME structure debug tool
 * Upload to public/ and access directly: /debug-mime.php?folder=INBOX&uid=24
 * DELETE THIS FILE after debugging!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

$folder = $_GET['folder'] ?? 'INBOX';
$uid = (int)($_GET['uid'] ?? 0);

if (!$uid) {
    echo json_encode(['error' => 'Usage: ?folder=INBOX&uid=24']);
    exit;
}

// Load config
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    echo json_encode(['error' => 'Config not found']);
    exit;
}
$config = require $configPath;

$imapHost = $config['imap']['host'] ?? 'localhost';
$imapPort = $config['imap']['port'] ?? 993;
$imapEncryption = $config['imap']['encryption'] ?? 'ssl';

// Use robert@pixelranger.hu credentials from config or hardcode for debug
// We'll connect with a minimal IMAP test
$email = $_GET['email'] ?? '';
$password = $_GET['pass'] ?? '';

if (!$email || !$password) {
    echo json_encode([
        'error' => 'Provide ?email=user@domain&pass=password&folder=INBOX&uid=24',
        'note' => 'DELETE this file after debugging!'
    ]);
    exit;
}

$mailbox = "{{$imapHost}:{$imapPort}/imap/{$imapEncryption}}";
$conn = @imap_open($mailbox . $folder, $email, $password);

if (!$conn) {
    echo json_encode(['error' => 'IMAP connection failed', 'imap_errors' => imap_errors()]);
    exit;
}

// List all UIDs in folder
$check = imap_check($conn);
$totalMessages = $check->Nmsgs;

// Get structure
$structure = @imap_fetchstructure($conn, $uid, FT_UID);
$msgno = @imap_msgno($conn, $uid);

if (!$structure) {
    // List available UIDs
    $availableUids = [];
    if ($totalMessages > 0) {
        $overview = @imap_fetch_overview($conn, "1:{$totalMessages}", 0);
        if ($overview) {
            foreach ($overview as $msg) {
                $availableUids[] = $msg->uid;
            }
        }
    }
    
    // Also list subfolders
    $folders = imap_list($conn, $mailbox, '*');
    
    echo json_encode([
        'error' => "UID {$uid} not found in {$folder}",
        'total_messages' => $totalMessages,
        'available_uids' => $availableUids,
        'folders' => $folders ? array_map(fn($f) => str_replace($mailbox, '', $f), $folders) : [],
    ], JSON_PRETTY_PRINT);
    imap_close($conn);
    exit;
}

// Build MIME tree
$mimeTypes = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER'];
$encodings = ['7BIT', 'BINARY', 'BASE64', 'QUOTED-PRINTABLE', '8BIT'];

function buildTree($conn, $uid, $part, $partNum = '') {
    global $mimeTypes, $encodings;
    
    $type = $mimeTypes[$part->type] ?? 'UNKNOWN';
    $subtype = strtolower($part->subtype ?? 'unknown');
    $encoding = $encodings[$part->encoding ?? 0] ?? 'UNKNOWN';

    $node = [
        'part' => $partNum ?: 'ROOT',
        'mime' => strtolower($type) . '/' . $subtype,
        'encoding' => $encoding,
        'bytes' => $part->bytes ?? null,
    ];

    if (isset($part->parameters) && $part->parameters) {
        foreach ($part->parameters as $param) {
            $node['params'][strtolower($param->attribute)] = $param->value;
        }
    }
    if (isset($part->disposition)) {
        $node['disposition'] = $part->disposition;
    }

    if ($part->type == 0) {
        $content = @imap_fetchbody($conn, $uid, $partNum ?: '1', FT_UID);
        $node['raw_length'] = strlen($content);
        $node['preview'] = mb_substr($content, 0, 150) . '...';
    }

    if (isset($part->parts) && $part->parts) {
        $node['children'] = [];
        foreach ($part->parts as $index => $child) {
            $childPart = $partNum ? "$partNum." . ($index + 1) : (string)($index + 1);
            $node['children'][] = buildTree($conn, $uid, $child, $childPart);
        }
    }

    return $node;
}

$tree = buildTree($conn, $uid, $structure);

// Get header info too
$header = imap_headerinfo($conn, $msgno);

echo json_encode([
    'uid' => $uid,
    'msgno' => $msgno,
    'folder' => $folder,
    'subject' => isset($header->subject) ? iconv_mime_decode($header->subject, 0, 'UTF-8') : '',
    'from' => isset($header->fromaddress) ? iconv_mime_decode($header->fromaddress, 0, 'UTF-8') : '',
    'date' => $header->date ?? '',
    'total_in_folder' => $totalMessages,
    'structure' => $tree,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

imap_close($conn);
