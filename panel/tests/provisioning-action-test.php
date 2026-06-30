#!/usr/bin/env php
<?php
/**
 * Provisioning Action :: HTTP-facing queue + sites query layer (Step 6)
 *
 * Exercises the agent's ProvisioningAction class directly (no socket
 * round-trip) against the live MariaDB so we can verify the HTTP-facing
 * surface end-to-end without standing up a webserver.
 *
 * Coverage:
 *   - preflight: agent action registers, PanelDatabase reachable, sites +
 *     site_jobs schemas present.
 *   - enqueueCreate: enqueues + writes sites row + writes audit row.
 *   - enqueueCreate: duplicate request returns the existing in-flight job.
 *   - enqueueCreate: rejects bad domains.
 *   - enqueueCreate: masks plaintext secrets in payload before insert.
 *   - enqueueDelete: rejects unknown domains.
 *   - enqueueDelete: flips sites.actual_state to 'deleting' before enqueue.
 *   - enqueueDelete: duplicate request returns the existing in-flight job.
 *   - listSites: paginates + filters + RBAC-friendly fields.
 *   - getSite: returns site + recent jobs + audit summary.
 *   - listJobs: filters by status / type / domain + reports status_counts.
 *   - getJob: returns job + step executions + event tail.
 *   - getJobEvents: tails events after since_id, surfaces job_terminal.
 *   - cancelJob: queued -> cancelled with audit, refuses other states.
 *   - retryJob: failed parent -> new job with parent_job_id.
 *   - retryJob: refuses to retry a succeeded job.
 *   - error envelope: standard agent error shape on every failure path.
 *
 * All test data uses a recognisable `flowone-test-` domain prefix and
 * the `.test` reserved TLD so
 * the cleanup callback can purge with confidence even if the test
 * crashes mid-run.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-action-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'skip-send', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1800));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Actions\ProvisioningAction;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ProvisioningAction', $opts);

// ── shared state + cleanup ────────────────────────────────────
$action = null;
$pdo = null;
$db = null;

/** @var list<string> */
$testDomains = [];
/** @var list<int> */
$testJobIds = [];

$harness->onCleanup(function () use (&$pdo, &$testJobIds, &$testDomains): void {
    if (!$pdo) {
        return;
    }
    if ($testJobIds) {
        $in = implode(',', array_fill(0, count($testJobIds), '?'));
        @$pdo->prepare("DELETE FROM site_job_events WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_step_executions WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_audit_log WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_jobs WHERE id IN ({$in})")->execute($testJobIds);
    }
    if ($testDomains) {
        $in = implode(',', array_fill(0, count($testDomains), '?'));
        @$pdo->prepare("DELETE FROM site_job_events WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_step_executions WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_audit_log WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_jobs WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM sites WHERE domain IN ({$in})")->execute($testDomains);
        try {
            @$pdo->prepare("DELETE FROM site_locks WHERE domain IN ({$in})")->execute($testDomains);
        } catch (\Throwable) {
            // site_locks may be absent on this install
        }
    }
});

function paTestDomain(): string
{
    // The agent's requireDomain() validator rejects underscores and
    // square brackets. We still want a recognisable test-data prefix
    // (so cleanup can be safe and a stray production query can never
    // collide), so we use the hyphen-and-`.test`-TLD form: every
    // test domain ends in `.test` (RFC 6761 reserved for testing) and
    // begins with `flowone-test-pa-` so leftover rows are obviously
    // not production data.
    return 'flowone-test-pa-' . bin2hex(random_bytes(3)) . '.test';
}

/**
 * Helper: capture all newly-inserted site_jobs ids so cleanup can purge
 * them even when a test fails mid-flight. We tag-and-cleanup by domain,
 * but explicit id tracking keeps cleanup fast and idempotent.
 */
function trackJobsFor(\PDO $pdo, string $domain, array &$testJobIds): void
{
    $stmt = $pdo->prepare('SELECT id FROM site_jobs WHERE site_domain = :d');
    $stmt->execute(['d' => $domain]);
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
        $testJobIds[] = (int) $id;
    }
}

// ──────────────────────────────────────────────────────────────
// preflight
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'PanelDatabase + sites/site_jobs/site_audit_log schemas present',
    function () use (&$db, &$pdo, &$action) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');

        foreach (['sites', 'site_jobs', 'site_audit_log', 'site_job_events', 'site_step_executions'] as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            if ($stmt->rowCount() === 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing table {$table} (run migrations first)"];
            }
        }

        // Build the action with minimal stubs. The Logger writes to a
        // temp file so we don't pollute the agent log under test.
        $tmp = sys_get_temp_dir();
        $config = [
            'logging' => [
                'file' => $tmp . '/flowone_test_provisioning_action.log',
                'level' => 'warning',
            ],
            'backup' => [
                'max_age_days' => 7,
                'max_count' => 10,
            ],
            'paths' => [
                'base' => $tmp,
                'backups' => $tmp . '/flowone_test_backups',
            ],
        ];
        $logger = new Logger($config);
        $backup = new BackupManager($config);
        $diff = new DiffGenerator();
        $action = new ProvisioningAction($config, $backup, $diff, $logger);

        if ($action->getNamespace() !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'wrong namespace: ' . $action->getNamespace()];
        }
        $methods = $action->getMethods();
        foreach (['enqueueCreate', 'enqueueDelete', 'purgeTombstone',
                  'listSites', 'getSite',
                  'listJobs', 'getJob', 'getJobEvents', 'cancelJob', 'retryJob'] as $m) {
            if (!in_array($m, $methods, true)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "method {$m} missing from getMethods()"];
            }
        }
    });

// ──────────────────────────────────────────────────────────────
// enqueueCreate
// ──────────────────────────────────────────────────────────────

$harness->test('create', 'enqueueCreate inserts sites row + site_jobs row + audit row',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;

        $res = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['php_version' => 'lsphp83'],
            'actor_username' => 'flowone_test_user',
            'actor_user_id' => 999,
            'source_ip' => '127.0.0.1',
            'request_id' => 'req-create-' . bin2hex(random_bytes(3)),
        ], 'flowone_test_user');

        if (!($res['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'enqueueCreate failed: ' . ($res['error'] ?? 'unknown')];
        }
        $job = $res['data']['job'] ?? null;
        $site = $res['data']['site'] ?? null;
        if ($job === null || $site === null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'missing job/site in response'];
        }
        $testJobIds[] = (int) $job['id'];

        if (($job['status'] ?? null) !== JobStatus::QUEUED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'job not queued: ' . ($job['status'] ?? 'null')];
        }
        if (($site['actual_state'] ?? null) !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'site actual_state not provisioning: ' . ($site['actual_state'] ?? 'null')];
        }
        if (($res['data']['duplicate'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first request should not be flagged duplicate'];
        }

        // Audit row check.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE site_domain = :d AND action IN ('state_transition', 'job_enqueued')"
        );
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() < 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected at least 2 audit rows (state + enqueue)'];
        }
    });

$harness->test('create', 'duplicate enqueueCreate returns the existing in-flight job',
    function () use (&$action, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;

        $r1 = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['php_version' => 'lsphp83'],
            'actor_user_id' => 1,
        ], 'tester');
        if (!($r1['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first enqueue failed: ' . ($r1['error'] ?? '?')];
        }
        $firstId = (int) $r1['data']['job']['id'];
        $testJobIds[] = $firstId;

        $r2 = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['php_version' => 'lsphp83'],
            'actor_user_id' => 1,
        ], 'tester');
        if (!($r2['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second enqueue failed: ' . ($r2['error'] ?? '?')];
        }
        if (($r2['data']['duplicate'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second request not flagged duplicate'];
        }
        if ((int) $r2['data']['job']['id'] !== $firstId) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'duplicate returned a different job id'];
        }
    });

$harness->test('create', 'enqueueCreate rejects empty + malformed domains',
    function () use (&$action) {
        $r1 = $action->execute('enqueueCreate', ['domain' => ''], 'tester');
        if (($r1['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'empty domain accepted'];
        }
        $r2 = $action->execute('enqueueCreate', ['domain' => 'NOT A DOMAIN'], 'tester');
        if (($r2['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'spaces in domain accepted'];
        }
        $longDomain = str_repeat('a', 254) . '.test';
        $r3 = $action->execute('enqueueCreate', ['domain' => $longDomain], 'tester');
        if (($r3['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'overlong domain accepted'];
        }
    });

$harness->test('create', 'plaintext secrets in payload are masked before insert',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;

        $secret = 'leak-via-payload-' . bin2hex(random_bytes(4));
        $res = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['php_version' => 'lsphp83', 'db_password' => $secret],
            'actor_user_id' => 1,
        ], 'tester');
        if (!($res['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'enqueueCreate failed: ' . ($res['error'] ?? '?')];
        }
        $jobId = (int) $res['data']['job']['id'];
        $testJobIds[] = $jobId;

        $stmt = $pdo->prepare('SELECT payload FROM site_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $raw = (string) $stmt->fetchColumn();
        if (str_contains($raw, $secret)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'plaintext secret leaked into site_jobs.payload'];
        }
        // The response payload should also be masked.
        $respPayload = json_encode($res['data']['job']['payload'] ?? []);
        if (str_contains((string) $respPayload, $secret)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'plaintext secret leaked into HTTP response payload'];
        }
    });

// ──────────────────────────────────────────────────────────────
// enqueue guards (June 2026 orphan-row fixes)
// ──────────────────────────────────────────────────────────────

$harness->test('guards', 'enqueueCreate rejects when the site is in a live state',
    function () use (&$action, &$pdo, &$testDomains) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
              VALUES (?, 'active', 'active', NOW(), NOW())"
        )->execute([$domain]);

        $res = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'actor_user_id' => 1,
        ], 'tester');

        if (($res['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'CREATE against a live active site was accepted'];
        }
        if (($res['data']['code'] ?? null) !== 'already_exists') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected code=already_exists, got " . json_encode($res['data'] ?? null)];
        }
        $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE domain = ?');
        $stmt->execute([$domain]);
        if ($stmt->fetchColumn() !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'rejected CREATE still mutated the live row'];
        }
    });

$harness->test('guards', 'enqueueCreate resurrects a parked failed row into provisioning',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, last_error, created_at, updated_at)
              VALUES (?, 'active', 'failed', 'old create error', NOW(), NOW())"
        )->execute([$domain]);

        $res = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'actor_user_id' => 1,
        ], 'tester');
        if (!($res['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 're-create of failed row rejected: ' . ($res['error'] ?? '?')];
        }
        $testJobIds[] = (int) $res['data']['job']['id'];

        $stmt = $pdo->prepare('SELECT actual_state, last_error FROM sites WHERE domain = ?');
        $stmt->execute([$domain]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row['actual_state'] !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "row not resurrected: actual_state='{$row['actual_state']}'"];
        }
        if ($row['last_error'] !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'stale last_error survived the resurrection'];
        }
    });

$harness->test('guards', 'enqueueCreate rejects while a DELETE job is in flight (cross-type)',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
              VALUES (?, 'absent', 'deleting', NOW(), NOW())"
        )->execute([$domain]);
        $pdo->prepare(
            "INSERT INTO site_jobs
                (site_domain, type, status, priority, priority_class, payload,
                 schema_version, attempts, max_attempts, dry_run, actor, enqueued_at)
              VALUES (?, 'delete', 'running', 50, 'operator', '{}', 1, 0, 3, 0, 'test', NOW(3))"
        )->execute([$domain]);
        $testJobIds[] = (int) $pdo->lastInsertId();

        $res = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'actor_user_id' => 1,
        ], 'tester');
        if (($res['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'CREATE accepted while a DELETE saga is mid-run'];
        }
        if (($res['data']['code'] ?? null) !== 'conflicting_job') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected code=conflicting_job, got " . json_encode($res['data'] ?? null)];
        }
    });

$harness->test('guards', 'enqueueDelete rejects while a CREATE job is in flight (cross-type)',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, created_at, updated_at)
              VALUES (?, 'active', 'provisioning', NOW(), NOW())"
        )->execute([$domain]);
        $pdo->prepare(
            "INSERT INTO site_jobs
                (site_domain, type, status, priority, priority_class, payload,
                 schema_version, attempts, max_attempts, dry_run, actor, enqueued_at)
              VALUES (?, 'create', 'queued', 50, 'operator', '{}', 1, 0, 3, 0, 'test', NOW(3))"
        )->execute([$domain]);
        $testJobIds[] = (int) $pdo->lastInsertId();

        $res = $action->execute('enqueueDelete', [
            'domain' => $domain,
            'actor_user_id' => 1,
        ], 'tester');
        if (($res['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'DELETE accepted while a CREATE saga is in flight'];
        }
        if (($res['data']['code'] ?? null) !== 'conflicting_job') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected code=conflicting_job, got " . json_encode($res['data'] ?? null)];
        }
        // The pre-transition must NOT have run: row still provisioning.
        $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE domain = ?');
        $stmt->execute([$domain]);
        if ($stmt->fetchColumn() !== 'provisioning') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'rejected DELETE still pre-transitioned the row'];
        }
    });

$harness->test('guards', 'enqueue is rejected with code=locked while the SiteLock is held',
    function () use (&$action, &$db, &$testDomains) {
        $domain = paTestDomain();
        $testDomains[] = $domain;

        $lock = new \VpsAdmin\Agent\Provisioner\Services\SiteLock($db);
        $handle = $lock->tryAcquire($domain, 'pa-test-holder', 'guard-test', null, 30);
        if ($handle === null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'could not acquire the test lock (leftover holder?)'];
        }
        try {
            $res = $action->execute('enqueueCreate', [
                'domain' => $domain,
                'actor_user_id' => 1,
            ], 'tester');
            if (($res['success'] ?? true) !== false) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'enqueueCreate succeeded despite a held site lock'];
            }
            if (($res['data']['code'] ?? null) !== 'locked') {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "expected code=locked, got " . json_encode($res['data'] ?? null)];
            }
        } finally {
            $handle->release();
        }
    });

// ──────────────────────────────────────────────────────────────
// enqueueDelete
// ──────────────────────────────────────────────────────────────

$harness->test('delete', 'enqueueDelete rejects unknown domain with 404-shaped envelope',
    function () use (&$action) {
        $domain = 'flowone-test-nonexistent-' . bin2hex(random_bytes(3)) . '.test';
        $res = $action->execute('enqueueDelete', [
            'domain' => $domain,
            'actor_user_id' => 1,
        ], 'tester');
        if (($res['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'delete of unknown domain succeeded'];
        }
        $code = $res['data']['code'] ?? null;
        if ($code !== 'not_found') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected code=not_found, got " . var_export($code, true)];
        }
    });

$harness->test('delete', 'enqueueDelete on an active site flips actual_state -> deleting',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;

        // Manually insert a site in 'active' state so we can verify the
        // transition without running the full CREATE saga first.
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);

        $res = $action->execute('enqueueDelete', [
            'domain' => $domain,
            'actor_user_id' => 7,
            'source_ip' => '192.0.2.1',
        ], 'tester');
        if (!($res['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'enqueueDelete failed: ' . ($res['error'] ?? '?')];
        }
        $jobId = (int) $res['data']['job']['id'];
        $testJobIds[] = $jobId;

        // Site should be in 'deleting' now, with operator intent recorded.
        $stmt = $pdo->prepare('SELECT actual_state, desired_state FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (($row['actual_state'] ?? null) !== 'deleting') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected actual_state=deleting, got '{$row['actual_state']}'"];
        }
        // Without this, a finished delete reads as desired=active /
        // actual=absent - the same signature as an orphaned create.
        if (($row['desired_state'] ?? null) !== 'deleted') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected desired_state=deleted, got '{$row['desired_state']}'"];
        }
        if (($res['data']['job']['type'] ?? null) !== 'delete') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'job type not delete'];
        }
    });

$harness->test('delete', 'duplicate enqueueDelete returns the existing in-flight job',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;

        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);

        $r1 = $action->execute('enqueueDelete', ['domain' => $domain, 'actor_user_id' => 1], 'tester');
        if (!($r1['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first delete enqueue failed'];
        }
        $firstId = (int) $r1['data']['job']['id'];
        $testJobIds[] = $firstId;

        $r2 = $action->execute('enqueueDelete', ['domain' => $domain, 'actor_user_id' => 1], 'tester');
        if (!($r2['success'] ?? false) || ($r2['data']['duplicate'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second delete enqueue not deduplicated'];
        }
        if ((int) $r2['data']['job']['id'] !== $firstId) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'duplicate returned a different job id'];
        }
    });

// ──────────────────────────────────────────────────────────────
// listSites / getSite
// ──────────────────────────────────────────────────────────────

$harness->test('read', 'listSites paginates and filters by actual_state',
    function () use (&$action, &$pdo, &$testDomains) {
        // Seed three sites in distinct states.
        $domains = [];
        foreach (['active', 'provisioning', 'failed'] as $state) {
            $d = paTestDomain();
            $domains[] = $d;
            $testDomains[] = $d;
            $pdo->prepare(
                "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
                   VALUES (:domain, 'active', :state, '{}', NOW(), NOW())"
            )->execute(['domain' => $d, 'state' => $state]);
        }

        $r = $action->execute('listSites', ['actual_state' => 'failed', 'per_page' => 100], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'listSites failed'];
        }
        $rows = $r['data']['sites'] ?? [];
        $foundFailed = false;
        foreach ($rows as $row) {
            if (($row['actual_state'] ?? null) !== 'failed') {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'state filter returned non-matching row: ' . ($row['domain'] ?? '?')];
            }
            if (($row['domain'] ?? '') === $domains[2]) {
                $foundFailed = true;
            }
        }
        if (!$foundFailed) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected to see the seeded failed domain in results'];
        }
        if (!isset($r['data']['pagination']['page'], $r['data']['pagination']['per_page'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'pagination block missing'];
        }
    });

// Regression for the "near '.actual_state'" SQL syntax error: the
// alias-rewrite regex used to also rewrite the placeholder name
// (':actual_state' -> ':s.actual_state'), which PDO parsed as the
// placeholder ':s' followed by literal '.actual_state' SQL. The fix
// is a negative lookbehind that excludes ':' (placeholder), '.'
// (already-aliased), and '\w' (defensive). This test exercises every
// filter that touches a column name the regex knows about, so a
// future regression on any of (actual_state, desired_state, domain)
// would surface here as a 1064 error rather than as a UI 500.
$harness->test('read', 'listSites does not break placeholder names when rewriting aliases',
    function () use (&$action, &$pdo, &$testDomains) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);

        // Each combination must run cleanly (no PDOException). We're
        // not asserting result shape here - the filter-correctness
        // assertion lives in the "paginates and filters by
        // actual_state" test above. This test's value is binary:
        // does the SQL parse?
        $combos = [
            ['actual_state' => 'active'],
            ['desired_state' => 'active'],
            ['actual_state' => 'active', 'desired_state' => 'active'],
            ['search' => substr($domain, 0, 12)],
            ['actual_state' => 'active', 'search' => substr($domain, 0, 12)],
        ];
        foreach ($combos as $params) {
            try {
                $r = $action->execute('listSites',
                    array_merge($params, ['per_page' => 50]),
                    'tester'
                );
            } catch (\Throwable $e) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'execute threw on params=' . json_encode($params)
                        . ': ' . $e->getMessage()];
            }
            if (!($r['success'] ?? false)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'listSites returned error on params=' . json_encode($params)
                        . ': ' . ($r['error'] ?? 'unknown')];
            }
        }
    });

// Default listSites view must hide tombstones (rows whose lifecycle
// landed in actual_state='absent' after a successful delete saga). The
// row is preserved for audit / snapshot reference, but operators
// should only see live sites unless they explicitly opt in.
$harness->test('read', 'listSites excludes absent (tombstone) rows by default',
    function () use (&$action, &$pdo, &$testDomains) {
        $absent = paTestDomain();
        $active = paTestDomain();
        $testDomains[] = $absent;
        $testDomains[] = $active;
        $pdo->prepare(
            // A real tombstone has desired_state='deleted' + actual_state='absent'
            // (operator wanted it gone, the saga drove reality there). The
            // 'deleted' enum is what desired_state allows; 'absent' belongs
            // to actual_state.
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:absent, 'deleted', 'absent', '{}', NOW(), NOW()),
                      (:active, 'active',  'active', '{}', NOW(), NOW())"
        )->execute(['absent' => $absent, 'active' => $active]);

        $r = $action->execute('listSites', ['per_page' => 200], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'listSites failed'];
        }
        $rows = $r['data']['sites'] ?? [];
        $sawAbsent = false;
        $sawActive = false;
        foreach ($rows as $row) {
            $d = $row['domain'] ?? '';
            if ($d === $absent) {
                $sawAbsent = true;
            }
            if ($d === $active) {
                $sawActive = true;
            }
        }
        if ($sawAbsent) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'tombstone row leaked into default listSites response'];
        }
        if (!$sawActive) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'default listSites failed to return the active row'];
        }
    });

// Template metadata is joined into the v2 list response via the
// template_deployments table. Operators need has_template_backup and
// template_type on every site so the table can render the template
// badge and the "apply template" / "revert" actions without an
// extra round-trip per row.
$harness->test('read', 'listSites projects template_type + has_template_backup when a template is deployed',
    function () use (&$action, &$pdo, &$testDomains) {
        // Schema check: skip cleanly if template_deployments is
        // missing on this host (e.g. a fresh dev box without the
        // legacy migration applied).
        $hasTable = $pdo->query("SHOW TABLES LIKE 'template_deployments'")->rowCount() > 0;
        if (!$hasTable) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'template_deployments table not present on this host'];
        }

        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);
        $pdo->prepare(
            "INSERT INTO template_deployments (domain, template_type, deployed_by)
               VALUES (:domain, 'site_placeholder', 'tester')"
        )->execute(['domain' => $domain]);
        // Cleanup hook for the join table (the per-domain WHERE in
        // the harness's main onCleanup doesn't cover this one).
        register_shutdown_function(function () use ($pdo, $domain) {
            @$pdo->prepare('DELETE FROM template_deployments WHERE domain = :d')
                ->execute(['d' => $domain]);
        });

        $r = $action->execute('listSites', [
            'search' => substr($domain, 0, 18),
            'per_page' => 100,
        ], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'listSites failed: ' . ($r['error'] ?? 'unknown')];
        }
        $row = null;
        foreach (($r['data']['sites'] ?? []) as $site) {
            if (($site['domain'] ?? '') === $domain) {
                $row = $site;
                break;
            }
        }
        if ($row === null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'seeded domain not present in response'];
        }
        if (($row['template_type'] ?? null) !== 'site_placeholder') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected template_type=site_placeholder, got " . var_export($row['template_type'] ?? null, true)];
        }
        if (($row['has_template_backup'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected has_template_backup=true'];
        }
    });

// Sites WITHOUT a template_deployments row must report
// has_template_backup=false (not null, not missing) so the frontend
// can render a stable "no template applied" affordance.
$harness->test('read', 'listSites reports has_template_backup=false for sites without a deployment',
    function () use (&$action, &$pdo, &$testDomains) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);

        $r = $action->execute('listSites', [
            'search' => substr($domain, 0, 18),
            'per_page' => 100,
        ], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'listSites failed'];
        }
        $row = null;
        foreach (($r['data']['sites'] ?? []) as $site) {
            if (($site['domain'] ?? '') === $domain) {
                $row = $site;
                break;
            }
        }
        if ($row === null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'seeded domain not present in response'];
        }
        if (($row['has_template_backup'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected has_template_backup=false, got "
                    . var_export($row['has_template_backup'] ?? null, true)];
        }
        if (($row['template_type'] ?? 'unset') !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected template_type=null, got "
                    . var_export($row['template_type'] ?? 'unset', true)];
        }
    });

$harness->test('read', 'listSites returns absent rows when actual_state=absent is explicitly requested',
    function () use (&$action, &$pdo, &$testDomains) {
        $absent = paTestDomain();
        $testDomains[] = $absent;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'deleted', 'absent', '{}', NOW(), NOW())"
        )->execute(['domain' => $absent]);

        $r = $action->execute('listSites', ['actual_state' => 'absent', 'per_page' => 200], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'listSites failed'];
        }
        $rows = $r['data']['sites'] ?? [];
        $sawAbsent = false;
        foreach ($rows as $row) {
            if (($row['domain'] ?? '') === $absent) {
                $sawAbsent = true;
            }
            if (($row['actual_state'] ?? null) !== 'absent') {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'explicit absent filter returned non-absent row: ' . ($row['domain'] ?? '?')];
            }
        }
        if (!$sawAbsent) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected to see the seeded absent domain when filtering for absent'];
        }
    });

// ──────────────────────────────────────────────────────────────
// purgeTombstone
// ──────────────────────────────────────────────────────────────

$harness->test('purge', 'purgeTombstone fails on unknown domain',
    function () use (&$action) {
        if ($action === null) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'preflight not run - invoke with --only=preflight,purge'];
        }
        $r = $action->execute('purgeTombstone', [
            'domain' => 'flowone-test-nope-' . bin2hex(random_bytes(3)) . '.test',
        ], 'tester');
        if (($r['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'purgeTombstone of unknown domain succeeded'];
        }
        if (($r['data']['code'] ?? null) !== 'not_found') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected code=not_found, got " . var_export($r['data']['code'] ?? null, true)];
        }
    });

// Safety guard: a LIVE site must NEVER be purgeable. The only path
// that produces an actual_state='absent' row is the full DELETE
// saga, which has already snapshotted + torn down the live
// resources. If we let operators short-circuit that pipeline, a
// fat-fingered click could wipe a production site without a backup.
$harness->test('purge', 'purgeTombstone refuses to touch a live site',
    function () use (&$action, &$pdo, &$testDomains) {
        if ($action === null || $pdo === null) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'preflight not run - invoke with --only=preflight,purge'];
        }
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);

        $r = $action->execute('purgeTombstone', ['domain' => $domain], 'tester');
        if (($r['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'purgeTombstone of an ACTIVE site succeeded - data-loss risk'];
        }
        if (($r['data']['code'] ?? null) !== 'not_a_tombstone') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected code=not_a_tombstone, got " . var_export($r['data']['code'] ?? null, true)];
        }
        if (($r['data']['actual_state'] ?? null) !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected actual_state=active in error payload, got "
                    . var_export($r['data']['actual_state'] ?? null, true)];
        }

        // The site must still be there afterwards.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'live site was deleted by a refused purge - CRITICAL'];
        }
    });

$harness->test('purge', 'dry_run reports row counts without applying changes',
    function () use (&$action, &$pdo, &$testDomains) {
        if ($action === null || $pdo === null) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'preflight not run - invoke with --only=preflight,purge'];
        }
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'deleted', 'absent', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);
        // Two audit rows. Use distinct placeholders per row - PDO's
        // native prepared-statement protocol (no emulation) requires
        // unique placeholders even when the value is identical.
        $pdo->prepare(
            "INSERT INTO site_audit_log (occurred_at, action, site_domain, actor_username)
               VALUES (NOW(), 'test', :d1, 'tester'),
                      (NOW(), 'test', :d2, 'tester')"
        )->execute(['d1' => $domain, 'd2' => $domain]);

        $r = $action->execute('purgeTombstone', [
            'domain' => $domain,
            'dry_run' => true,
        ], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'dry_run failed: ' . ($r['error'] ?? 'unknown')];
        }
        if (($r['data']['dry_run'] ?? null) !== true) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected dry_run=true in response'];
        }
        $counts = $r['data']['rows_to_delete'] ?? [];
        if (($counts['sites'] ?? null) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected rows_to_delete.sites=1'];
        }
        if (($counts['site_audit_log'] ?? null) !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected rows_to_delete.site_audit_log=2, got "
                    . var_export($counts['site_audit_log'] ?? null, true)];
        }

        // Verify NOTHING was actually deleted.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'sites row missing after dry_run - dry_run was destructive'];
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM site_audit_log WHERE site_domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'audit rows missing after dry_run - dry_run was destructive'];
        }
    });

$harness->test('purge', 'full purge removes sites row + all dependent history',
    function () use (&$action, &$pdo, &$testDomains) {
        if ($action === null || $pdo === null) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'preflight not run - invoke with --only=preflight,purge'];
        }
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'deleted', 'absent', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);
        $pdo->prepare(
            "INSERT INTO site_audit_log (occurred_at, action, site_domain, actor_username)
               VALUES (NOW(), 'state_change', :d1, 'tester'),
                      (NOW(), 'job_finished',  :d2, 'tester')"
        )->execute(['d1' => $domain, 'd2' => $domain]);
        $pdo->prepare(
            "INSERT INTO site_jobs (site_domain, type, status, payload, actor, enqueued_at)
               VALUES (:domain, 'delete', 'succeeded', '{}', 'tester', NOW())"
        )->execute(['domain' => $domain]);

        $r = $action->execute('purgeTombstone', ['domain' => $domain], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'purge failed: ' . ($r['error'] ?? 'unknown')];
        }
        $rowsDeleted = $r['data']['rows_deleted'] ?? [];
        if (($rowsDeleted['sites'] ?? null) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected rows_deleted.sites=1'];
        }
        if (($rowsDeleted['site_audit_log'] ?? null) !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected rows_deleted.site_audit_log=2, got "
                    . var_export($rowsDeleted['site_audit_log'] ?? null, true)];
        }
        if (($rowsDeleted['site_jobs'] ?? null) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected rows_deleted.site_jobs=1"];
        }

        // Verify on-disk: no traces remain.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'sites row survived purge'];
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM site_audit_log WHERE site_domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'audit rows survived purge'];
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM site_jobs WHERE site_domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'job rows survived purge'];
        }
    });

// The June 2026 leftovers: a purged tombstone must also reclaim the
// related panel registries (mail_domains / dns zones / database_links)
// or the domain keeps resurfacing in the Mail Security, DNS and
// Databases views after the site itself is long gone.
$harness->test('purge', 'purge wipes related mail/dns/database_links registries',
    function () use (&$action, &$pdo, &$testDomains) {
        if ($action === null || $pdo === null) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'preflight not run - invoke with --only=preflight,purge'];
        }
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'deleted', 'absent', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);

        // Seed whichever related registries exist on this install.
        $seeded = [];
        foreach ([
            'mail_domains' => ["INSERT INTO mail_domains (domain, status) VALUES (?, 'active')", [$domain]],
            'database_links' => [
                "INSERT INTO database_links (db_name, domain) VALUES (?, ?)",
                ['flowone_test_pg_' . substr(bin2hex(random_bytes(2)), 0, 4), $domain],
            ],
            'dns_domains' => ["INSERT INTO dns_domains (name, type) VALUES (?, 'NATIVE')", [$domain]],
        ] as $label => [$sql, $args]) {
            try {
                $pdo->prepare($sql)->execute($args);
                $seeded[] = $label;
            } catch (\Throwable) {
                // registry table absent here - the purge must still work
            }
        }
        if ($seeded === []) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'no related registry tables on this install'];
        }

        try {
            $r = $action->execute('purgeTombstone', ['domain' => $domain], 'tester');
            if (!($r['success'] ?? false)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'purge failed: ' . ($r['error'] ?? 'unknown')];
            }
            $related = $r['data']['related_registries'] ?? null;
            if (!is_array($related)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'related_registries missing from purge response'];
            }

            foreach ($seeded as $label) {
                $column = $label === 'dns_domains' ? 'name' : 'domain';
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$label} WHERE {$column} = ?");
                $stmt->execute([$domain]);
                if ((int) $stmt->fetchColumn() !== 0) {
                    return ['outcome' => TestHarness::FAIL,
                        'message' => "{$label} row survived the tombstone purge"];
                }
                if (($related[$label] ?? null) !== 1) {
                    return ['outcome' => TestHarness::FAIL,
                        'message' => "related_registries.{$label} should be 1, got "
                            . var_export($related[$label] ?? null, true)];
                }
            }
        } finally {
            // Belt-and-braces: remove seeds even if the purge FAILed.
            foreach ([
                "DELETE FROM mail_domains WHERE domain = ?",
                "DELETE FROM database_links WHERE domain = ?",
                "DELETE FROM dns_domains WHERE name = ?",
            ] as $sql) {
                try {
                    $pdo->prepare($sql)->execute([$domain]);
                } catch (\Throwable) {
                }
            }
        }
    });

// Verify the snapshot dir cleanup path stays scoped to the
// snapshot root - a malformed domain or path traversal must NEVER
// escape into the wider filesystem.
$harness->test('purge', 'snapshot cleanup is sandboxed to the snapshot root',
    function () use (&$action, &$pdo, &$testDomains) {
        if ($action === null || $pdo === null) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'preflight not run - invoke with --only=preflight,purge'];
        }
        $snapshotRoot = '/var/www/vps-admin/storage/snapshots';
        if (!is_dir($snapshotRoot)) {
            return ['outcome' => TestHarness::SKIP,
                'message' => "snapshot root {$snapshotRoot} not present on this host"];
        }
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $dir = $snapshotRoot . '/' . $domain;
        if (!@mkdir($dir, 0700, true)) {
            return ['outcome' => TestHarness::SKIP,
                'message' => "cannot create test snapshot dir at {$dir} (perms?)"];
        }
        @file_put_contents($dir . '/marker.txt', 'flowone_test marker');

        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:domain, 'deleted', 'absent', '{}', NOW(), NOW())"
        )->execute(['domain' => $domain]);

        $r = $action->execute('purgeTombstone', ['domain' => $domain], 'tester');
        if (!($r['success'] ?? false)) {
            @unlink($dir . '/marker.txt');
            @rmdir($dir);
            return ['outcome' => TestHarness::FAIL,
                'message' => 'purge failed: ' . ($r['error'] ?? 'unknown')];
        }
        if (($r['data']['snapshot_removed'] ?? null) !== true) {
            // Permissions might prevent rm; warn rather than fail.
            @unlink($dir . '/marker.txt');
            @rmdir($dir);
            return ['outcome' => TestHarness::WARN,
                'message' => 'snapshot_removed was false; check directory perms'];
        }
        if (is_dir($dir)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "snapshot dir {$dir} still exists after purge"];
        }
        // And confirm the SNAPSHOT ROOT was NOT removed.
        if (!is_dir($snapshotRoot)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'CRITICAL: snapshot ROOT was deleted by the purge - sandbox failed'];
        }
    });

$harness->test('read', 'getSite returns 404-shape for unknown domain',
    function () use (&$action) {
        $r = $action->execute('getSite', [
            'domain' => 'flowone-test-none-' . bin2hex(random_bytes(3)) . '.test',
        ], 'tester');
        if (($r['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'getSite of unknown domain succeeded'];
        }
        if (($r['data']['code'] ?? null) !== 'not_found') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected code=not_found'];
        }
    });

$harness->test('read', 'getSite returns site + recent jobs + audit summary',
    function () use (&$action, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;

        $created = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['php_version' => 'lsphp83'],
            'actor_user_id' => 5,
        ], 'tester');
        if (!($created['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'precondition enqueue failed'];
        }
        $testJobIds[] = (int) $created['data']['job']['id'];

        $r = $action->execute('getSite', ['domain' => $domain], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'getSite failed: ' . ($r['error'] ?? '?')];
        }
        if (($r['data']['site']['domain'] ?? null) !== $domain) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'site.domain missing/wrong'];
        }
        if (!isset($r['data']['jobs']) || !is_array($r['data']['jobs']) || count($r['data']['jobs']) < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected at least 1 recent job'];
        }
        if (!isset($r['data']['audit']) || !is_array($r['data']['audit'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'audit block missing'];
        }
    });

// ──────────────────────────────────────────────────────────────
// listJobs / getJob / getJobEvents
// ──────────────────────────────────────────────────────────────

$harness->test('jobs', 'listJobs filters by domain + returns status_counts',
    function () use (&$action, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $created = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['x' => 1],
            'actor_user_id' => 1,
        ], 'tester');
        if (!($created['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'precondition failed'];
        }
        $testJobIds[] = (int) $created['data']['job']['id'];

        $r = $action->execute('listJobs', ['domain' => $domain], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'listJobs failed'];
        }
        $jobs = $r['data']['jobs'] ?? [];
        if (count($jobs) < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected at least 1 job for domain ' . $domain];
        }
        foreach ($jobs as $j) {
            if (($j['site_domain'] ?? null) !== $domain) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'domain filter leaked: ' . ($j['site_domain'] ?? '?')];
            }
        }
        if (!isset($r['data']['status_counts']) || !is_array($r['data']['status_counts'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'status_counts missing'];
        }
        foreach (['queued', 'running', 'succeeded', 'failed', 'cancelled'] as $st) {
            if (!array_key_exists($st, $r['data']['status_counts'])) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "status_counts missing '{$st}'"];
            }
        }
    });

$harness->test('jobs', 'getJob returns 404-shape for unknown id',
    function () use (&$action) {
        $r = $action->execute('getJob', ['id' => 2_000_000_000], 'tester');
        if (($r['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'getJob of unknown id succeeded'];
        }
        if (($r['data']['code'] ?? null) !== 'not_found') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected code=not_found'];
        }
    });

$harness->test('jobs', 'getJob bundles job + steps + events + site',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $created = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['x' => 'getjob'],
            'actor_user_id' => 1,
        ], 'tester');
        if (!($created['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'precondition failed'];
        }
        $jobId = (int) $created['data']['job']['id'];
        $testJobIds[] = $jobId;

        // Inject a fake step execution + event so the read endpoint has
        // something to return without running the worker.
        $pdo->prepare(
            "INSERT INTO site_step_executions
               (job_id, site_domain, step_name, attempt_number,
                started_at, finished_at, duration_ms, outcome, exit_code,
                input_snapshot, output_snapshot, worker_id, request_id)
             VALUES
               (:job_id, :domain, 'fake_step', 1,
                NOW(3), NOW(3), 42, 'success', 0,
                :input, :output, 'flowone_test_worker', :req)"
        )->execute([
            'job_id' => $jobId,
            'domain' => $domain,
            'input' => '{}',
            'output' => '{"ok":true}',
            'req' => 'req-test',
        ]);
        $pdo->prepare(
            "INSERT INTO site_job_events
               (job_id, site_domain, step_name, level, message, metadata, request_id, occurred_at)
             VALUES
               (:job_id, :domain, 'fake_step', 'info', 'fake event', :meta, :req, NOW(3))"
        )->execute([
            'job_id' => $jobId,
            'domain' => $domain,
            'meta' => '{"k":"v"}',
            'req' => 'req-test',
        ]);

        $r = $action->execute('getJob', ['id' => $jobId], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'getJob failed: ' . ($r['error'] ?? '?')];
        }
        if (($r['data']['job']['id'] ?? null) !== $jobId) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'job.id mismatch'];
        }
        $steps = $r['data']['steps'] ?? [];
        if (count($steps) === 0 || ($steps[0]['step_name'] ?? null) !== 'fake_step') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'fake_step not visible in steps'];
        }
        $events = $r['data']['events'] ?? [];
        if (count($events) === 0 || ($events[0]['step_name'] ?? null) !== 'fake_step') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'fake event not visible'];
        }
        if (($r['data']['site']['domain'] ?? null) !== $domain) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'site bundle missing'];
        }
    });

$harness->test('jobs', 'getJobEvents tails after since_id and reports terminal flag',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $created = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['x' => 'events'],
            'actor_user_id' => 1,
        ], 'tester');
        if (!($created['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'precondition failed'];
        }
        $jobId = (int) $created['data']['job']['id'];
        $testJobIds[] = $jobId;

        // Inject 3 events.
        for ($i = 0; $i < 3; $i++) {
            $pdo->prepare(
                "INSERT INTO site_job_events
                   (job_id, site_domain, step_name, level, message, request_id, occurred_at)
                 VALUES
                   (:job_id, :domain, 's', 'info', :msg, 'req', NOW(3))"
            )->execute([
                'job_id' => $jobId,
                'domain' => $domain,
                'msg' => "event {$i}",
            ]);
        }

        $r1 = $action->execute('getJobEvents', ['id' => $jobId, 'since_id' => 0, 'limit' => 2], 'tester');
        if (!($r1['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'getJobEvents first poll failed'];
        }
        if (count($r1['data']['events']) !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected exactly 2 events on first poll'];
        }
        $lastId = (int) $r1['data']['last_id'];

        $r2 = $action->execute('getJobEvents', ['id' => $jobId, 'since_id' => $lastId, 'limit' => 100], 'tester');
        if (!($r2['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'getJobEvents second poll failed'];
        }
        if (count($r2['data']['events']) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 1 remaining event after since_id, got '
                    . count($r2['data']['events'])];
        }
        if (($r2['data']['job_terminal'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'fresh queued job should not be terminal'];
        }
    });

// ──────────────────────────────────────────────────────────────
// cancelJob / retryJob
// ──────────────────────────────────────────────────────────────

$harness->test('control', 'cancelJob flips queued -> cancelled with audit row',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $created = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['x' => 'cancel'],
            'actor_user_id' => 1,
        ], 'tester');
        if (!($created['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'precondition failed'];
        }
        $jobId = (int) $created['data']['job']['id'];
        $testJobIds[] = $jobId;

        $r = $action->execute('cancelJob', [
            'id' => $jobId,
            'reason' => 'operator clicked cancel',
            'actor_user_id' => 1,
        ], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'cancelJob failed: ' . ($r['error'] ?? '?')];
        }

        $stmt = $pdo->prepare('SELECT status, error FROM site_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (($row['status'] ?? '') !== JobStatus::CANCELLED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'job not cancelled in DB: ' . ($row['status'] ?? 'null')];
        }
        if (!str_contains((string) $row['error'], 'operator clicked cancel')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'cancellation reason not stored in error column'];
        }

        // Audit row
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE job_id = :id AND action = 'job_cancelled'"
        );
        $stmt->execute(['id' => $jobId]);
        if ((int) $stmt->fetchColumn() < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'no job_cancelled audit row'];
        }
    });

$harness->test('control', 'cancelJob returns 404-shape for unknown id',
    function () use (&$action) {
        $r = $action->execute('cancelJob', ['id' => 2_000_000_001], 'tester');
        if (($r['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'cancel of unknown id succeeded'];
        }
        if (($r['data']['code'] ?? null) !== 'not_found') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected code=not_found'];
        }
    });

$harness->test('control', 'cancelJob refuses to cancel a succeeded job',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $created = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['x' => 'succeeded-cancel'],
            'actor_user_id' => 1,
        ], 'tester');
        $jobId = (int) $created['data']['job']['id'];
        $testJobIds[] = $jobId;

        // Pretend the worker finished this job.
        $pdo->prepare(
            "UPDATE site_jobs SET status = 'succeeded', finished_at = NOW(3) WHERE id = :id"
        )->execute(['id' => $jobId]);

        $r = $action->execute('cancelJob', ['id' => $jobId], 'tester');
        if (($r['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'cancel of succeeded job returned success'];
        }
        if (($r['data']['code'] ?? null) !== 'invalid_state') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected code=invalid_state'];
        }
    });

$harness->test('control', 'retryJob clones a failed job and links via parent_job_id',
    function () use (&$action, &$pdo, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $created = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['x' => 'retry'],
            'actor_user_id' => 1,
        ], 'tester');
        $parentId = (int) $created['data']['job']['id'];
        $testJobIds[] = $parentId;

        // Mark the parent failed to make it retryable.
        $pdo->prepare(
            "UPDATE site_jobs SET status = 'failed', finished_at = NOW(3),
                                  error = 'fake failure'
              WHERE id = :id"
        )->execute(['id' => $parentId]);

        $r = $action->execute('retryJob', [
            'id' => $parentId,
            'reason' => 'manual retry',
            'actor_user_id' => 1,
        ], 'tester');
        if (!($r['success'] ?? false)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'retryJob failed: ' . ($r['error'] ?? '?')];
        }
        $newId = (int) $r['data']['job']['id'];
        $testJobIds[] = $newId;
        if ($newId === $parentId) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'retry returned the same id as parent'];
        }
        if (($r['data']['job']['parent_job_id'] ?? null) !== $parentId) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'new job parent_job_id mismatch'];
        }
        if (($r['data']['job']['status'] ?? null) !== JobStatus::QUEUED->value) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'retry job not queued'];
        }
    });

$harness->test('control', 'retryJob refuses to retry a queued job',
    function () use (&$action, &$testDomains, &$testJobIds) {
        $domain = paTestDomain();
        $testDomains[] = $domain;
        $created = $action->execute('enqueueCreate', [
            'domain' => $domain,
            'payload' => ['x' => 'retry-queued'],
            'actor_user_id' => 1,
        ], 'tester');
        $jobId = (int) $created['data']['job']['id'];
        $testJobIds[] = $jobId;

        $r = $action->execute('retryJob', ['id' => $jobId], 'tester');
        if (($r['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'retry of queued job succeeded'];
        }
        if (($r['data']['code'] ?? null) !== 'invalid_state') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected code=invalid_state'];
        }
    });

// ──────────────────────────────────────────────────────────────
// envelope sanity (every method returns BaseAction's shape)
// ──────────────────────────────────────────────────────────────

$harness->test('envelope', 'every documented method returns success+data on happy path',
    function () use (&$action) {
        $methods = ['listSites', 'listJobs'];
        foreach ($methods as $m) {
            $r = $action->execute($m, ['per_page' => 1], 'tester');
            if (!isset($r['success'])) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "{$m}: missing 'success' key in envelope"];
            }
            if (!isset($r['data'])) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "{$m}: missing 'data' key in envelope"];
            }
        }
    });

$harness->test('envelope', 'unknown method returns success=false with helpful error',
    function () use (&$action) {
        $r = $action->execute('thisDoesNotExist', [], 'tester');
        if (($r['success'] ?? true) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unknown method returned success'];
        }
        if (!isset($r['error']) || !str_contains((string) $r['error'], 'Unknown')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unknown method error did not mention "Unknown"'];
        }
    });

exit($harness->run());
