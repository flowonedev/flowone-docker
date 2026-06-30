<?php
/**
 * Diagnostic script to find rows with possible encoded/quoted-printable content
 * Prints sample rows and decoding attempts to help debug remaining garbled text.
 *
 * Usage: php backend/scripts/diagnose_encoding.php
 */

$configPath = __DIR__ . '/../src/config.php';
if (!file_exists($configPath)) {
    echo "Config not found at {$configPath}\n";
    exit(1);
}
$appConfig = require $configPath;
$dbHost = $appConfig['db']['host'] ?? '127.0.0.1';
$dbName = $appConfig['db']['name'] ?? 'devc_vps_dash';
$dbUser = $appConfig['db']['user'] ?? 'vpsadmin';
$dbPass = $appConfig['db']['pass'] ?? '';
$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    echo "DB connection failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

function try_decode_variants(string $s): array {
    $variants = [];
    $variants['raw'] = $s;
    // show if contains MIME-encoded words
    $variants['has_mime_word'] = preg_match('/=\?[^?]+\?[BQbq]\?[^?]*\?=/i', $s) ? 'yes' : 'no';
    $variants['has_qp_tokens'] = preg_match('/=[0-9A-F]{2}/i', $s) ? 'yes' : 'no';

    // Attempt quoted-printable decode (after fixing soft breaks)
    $tmp = $s;
    $tmp = str_replace(["=\r\n", "=\n", "= "], ['', '', ''], $tmp);
    $qp = quoted_printable_decode($tmp);
    $variants['qp_decoded'] = $qp;

    // Decode HTML entities
    $variants['html_entity_decoded'] = html_entity_decode($qp, ENT_QUOTES, 'UTF-8');

    // Detect encoding
    $detected = mb_detect_encoding($variants['html_entity_decoded'], ['UTF-8','ISO-8859-2','Windows-1250','ISO-8859-1'], true);
    $variants['mb_detect'] = $detected ?: 'unknown';

    // Try convert using detected or common CE encodings
    $tryList = $detected ? [$detected] : ['ISO-8859-2','Windows-1250','CP1250','ISO-8859-1'];
    foreach ($tryList as $enc) {
        if (!$enc) continue;
        $converted = @mb_convert_encoding($variants['html_entity_decoded'], 'UTF-8', $enc);
        $variants['converted_from_' . $enc] = $converted === false ? '(fail)' : $converted;
    }

    // Try imap_mime_header_decode if it looks like MIME words
    if ($variants['has_mime_word'] === 'yes' && function_exists('imap_mime_header_decode')) {
        $parts = imap_mime_header_decode($s);
        $recon = '';
        foreach ($parts as $p) {
            $cs = $p->charset === 'default' ? 'UTF-8' : $p->charset;
            $recon .= @mb_convert_encoding($p->text, 'UTF-8', $cs);
        }
        $variants['imap_mime_decoded'] = $recon;
    } else {
        $variants['imap_mime_decoded'] = '(not applicable)';
    }

    return $variants;
}

$samples = [];

// Query suspicious patterns in subjects/snippets
$queries = [
    ["table"=>"webmail_conversation_members","id_cols"=>"user_email, message_id","text_col"=>"subject"],
    ["table"=>"webmail_conversations","id_cols"=>"user_email, conversation_id","text_col"=>"subject"],
    ["table"=>"webmail_conversations","id_cols"=>"user_email, conversation_id","text_col"=>"snippet"],
    ["table"=>"pinned_emails","id_cols"=>"user_email, folder_id, uid","text_col"=>"subject"],
];

foreach ($queries as $q) {
    $table = $q['table'];
    $textCol = $q['text_col'];
    $idCols = $q['id_cols'];
    $sql = "SELECT {$idCols}, {$textCol} as txt FROM {$table} WHERE {$textCol} IS NOT NULL AND ({$textCol} LIKE '%=%' OR {$textCol} LIKE '%?=%' OR {$textCol} LIKE '%C3=%' OR {$textCol} LIKE '%C5=%') LIMIT 30";
    try {
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['_table'] = $table;
            $row['_text_col'] = $textCol;
            $row['_decodes'] = try_decode_variants($row['txt']);
            $samples[] = $row;
        }
    } catch (Exception $e) {
        // ignore table not found or other errors
    }
}

if (empty($samples)) {
    echo "No suspicious rows found by pattern search.\n";
    exit(0);
}

foreach ($samples as $i => $samp) {
    echo "---- Sample #" . ($i+1) . " ({$samp['_table']} / {$samp['_text_col']}) ----\n";
    foreach ($samp as $k => $v) {
        if (strpos($k, '_') === 0) continue;
        if ($k === 'txt') continue;
        echo "{$k}: {$v}\n";
    }
    echo "original: " . $samp['txt'] . "\n\n";
    $dec = $samp['_decodes'];
    foreach ($dec as $k => $v) {
        echo "{$k}: " . (is_string($v) ? $v : '(binary)') . "\n";
    }
    echo "\n\n";
}

echo "Done. Displayed " . count($samples) . " samples.\n";


