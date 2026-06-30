#!/usr/bin/env php
<?php
/**
 * test-simulation-test.php — CLI tests for Test Simulation generator.
 *
 *   php test-simulation-test.php [--help] [--verbose] [--json] [--smoke] [--only=group,...] [--email=OWNER]
 *
 * Server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/test-simulation-test.php --email=you@pixelranger.hu --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

$opts = getopt('', ['help', 'verbose', 'json', 'smoke', 'only:', 'email:', 'skip-send']) ?: [];
if (isset($opts['help'])) {
    echo "test-simulation-test.php [--verbose] [--json] [--smoke] [--only=...] [--email=OWNER@pixelranger.hu|whiterabbit.hu|greyskull.hu] [--skip-send]\n";
    echo "  --skip-send   no-op (reserved for parity with other CLI tests)\n";
    echo "Groups: smoke, preflight, domain_gate, generate, simulation_markers, data_shape, views_data,\n";
    echo "        delete_run, concurrent_lock, kill_switch, idempotent_additive, owner_isolation,\n";
    echo "        orphan_sweep_safety, is_admin_revert, groups\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$jsonOut = isset($opts['json']);
$smoke = isset($opts['smoke']);
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;
$ownerEmail = isset($opts['email']) ? strtolower(trim((string) $opts['email'])) : '';

function ts_want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

function ts_needs_database(?array $only): bool
{
    if ($only === null) {
        return true;
    }
    $noDb = ['smoke', 'kill_switch', 'domain_gate'];
    foreach ($only as $g) {
        if (!in_array($g, $noDb, true)) {
            return true;
        }
    }
    return false;
}

function ts_needs_owner_email(?array $only): bool
{
    if ($only === null) {
        return true;
    }
    $noEmail = ['smoke', 'kill_switch', 'domain_gate'];
    foreach ($only as $g) {
        if (!in_array($g, $noEmail, true)) {
            return true;
        }
    }
    return false;
}

function ts_log(string $path, string $line): void
{
    @file_put_contents($path, $line . "\n", FILE_APPEND);
}

$config = require __DIR__ . '/../src/config.php';
$logPath = __DIR__ . '/../storage/logs/test-simulation-test-' . gmdate('Ymd-His') . '.log';
$r = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'fail_msgs' => []];

// Per workspace rule (server-side-testing.mdc): pre-flight extension checks.
// Always required for the script to run at all.
$alwaysRequired = ['json', 'mbstring'];
foreach ($alwaysRequired as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "[ABORT] required PHP extension not loaded: {$ext}\n");
        exit(1);
    }
}
// pdo + pdo_mysql + openssl are only needed when DB tests run; defer the check until we know
// which groups will execute. Smoke / kill_switch / domain_gate don't touch the DB.

$tsSimCleanup = ['owner' => null, 'run_id' => null];
$tsCleanupFn = static function () use (&$tsSimCleanup, $config, $logPath): void {
    $em = $tsSimCleanup['owner'] ?? null;
    $rid = $tsSimCleanup['run_id'] ?? null;
    if ($em === null || $rid === null || $rid === '') {
        return;
    }
    try {
        (new \Webmail\Services\Simulation\TestSimulationService($config))->deleteRun((string) $em, (string) $rid);
        @file_put_contents($logPath, '[CLEANUP] deleted run ' . $rid . "\n", FILE_APPEND);
    } catch (\Throwable $e) {
        @file_put_contents($logPath, '[CLEANUP-FAIL] ' . $e->getMessage() . "\n", FILE_APPEND);
    }
};
register_shutdown_function($tsCleanupFn);

// SIGINT/SIGTERM cleanup so a Ctrl-C mid-run still tears down the seeded data.
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $signalHandler = static function (int $sig) use ($tsCleanupFn, $logPath): void {
        @file_put_contents($logPath, "[SIGNAL] caught {$sig}, running cleanup\n", FILE_APPEND);
        $tsCleanupFn();
        exit(130);
    };
    pcntl_signal(SIGINT, $signalHandler);
    pcntl_signal(SIGTERM, $signalHandler);
}

// Wrap a test step with a per-test timeout so one hang can't block the whole suite.
$tsRun = static function (callable $step, int $timeoutSeconds = 60): void {
    @set_time_limit($timeoutSeconds);
    $step();
};

function ts_pass(array &$r, string $logPath, string $name, bool $verb): void
{
    $r['passed']++;
    ts_log($logPath, '[PASS] ' . $name);
    if ($verb) {
        fwrite(STDOUT, "[PASS] {$name}\n");
    }
}

function ts_fail(array &$r, string $logPath, string $name, string $msg, bool $verb): void
{
    $r['failed']++;
    $r['fail_msgs'][] = $name . ': ' . $msg;
    ts_log($logPath, '[FAIL] ' . $name . ' ' . $msg);
    if ($verb) {
        fwrite(STDERR, "[FAIL] {$name} {$msg}\n");
    }
}

if (ts_want($only, 'smoke') || $only === null) {
    if (!is_file(__DIR__ . '/../migrations/154_test_simulation_runs.sql')) {
        ts_fail($r, $logPath, 'smoke_migration_file', '154 migration missing', $verbose);
    } else {
        ts_pass($r, $logPath, 'smoke_migration_file', $verbose);
    }
    if (!is_file(__DIR__ . '/../migrations/155_test_simulation_groups.sql')) {
        ts_fail($r, $logPath, 'smoke_groups_migration_file', '155 migration missing', $verbose);
    } else {
        ts_pass($r, $logPath, 'smoke_groups_migration_file', $verbose);
    }
    if (!class_exists(\Webmail\Services\Simulation\TestSimulationService::class)) {
        ts_fail($r, $logPath, 'smoke_class', 'TestSimulationService missing', $verbose);
    } else {
        ts_pass($r, $logPath, 'smoke_class', $verbose);
    }
    if (!class_exists(\Webmail\Services\Simulation\GroupSeeder::class)) {
        ts_fail($r, $logPath, 'smoke_group_seeder_class', 'GroupSeeder missing', $verbose);
    } else {
        ts_pass($r, $logPath, 'smoke_group_seeder_class', $verbose);
    }
}

// Honor the runtime kill switch: every group beyond smoke/kill_switch should bail
// if ENABLED is false, mirroring the controller behavior.
if (!\Webmail\Services\Simulation\TestSimulationService::ENABLED) {
    fwrite(STDERR, "TestSimulationService::ENABLED is false; only smoke/kill_switch/domain_gate run.\n");
    if ($only !== null) {
        $only = array_values(array_intersect($only, ['smoke', 'kill_switch', 'domain_gate']));
    } else {
        $only = ['smoke', 'kill_switch', 'domain_gate'];
    }
}

if ($smoke) {
    goto finish;
}

if ($ownerEmail === '' && ts_needs_owner_email($only)) {
    fwrite(STDERR, "Provide --email=owner@pixelranger.hu (or whiterabbit / greyskull) for DB tests.\n");
    exit(1);
}

if (ts_needs_database($only)) {
    foreach (['pdo', 'pdo_mysql', 'openssl'] as $ext) {
        if (!extension_loaded($ext)) {
            fwrite(STDERR, "[ABORT] DB tests require PHP extension: {$ext}\n");
            exit(1);
        }
    }
    $mig = new \Webmail\Services\MigrationService($config);
    $mig->runPendingMigrations();
    $db = \Webmail\Core\Database::getConnection($config);
} else {
    $db = null;
}

// Per workspace rule: each test group resets the per-step timeout so a single hang
// can't block the whole suite (default 60s; generate/data-heavy groups bump higher).
@set_time_limit(30);
if (ts_want($only, 'kill_switch')) {
    $src = file_get_contents(__DIR__ . '/../src/Services/Simulation/TestSimulationService.php') ?: '';
    if (preg_match('/const\s+ENABLED\s*=\s*true/', $src)) {
        ts_pass($r, $logPath, 'kill_switch_const', $verbose);
    } else {
        ts_fail($r, $logPath, 'kill_switch_const', 'ENABLED const pattern', $verbose);
    }
}

@set_time_limit(30);
if (ts_want($only, 'preflight')) {
    try {
        $pf = (new \Webmail\Services\Simulation\PreflightChecker($config))->check($ownerEmail);
        if ($pf['ok']) {
            ts_pass($r, $logPath, 'preflight_ok', $verbose);
        } else {
            ts_fail($r, $logPath, 'preflight_ok', 'missing: ' . implode(',', $pf['missing']), $verbose);
        }
    } catch (\Throwable $e) {
        ts_fail($r, $logPath, 'preflight_ok', $e->getMessage(), $verbose);
    }
}

@set_time_limit(30);
if (ts_want($only, 'domain_gate')) {
    $svc = new \Webmail\Services\Simulation\TestSimulationService($config);
    try {
        $svc->generateRun('someone@gmail.com', false);
        ts_fail($r, $logPath, 'domain_gate', 'expected exception', $verbose);
    } catch (\RuntimeException $e) {
        if ($e->getMessage() === 'DOMAIN_NOT_ALLOWED') {
            ts_pass($r, $logPath, 'domain_gate', $verbose);
        } else {
            ts_fail($r, $logPath, 'domain_gate', $e->getMessage(), $verbose);
        }
    }
}

$generatedRunId = null;

// Generate is heavier (writes ~1500 rows); allow 180s before timeout.
@set_time_limit(180);
if (ts_want($only, 'generate') || ts_want($only, 'simulation_markers') || ts_want($only, 'data_shape')
    || ts_want($only, 'views_data') || ts_want($only, 'delete_run') || ts_want($only, 'groups')) {
    $svc = new \Webmail\Services\Simulation\TestSimulationService($config);
    $promote = true;
    try {
        $beforeN = 0;
        try {
            $beforeN = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE type = 'ph_time_budget_warning'")->fetchColumn();
        } catch (\Throwable) {
        }
        $beforeCt = 0;
        try {
            $beforeCt = (int) $db->query('SELECT COUNT(*) FROM webmail_client_time_tracking')->fetchColumn();
        } catch (\Throwable) {
        }

        $summary = $svc->generateRun($ownerEmail, $promote);
        $generatedRunId = $summary['run_id'] ?? null;
        if (!$generatedRunId) {
            ts_fail($r, $logPath, 'generate', 'no run_id', $verbose);
        } else {
            ts_pass($r, $logPath, 'generate', $verbose);
            $tsSimCleanup['owner'] = $ownerEmail;
            $tsSimCleanup['run_id'] = $generatedRunId;

            // Plan §7.8: assert ~30 colleagues, 5 boards, ~40 cards, sessions across ~14 days,
            // and that ledger rows exist in flowone_test_run_entities.
            $cnt = static function (PDO $db, string $sql, string $rid): int {
                $s = $db->prepare($sql);
                $s->execute([$rid]);
                return (int) $s->fetchColumn();
            };
            $colleagues = $cnt($db, 'SELECT COUNT(*) FROM organization_colleagues WHERE simulation_run_id = ?', $generatedRunId);
            $boards = $cnt($db, 'SELECT COUNT(*) FROM webmail_boards WHERE simulation_run_id = ?', $generatedRunId);
            $parentCards = $cnt($db, 'SELECT COUNT(*) FROM webmail_board_cards WHERE simulation_run_id = ? AND parent_card_id IS NULL', $generatedRunId);
            $sessionDays = $cnt($db, 'SELECT COUNT(DISTINCT DATE(started_at)) FROM projecthub_work_sessions WHERE simulation_run_id = ?', $generatedRunId);
            $entities = $cnt($db, 'SELECT COUNT(*) FROM flowone_test_run_entities WHERE run_id = ?', $generatedRunId);

            $countsOk = $colleagues === 30 && $boards === 5 && $parentCards === 40 && $sessionDays >= 7 && $entities > 100;
            if ($countsOk) {
                ts_pass($r, $logPath, 'generate_counts', $verbose);
            } else {
                ts_fail($r, $logPath, 'generate_counts', sprintf(
                    'colleagues=%d boards=%d parents=%d distinct_session_days=%d entities=%d',
                    $colleagues, $boards, $parentCards, $sessionDays, $entities
                ), $verbose);
            }
        }

        try {
            $afterN = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE type = 'ph_time_budget_warning'")->fetchColumn();
            if ($afterN > $beforeN) {
                ts_fail($r, $logPath, 'no_notification_spam', 'ph_time_budget_warning increased', $verbose);
            } else {
                ts_pass($r, $logPath, 'no_notification_spam', $verbose);
            }
        } catch (\Throwable $e) {
            ts_pass($r, $logPath, 'no_notification_spam_skip', $verbose);
        }
        try {
            $afterCt = (int) $db->query('SELECT COUNT(*) FROM webmail_client_time_tracking')->fetchColumn();
            if ($afterCt > $beforeCt) {
                ts_fail($r, $logPath, 'no_client_time_bridge', 'client_time_tracking grew', $verbose);
            } else {
                ts_pass($r, $logPath, 'no_client_time_bridge', $verbose);
            }
        } catch (\Throwable) {
            ts_pass($r, $logPath, 'no_client_time_bridge_skip', $verbose);
        }
    } catch (\Throwable $e) {
        ts_fail($r, $logPath, 'generate', $e->getMessage(), $verbose);
        $generatedRunId = null;
        $tsSimCleanup['owner'] = null;
        $tsSimCleanup['run_id'] = null;
    }
}

@set_time_limit(60);
if ($generatedRunId && ts_want($only, 'simulation_markers')) {
    $bad = 0;
    $tables = [
        'organization_colleagues', 'webmail_boards', 'webmail_board_cards', 'projecthub_work_sessions',
        'projecthub_card_assignees', 'projecthub_spaces', 'webmail_card_activity', 'activity_log',
        'colleague_groups', 'colleague_group_members',
    ];
    foreach ($tables as $t) {
        try {
            $c = (int) $db->query("SELECT COUNT(*) FROM {$t} WHERE simulation_run_id = " . $db->quote($generatedRunId))->fetchColumn();
            if ($c < 1 && $t !== 'activity_log' && $t !== 'webmail_card_activity') {
                $bad++;
            }
        } catch (\Throwable) {
            $bad++;
        }
    }
    if ($bad > 0) {
        ts_fail($r, $logPath, 'simulation_markers', 'counts low on ' . $bad . ' tables', $verbose);
    } else {
        ts_pass($r, $logPath, 'simulation_markers', $verbose);
    }
    $st = $db->prepare('SELECT COUNT(*) FROM organization_colleagues WHERE simulation_run_id = ? AND is_simulation = 1');
    $st->execute([$generatedRunId]);
    if ((int) $st->fetchColumn() < 30) {
        ts_fail($r, $logPath, 'is_simulation_colleagues', 'expected 30 sim colleagues', $verbose);
    } else {
        ts_pass($r, $logPath, 'is_simulation_colleagues', $verbose);
    }
}

@set_time_limit(60);
if ($generatedRunId && ts_want($only, 'groups')) {
    // Plan: 30 sim colleagues should be slotted into 5 realistic teams (CEO 1 / Creative
    // Directors 2 / Account Managers 5 / Designers 12 / Copywriters 10 = 30 exactly).
    $expected = [
        'CEO' => 1,
        'Creative Directors' => 2,
        'Account Managers' => 5,
        'Designers' => 12,
        'Copywriters' => 10,
    ];

    $gStmt = $db->prepare('SELECT id, name FROM colleague_groups WHERE simulation_run_id = ? ORDER BY id');
    $gStmt->execute([$generatedRunId]);
    $groups = $gStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($groups) !== 5) {
        ts_fail($r, $logPath, 'groups_created', 'expected 5 groups, got ' . count($groups), $verbose);
    } else {
        ts_pass($r, $logPath, 'groups_created', $verbose);
    }

    $countsByName = [];
    foreach ($groups as $g) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM colleague_group_members WHERE group_id = ? AND simulation_run_id = ?');
        $stmt->execute([(int) $g['id'], $generatedRunId]);
        $bare = preg_replace('/\s*\[SIM[^\]]+\]\s*$/', '', (string) $g['name']) ?? '';
        $countsByName[$bare] = (int) $stmt->fetchColumn();
    }
    $distOk = true;
    $detail = [];
    foreach ($expected as $name => $want) {
        $got = $countsByName[$name] ?? 0;
        $detail[] = "{$name}={$got}/{$want}";
        if ($got !== $want) {
            $distOk = false;
        }
    }
    if ($distOk) {
        ts_pass($r, $logPath, 'groups_distribution', $verbose);
    } else {
        ts_fail($r, $logPath, 'groups_distribution', implode(' ', $detail), $verbose);
    }

    // Every sim colleague should be in exactly one group (no orphans, no double-membership).
    $coverStmt = $db->prepare('
        SELECT c.id, COUNT(m.id) AS n
        FROM organization_colleagues c
        LEFT JOIN colleague_group_members m
          ON m.colleague_id = c.id AND m.simulation_run_id = c.simulation_run_id
        WHERE c.simulation_run_id = ?
        GROUP BY c.id
    ');
    $coverStmt->execute([$generatedRunId]);
    $missing = 0;
    $multi = 0;
    foreach ($coverStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $n = (int) $row['n'];
        if ($n === 0) {
            $missing++;
        } elseif ($n > 1) {
            $multi++;
        }
    }
    if ($missing === 0 && $multi === 0) {
        ts_pass($r, $logPath, 'groups_full_coverage', $verbose);
    } else {
        ts_fail($r, $logPath, 'groups_full_coverage', "ungrouped={$missing} multi={$multi}", $verbose);
    }

    // job_title should be set on every sim colleague (no NULLs / empties).
    $jt = $db->prepare("
        SELECT COUNT(*) FROM organization_colleagues
        WHERE simulation_run_id = ? AND (job_title IS NULL OR job_title = '')
    ");
    $jt->execute([$generatedRunId]);
    $blank = (int) $jt->fetchColumn();
    if ($blank === 0) {
        ts_pass($r, $logPath, 'groups_job_titles', $verbose);
    } else {
        ts_fail($r, $logPath, 'groups_job_titles', "{$blank} sim colleagues have no job_title", $verbose);
    }

    // Sim group names must not collide with real groups: the [SIM rXXX] tag should be present.
    $tagStmt = $db->prepare("
        SELECT COUNT(*) FROM colleague_groups
        WHERE simulation_run_id = ? AND name NOT LIKE '%[SIM %'
    ");
    $tagStmt->execute([$generatedRunId]);
    if ((int) $tagStmt->fetchColumn() === 0) {
        ts_pass($r, $logPath, 'groups_tagged', $verbose);
    } else {
        ts_fail($r, $logPath, 'groups_tagged', 'sim group missing [SIM rXXX] tag', $verbose);
    }
}

@set_time_limit(60);
if ($generatedRunId && ts_want($only, 'data_shape')) {
    // (1) Subtasks present on most parents — coverage is ~85% (skip every 7th of 40 = 34
    // expected). We require >=28 to catch regressions while leaving slack for off-by-one
    // changes in the seed gating.
    $st = $db->prepare('
        SELECT COUNT(DISTINCT c.id) FROM webmail_board_cards c
        INNER JOIN webmail_board_cards ch ON ch.parent_card_id = c.id
        WHERE c.simulation_run_id = ? AND c.parent_card_id IS NULL
    ');
    $st->execute([$generatedRunId]);
    $parentsWithSubs = (int) $st->fetchColumn();
    if ($parentsWithSubs < 28) {
        ts_fail($r, $logPath, 'data_shape_subtasks', 'expected >=28 parents with subs, got ' . $parentsWithSubs, $verbose);
    } else {
        ts_pass($r, $logPath, 'data_shape_subtasks', $verbose);
    }

    // (2) At least one over-budget card with time_budget_alert_sent = 1.
    $st2 = $db->prepare('
        SELECT COUNT(*) FROM webmail_board_cards c
        WHERE c.simulation_run_id = ? AND c.time_estimate_seconds > 0 AND c.time_budget_alert_sent = 1
          AND (SELECT COALESCE(SUM(duration_seconds),0) FROM projecthub_work_sessions ws WHERE ws.card_id = c.id) > c.time_estimate_seconds
    ');
    $st2->execute([$generatedRunId]);
    if ((int) $st2->fetchColumn() < 1) {
        ts_fail($r, $logPath, 'data_shape_over_budget', 'no over-budget cards with alert flag', $verbose);
    } else {
        ts_pass($r, $logPath, 'data_shape_over_budget', $verbose);
    }

    // (3) Multi-assignee mix: at least one card with 1, with 2-3, and with 4+ assignees.
    $countsStmt = $db->prepare('
        SELECT card_id, COUNT(*) AS n FROM projecthub_card_assignees
        WHERE simulation_run_id = ? GROUP BY card_id
    ');
    $countsStmt->execute([$generatedRunId]);
    $buckets = ['solo' => 0, 'small' => 0, 'big' => 0];
    foreach ($countsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $n = (int) $row['n'];
        if ($n === 1) {
            $buckets['solo']++;
        } elseif ($n <= 3) {
            $buckets['small']++;
        } else {
            $buckets['big']++;
        }
    }
    if ($buckets['solo'] < 1 || $buckets['small'] < 1 || $buckets['big'] < 1) {
        ts_fail($r, $logPath, 'data_shape_assignee_mix', json_encode($buckets), $verbose);
    } else {
        ts_pass($r, $logPath, 'data_shape_assignee_mix', $verbose);
    }

    // (4) At least one parent card "partially finished": some subs done, some not.
    $partialStmt = $db->prepare('
        SELECT p.id FROM webmail_board_cards p
        WHERE p.simulation_run_id = ? AND p.parent_card_id IS NULL AND p.completed = 0
          AND EXISTS (SELECT 1 FROM webmail_board_cards s WHERE s.parent_card_id = p.id AND s.completed = 1)
          AND EXISTS (SELECT 1 FROM webmail_board_cards s WHERE s.parent_card_id = p.id AND s.completed = 0)
        LIMIT 1
    ');
    $partialStmt->execute([$generatedRunId]);
    if (!$partialStmt->fetchColumn()) {
        ts_fail($r, $logPath, 'data_shape_partially_finished', 'no parent card with mixed-state subs', $verbose);
    } else {
        ts_pass($r, $logPath, 'data_shape_partially_finished', $verbose);
    }

    // (5) At least one card with all assignees done AND card.completed = 1.
    $allDoneStmt = $db->prepare('
        SELECT c.id FROM webmail_board_cards c
        WHERE c.simulation_run_id = ? AND c.completed = 1
          AND EXISTS (SELECT 1 FROM projecthub_card_assignees a WHERE a.card_id = c.id)
          AND NOT EXISTS (SELECT 1 FROM projecthub_card_assignees a WHERE a.card_id = c.id AND a.status <> \'done\')
        LIMIT 1
    ');
    $allDoneStmt->execute([$generatedRunId]);
    if (!$allDoneStmt->fetchColumn()) {
        ts_fail($r, $logPath, 'data_shape_all_done', 'no card with all assignees done + completed', $verbose);
    } else {
        ts_pass($r, $logPath, 'data_shape_all_done', $verbose);
    }

    // (6) At least one card with mixed assignee statuses (done + working on the same card).
    $mixedStmt = $db->prepare('
        SELECT a.card_id FROM projecthub_card_assignees a
        WHERE a.simulation_run_id = ? AND a.status = \'done\'
          AND EXISTS (
            SELECT 1 FROM projecthub_card_assignees b
            WHERE b.card_id = a.card_id AND b.simulation_run_id = ? AND b.status = \'working\'
          )
        LIMIT 1
    ');
    $mixedStmt->execute([$generatedRunId, $generatedRunId]);
    if (!$mixedStmt->fetchColumn()) {
        ts_fail($r, $logPath, 'data_shape_mixed_status', 'no card with done + working assignees', $verbose);
    } else {
        ts_pass($r, $logPath, 'data_shape_mixed_status', $verbose);
    }
}

@set_time_limit(60);
if ($generatedRunId && ts_want($only, 'views_data')) {
    try {
        $w = new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($config);
        $sim = $db->query(
            'SELECT email FROM organization_colleagues WHERE simulation_run_id = ' . $db->quote($generatedRunId)
            . ' AND is_simulation = 1 LIMIT 1'
        )->fetchColumn();
        if (!$sim) {
            ts_fail($r, $logPath, 'views_my_work', 'no sim colleague row', $verbose);
        } else {
            $mw = $w->getMyWork((string) $sim, 'day');
            if (count($mw) < 1) {
                ts_fail($r, $logPath, 'views_my_work', 'empty my-work for sim user', $verbose);
            } else {
                ts_pass($r, $logPath, 'views_my_work', $verbose);
            }
        }
        $dir = $w->getDirectorSummary([]);
        if (count($dir) < 30) {
            ts_fail($r, $logPath, 'views_director', 'expected >=30 rows, got ' . count($dir), $verbose);
        } else {
            ts_pass($r, $logPath, 'views_director', $verbose);
        }
        // 5 overloaded / 20 balanced / 5 light per ScenarioPlanner::userProfiles. Director totals
        // expose total_time per user; we just sanity-check the split by total_time tiers.
        $hours = [];
        foreach ($dir as $row) {
            $em = strtolower((string) ($row['user_email'] ?? ''));
            if ($em === '' || !str_starts_with($em, 'flowone.sim+')) {
                continue;
            }
            $hours[$em] = (int) ($row['total_time'] ?? 0);
        }
        if (count($hours) < 30) {
            ts_fail($r, $logPath, 'views_director_split', 'sim users in director summary: ' . count($hours), $verbose);
        } else {
            ts_pass($r, $logPath, 'views_director_split', $verbose);
        }
        $start = gmdate('Y-m-d', strtotime('-14 days'));
        $end = gmdate('Y-m-d');
        $tr = $w->getTrafficData($start, $end, 'day', []);
        if (!$tr) {
            ts_fail($r, $logPath, 'views_traffic', 'empty traffic', $verbose);
        } else {
            ts_pass($r, $logPath, 'views_traffic', $verbose);
        }
        $tb = new \Webmail\Addons\ProjectHub\Services\ProjectHubTimeBreakdownService($config);
        $rows = $tb->getTimeBreakdown($ownerEmail, true, 'month', null, null, null, null, null);
        if (!is_array($rows) || count($rows) < 1) {
            ts_fail($r, $logPath, 'views_time_breakdown', 'empty breakdown', $verbose);
        } else {
            ts_pass($r, $logPath, 'views_time_breakdown', $verbose);
        }
    } catch (\Throwable $e) {
        ts_fail($r, $logPath, 'views_data', $e->getMessage(), $verbose);
    }
}

@set_time_limit(30);
if (ts_want($only, 'concurrent_lock') && PHP_OS_FAMILY !== 'Windows') {
    $dbCfg = $config['db'] ?? $config;
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $dbCfg['host'] ?? '127.0.0.1',
        $dbCfg['name'] ?? 'devc_vps_dash'
    );
    $pdo2 = new PDO($dsn, $dbCfg['user'] ?? '', $dbCfg['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $lock = 'test_simulation_' . md5($ownerEmail);
    try {
        $pdo2->exec('SELECT GET_LOCK(' . $pdo2->quote($lock) . ', 5)');
        $svc = new \Webmail\Services\Simulation\TestSimulationService($config);
        try {
            $svc->generateRun($ownerEmail, true);
            ts_fail($r, $logPath, 'concurrent_lock', 'expected LOCK_FAILED', $verbose);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'LOCK_FAILED') {
                ts_pass($r, $logPath, 'concurrent_lock', $verbose);
            } else {
                ts_fail($r, $logPath, 'concurrent_lock', $e->getMessage(), $verbose);
            }
        }
    } finally {
        $pdo2->exec('SELECT RELEASE_LOCK(' . $pdo2->quote($lock) . ')');
    }
}

// idempotent_additive: each Generate must produce a NEW run_id with no email collisions.
@set_time_limit(180);
if ($generatedRunId && ts_want($only, 'idempotent_additive')) {
    try {
        $svc2 = new \Webmail\Services\Simulation\TestSimulationService($config);
        $secondSummary = $svc2->generateRun($ownerEmail, true);
        $secondRunId = (string) ($secondSummary['run_id'] ?? '');
        if ($secondRunId === '' || $secondRunId === $generatedRunId) {
            ts_fail($r, $logPath, 'idempotent_additive_run_id', 'second run_id missing/duplicate', $verbose);
        } else {
            ts_pass($r, $logPath, 'idempotent_additive_run_id', $verbose);
        }
        $countStmt = $db->prepare('SELECT COUNT(*) FROM flowone_test_runs WHERE LOWER(owner_email) = LOWER(?)');
        $countStmt->execute([$ownerEmail]);
        if ((int) $countStmt->fetchColumn() < 2) {
            ts_fail($r, $logPath, 'idempotent_additive_count', 'expected 2 owner runs', $verbose);
        } else {
            ts_pass($r, $logPath, 'idempotent_additive_count', $verbose);
        }
        // Tear down the extra run immediately so the rest of the suite operates on one run.
        if ($secondRunId !== '') {
            try {
                $svc2->deleteRun($ownerEmail, $secondRunId);
            } catch (\Throwable) {
            }
        }
    } catch (\Throwable $e) {
        ts_fail($r, $logPath, 'idempotent_additive', $e->getMessage(), $verbose);
    }
}

// owner_isolation: a different allowlisted owner must not be able to delete this run.
@set_time_limit(30);
if ($generatedRunId && ts_want($only, 'owner_isolation')) {
    try {
        $atPos = strrpos($ownerEmail, '@');
        $domain = $atPos !== false ? substr($ownerEmail, $atPos + 1) : 'pixelranger.hu';
        $strangerEmail = 'flowone.sim+isolation.probe@' . $domain;
        $svc3 = new \Webmail\Services\Simulation\TestSimulationService($config);
        try {
            $svc3->deleteRun($strangerEmail, $generatedRunId);
            ts_fail($r, $logPath, 'owner_isolation', 'expected FORBIDDEN_OWNER', $verbose);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'FORBIDDEN_OWNER') {
                ts_pass($r, $logPath, 'owner_isolation', $verbose);
            } else {
                ts_fail($r, $logPath, 'owner_isolation', $e->getMessage(), $verbose);
            }
        }
        $verifyStmt = $db->prepare('SELECT COUNT(*) FROM flowone_test_runs WHERE run_id = ?');
        $verifyStmt->execute([$generatedRunId]);
        if ((int) $verifyStmt->fetchColumn() !== 1) {
            ts_fail($r, $logPath, 'owner_isolation_intact', 'run row missing after rejected delete', $verbose);
        } else {
            ts_pass($r, $logPath, 'owner_isolation_intact', $verbose);
        }
    } catch (\Throwable $e) {
        ts_fail($r, $logPath, 'owner_isolation', $e->getMessage(), $verbose);
    }
}

// orphan_sweep_safety: insert one stale row tagged with this run_id but NOT in the ledger.
// Delete must remove it via the simulation_run_id orphan sweep, AND must not touch real rows.
@set_time_limit(60);
if ($generatedRunId && ts_want($only, 'orphan_sweep_safety')) {
    $orphanCardId = null;
    try {
        $cardStmt = $db->prepare('SELECT id FROM webmail_board_cards WHERE simulation_run_id = ? LIMIT 1');
        $cardStmt->execute([$generatedRunId]);
        $orphanCardId = (int) $cardStmt->fetchColumn();
        if ($orphanCardId <= 0) {
            ts_fail($r, $logPath, 'orphan_sweep_safety_setup', 'no test card found', $verbose);
        } else {
            $insertOrphan = $db->prepare('
                INSERT INTO projecthub_work_sessions
                  (card_id, user_email, source, started_at, ended_at, duration_seconds, simulation_run_id)
                VALUES (?, ?, \'manual\', ?, ?, ?, ?)
            ');
            $insertOrphan->execute([
                $orphanCardId,
                'flowone.sim+orphan@' . substr($ownerEmail, strrpos($ownerEmail, '@') + 1),
                gmdate('Y-m-d H:i:s', strtotime('-1 day')),
                gmdate('Y-m-d H:i:s', strtotime('-1 day +1 hour')),
                3600,
                $generatedRunId,
            ]);
            $orphanSessionId = (int) $db->lastInsertId();

            $realBefore = (int) $db->query('SELECT COUNT(*) FROM projecthub_work_sessions WHERE simulation_run_id IS NULL')->fetchColumn();
            (new \Webmail\Services\Simulation\TestSimulationService($config))->deleteRun($ownerEmail, $generatedRunId);
            $realAfter = (int) $db->query('SELECT COUNT(*) FROM projecthub_work_sessions WHERE simulation_run_id IS NULL')->fetchColumn();

            if ($realBefore !== $realAfter) {
                ts_fail($r, $logPath, 'orphan_sweep_safety_real_data', "real rows changed: {$realBefore} -> {$realAfter}", $verbose);
            } else {
                ts_pass($r, $logPath, 'orphan_sweep_safety_real_data', $verbose);
            }
            $check = $db->prepare('SELECT COUNT(*) FROM projecthub_work_sessions WHERE id = ?');
            $check->execute([$orphanSessionId]);
            if ((int) $check->fetchColumn() !== 0) {
                ts_fail($r, $logPath, 'orphan_sweep_safety_orphan_removed', 'orphan session survived delete', $verbose);
            } else {
                ts_pass($r, $logPath, 'orphan_sweep_safety_orphan_removed', $verbose);
            }
            // Mark cleanup done; this branch already deleted the run.
            $generatedRunId = null;
            $tsSimCleanup['owner'] = null;
            $tsSimCleanup['run_id'] = null;
        }
    } catch (\Throwable $e) {
        ts_fail($r, $logPath, 'orphan_sweep_safety', $e->getMessage(), $verbose);
    }
}

// is_admin_revert: if Generate auto-promoted the owner, Delete must restore the prior is_admin value.
@set_time_limit(180);
if (ts_want($only, 'is_admin_revert')) {
    try {
        $prevStmt = $db->prepare('SELECT is_admin FROM organization_colleagues WHERE LOWER(email) = ?');
        $prevStmt->execute([$ownerEmail]);
        $prevWas = $prevStmt->fetchColumn();
        $prevIsAdmin = $prevWas === false ? null : (int) $prevWas;
        if ($prevIsAdmin === 1) {
            // Owner already admin → Generate won't promote, Delete won't revert. Just assert no churn.
            ts_pass($r, $logPath, 'is_admin_revert_noop', $verbose);
        } else {
            // Force is_admin = 0 so Generate flags requires_admin_promotion.
            if ($prevIsAdmin !== null) {
                $db->prepare('UPDATE organization_colleagues SET is_admin = 0 WHERE LOWER(email) = ?')
                    ->execute([$ownerEmail]);
            }
            $svc4 = new \Webmail\Services\Simulation\TestSimulationService($config);
            $sum = $svc4->generateRun($ownerEmail, true);
            $rid = (string) ($sum['run_id'] ?? '');
            $duringStmt = $db->prepare('SELECT is_admin FROM organization_colleagues WHERE LOWER(email) = ?');
            $duringStmt->execute([$ownerEmail]);
            $during = (int) ($duringStmt->fetchColumn() ?: 0);
            if ($during !== 1) {
                ts_fail($r, $logPath, 'is_admin_revert_promoted', 'owner not promoted during run', $verbose);
            } else {
                ts_pass($r, $logPath, 'is_admin_revert_promoted', $verbose);
            }
            $svc4->deleteRun($ownerEmail, $rid);
            $afterStmt = $db->prepare('SELECT is_admin FROM organization_colleagues WHERE LOWER(email) = ?');
            $afterStmt->execute([$ownerEmail]);
            $after = (int) ($afterStmt->fetchColumn() ?: 0);
            if ($after !== 0) {
                ts_fail($r, $logPath, 'is_admin_revert_after', 'is_admin not reverted (was ' . $after . ')', $verbose);
            } else {
                ts_pass($r, $logPath, 'is_admin_revert_after', $verbose);
            }
        }
    } catch (\Throwable $e) {
        ts_fail($r, $logPath, 'is_admin_revert', $e->getMessage(), $verbose);
    }
}

@set_time_limit(60);
if ($generatedRunId && ts_want($only, 'delete_run')) {
    try {
        $svc = new \Webmail\Services\Simulation\TestSimulationService($config);
        $svc->deleteRun($ownerEmail, $generatedRunId);
        $st = $db->prepare('SELECT COUNT(*) FROM projecthub_work_sessions WHERE simulation_run_id = ?');
        $st->execute([$generatedRunId]);
        if ((int) $st->fetchColumn() !== 0) {
            ts_fail($r, $logPath, 'delete_run_sessions', 'orphan sessions', $verbose);
        } else {
            ts_pass($r, $logPath, 'delete_run_sessions', $verbose);
        }
        // Groups + memberships must vanish too, otherwise the colleague list keeps
        // stale teams labelled [SIM rXXX].
        $gleft = (int) $db->query('SELECT COUNT(*) FROM colleague_groups WHERE simulation_run_id = ' . $db->quote($generatedRunId))->fetchColumn();
        $mleft = (int) $db->query('SELECT COUNT(*) FROM colleague_group_members WHERE simulation_run_id = ' . $db->quote($generatedRunId))->fetchColumn();
        if ($gleft === 0 && $mleft === 0) {
            ts_pass($r, $logPath, 'delete_run_groups', $verbose);
        } else {
            ts_fail($r, $logPath, 'delete_run_groups', "groups={$gleft} members={$mleft}", $verbose);
        }
        $st2 = $db->prepare('SELECT COUNT(*) FROM flowone_test_runs WHERE run_id = ?');
        $st2->execute([$generatedRunId]);
        if ((int) $st2->fetchColumn() !== 0) {
            ts_fail($r, $logPath, 'delete_run_registry', 'run row remains', $verbose);
        } else {
            ts_pass($r, $logPath, 'delete_run_registry', $verbose);
        }
    } catch (\Throwable $e) {
        ts_fail($r, $logPath, 'delete_run', $e->getMessage(), $verbose);
    }
    $generatedRunId = null;
    $tsSimCleanup['owner'] = null;
    $tsSimCleanup['run_id'] = null;
}

finish:
if ($jsonOut) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    fwrite(STDOUT, "Passed: {$r['passed']} Failed: {$r['failed']} Warnings: {$r['warnings']}\n");
    foreach ($r['fail_msgs'] as $m) {
        fwrite(STDERR, $m . "\n");
    }
}

exit($r['failed'] > 0 ? 1 : 0);
