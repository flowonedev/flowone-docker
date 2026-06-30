<?php
/**
 * Test script for Board Pro Email Auto-Link Rules
 * 
 * Usage:
 *   php test-email-rules.php --verbose
 *   php test-email-rules.php --dry-run --verbose
 *   php test-email-rules.php --subject="[Feedback] Design Issue" --from="robert@pixelranger.hu"
 *   php test-email-rules.php --subject="[Feedback] Bug Report" --uid=4562 --folder=INBOX
 */

require_once __DIR__ . '/bootstrap.php';

use Webmail\Addons\BoardPro\Services\BoardProEmailService;

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../src/config.php';
}
$config = require $configFile;

$options = getopt('', ['verbose', 'dry-run', 'subject:', 'from:', 'uid:', 'folder:', 'user:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php test-email-rules.php [options]\n";
    echo "  --verbose       Show detailed output\n";
    echo "  --dry-run       Only check rule matching, don't create cards\n";
    echo "  --subject=...   Email subject to test (default: '[Feedback] Design Issue - Kanban Board')\n";
    echo "  --from=...      Sender email (default: 'robert@pixelranger.hu')\n";
    echo "  --uid=...       Email UID (default: 9999)\n";
    echo "  --folder=...    IMAP folder (default: 'INBOX')\n";
    echo "  --user=...      User email to test as (default: board owner)\n";
    exit(0);
}

$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

$testSubject = $options['subject'] ?? '[Feedback] Design Issue - Kanban Board (robert@pixelranger.hu)';
$testFrom = $options['from'] ?? 'support@devcon1.hu';
$testUid = (int)($options['uid'] ?? 9999);
$testFolder = $options['folder'] ?? 'INBOX';
$testUser = $options['user'] ?? null;

echo "=== Board Pro Email Rules Test ===\n\n";

// Step 1: Check database connection
echo "[1] Database connection... ";
try {
    $db = \Webmail\Core\Database::getConnection($config);
    echo "OK\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 2: List all active rules
echo "[2] Active email rules:\n";
$stmt = $db->prepare("
    SELECT er.*, b.name AS board_name, b.owner_email, bl.name AS list_name
    FROM boardpro_email_rules er
    JOIN webmail_boards b ON b.id = er.board_id
    LEFT JOIN webmail_board_lists bl ON bl.id = er.list_id
    WHERE er.is_active = 1
");
$stmt->execute();
$rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rules)) {
    echo "    NO ACTIVE RULES FOUND!\n";
    exit(1);
}

foreach ($rules as $rule) {
    echo "    Rule #{$rule['id']}: type={$rule['rule_type']}, value=\"{$rule['rule_value']}\"\n";
    echo "      Board: \"{$rule['board_name']}\" (id={$rule['board_id']}), owner={$rule['owner_email']}\n";
    echo "      List: \"{$rule['list_name']}\" (id={$rule['list_id']})\n";
    echo "      auto_create_card={$rule['auto_create_card']}, body_handling={$rule['body_handling']}\n";
    echo "      card_title_template=\"{$rule['card_title_template']}\"\n";
    echo "      type_categories=" . ($rule['type_categories'] ?? 'null') . "\n";
    echo "      run_count={$rule['run_count']}, last_run_at={$rule['last_run_at']}\n\n";

    if (!$testUser) {
        $testUser = $rule['owner_email'];
    }
}

// Step 3: Check board membership
echo "[3] Checking board access for user: $testUser\n";
foreach ($rules as $rule) {
    $stmt = $db->prepare("
        SELECT 'owner' AS role FROM webmail_boards WHERE id = ? AND owner_email = ?
        UNION
        SELECT role FROM webmail_board_members WHERE board_id = ? AND user_email = ?
    ");
    $stmt->execute([$rule['board_id'], $testUser, $rule['board_id'], $testUser]);
    $access = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($access)) {
        echo "    Rule #{$rule['id']}: NO ACCESS for $testUser on board #{$rule['board_id']}\n";
    } else {
        $roles = array_column($access, 'role');
        echo "    Rule #{$rule['id']}: Access OK (roles: " . implode(', ', $roles) . ")\n";
    }
}

// Step 4: Test rule matching
echo "\n[4] Testing rule matching with:\n";
echo "    Subject: \"$testSubject\"\n";
echo "    From: \"$testFrom\"\n";
echo "    Folder: \"$testFolder\"\n";
echo "    UID: $testUid\n\n";

$emailData = [
    'uid' => $testUid,
    'folder' => $testFolder,
    'subject' => $testSubject,
    'from' => $testFrom,
    'from_name' => explode('@', $testFrom)[0],
    'date' => date('r'),
    'snippet' => 'This is a test email body with design keywords for type detection.',
    'body_text' => "This is a test feedback email.\nIt mentions design issues.\nPlease fix the layout.\nAlso check the visual alignment.",
];

foreach ($rules as $rule) {
    $matches = false;
    switch ($rule['rule_type']) {
        case 'subject_contains':
            $matches = stripos($emailData['subject'], $rule['rule_value']) !== false;
            echo "    Rule #{$rule['id']} (subject_contains \"{$rule['rule_value']}\"): ";
            echo $matches ? "MATCH" : "NO MATCH";
            echo " (subject=\"{$emailData['subject']}\")\n";
            break;
        case 'sender_domain':
            $domain = substr(strrchr($emailData['from'], '@'), 1);
            $matches = strcasecmp($domain ?: '', $rule['rule_value']) === 0;
            echo "    Rule #{$rule['id']} (sender_domain \"{$rule['rule_value']}\"): ";
            echo $matches ? "MATCH" : "NO MATCH";
            echo " (domain=\"$domain\")\n";
            break;
        case 'sender_email':
            $matches = strcasecmp($emailData['from'], $rule['rule_value']) === 0;
            echo "    Rule #{$rule['id']} (sender_email \"{$rule['rule_value']}\"): ";
            echo $matches ? "MATCH" : "NO MATCH";
            echo " (from=\"{$emailData['from']}\")\n";
            break;
        case 'label_match':
            $matches = stripos($emailData['folder'], $rule['rule_value']) !== false;
            echo "    Rule #{$rule['id']} (label_match \"{$rule['rule_value']}\"): ";
            echo $matches ? "MATCH" : "NO MATCH";
            echo " (folder=\"{$emailData['folder']}\")\n";
            break;
    }
}

// Step 5: Check processed emails table
echo "\n[5] Checking boardpro_rule_processed_emails table:\n";
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM boardpro_rule_processed_emails");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    echo "    Table exists, $count records\n";
} catch (\Exception $e) {
    echo "    TABLE MISSING OR ERROR: " . $e->getMessage() . "\n";
    echo "    This will cause rule evaluation to crash!\n";
}

// Step 6: Actually run the evaluation
if ($dryRun) {
    echo "\n[6] DRY RUN - skipping actual card creation\n";
    echo "\n=== Test complete (dry run) ===\n";
    exit(0);
}

echo "\n[6] Running evaluateEmailAgainstRules()...\n";
try {
    $service = new BoardProEmailService($config);
    $results = $service->evaluateEmailAgainstRules($emailData, $testUser, null);
    echo "    Results: " . json_encode($results, JSON_PRETTY_PRINT) . "\n";
    
    if (empty($results)) {
        echo "\n    No results returned. Possible reasons:\n";
        echo "    - No rules matched (check step 4)\n";
        echo "    - User has no access to boards (check step 3)\n";
        echo "    - Email already processed (check step 5)\n";
    } else {
        foreach ($results as $r) {
            if (($r['action'] ?? '') === 'card_created') {
                echo "\n    CARD CREATED! card_id={$r['card_id']}\n";
            }
        }
    }
} catch (\Exception $e) {
    echo "    EXCEPTION: " . $e->getMessage() . "\n";
    echo "    Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== Test complete ===\n";
