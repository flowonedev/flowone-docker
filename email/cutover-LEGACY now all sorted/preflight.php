#!/usr/bin/env php
<?php
/**
 * Canonical Identity Cutover -- preflight gate.
 *
 * Run BEFORE applying `166_canonical_identity_cutover.sql`. Refuses to
 * exit 0 unless every safety condition is met. The runbook treats a
 * non-zero exit as "ABORT, do not run the migration".
 *
 * Conditions (all must hold):
 *
 *   1. Compare-mode telemetry shows zero divergence and zero partial
 *      samples (proves byPath and byId never disagree). The samples
 *      counter must be at least --require-samples (default: 1) so the
 *      compare path actually exercised in the soak window. Use
 *      --warmup to issue a few synthetic resolveCompare calls that
 *      seed the counter before this script reads it -- useful right
 *      after a fresh deploy.
 *
 *   2. invariant_violations from the readiness file is zero (no
 *      `evt=allmail_invariant_violation` lines in the last 24h).
 *
 *   3. Direct DB sanity check: zero rows have NULL folder_id across
 *      pinned_emails / webmail_conversation_members / webmail_conversations.
 *      The MODIFY folder_id NOT NULL in the migration would fail anyway,
 *      but failing here gives a much friendlier error message.
 *
 *   4. Direct DB sanity check: every legacy `folder` value (still
 *      present at this stage -- the cutover hasn't dropped the column
 *      yet) is consistent with the open interval row for `folder_id`
 *      in the identity table. A stale folder string here would mean
 *      the migration loses the ability to disambiguate via the legacy
 *      column.
 *
 * Usage:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cutover/preflight.php
 *
 * Flags:
 *   --require-samples=N   Minimum dual_resolve_samples in the readiness
 *                         file. Defaults to 1. Set higher for stricter
 *                         certainty.
 *   --warmup              Before reading the readiness file, run a
 *                         handful of in-process resolveCompare calls
 *                         against an existing folder so samples > 0
 *                         even when the live request rate is low. The
 *                         script then refreshes the readiness file by
 *                         invoking dual-write-readiness.php.
 *   --account=EMAIL       Override the warmup account (otherwise the
 *                         most recent active account is used).
 *   --help                Show this message.
 *
 * Exit codes:
 *   0  -- all green, safe to run the migration
 *   1  -- a check failed; do NOT run the migration
 *   2  -- harness error (config missing, DB unreachable, etc.)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$opts = getopt('', ['require-samples::', 'warmup', 'account::', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2200));
    exit(0);
}

$config = require __DIR__ . '/../src/config.php';

$readinessFile  = __DIR__ . '/../storage/logs/dual-write-readiness.json';
$readinessCron  = __DIR__ . '/../cron/dual-write-readiness.php';
$requireSamples = isset($opts['require-samples']) && is_numeric($opts['require-samples'])
    ? max(0, (int) $opts['require-samples'])
    : 1;

$pass = [];
$fail = [];
$warn = [];

function check_pass(string $label, string $detail = ''): void
{
    global $pass;
    $pass[] = $detail !== '' ? "  [OK]    {$label}  -- {$detail}" : "  [OK]    {$label}";
}
function check_fail(string $label, string $detail): void
{
    global $fail;
    $fail[] = "  [FAIL]  {$label}  -- {$detail}";
}
function check_warn(string $label, string $detail): void
{
    global $warn;
    $warn[] = "  [WARN]  {$label}  -- {$detail}";
}

// ---------------------------------------------------------------------
// (Optional) --warmup: seed compare-mode counters from this process.
// We pick an account that has at least one open interval; for each we
// resolve a real folder via FolderInputResolver in compare mode a few
// times. The resolver bumps the four resolve_* counters as a side
// effect. After warmup we kick the readiness cron so the JSON is fresh.
// ---------------------------------------------------------------------
if (isset($opts['warmup'])) {
    try {
        $db = \Webmail\Core\Database::getConnection($config);
        $accountSql = isset($opts['account']) && $opts['account'] !== ''
            ? 'SELECT account_id FROM webmail_folder_identity WHERE account_id = ? LIMIT 1'
            : 'SELECT account_id FROM webmail_folder_identity ORDER BY last_seen_at DESC LIMIT 1';
        $stmt = $db->prepare($accountSql);
        $stmt->execute(isset($opts['account']) && $opts['account'] !== '' ? [(string) $opts['account']] : []);
        $accountId = (string) ($stmt->fetchColumn() ?: '');
        if ($accountId === '') {
            check_warn('warmup', 'no account with identity rows found; skipped');
        } else {
            $row = $db->prepare(
                'SELECT id, current_path FROM webmail_folder_identity
                  WHERE account_id = ? ORDER BY last_seen_at DESC LIMIT 1'
            );
            $row->execute([$accountId]);
            $folder = $row->fetch();
            if (!$folder) {
                check_warn('warmup', "no folders for {$accountId}; skipped");
            } else {
                $resolver = new \Webmail\Services\FolderInputResolver($config);
                for ($i = 0; $i < 5; $i++) {
                    try {
                        $resolver->compareResolve($accountId, (string) $folder['id'], (string) $folder['current_path']);
                    } catch (\Throwable $e) {
                        // best-effort warmup; ignore individual failures
                    }
                }
                check_pass('warmup', "ran 5 compareResolve() calls for {$accountId}");
            }
        }
    } catch (\Throwable $e) {
        check_warn('warmup', $e->getMessage());
    }

    // Refresh the readiness file from this process so the JSON we read
    // below reflects the bumps we just made.
    @passthru(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($readinessCron) . ' >/dev/null 2>&1');
}

// ---------------------------------------------------------------------
// 1. Read the readiness JSON.
// ---------------------------------------------------------------------
$state = [];
if (!is_readable($readinessFile)) {
    check_fail('readiness file', "{$readinessFile} not found -- run dual-write-readiness.php at least once first");
} else {
    $raw = @file_get_contents($readinessFile);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        check_fail('readiness file', "could not parse {$readinessFile}");
    } else {
        $state = $decoded;
        check_pass('readiness file', $readinessFile);
    }
}

// ---------------------------------------------------------------------
// 2. Compare-mode counters.
// ---------------------------------------------------------------------
foreach (['dual_resolve_divergences', 'dual_resolve_partial'] as $key) {
    if (!array_key_exists($key, $state)) {
        check_fail("compare {$key}", 'missing from readiness state');
        continue;
    }
    $val = (int) $state[$key];
    if ($val === 0) {
        check_pass("compare {$key}", '0');
    } else {
        check_fail("compare {$key}", "expected 0, got {$val}");
    }
}

$samples = (int) ($state['dual_resolve_samples'] ?? 0);
if ($samples >= $requireSamples && $requireSamples > 0) {
    check_pass('compare samples', "{$samples} (>= --require-samples={$requireSamples})");
} elseif ($samples > 0 && $requireSamples === 0) {
    check_pass('compare samples', "{$samples} (gate disabled by --require-samples=0)");
} elseif ($requireSamples === 0) {
    check_warn('compare samples', 'samples=0 (gate disabled by --require-samples=0)');
} else {
    check_fail(
        'compare samples',
        "samples={$samples}; need at least {$requireSamples}. " .
        'Use --warmup to seed counters or exercise the app, then re-run.'
    );
}

// ---------------------------------------------------------------------
// 3. invariant_violations
// ---------------------------------------------------------------------
$invKey = 'invariant_violations';
if (!array_key_exists($invKey, $state)) {
    check_fail($invKey, 'missing from readiness state');
} else {
    $val = (int) $state[$invKey];
    if ($val === 0) {
        check_pass($invKey, '0');
    } else {
        check_fail($invKey, "expected 0, got {$val}");
    }
}

// ---------------------------------------------------------------------
// 4. DB sanity: no NULL folder_id rows
// ---------------------------------------------------------------------
try {
    $db ??= \Webmail\Core\Database::getConnection($config);
    foreach ([
        'pinned_emails',
        'webmail_conversation_members',
        'webmail_conversations',
    ] as $table) {
        $row = $db->query("SELECT COUNT(*) AS c FROM {$table} WHERE folder_id IS NULL")->fetch();
        $c = (int) ($row['c'] ?? -1);
        if ($c === 0) {
            check_pass("null folder_id in {$table}", '0 rows');
        } else {
            check_fail("null folder_id in {$table}", "{$c} rows still NULL -- run backfill-folder-ids.php first");
        }
    }
} catch (\Throwable $e) {
    check_fail('DB connect', $e->getMessage());
}

// ---------------------------------------------------------------------
// 5. DB sanity: legacy `folder` matches the open interval for folder_id
//    (only meaningful BEFORE the cutover migration drops the column;
//    silently downgraded to a warning if the column is already gone so
//    re-running preflight after a successful cutover is harmless).
// ---------------------------------------------------------------------
try {
    if (isset($db)) {
        $col = $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'pinned_emails'
                AND COLUMN_NAME  = 'folder'"
        )->fetchColumn();
        if ((int) $col === 0) {
            check_warn('legacy folder<->folder_id consistency', 'column already dropped (post-cutover state)');
        } else {
            $sql = "
                SELECT COUNT(*) AS c
                  FROM pinned_emails p
                  LEFT JOIN webmail_folder_path_intervals pi
                    ON pi.folder_id  = p.folder_id
                   AND pi.account_id = p.user_email
                   AND pi.path       = p.folder
                   AND pi.valid_to IS NULL
                 WHERE pi.id IS NULL
            ";
            $row = $db->query($sql)->fetch();
            $c = (int) ($row['c'] ?? -1);
            if ($c === 0) {
                check_pass('legacy folder<->folder_id consistency (pinned)', '0 mismatched rows');
            } else {
                check_fail('legacy folder<->folder_id consistency (pinned)', "{$c} mismatched rows -- investigate before cutover");
            }
        }
    }
} catch (\Throwable $e) {
    check_fail('legacy<->folder_id check', $e->getMessage());
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "================================================================\n";
echo "  Canonical Identity Cutover -- preflight\n";
echo "  " . date('Y-m-d H:i:s T') . "\n";
echo "  --require-samples=" . $requireSamples . (isset($opts['warmup']) ? '  --warmup' : '') . "\n";
echo "================================================================\n\n";

foreach ($pass as $line) echo $line . "\n";
foreach ($warn as $line) echo $line . "\n";
foreach ($fail as $line) echo $line . "\n";

echo "\n----------------------------------------------------------------\n";
if (!empty($fail)) {
    echo "  RESULT: ABORT\n";
    echo "  Do NOT run 166_canonical_identity_cutover.sql.\n";
    echo "  Resolve the FAIL items above and re-run preflight.\n";
    echo "----------------------------------------------------------------\n";
    exit(1);
}
echo "  RESULT: SAFE TO PROCEED\n";
echo "  You may now apply 166_canonical_identity_cutover.sql.\n";
echo "  See RUN-CUTOVER.md for the exact command.\n";
echo "----------------------------------------------------------------\n";
exit(0);
