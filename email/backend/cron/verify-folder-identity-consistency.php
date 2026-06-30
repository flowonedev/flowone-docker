#!/usr/bin/env php
<?php
/**
 * Folder Identity Consistency Reconciliation (Wave 2 P1).
 *
 * Nightly drift detector for the canonical folder identity system. The
 * system has multiple sources of truth that must remain consistent:
 *
 *   webmail_folder_identity            -- the per-folder UUIDv7 row
 *   webmail_folder_path_intervals      -- state-interval ownership
 *   webmail_folder_path_history        -- legacy event log
 *   pinned_emails.folder_id            -- dual-write target
 *   webmail_conversation_members.folder_id
 *   webmail_conversations.folder_id
 *
 * Most drift is harmless once dual-write is fully on, but we need a
 * loud signal when something diverges so engineering can intervene
 * before the cutover migration is run. This cron performs read-only
 * checks and reports counters; it does NOT auto-repair (an automated
 * "fix" against an unknown invariant violation could destroy data).
 *
 * Checks:
 *
 *   1. identity_without_open_interval
 *      A row in webmail_folder_identity whose current_path has no
 *      OPEN row in webmail_folder_path_intervals. Indicates a missed
 *      ensureOpenInterval() call. Fixable by re-running upsert.
 *
 *   2. multiple_open_intervals_per_path
 *      Two folders own the same (account_id, path) at the same time
 *      (both rows have valid_to IS NULL). Hard invariant violation;
 *      applyRename() guards against this so a positive count means
 *      something bypassed it.
 *
 *   3. dual_write_orphan_folder_id
 *      A row in pinned_emails / webmail_conversation_members /
 *      webmail_conversations has a folder_id that no longer exists in
 *      webmail_folder_identity. Indicates an identity row was hard-
 *      deleted without cascading; should never happen.
 *
 *   4. dual_write_folder_id_mismatch
 *      A row's `folder` column does not match the current_path of its
 *      folder_id row. Indicates a rename was applied to the identity
 *      table but the cosmetic dual-write column was not refreshed.
 *      Cosmetic only; folder_id remains correct.
 *
 *   5. backfill_pending
 *      Same counter as dual-write-readiness but broken down by
 *      provider_type so we know which population is dragging.
 *
 * Output:
 *   storage/logs/folder-identity-consistency.log         -- text
 *   storage/logs/folder-identity-consistency.json        -- latest
 *
 * Run: /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/verify-folder-identity-consistency.php
 *
 * Crontab (nightly 2:25 a.m. so it runs after the readiness cron):
 *   25 2 * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/verify-folder-identity-consistency.php
 */

require_once __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$args = [
    'help' => false,
    'verbose' => false,
    'json' => false,
];
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if ($a === '--help' || $a === '-h') { $args['help'] = true; continue; }
    if ($a === '--verbose')  { $args['verbose'] = true; continue; }
    if ($a === '--json')     { $args['json']    = true; continue; }
}

if ($args['help']) {
    echo <<<USAGE
verify-folder-identity-consistency.php -- nightly drift detector.

Usage:
  php verify-folder-identity-consistency.php [flags]

Flags:
  --verbose      Print the first 20 offending rows for each check.
  --json         Emit JSON summary on stdout.
  --help         Show this message.

Read-only. Never modifies any data.

USAGE;
    exit(0);
}

$verbose = (bool) $args['verbose'];
$jsonOut = (bool) $args['json'];

$config = require __DIR__ . '/../src/config.php';

$logTag  = '[IDENTITY-CONSISTENCY]';
$logFile = __DIR__ . '/../storage/logs/folder-identity-consistency.log';
$jsonFile = __DIR__ . '/../storage/logs/folder-identity-consistency.json';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}
$logLine = function (string $msg) use ($logFile, $jsonOut): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (!$jsonOut) echo $line;
};

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$counters = [];
$samples  = []; // verbose-mode offenders

// ---- Helper: run a count query, optionally a sample query for verbose. ----
$runCheck = function (string $name, string $countSql, ?string $sampleSql, array $params = []) use (&$counters, &$samples, $db, $verbose, $logLine, $logTag): void {
    try {
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $counters[$name] = (int) ($row['c'] ?? 0);
    } catch (\Throwable $e) {
        $logLine($logTag . " check {$name} failed: " . $e->getMessage());
        $counters[$name] = -1;
    }

    if ($verbose && $sampleSql !== null && ($counters[$name] ?? 0) > 0) {
        try {
            $stmt = $db->prepare($sampleSql . ' LIMIT 20');
            $stmt->execute($params);
            $samples[$name] = $stmt->fetchAll();
        } catch (\Throwable $e) {
            $samples[$name] = [['error' => $e->getMessage()]];
        }
    }
};

// ---- Check 1: identity rows without an open interval ----
$runCheck(
    'identity_without_open_interval',
    'SELECT COUNT(*) AS c FROM webmail_folder_identity fi
       WHERE NOT EXISTS (
         SELECT 1 FROM webmail_folder_path_intervals pi
          WHERE pi.folder_id = fi.id
            AND pi.account_id = fi.account_id
            AND pi.path = fi.current_path
            AND pi.valid_to IS NULL
       )',
    'SELECT fi.id, fi.account_id, fi.current_path FROM webmail_folder_identity fi
       WHERE NOT EXISTS (
         SELECT 1 FROM webmail_folder_path_intervals pi
          WHERE pi.folder_id = fi.id
            AND pi.account_id = fi.account_id
            AND pi.path = fi.current_path
            AND pi.valid_to IS NULL
       )'
);

// ---- Check 2: multiple folders own the same path simultaneously ----
$runCheck(
    'multiple_open_intervals_per_path',
    'SELECT COUNT(*) AS c FROM (
        SELECT account_id, path
          FROM webmail_folder_path_intervals
         WHERE valid_to IS NULL
         GROUP BY account_id, path
        HAVING COUNT(DISTINCT folder_id) > 1
     ) t',
    'SELECT account_id, path, COUNT(DISTINCT folder_id) AS owners
       FROM webmail_folder_path_intervals
      WHERE valid_to IS NULL
      GROUP BY account_id, path
     HAVING COUNT(DISTINCT folder_id) > 1'
);

// ---- Check 3: dual-write rows pointing at non-existent identity ----
$tablesForOrphan = ['pinned_emails', 'webmail_conversation_members', 'webmail_conversations'];
foreach ($tablesForOrphan as $table) {
    $runCheck(
        'dual_write_orphan_folder_id__' . $table,
        "SELECT COUNT(*) AS c FROM {$table} t
           WHERE t.folder_id IS NOT NULL
             AND NOT EXISTS (
               SELECT 1 FROM webmail_folder_identity fi WHERE fi.id = t.folder_id
             )",
        "SELECT t.folder_id, t.user_email FROM {$table} t
           WHERE t.folder_id IS NOT NULL
             AND NOT EXISTS (
               SELECT 1 FROM webmail_folder_identity fi WHERE fi.id = t.folder_id
             )"
    );
}

// ---- Check 4: dual-write rows whose folder column doesn't match folder_id's current_path ----
foreach ($tablesForOrphan as $table) {
    $runCheck(
        'dual_write_folder_id_mismatch__' . $table,
        "SELECT COUNT(*) AS c FROM {$table} t
           JOIN webmail_folder_identity fi ON fi.id = t.folder_id
          WHERE t.folder_id IS NOT NULL
            AND t.folder IS NOT NULL
            AND t.folder <> fi.current_path",
        "SELECT t.user_email, t.folder, fi.current_path FROM {$table} t
           JOIN webmail_folder_identity fi ON fi.id = t.folder_id
          WHERE t.folder_id IS NOT NULL
            AND t.folder IS NOT NULL
            AND t.folder <> fi.current_path"
    );
}

// ---- Check 5: backfill_pending broken down by provider_type ----
$breakdown = [];
foreach ($tablesForOrphan as $table) {
    try {
        $stmt = $db->prepare(
            "SELECT COALESCE(p.provider_type, 'unknown') AS provider_type, COUNT(*) AS c
               FROM {$table} t
               LEFT JOIN webmail_account_provider p ON p.account_id = t.user_email
              WHERE t.folder_id IS NULL
              GROUP BY p.provider_type"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $breakdown[$table] = [];
        foreach ($rows as $r) {
            $breakdown[$table][(string) $r['provider_type']] = (int) $r['c'];
        }
    } catch (\Throwable $e) {
        $breakdown[$table] = ['error' => $e->getMessage()];
    }
}

// ---- Persist + report ----
$state = [
    'updated_at' => gmdate('c'),
    'counters' => $counters,
    'backfill_by_provider' => $breakdown,
];
if ($verbose) {
    $state['samples'] = $samples;
}
@file_put_contents($jsonFile, json_encode($state, JSON_PRETTY_PRINT) . "\n", LOCK_EX);

$worst = 0;
foreach ($counters as $v) {
    if ($v > 0) $worst = max($worst, $v);
}

$logLine($logTag . sprintf(
    ' summary worst=%d ' .
    'no_open_interval=%d multi_open=%d ' .
    'orphan_pinned=%d orphan_conv_members=%d orphan_convs=%d ' .
    'mismatch_pinned=%d mismatch_conv_members=%d mismatch_convs=%d',
    $worst,
    (int) ($counters['identity_without_open_interval'] ?? 0),
    (int) ($counters['multiple_open_intervals_per_path'] ?? 0),
    (int) ($counters['dual_write_orphan_folder_id__pinned_emails'] ?? 0),
    (int) ($counters['dual_write_orphan_folder_id__webmail_conversation_members'] ?? 0),
    (int) ($counters['dual_write_orphan_folder_id__webmail_conversations'] ?? 0),
    (int) ($counters['dual_write_folder_id_mismatch__pinned_emails'] ?? 0),
    (int) ($counters['dual_write_folder_id_mismatch__webmail_conversation_members'] ?? 0),
    (int) ($counters['dual_write_folder_id_mismatch__webmail_conversations'] ?? 0)
));

if ($jsonOut) {
    echo json_encode($state, JSON_PRETTY_PRINT) . "\n";
}

// Exit 0 even on detected drift -- this is a reporting cron, not a
// gate. Alerts should be wired off the JSON file or the log line.
exit(0);
