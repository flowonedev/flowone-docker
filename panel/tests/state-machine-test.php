#!/usr/bin/env php
<?php
/**
 * SiteStateMachine Test Suite
 *
 * Verifies:
 *   - Every legal transition succeeds and writes a site_audit_log row.
 *   - Every illegal transition throws InvalidStateTransition.
 *   - Concurrent transitions are guarded (StateGuardFailed when stale).
 *   - createInProvisioning creates a fresh row atomically.
 *   - Audit row carries the actor, reason, before/after snapshots.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/state-machine-test.php --verbose
 *
 * Options:
 *   --verbose          Show extra debug info (stack traces, raw rows)
 *   --skip-send        n/a (no destructive external side effects)
 *   --only=GROUP       transitions,guards,audit,bootstrap
 *   --smoke            connectivity + schema check only
 *   --json             JSON output
 *   --help             Show this help
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Exceptions\StateGuardFailed;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SiteStateMachine', $opts);

// ── Shared state ─────────────────────────────────────────────
$db = null;
$pdo = null;
$audit = null;
$machine = null;
$actor = ActorContext::cli('state-machine-test', 'flowone_test_user');
$testDomains = [];

// ── Cleanup: remove any test rows we created ─────────────────
$harness->onCleanup(function () use (&$pdo, &$testDomains): void {
    if (!$pdo || !$testDomains) {
        return;
    }
    $in = implode(',', array_fill(0, count($testDomains), '?'));
    $pdo->prepare("DELETE FROM site_audit_log WHERE site_domain IN ({$in})")
        ->execute($testDomains);
    $pdo->prepare("DELETE FROM sites WHERE domain IN ({$in})")
        ->execute($testDomains);
});

// ──────────────────────────────────────────────────────────────
// preflight: connectivity, schema presence, libsodium
// ──────────────────────────────────────────────────────────────
$harness->test('preflight', 'PanelDatabase connection alive', function () use (&$db, &$pdo) {
    $db = PanelDatabase::fromDefaultConfigFiles();
    $pdo = $db->pdo();
    $pdo->query('SELECT 1');
});

$harness->test('preflight', 'sites table exists with required columns', function () use (&$pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM sites");
    $cols = array_column($stmt->fetchAll(), 'Field');
    foreach (['domain', 'desired_state', 'actual_state', 'config', 'state'] as $required) {
        if (!in_array($required, $cols, true)) {
            return [
                'outcome' => TestHarness::FAIL,
                'message' => "Missing column: {$required}. Run migrate_sites_state.sql first.",
            ];
        }
    }
});

$harness->test('preflight', 'site_audit_log table exists', function () use (&$pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'site_audit_log'");
    if ($stmt->rowCount() === 0) {
        return [
            'outcome' => TestHarness::FAIL,
            'message' => 'site_audit_log not present. Run migrate_site_audit_log.sql first.',
        ];
    }
});

$harness->test('preflight', 'SiteStateMachine instantiates', function () use (&$db, &$audit, &$machine) {
    $masker = new SecretMasker();
    $audit = new AuditLogger($db, $masker);
    $machine = new SiteStateMachine($db, $audit);
});

// ──────────────────────────────────────────────────────────────
// transitions: every legal move succeeds
// ──────────────────────────────────────────────────────────────
$harness->test('transitions', 'createInProvisioning writes site + audit atomically',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $id = $machine->createInProvisioning(
            $domain,
            ['php_version' => 'lsphp83'],
            $actor
        );
        if ($id <= 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected positive site id'];
        }
        $stateRow = $pdo->prepare('SELECT actual_state FROM sites WHERE id = ?');
        $stateRow->execute([$id]);
        if ($stateRow->fetchColumn() !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'site not in provisioning state'];
        }
        $auditRow = $pdo->prepare('SELECT COUNT(*) FROM site_audit_log WHERE site_domain = ?');
        $auditRow->execute([$domain]);
        if ((int) $auditRow->fetchColumn() !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'audit row missing for create'];
        }
    });

// Regression for the June 2026 orphan-row bug: re-creating a site whose
// row is parked at actual_state=absent/failed (tombstone or dead CREATE)
// must resurrect the row into 'provisioning'. Before the fix the UPSERT
// left actual_state untouched, producing invisible desired=active /
// actual=absent orphans.
$harness->test('transitions', 'createInProvisioning resurrects an absent tombstone row',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $pdo->prepare(
            'INSERT INTO sites (domain, desired_state, actual_state, state, last_error, created_at, updated_at)
              VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$domain, 'absent', 'absent', '{"old":"saga-state"}', 'previous delete error']);
        $tombstoneId = (int) $pdo->lastInsertId();

        $id = $machine->createInProvisioning($domain, ['php_version' => 'lsphp83'], $actor);
        if ($id !== $tombstoneId) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected reuse of row {$tombstoneId}, got {$id}"];
        }
        $stmt = $pdo->prepare('SELECT actual_state, desired_state, state, last_error FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row['actual_state'] !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "actual_state stayed '{$row['actual_state']}' instead of 'provisioning' (orphan-row bug)"];
        }
        if ($row['desired_state'] !== 'active') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'desired_state not reset to active'];
        }
        if ($row['state'] !== null || $row['last_error'] !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'stale saga state / last_error not cleared on resurrection'];
        }
    });

$harness->test('transitions', 'createInProvisioning resurrects a failed row',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $pdo->prepare(
            'INSERT INTO sites (domain, desired_state, actual_state, last_error, created_at, updated_at)
              VALUES (?, ?, ?, ?, NOW(), NOW())'
        )->execute([$domain, 'active', 'failed', 'create saga exploded']);

        $machine->createInProvisioning($domain, [], $actor);
        $stmt = $pdo->prepare('SELECT actual_state, last_error FROM sites WHERE domain = ?');
        $stmt->execute([$domain]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row['actual_state'] !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "failed row not resurrected, actual_state='{$row['actual_state']}'"];
        }
        if ($row['last_error'] !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'last_error not cleared'];
        }
    });

$harness->test('transitions', 'createInProvisioning does NOT clobber a live active row',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $pdo->prepare(
            'INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
              VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$domain, 'active', 'active']);

        $machine->createInProvisioning($domain, [], $actor);
        $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE domain = ?');
        $stmt->execute([$domain]);
        if ($stmt->fetchColumn() !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'live active row was knocked back to provisioning by the UPSERT'];
        }
    });

$legalCases = [
    ['provisioning', 'active'],
    ['provisioning', 'failed'],
    ['provisioning', 'degraded'],
    ['provisioning', 'pending_dns'],
    ['active', 'degraded'],
    ['active', 'deleting'],
    ['active', 'suspended'],
    ['active', 'archived'],
    ['suspended', 'active'],
    ['suspended', 'archived'],
    ['archived', 'restoring'],
    ['restoring', 'active'],
    ['degraded', 'active'],
    ['failed', 'provisioning'],
    ['deleting', 'absent'],
    // enqueue-failure rollback edges: actionEnqueueDelete pre-transitions
    // to 'deleting' before enqueueing; if the enqueue throws, the row is
    // rolled back to wherever it came from.
    ['deleting', 'active'],
    ['deleting', 'suspended'],
    ['deleting', 'failed'],
];

foreach ($legalCases as [$from, $to]) {
    $harness->test('transitions', "legal: {$from} -> {$to}",
        function () use (&$machine, &$pdo, &$actor, &$testDomains, $from, $to) {
            $domain = '[flowone_test_]ssm-' . bin2hex(random_bytes(4)) . '.local';
            $testDomains[] = $domain;
            // Seed a row directly so we can start from any state
            $pdo->prepare(
                'INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
                  VALUES (?, ?, ?, NOW(), NOW())'
            )->execute([$domain, 'active', $from]);
            $id = (int) $pdo->lastInsertId();

            $machine->transition($id, $from, $to, "test {$from}->{$to}", $actor);

            $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== $to) {
                return ['outcome' => TestHarness::FAIL, 'message' => "expected {$to}, got something else"];
            }
        });
}

// ──────────────────────────────────────────────────────────────
// transitions: every illegal move throws
// ──────────────────────────────────────────────────────────────
$illegalCases = [
    ['absent', 'active'],
    ['absent', 'degraded'],
    ['active', 'absent'],
    ['active', 'provisioning'],   // legal in our map for reprovision; remove?
    ['archived', 'active'],       // must go through restoring
    ['archived', 'provisioning'],
    ['deleted', 'active'],         // 'deleted' is not even in the actual_state enum, treat as missing
];

// Filter out any that the current machine actually allows (so the test stays in sync if we widen the map).
$harness->test('preflight', 'expected illegal transitions are actually illegal',
    function () use (&$machine, &$illegalCases) {
        $stillIllegal = [];
        foreach ($illegalCases as [$from, $to]) {
            if (!$machine->canTransition($from, $to)) {
                $stillIllegal[] = [$from, $to];
            }
        }
        $illegalCases = $stillIllegal;
        if ($illegalCases === []) {
            return ['outcome' => TestHarness::WARN, 'message' => 'no illegal cases left to test'];
        }
    });

$harness->test('guards', 'illegal transitions throw InvalidStateTransition',
    function () use (&$machine, &$pdo, &$actor, &$testDomains, &$illegalCases) {
        $failures = [];
        foreach ($illegalCases as [$from, $to]) {
            $domain = '[flowone_test_]ssm-' . bin2hex(random_bytes(4)) . '.local';
            $testDomains[] = $domain;
            $pdo->prepare(
                'INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
                  VALUES (?, ?, ?, NOW(), NOW())'
            )->execute([$domain, 'active', $from === 'deleted' ? 'absent' : $from]);
            $id = (int) $pdo->lastInsertId();

            try {
                $machine->transition($id, $from, $to, 'illegal test', $actor);
                $failures[] = "{$from}->{$to} did NOT throw";
            } catch (InvalidStateTransition) {
                continue;
            } catch (\Throwable $e) {
                $failures[] = "{$from}->{$to} threw wrong type: " . $e::class;
            }
        }
        if ($failures) {
            return ['outcome' => TestHarness::FAIL, 'message' => implode('; ', $failures)];
        }
    });

$harness->test('guards', 'stale-from throws StateGuardFailed',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $pdo->prepare(
            'INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
              VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$domain, 'active', 'active']);
        $id = (int) $pdo->lastInsertId();

        try {
            // Caller claims it was provisioning, but row is active -> guard must catch.
            $machine->transition($id, 'provisioning', 'active', 'stale test', $actor);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected StateGuardFailed'];
        } catch (StateGuardFailed) {
            // ok
        }
    });

$harness->test('guards', 'missing site throws StateGuardFailed',
    function () use (&$machine, &$actor) {
        try {
            $machine->transition(999999999, 'provisioning', 'active', 'missing test', $actor);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected StateGuardFailed'];
        } catch (StateGuardFailed) {
            // ok
        }
    });

// ──────────────────────────────────────────────────────────────
// audit: transitions produce audit rows with proper actor/reason
// ──────────────────────────────────────────────────────────────
// ──────────────────────────────────────────────────────────────
// adoptExisting: legacy backfill path
// ──────────────────────────────────────────────────────────────
$harness->test('adopt', 'adoptExisting inserts a row directly in active state',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-adopt-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;

        $r = $machine->adoptExisting($domain, [
            'php_version' => '8.3',
            'sftp_user' => 'site_adopt_test',
            'home_dir' => '/home/' . $domain,
            'document_root' => '/home/' . $domain . '/public_html',
            'ssl_enabled' => true,
            'ssl_expires_at' => '2099-01-01 00:00:00',
            'ssl_issuer' => 'CN=Test',
        ], $actor);

        if ($r['site_id'] <= 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'site_id missing/zero'];
        }
        if ($r['inserted'] !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected inserted=1'];
        }
        if ($r['already_existed'] !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected already_existed=false on first call'];
        }

        $row = $pdo->prepare('SELECT actual_state, desired_state, sftp_user, ssl_enabled, imported_at
                                FROM sites WHERE id = ?');
        $row->execute([$r['site_id']]);
        $data = $row->fetch();
        if ($data['actual_state'] !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected actual_state=active, got ' . $data['actual_state']];
        }
        if ($data['desired_state'] !== 'active') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'desired_state not active'];
        }
        if ($data['sftp_user'] !== 'site_adopt_test') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'sftp_user not denormalised into column'];
        }
        if ((int) $data['ssl_enabled'] !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'ssl_enabled column not set'];
        }
        if ($data['imported_at'] === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'imported_at not populated'];
        }
    });

$harness->test('adopt', 'adoptExisting skips when row already present (idempotent)',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-adopt-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;

        $r1 = $machine->adoptExisting($domain, ['php_version' => '8.3'], $actor);
        $r2 = $machine->adoptExisting($domain, ['php_version' => '8.4'], $actor);

        if ($r2['inserted'] !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second adopt inserted again'];
        }
        if ($r2['already_existed'] !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second adopt did not flag already_existed'];
        }
        if ($r2['site_id'] !== $r1['site_id']) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'site_id changed between adopt calls'];
        }
        // Without overwrite the second adopt MUST NOT clobber values.
        $row = $pdo->prepare('SELECT php_version FROM sites WHERE id = ?');
        $row->execute([$r1['site_id']]);
        if ($row->fetchColumn() !== '8.3') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'php_version got mutated despite overwrite=false'];
        }
    });

$harness->test('adopt', 'adoptExisting --overwrite rewrites without changing site id',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-adopt-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;

        $r1 = $machine->adoptExisting($domain, ['php_version' => '8.2'], $actor);
        $r2 = $machine->adoptExisting($domain, ['php_version' => '8.4'], $actor, overwrite: true);

        if ($r2['site_id'] !== $r1['site_id']) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'overwrite mutated site_id'];
        }
        $row = $pdo->prepare('SELECT php_version FROM sites WHERE id = ?');
        $row->execute([$r1['site_id']]);
        if ($row->fetchColumn() !== '8.4') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'overwrite did not refresh php_version'];
        }
    });

$harness->test('adopt', 'adoptExisting writes a site_adopted_existing audit row',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-adopt-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;

        $machine->adoptExisting($domain, ['php_version' => '8.3'], $actor);
        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE site_domain = ? AND action = 'site_adopted_existing'"
        );
        $count->execute([$domain]);
        if ((int) $count->fetchColumn() < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'site_adopted_existing audit row not written'];
        }
    });

$harness->test('audit', 'transition writes audit row with before/after snapshot',
    function () use (&$machine, &$pdo, &$actor, &$testDomains) {
        $domain = '[flowone_test_]ssm-' . bin2hex(random_bytes(4)) . '.local';
        $testDomains[] = $domain;
        $pdo->prepare(
            'INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
              VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$domain, 'active', 'provisioning']);
        $id = (int) $pdo->lastInsertId();

        $machine->transition(
            $id, 'provisioning', 'active', 'create succeeded', $actor,
            extraAfter: ['ssl_enabled' => true]
        );

        $row = $pdo->prepare(
            'SELECT action, actor_username, reason, before_snapshot, after_snapshot
               FROM site_audit_log
              WHERE site_domain = ?
              ORDER BY id DESC LIMIT 1'
        );
        $row->execute([$domain]);
        $r = $row->fetch();
        if (!$r) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'no audit row'];
        }
        if ($r['action'] !== 'state_transition') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wrong action: ' . $r['action']];
        }
        $before = json_decode($r['before_snapshot'], true);
        $after = json_decode($r['after_snapshot'], true);
        if (($before['actual_state'] ?? null) !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wrong before snapshot'];
        }
        if (($after['actual_state'] ?? null) !== 'active' || ($after['ssl_enabled'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wrong after snapshot'];
        }
    });

exit($harness->run());
