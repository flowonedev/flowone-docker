<?php
/**
 * One-time script to normalize and convert existing webmail_conversations.snippet values
 * Usage: php backend/scripts/normalize_snippets.php
 *
 * Edit DB connection details below if not available via environment.
 */

// Build DB connection settings:
// Priority: backend/src/config.php > environment variables > hardcoded fallback
$dbDsn = null;
$dbUser = null;
$dbPass = null;

$configPath = __DIR__ . '/../src/config.php';
if (file_exists($configPath)) {
    $appConfig = require $configPath;
    if (isset($appConfig['db'])) {
        $dbHost = $appConfig['db']['host'] ?? '127.0.0.1';
        $dbName = $appConfig['db']['name'] ?? 'devc_vps_dash';
        $dbUser = $appConfig['db']['user'] ?? null;
        $dbPass = $appConfig['db']['pass'] ?? null;
        $dbDsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    }
}

// Environment overrides (if provided)
if (getenv('DB_DSN')) {
    $dbDsn = getenv('DB_DSN');
}
if (getenv('DB_USER')) {
    $dbUser = getenv('DB_USER');
}
if (getenv('DB_PASS')) {
    $dbPass = getenv('DB_PASS');
}

// Final fallback
if (!$dbDsn) {
    $dbHost = '127.0.0.1';
    $dbName = 'devc_vps_dash';
    $dbUser = $dbUser ?: 'vpsadmin';
    $dbPass = $dbPass ?: '';
    $dbDsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
}

try {
    $pdo = new PDO($dbDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    echo "DB connection failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo "Connected to DB. Scanning snippets..." . PHP_EOL;

$select = $pdo->query("SELECT user_email, conversation_id, snippet FROM webmail_conversations WHERE snippet IS NOT NULL");
$update = $pdo->prepare("UPDATE webmail_conversations SET snippet = ? WHERE user_email = ? AND conversation_id = ?");

$count = 0;
while ($row = $select->fetch(PDO::FETCH_ASSOC)) {
    $s = $row['snippet'];
    $original = $s;

    // If it contains quoted-printable tokens, try to decode
    if (preg_match('/=[0-9A-F]{2}/i', $s)) {
        $s = str_replace(["=\r\n", "=\n", "= "], ['', '', ''], $s);
        $s = quoted_printable_decode($s);
    }

    // Decode HTML entities
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', trim($s));

    // Ensure UTF-8; detect common CE encodings if necessary
    if (!mb_check_encoding($s, 'UTF-8')) {
        $detected = mb_detect_encoding($s, ['UTF-8','ISO-8859-2','Windows-1250','ISO-8859-1'], true);
        if ($detected && $detected !== 'UTF-8') {
            $converted = @mb_convert_encoding($s, 'UTF-8', $detected);
            if ($converted !== false) {
                $s = $converted;
            }
        } else {
            $tryEnc = ['ISO-8859-2','Windows-1250','CP1250','ISO-8859-1'];
            foreach ($tryEnc as $enc) {
                $converted = @iconv($enc, 'UTF-8//TRANSLIT', $s);
                if ($converted !== false) {
                    $s = $converted;
                    break;
                }
            }
        }
    }

    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');

    if ($s !== $original) {
        $update->execute([$s, $row['user_email'], $row['conversation_id']]);
        $count++;
    }
}

echo "Normalization complete. Updated {$count} snippets." . PHP_EOL;

// --- Normalize subjects in conversation_members and conversations and pinned_emails ---
$subCount = 0;
echo "Scanning subjects in webmail_conversation_members..." . PHP_EOL;
$selMembers = $pdo->query("SELECT user_email, message_id, subject FROM webmail_conversation_members WHERE subject IS NOT NULL");
$updMember = $pdo->prepare("UPDATE webmail_conversation_members SET subject = ? WHERE user_email = ? AND message_id = ?");
while ($r = $selMembers->fetch(PDO::FETCH_ASSOC)) {
    $s = $r['subject'];
    $orig = $s;
    if (preg_match('/=[0-9A-F]{2}/i', $s)) {
        $s = str_replace(["=\r\n", "=\n", "= "], ['', '', ''], $s);
        $s = quoted_printable_decode($s);
    }
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', trim($s));
    if (!mb_check_encoding($s, 'UTF-8')) {
        $detected = mb_detect_encoding($s, ['UTF-8','ISO-8859-2','Windows-1250','ISO-8859-1'], true);
        if ($detected && $detected !== 'UTF-8') {
            $converted = @mb_convert_encoding($s, 'UTF-8', $detected);
            if ($converted !== false) $s = $converted;
        } else {
            $tryEnc = ['ISO-8859-2','Windows-1250','CP1250','ISO-8859-1'];
            foreach ($tryEnc as $enc) {
                $converted = @iconv($enc, 'UTF-8//TRANSLIT', $s);
                if ($converted !== false) {
                    $s = $converted;
                    break;
                }
            }
        }
    }
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    if ($s !== $orig) {
        $updMember->execute([$s, $r['user_email'], $r['message_id']]);
        $subCount++;
    }
}

echo "Scanning subjects in webmail_conversations..." . PHP_EOL;
$selConvs = $pdo->query("SELECT user_email, conversation_id, subject FROM webmail_conversations WHERE subject IS NOT NULL");
$updConv = $pdo->prepare("UPDATE webmail_conversations SET subject = ? WHERE user_email = ? AND conversation_id = ?");
while ($r = $selConvs->fetch(PDO::FETCH_ASSOC)) {
    $s = $r['subject'];
    $orig = $s;
    if (preg_match('/=[0-9A-F]{2}/i', $s)) {
        $s = str_replace(["=\r\n", "=\n", "= "], ['', '', ''], $s);
        $s = quoted_printable_decode($s);
    }
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', trim($s));
    if (!mb_check_encoding($s, 'UTF-8')) {
        $detected = mb_detect_encoding($s, ['UTF-8','ISO-8859-2','Windows-1250','ISO-8859-1'], true);
        if ($detected && $detected !== 'UTF-8') {
            $converted = @mb_convert_encoding($s, 'UTF-8', $detected);
            if ($converted !== false) $s = $converted;
        } else {
            $tryEnc = ['ISO-8859-2','Windows-1250','CP1250','ISO-8859-1'];
            foreach ($tryEnc as $enc) {
                $converted = @iconv($enc, 'UTF-8//TRANSLIT', $s);
                if ($converted !== false) {
                    $s = $converted;
                    break;
                }
            }
        }
    }
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    if ($s !== $orig) {
        $updConv->execute([$s, $r['user_email'], $r['conversation_id']]);
        $subCount++;
    }
}

// Also normalize pinned_emails.subject. Post-cutover the row is keyed by
// (user_email, folder_id, uid); folder_id is the stable identifier so we
// scope updates by it directly.
echo "Scanning subjects in pinned_emails..." . PHP_EOL;
$selPins = $pdo->query("SELECT user_email, folder_id, uid, subject FROM pinned_emails WHERE subject IS NOT NULL");
$updPin = $pdo->prepare("UPDATE pinned_emails SET subject = ? WHERE user_email = ? AND folder_id = ? AND uid = ?");
while ($r = $selPins->fetch(PDO::FETCH_ASSOC)) {
    $s = $r['subject'];
    $orig = $s;
    if (preg_match('/=[0-9A-F]{2}/i', $s)) {
        $s = str_replace(["=\r\n", "=\n", "= "], ['', '', ''], $s);
        $s = quoted_printable_decode($s);
    }
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', trim($s));
    if (!mb_check_encoding($s, 'UTF-8')) {
        $detected = mb_detect_encoding($s, ['UTF-8','ISO-8859-2','Windows-1250','ISO-8859-1'], true);
        if ($detected && $detected !== 'UTF-8') {
            $converted = @mb_convert_encoding($s, 'UTF-8', $detected);
            if ($converted !== false) $s = $converted;
        } else {
            $tryEnc = ['ISO-8859-2','Windows-1250','CP1250','ISO-8859-1'];
            foreach ($tryEnc as $enc) {
                $converted = @iconv($enc, 'UTF-8//TRANSLIT', $s);
                if ($converted !== false) {
                    $s = $converted;
                    break;
                }
            }
        }
    }
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    if ($s !== $orig) {
        $updPin->execute([$s, $r['user_email'], $r['folder_id'], $r['uid']]);
        $subCount++;
    }
}

echo "Subject normalization complete. Updated {$subCount} subject fields." . PHP_EOL;


