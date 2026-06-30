#!/usr/bin/env php
<?php
/**
 * Wave 2 Cutover (Track #4): post-cutover health check.
 *
 * Run AFTER applying `166_canonical_identity_cutover.sql`. Verifies
 * that:
 *
 *   1. The legacy `folder` columns are gone from the three target
 *      tables (the migration actually executed and committed).
 *
 *   2. `folder_id` is NOT NULL on all three tables (the MODIFY
 *      succeeded).
 *
 *   3. The replacement unique keys / indexes exist with the expected
 *      column shape.
 *
 *   4. A spot-check SELECT on each table works -- no accidental
 *      tooling broke (e.g. PDO not refreshing schema).
 *
 *   5. The PHP error log shows zero new "Unknown column 'folder'"
 *      entries since the cutover started -- proves the deployed code
 *      already routes via folder_id and is not reading the dropped
 *      column. Reads /var/www/vps-email/backend/logs/php_errors.log
 *      and looks at entries newer than `--since=N` minutes (default 5).
 *
 * Usage:
 *   php /var/www/vps-email/backend/cutover/post-health.php
 *   php /var/www/vps-email/backend/cutover/post-health.php --since=15
 *
 * Exit codes:
 *   0  -- all green, cutover is healthy
 *   1  -- one or more checks failed; investigate and possibly rollback
 *   2  -- harness error
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$config = require __DIR__ . '/../src/config.php';

$sinceMinutes = 5;
foreach ($argv as $a) {
    if (preg_match('/^--since=(\d+)$/', $a, $m)) {
        $sinceMinutes = max(1, (int) $m[1]);
    }
}

$pass = [];
$fail = [];
function p(string $l, string $d = ''): void { global $pass; $pass[] = $d !== '' ? "  [OK]    {$l}  -- {$d}" : "  [OK]    {$l}"; }
function f(string $l, string $d): void { global $fail; $fail[] = "  [FAIL]  {$l}  -- {$d}"; }

// ---------------------------------------------------------------------
// 1. Legacy column gone, folder_id NOT NULL.
// ---------------------------------------------------------------------
try {
    $db = \Webmail\Core\Database::getConnection($config);
    $dbName = $db->query('SELECT DATABASE()')->fetchColumn();

    foreach ([
        'pinned_emails',
        'webmail_conversation_members',
        'webmail_conversations',
    ] as $table) {
        $stmt = $db->prepare("
            SELECT column_name, is_nullable
              FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ?
        ");
        $stmt->execute([$dbName, $table]);
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($cols as $c) {
            $byName[strtolower($c['column_name'])] = $c;
        }

        if (isset($byName['folder'])) {
            f("legacy column dropped on {$table}", 'column `folder` still present -- migration did not run or failed silently');
        } else {
            p("legacy column dropped on {$table}", 'folder column gone');
        }

        if (!isset($byName['folder_id'])) {
            f("folder_id present on {$table}", 'folder_id column missing entirely -- something went very wrong');
            continue;
        }
        $isNullable = strtoupper((string) ($byName['folder_id']['is_nullable'] ?? 'YES'));
        if ($isNullable === 'NO') {
            p("folder_id NOT NULL on {$table}");
        } else {
            f("folder_id NOT NULL on {$table}", 'folder_id is still nullable -- MODIFY failed');
        }
    }

    // -----------------------------------------------------------------
    // 3. Replacement indexes exist (every new key/index added by 166).
    // -----------------------------------------------------------------
    $expectedIndexes = [
        'pinned_emails'                 => ['unique_pin_id'],
        'webmail_conversation_members'  => ['unique_msg_id', 'idx_folder_id_conv'],
        'webmail_conversations'         => ['idx_latest_id', 'idx_norm_subject_id'],
    ];
    foreach ($expectedIndexes as $table => $names) {
        $stmt = $db->prepare("
            SELECT DISTINCT index_name
              FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ?
        ");
        $stmt->execute([$dbName, $table]);
        $found = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
        foreach ($names as $idx) {
            if (in_array(strtolower($idx), $found, true)) {
                p("index {$idx} on {$table}");
            } else {
                f("index {$idx} on {$table}", 'expected index missing -- query plans may have regressed');
            }
        }
    }

    // -----------------------------------------------------------------
    // 4. Spot-check SELECT.
    // -----------------------------------------------------------------
    foreach ([
        'pinned_emails'                 => 'SELECT COUNT(*) FROM pinned_emails LIMIT 1',
        'webmail_conversation_members'  => 'SELECT COUNT(*) FROM webmail_conversation_members LIMIT 1',
        'webmail_conversations'         => 'SELECT COUNT(*) FROM webmail_conversations LIMIT 1',
    ] as $tag => $sql) {
        try {
            $db->query($sql)->fetchColumn();
            p("read works on {$tag}");
        } catch (\Throwable $e) {
            f("read works on {$tag}", $e->getMessage());
        }
    }

} catch (\Throwable $e) {
    f('DB connect', $e->getMessage());
}

// ---------------------------------------------------------------------
// 5. PHP error log: no folder-column references since cutover.
//
// We catch every flavour MySQL/MariaDB emits when a query references
// the dropped column:
//   - Unknown column 'folder'
//   - Unknown column 'm.folder' (and c., p., pe., cm. variants)
//   - Column not found: 1054 ...
// AND every reference to the dropped legacy indexes so we notice if
// a forgotten code path is still hinting at them.
// ---------------------------------------------------------------------
$logFile = '/var/www/vps-email/backend/logs/php_errors.log';
if (!is_readable($logFile)) {
    $localFallback = __DIR__ . '/../logs/php_errors.log';
    if (is_readable($localFallback)) $logFile = $localFallback;
}
if (!is_readable($logFile)) {
    f("php_errors.log readable", "tried {$logFile} -- cannot certify zero column-not-found errors");
} else {
    $cutoffEpoch = time() - ($sinceMinutes * 60);

    // Patterns to flag. Each entry is [label, regex].
    $patterns = [
        // Plain column name with optional table-alias prefix and either quote.
        ['unknown column "folder"',
            "/Unknown column ['\"](?:[a-zA-Z_]+\\.)?folder['\"]/i"],
        // PDO/MySQL error-code form.
        ['column not found: 1054',
            "/Column not found: 1054 Unknown column ['\"](?:[a-zA-Z_]+\\.)?folder['\"]/i"],
        // Lingering references to dropped indexes.
        ['dropped index referenced',
            "/(idx_user_folder|idx_folder_conv|idx_norm_subject)(?![\w_])/i"],
    ];

    $hits = [];
    $lastLines = [];
    $fp = @fopen($logFile, 'r');
    if (!is_resource($fp)) {
        f('php_errors.log open', $logFile);
    } else {
        // Tail the last ~5MB; cheap and bounded.
        fseek($fp, 0, SEEK_END);
        $size = ftell($fp);
        $tailBytes = min(5 * 1024 * 1024, $size);
        fseek($fp, max(0, $size - $tailBytes), SEEK_SET);
        // Discard the first (likely partial) line.
        if ($size > $tailBytes) {
            fgets($fp);
        }
        while (($line = fgets($fp)) !== false) {
            $matchedLabel = null;
            foreach ($patterns as [$label, $regex]) {
                if (preg_match($regex, $line)) {
                    $matchedLabel = $label;
                    break;
                }
            }
            if ($matchedLabel === null) continue;

            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
                $ts = strtotime($m[1]);
                if ($ts !== false && $ts >= $cutoffEpoch) {
                    $hits[$matchedLabel] = ($hits[$matchedLabel] ?? 0) + 1;
                    $lastLines[$matchedLabel] = trim($line);
                }
            } else {
                // No parseable timestamp: count it -- safer to over-alert
                // than to silently drop something flagrant.
                $hits[$matchedLabel] = ($hits[$matchedLabel] ?? 0) + 1;
                $lastLines[$matchedLabel] = trim($line);
            }
        }
        fclose($fp);
        if (empty($hits)) {
            p("zero folder-column / dropped-index errors", "in last {$sinceMinutes} min");
        } else {
            foreach ($hits as $label => $count) {
                f("zero folder-column / dropped-index errors", "{$count} hit(s) for [{$label}]: " . substr($lastLines[$label] ?? '', 0, 240));
            }
        }
    }
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "================================================================\n";
echo "  Wave 2 Cutover post-health\n";
echo "  " . date('Y-m-d H:i:s T') . "  (window: last {$sinceMinutes} min)\n";
echo "================================================================\n\n";

foreach ($pass as $line) echo $line . "\n";
foreach ($fail as $line) echo $line . "\n";

echo "\n----------------------------------------------------------------\n";
if (!empty($fail)) {
    echo "  RESULT: NOT HEALTHY\n";
    echo "  Investigate FAIL items above. If unrecoverable, restore the\n";
    echo "  pre-cutover backup taken in Step 1 of RUN-CUTOVER.md.\n";
    echo "----------------------------------------------------------------\n";
    exit(1);
}
echo "  RESULT: HEALTHY\n";
echo "  Cutover is live. Proceed with the code-cleanup checklist in\n";
echo "  RUN-CUTOVER.md (strip dual-write branches, remove legacy routes,\n";
echo "  remove frontend fallbacks).\n";
echo "----------------------------------------------------------------\n";
exit(0);
