#!/usr/bin/env php
<?php
/**
 * Reconciler :: Drift detection + RECONCILE job enqueue (Step 7)
 *
 * Exercises the reconciler subsystem end-to-end with controlled drift:
 * we hand-craft `sites` rows (some with drift, some without), drive
 * the assessor with hand-built `SiteHealthProbe`s via the
 * `SiteProberInterface` seam (production adapters are `final` so we
 * cannot mock them), and assert the ReconcilerService's verdicts and
 * queue side effects.
 *
 * Coverage:
 *   - probe group (real adapters against a StepTestContext sandbox):
 *       * SiteProber: confirms vhost/home present after a sandbox seed
 *       * SiteProber: detects missing vhost + missing home dir
 *       * SiteProber: leaves DB + SFTP unevaluated when the row omits them
 *   - assess group (pure DriftAssessor logic, no DB):
 *       * all decision branches (healthy, reconcile, skip, degrade)
 *         including severity classification + skipReason routing
 *   - service group (live DB):
 *       * ReconcilerService scans only eligible actual_states
 *       * RECONCILE job enqueued when drift detected
 *       * Existing in-flight CREATE blocks reconcile enqueue
 *       * DEGRADE_ONLY transitions active -> degraded via SiteStateMachine
 *       * Audit row written for every RECONCILE enqueue
 *       * desired_state=deleted with artifacts present -> DEGRADE
 *       * degraded site that probes healthy is healed back to active
 *       * dry-run performs no writes (no enqueue / heal / transition)
 *
 * Test data uses the `[flowone_test_]` domain prefix so cleanup is
 * unambiguous.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/reconciler-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1800));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobDispatcher;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobType;
use VpsAdmin\Agent\Provisioner\Reconciler\DriftAssessment;
use VpsAdmin\Agent\Provisioner\Reconciler\DriftAssessor;
use VpsAdmin\Agent\Provisioner\Reconciler\ReconcilerService;
use VpsAdmin\Agent\Provisioner\Reconciler\SiteHealthProbe;
use VpsAdmin\Agent\Provisioner\Reconciler\SiteProberInterface;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Reconciler\SiteProber;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('Reconciler', $opts);

// ── shared state + cleanup ────────────────────────────────────
$db = null;
$pdo = null;
$dispatcher = null;
$stateMachine = null;
$audit = null;

/** @var list<string> */
$testDomains = [];
/** @var list<int> */
$testJobIds = [];
/** @var list<int> */
$testSiteIds = [];

$harness->onCleanup(function () use (&$pdo, &$testDomains, &$testJobIds, &$testSiteIds): void {
    if (!$pdo) {
        return;
    }
    if ($testJobIds) {
        $in = implode(',', array_fill(0, count($testJobIds), '?'));
        @$pdo->prepare("DELETE FROM site_audit_log WHERE job_id IN ({$in})")->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_jobs WHERE id IN ({$in})")->execute($testJobIds);
    }
    if ($testDomains) {
        $in = implode(',', array_fill(0, count($testDomains), '?'));
        @$pdo->prepare("DELETE FROM site_audit_log WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_jobs WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM sites WHERE domain IN ({$in})")->execute($testDomains);
    }
});

function rcTestDomain(): string
{
    return '[flowone_test_]rc-' . bin2hex(random_bytes(3)) . '.local';
}

/**
 * Programmable fake prober. Tests register, by domain, the
 * SiteHealthProbe they want the reconciler to receive. Domains that
 * aren't registered get a "all healthy" probe.
 *
 * Why a hand-rolled fake rather than a real SiteProber: the production
 * prober calls into FilesystemAdapter/OlsAdapter/MysqlAdapter/SftpAdapter,
 * all of which are `final` for safety reasons. The reconciler's
 * SiteProberInterface seam lets us bypass them entirely and drive the
 * decision tree with hand-rolled drift scenarios.
 */
final class FakeProber implements SiteProberInterface
{
    /** @var array<string, SiteHealthProbe> */
    private array $byDomain = [];

    public function register(SiteHealthProbe $probe): void
    {
        $this->byDomain[$probe->domain] = $probe;
    }

    public function probe(array $siteRow): SiteHealthProbe
    {
        $domain = (string) ($siteRow['domain'] ?? '');
        if (isset($this->byDomain[$domain])) {
            return $this->byDomain[$domain];
        }
        // Default: every probed subsystem reports present. Subsystems
        // not declared on the row are unevaluated.
        return new SiteHealthProbe(
            domain: $domain,
            vhostConfigPresent: true,
            homeDirPresent: true,
            documentRootPresent: true,
            databasePresent: isset($siteRow['db_name']) && $siteRow['db_name'] !== '' ? true : null,
            databaseUserPresent: isset($siteRow['db_user']) && $siteRow['db_user'] !== '' ? true : null,
            sftpUserPresent: isset($siteRow['sftp_user']) && $siteRow['sftp_user'] !== '' ? true : null,
            sftpGroupPresent: isset($siteRow['sftp_user']) && $siteRow['sftp_user'] !== '' ? true : null,
            probedAtUnix: microtime(true),
        );
    }
}

// ──────────────────────────────────────────────────────────────
// preflight
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'PanelDatabase + sites/site_jobs schemas present',
    function () use (&$db, &$pdo, &$dispatcher, &$stateMachine, &$audit) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        foreach (['sites', 'site_jobs', 'site_audit_log'] as $t) {
            if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->rowCount() === 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "table {$t} missing"];
            }
        }
        $masker = new SecretMasker();
        $audit = new AuditLogger($db, $masker);
        $dispatcher = new JobDispatcher($db, $masker, $audit);
        $stateMachine = new SiteStateMachine($db, $audit);
    });

// ──────────────────────────────────────────────────────────────
// probe (real adapters against a StepTestContext sandbox)
// ──────────────────────────────────────────────────────────────

$probeSandboxes = [];
$harness->onCleanup(function () use (&$probeSandboxes) {
    foreach ($probeSandboxes as $b) {
        StepTestContext::teardown($b);
    }
});

$harness->test('probe', 'SiteProber sees vhost+home present after a sandbox seed',
    function () use (&$probeSandboxes) {
        $bundle = StepTestContext::build();
        $probeSandboxes[] = $bundle;

        $domain = $bundle['ctx']->domain();
        $home = '/tmp/flowone_test_home_' . bin2hex(random_bytes(3));
        @mkdir($home . '/public_html', 0750, true);

        // Seed an OLS vhost config so vhostConfigExists() returns true.
        $bundle['ols']->writeVhostConfig($domain, "docRoot $home/public_html\n");

        $adapters = new \VpsAdmin\Agent\Provisioner\Adapters\Adapters(
            $bundle['runner'], $bundle['fs'], $bundle['ols'],
            $bundle['mysql'], $bundle['sftp'], $bundle['nas']
        );
        $prober = new SiteProber($adapters);

        $probe = $prober->probe([
            'domain' => $domain,
            'home_dir' => $home,
            // No db_name / sftp_user -> those probes return null (unevaluated).
        ]);

        @exec('rm -rf ' . escapeshellarg($home));

        if (!in_array('vhost', $probe->present(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected vhost present (sandbox has the file)'];
        }
        if (!in_array('home', $probe->present(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected home present (seeded dir)'];
        }
        if (!in_array('document_root', $probe->present(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected document_root present'];
        }
    });

$harness->test('probe', 'SiteProber detects missing vhost + missing home',
    function () use (&$probeSandboxes) {
        $bundle = StepTestContext::build();
        $probeSandboxes[] = $bundle;

        $adapters = new \VpsAdmin\Agent\Provisioner\Adapters\Adapters(
            $bundle['runner'], $bundle['fs'], $bundle['ols'],
            $bundle['mysql'], $bundle['sftp'], $bundle['nas']
        );
        $prober = new SiteProber($adapters);

        $probe = $prober->probe([
            'domain' => 'flowone_test_doesnotexist.local',
            'home_dir' => '/tmp/flowone_test_missing_' . bin2hex(random_bytes(3)),
        ]);
        if (!in_array('vhost', $probe->missing(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected vhost missing'];
        }
        if (!in_array('home', $probe->missing(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected home missing'];
        }
    });

$harness->test('probe', 'SiteProber leaves DB + SFTP unevaluated when row omits them',
    function () use (&$probeSandboxes) {
        $bundle = StepTestContext::build();
        $probeSandboxes[] = $bundle;
        $adapters = new \VpsAdmin\Agent\Provisioner\Adapters\Adapters(
            $bundle['runner'], $bundle['fs'], $bundle['ols'],
            $bundle['mysql'], $bundle['sftp'], $bundle['nas']
        );
        $prober = new SiteProber($adapters);

        $probe = $prober->probe(['domain' => 'flowone_test_no_extras.local']);
        $u = $probe->unevaluated();
        foreach (['database', 'database_user', 'sftp_user', 'sftp_group'] as $expected) {
            if (!in_array($expected, $u, true)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "expected {$expected} unevaluated, got: " . implode(',', $u)];
            }
        }
    });

// ──────────────────────────────────────────────────────────────
// assess (pure - DriftAssessor logic)
// ──────────────────────────────────────────────────────────────

$harness->test('assess', 'all present + desired=active -> HEALTHY',
    function () {
        $a = new DriftAssessor();
        $probe = new SiteHealthProbe('x.example', true, true, true, null, null, null, null);
        $verdict = $a->assess(['domain' => 'x.example', 'desired_state' => 'active', 'actual_state' => 'active'], $probe);
        if (!$verdict->isHealthy()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected HEALTHY, got ' . $verdict->recommendation];
        }
    });

$harness->test('assess', 'missing vhost + desired=active -> RECONCILE/high',
    function () {
        $a = new DriftAssessor();
        $probe = new SiteHealthProbe('x.example', false, true, true, null, null, null, null);
        $verdict = $a->assess(['domain' => 'x.example', 'desired_state' => 'active', 'actual_state' => 'active'], $probe);
        if (!$verdict->needsReconcile()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected RECONCILE'];
        }
        if ($verdict->severity !== DriftAssessment::SEVERITY_HIGH) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected HIGH severity'];
        }
    });

$harness->test('assess', 'missing only db_user -> RECONCILE/medium',
    function () {
        $a = new DriftAssessor();
        $probe = new SiteHealthProbe('x.example', true, true, true, true, false, true, true);
        $verdict = $a->assess(['domain' => 'x.example', 'desired_state' => 'active', 'actual_state' => 'active'], $probe);
        if (!$verdict->needsReconcile()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected RECONCILE'];
        }
        if ($verdict->severity !== DriftAssessment::SEVERITY_MEDIUM) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected MEDIUM severity, got ' . $verdict->severity];
        }
    });

$harness->test('assess', 'actual=provisioning -> SKIP/in_flight',
    function () {
        $a = new DriftAssessor();
        $probe = new SiteHealthProbe('x.example', false, false, false, null, null, null, null);
        $verdict = $a->assess(['domain' => 'x.example', 'desired_state' => 'active', 'actual_state' => 'provisioning'], $probe);
        if (!$verdict->wasSkipped()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SKIP for in-flight saga'];
        }
        if ($verdict->skipReason !== 'in_flight') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected skipReason=in_flight'];
        }
    });

$harness->test('assess', 'actual=suspended -> SKIP/suspended',
    function () {
        $a = new DriftAssessor();
        $probe = new SiteHealthProbe('x.example', true, true, true, null, null, null, null);
        $verdict = $a->assess(['domain' => 'x.example', 'desired_state' => 'active', 'actual_state' => 'suspended'], $probe);
        if (!$verdict->wasSkipped() || $verdict->skipReason !== 'suspended') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SKIP/suspended'];
        }
    });

$harness->test('assess', 'desired=deleted but artifacts present -> DEGRADE',
    function () {
        $a = new DriftAssessor();
        $probe = new SiteHealthProbe('x.example', true, true, true, null, null, null, null);
        $verdict = $a->assess(['domain' => 'x.example', 'desired_state' => 'deleted', 'actual_state' => 'active'], $probe);
        if (!$verdict->needsDegrade()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected DEGRADE recommendation'];
        }
    });

$harness->test('assess', 'probe errors -> SKIP/probe_errors',
    function () {
        $a = new DriftAssessor();
        $probe = new SiteHealthProbe('x.example', null, null, null, null, null, null, null,
            errors: ['mysql admin creds missing']);
        $verdict = $a->assess(['domain' => 'x.example', 'desired_state' => 'active', 'actual_state' => 'active'], $probe);
        if (!$verdict->wasSkipped() || $verdict->skipReason !== 'probe_errors') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SKIP/probe_errors'];
        }
    });

// ──────────────────────────────────────────────────────────────
// service (live DB)
// ──────────────────────────────────────────────────────────────

$harness->test('service', 'scan enqueues RECONCILE for drifted site',
    function () use (&$db, &$pdo, &$dispatcher, &$stateMachine, &$audit, &$testDomains, &$testJobIds) {
        $domain = rcTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:d, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['d' => $domain]);

        // Probe reports vhost missing -> reconcile expected.
        $prober = new FakeProber();
        $prober->register(new SiteHealthProbe(
            domain: $domain,
            vhostConfigPresent: false,
            homeDirPresent: true,
            documentRootPresent: true,
            databasePresent: null,
            databaseUserPresent: null,
            sftpUserPresent: null,
            sftpGroupPresent: null,
        ));
        $service = new ReconcilerService(
            database: $db,
            dispatcher: $dispatcher,
            stateMachine: $stateMachine,
            audit: $audit,
            prober: $prober,
            assessor: new DriftAssessor(),
        );
        $run = $service->scan();

        // capture all jobs for this domain so cleanup doesn't leak
        $rows = $pdo->prepare('SELECT id FROM site_jobs WHERE site_domain = :d');
        $rows->execute(['d' => $domain]);
        foreach ($rows->fetchAll(\PDO::FETCH_COLUMN) as $jid) {
            $testJobIds[] = (int) $jid;
        }

        if ($run->sitesReconciled < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected at least 1 reconciled, got ' . $run->sitesReconciled];
        }
        $found = false;
        foreach ($run->assessments as $v) {
            if ($v->domain === $domain && $v->needsReconcile()) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected reconcile assessment for ' . $domain];
        }

        // Confirm a job_jobs row was actually written.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_jobs WHERE site_domain = :d AND type = 'reconcile'"
        );
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected at least 1 reconcile row in site_jobs'];
        }

        // Audit row.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE site_domain = :d AND action = 'reconciler_enqueued'"
        );
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected reconciler_enqueued audit row'];
        }
    });

$harness->test('service', 'existing in-flight CREATE blocks reconcile enqueue',
    function () use (&$db, &$pdo, &$dispatcher, &$stateMachine, &$audit, &$testDomains, &$testJobIds) {
        $domain = rcTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:d, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['d' => $domain]);

        // Seed an in-flight CREATE.
        $existing = $dispatcher->enqueue(
            siteDomain: $domain,
            type: JobType::CREATE,
            payload: ['x' => 1],
            actor: ActorContext::cli('reconciler-test'),
        );
        $testJobIds[] = $existing->id;

        $prober = new FakeProber();
        $prober->register(new SiteHealthProbe(
            domain: $domain,
            vhostConfigPresent: false,
            homeDirPresent: true,
            documentRootPresent: true,
            databasePresent: null,
            databaseUserPresent: null,
            sftpUserPresent: null,
            sftpGroupPresent: null,
        ));
        $service = new ReconcilerService(
            database: $db,
            dispatcher: $dispatcher,
            stateMachine: $stateMachine,
            audit: $audit,
            prober: $prober,
            assessor: new DriftAssessor(),
        );
        $run = $service->scan();

        // capture extra jobs (should be none added, but safe)
        $rows = $pdo->prepare('SELECT id FROM site_jobs WHERE site_domain = :d');
        $rows->execute(['d' => $domain]);
        foreach ($rows->fetchAll(\PDO::FETCH_COLUMN) as $jid) {
            $testJobIds[] = (int) $jid;
        }

        if ($run->sitesReconciled > 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 0 enqueues with in-flight CREATE, got ' . $run->sitesReconciled];
        }
        $blockedSeen = false;
        foreach ($run->skippedEnqueues as $s) {
            if ($s['domain'] === $domain && $s['reason'] === 'existing_job_in_flight') {
                $blockedSeen = true;
                break;
            }
        }
        if (!$blockedSeen) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected skippedEnqueues to mention in-flight block for ' . $domain];
        }

        // Only one site_jobs row should exist for this domain.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM site_jobs WHERE site_domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected exactly 1 job row, got ' . $stmt->fetchColumn()];
        }
    });

$harness->test('service', 'desired=deleted with artifacts present -> degrade transition',
    function () use (&$db, &$pdo, &$dispatcher, &$stateMachine, &$audit, &$testDomains) {
        $domain = rcTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:d, 'deleted', 'active', '{}', NOW(), NOW())"
        )->execute(['d' => $domain]);

        // Probe reports everything present -> degrade because desired=deleted.
        $prober = new FakeProber();
        $prober->register(new SiteHealthProbe(
            domain: $domain,
            vhostConfigPresent: true,
            homeDirPresent: true,
            documentRootPresent: true,
            databasePresent: null,
            databaseUserPresent: null,
            sftpUserPresent: null,
            sftpGroupPresent: null,
        ));
        $service = new ReconcilerService(
            database: $db,
            dispatcher: $dispatcher,
            stateMachine: $stateMachine,
            audit: $audit,
            prober: $prober,
            assessor: new DriftAssessor(),
        );
        $run = $service->scan();

        if ($run->sitesDegraded < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected at least 1 degraded, got ' . $run->sitesDegraded];
        }
        $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((string) $stmt->fetchColumn() !== 'degraded') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected actual_state=degraded'];
        }
    });

$harness->test('service', 'degraded site that probes healthy is healed back to active',
    function () use (&$db, &$pdo, &$dispatcher, &$stateMachine, &$audit, &$testDomains) {
        $domain = rcTestDomain();
        $testDomains[] = $domain;
        // A site that was parked in `degraded` by an earlier failed step
        // (e.g. the DB create) but whose artifacts are all present now
        // that the operator fixed the underlying problem.
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:d, 'active', 'degraded', '{}', NOW(), NOW())"
        )->execute(['d' => $domain]);

        // Probe reports all core subsystems present -> HEALTHY.
        $prober = new FakeProber();
        $prober->register(new SiteHealthProbe(
            domain: $domain,
            vhostConfigPresent: true,
            homeDirPresent: true,
            documentRootPresent: true,
            databasePresent: null,
            databaseUserPresent: null,
            sftpUserPresent: null,
            sftpGroupPresent: null,
        ));
        $service = new ReconcilerService(
            database: $db,
            dispatcher: $dispatcher,
            stateMachine: $stateMachine,
            audit: $audit,
            prober: $prober,
            assessor: new DriftAssessor(),
        );
        $run = $service->scan();

        if ($run->sitesHealed < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected at least 1 healed, got ' . $run->sitesHealed];
        }
        $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((string) $stmt->fetchColumn() !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected actual_state=active after heal'];
        }
        // Audit trail must record the heal.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE site_domain = :d AND action = 'reconciler_healed'"
        );
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected reconciler_healed audit row'];
        }
    });

$harness->test('service', 'dry-run performs no writes: no enqueue, no heal, no transition',
    function () use (&$db, &$pdo, &$dispatcher, &$stateMachine, &$audit, &$testDomains) {
        $drift = rcTestDomain();
        $deg = rcTestDomain();
        $testDomains[] = $drift;
        $testDomains[] = $deg;
        // One drifted active site (would normally enqueue RECONCILE) and
        // one degraded-but-healthy site (would normally heal to active).
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:d, 'active', 'active', '{}', NOW(), NOW())"
        )->execute(['d' => $drift]);
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:d, 'active', 'degraded', '{}', NOW(), NOW())"
        )->execute(['d' => $deg]);

        $prober = new FakeProber();
        $prober->register(new SiteHealthProbe(
            domain: $drift,
            vhostConfigPresent: false, // missing -> would reconcile
            homeDirPresent: true,
            documentRootPresent: true,
            databasePresent: null,
            databaseUserPresent: null,
            sftpUserPresent: null,
            sftpGroupPresent: null,
        ));
        $prober->register(new SiteHealthProbe(
            domain: $deg,
            vhostConfigPresent: true, // all present -> would heal
            homeDirPresent: true,
            documentRootPresent: true,
            databasePresent: null,
            databaseUserPresent: null,
            sftpUserPresent: null,
            sftpGroupPresent: null,
        ));
        $service = new ReconcilerService(
            database: $db,
            dispatcher: $dispatcher,
            stateMachine: $stateMachine,
            audit: $audit,
            prober: $prober,
            assessor: new DriftAssessor(),
            batchSize: 200,
            dryRun: true,
        );
        $run = $service->scan();

        // No mutations happened.
        if ($run->sitesReconciled !== 0 || $run->sitesHealed !== 0 || $run->sitesDegraded !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => sprintf(
                    'dry-run mutated: reconciled=%d healed=%d degraded=%d',
                    $run->sitesReconciled, $run->sitesHealed, $run->sitesDegraded
                )];
        }
        // The degraded site is untouched.
        $stmt = $pdo->prepare('SELECT actual_state FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $deg]);
        if ((string) $stmt->fetchColumn() !== 'degraded') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'dry-run healed a degraded site (should not)'];
        }
        // No job rows were written for the drifted domain.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM site_jobs WHERE site_domain = :d');
        $stmt->execute(['d' => $drift]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'dry-run enqueued a job (should not)'];
        }
        // The plan is still visible via skippedEnqueues(reason=dry_run).
        $planned = false;
        foreach ($run->skippedEnqueues as $s) {
            if ($s['domain'] === $drift && ($s['reason'] ?? '') === 'dry_run') {
                $planned = true;
                break;
            }
        }
        if (!$planned) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected dry_run skip entry for drifted domain'];
        }
    });

$harness->test('service', 'scan ignores sites in non-eligible actual_states',
    function () use (&$db, &$pdo, &$dispatcher, &$stateMachine, &$audit, &$testDomains) {
        $domain = rcTestDomain();
        $testDomains[] = $domain;
        $pdo->prepare(
            "INSERT INTO sites (domain, desired_state, actual_state, config, created_at, updated_at)
               VALUES (:d, 'active', 'suspended', '{}', NOW(), NOW())"
        )->execute(['d' => $domain]);

        $prober = new FakeProber();
        $prober->register(new SiteHealthProbe(
            domain: $domain,
            vhostConfigPresent: false,
            homeDirPresent: true,
            documentRootPresent: true,
            databasePresent: null,
            databaseUserPresent: null,
            sftpUserPresent: null,
            sftpGroupPresent: null,
        ));
        $service = new ReconcilerService(
            database: $db,
            dispatcher: $dispatcher,
            stateMachine: $stateMachine,
            audit: $audit,
            prober: $prober,
            assessor: new DriftAssessor(),
        );
        $run = $service->scan();

        // The suspended domain should NOT appear in the scan at all
        // (filtered out by fetchEligibleSites).
        foreach ($run->assessments as $v) {
            if ($v->domain === $domain) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'suspended site was scanned despite ineligibility'];
            }
        }
    });

exit($harness->run());
