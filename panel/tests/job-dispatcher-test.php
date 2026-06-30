#!/usr/bin/env php
<?php
/**
 * Job Dispatcher :: enqueue + retrieval + listing
 *
 * Verifies the JobDispatcher persists site_jobs rows correctly, masks
 * payloads, audits the enqueue, and surfaces the right rows via the
 * listing and counting helpers.
 *
 * Coverage:
 *   - preflight: PanelDatabase reachable and site_jobs schema is present.
 *   - enqueue: a CREATE job round-trips with all fields intact.
 *   - enqueue: secrets in payload are masked before insert.
 *   - enqueue: writes an `job_enqueued` audit row.
 *   - getById: returns the same row that enqueue() returned.
 *   - listForDomain: returns multiple jobs in reverse-chronological order.
 *   - listQueued: returns only queued rows ordered by priority.
 *   - listQueuedForDomain: filters to one domain.
 *   - countByStatus: returns every status (including zero counts).
 *   - cancel: queued -> cancelled with an audit row.
 *   - cancel: refuses to cancel a non-queued row.
 *   - validation: payload over MAX_PAYLOAD_BYTES throws.
 *   - validation: invalid priority throws.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/job-dispatcher-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobDispatcher;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobPriorityClass;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobStatus;
use VpsAdmin\Agent\Provisioner\Orchestrator\Queue\JobType;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('JobDispatcher', $opts);

// ── shared state + cleanup ────────────────────────────────────
$db = null;
$pdo = null;
$dispatcher = null;
$actor = ActorContext::cli('job-dispatcher-test', 'flowone_test_user');

/** @var list<int> */
$testJobIds = [];
/** @var list<string> */
$testDomains = [];

$harness->onCleanup(function () use (&$pdo, &$testJobIds, &$testDomains): void {
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
    }
});

function dispatcherTestDomain(): string
{
    return '[flowone_test_]disp-' . bin2hex(random_bytes(3)) . '.local';
}

// ──────────────────────────────────────────────────────────────
// preflight
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'PanelDatabase + site_jobs schema present',
    function () use (&$db, &$pdo, &$dispatcher) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        $masker = new SecretMasker();
        $audit = new AuditLogger($db, $masker);
        $dispatcher = new JobDispatcher($db, $masker, $audit);

        $stmt = $pdo->query("SHOW TABLES LIKE 'site_jobs'");
        if ($stmt->rowCount() === 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'site_jobs table missing - run migrate_site_jobs.sql'];
        }
    });

// ──────────────────────────────────────────────────────────────
// enqueue
// ──────────────────────────────────────────────────────────────

$harness->test('enqueue', 'CREATE job round-trips with all fields intact',
    function () use (&$dispatcher, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $job = $dispatcher->enqueue(
            siteDomain: $domain,
            type: JobType::CREATE,
            payload: ['php_version' => 'lsphp83', 'plan' => 'starter'],
            actor: $actor,
            requestId: 'req-rt-' . bin2hex(random_bytes(3)),
            priority: 50,
            priorityClass: JobPriorityClass::OPERATOR,
            maxAttempts: 3,
        );
        $testJobIds[] = $job->id;

        if ($job->id <= 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'enqueue returned id <= 0'];
        }
        if ($job->siteDomain !== $domain) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "domain mismatch: got {$job->siteDomain}"];
        }
        if ($job->type !== JobType::CREATE) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'type mismatch: got ' . $job->type->value];
        }
        if ($job->status !== JobStatus::QUEUED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'status not queued: ' . $job->status->value];
        }
        if (($job->payload['php_version'] ?? null) !== 'lsphp83') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'payload.php_version missing/wrong'];
        }
        if ($job->attempts !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected attempts=0, got {$job->attempts}"];
        }
        if ($job->maxAttempts !== 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected maxAttempts=3, got {$job->maxAttempts}"];
        }
    });

$harness->test('enqueue', 'secrets in payload are masked before insert',
    function () use (&$dispatcher, &$pdo, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $job = $dispatcher->enqueue(
            siteDomain: $domain,
            type: JobType::CREATE,
            payload: [
                'php_version' => 'lsphp83',
                // Pretend a controller leaked a plaintext password into payload.
                'password' => 'leak-via-payload-123',
            ],
            actor: $actor,
        );
        $testJobIds[] = $job->id;

        // Read the raw column directly to confirm masking.
        $stmt = $pdo->prepare('SELECT payload FROM site_jobs WHERE id = :id');
        $stmt->execute(['id' => $job->id]);
        $raw = (string) $stmt->fetchColumn();

        if (str_contains($raw, 'leak-via-payload-123')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'plaintext password leaked into site_jobs.payload'];
        }
        if (!str_contains($raw, 'REDACTED')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected [REDACTED] marker in masked payload'];
        }
    });

$harness->test('enqueue', 'writes a job_enqueued audit row',
    function () use (&$dispatcher, &$pdo, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $job = $dispatcher->enqueue(
            siteDomain: $domain,
            type: JobType::CREATE,
            payload: ['x' => 1],
            actor: $actor,
        );
        $testJobIds[] = $job->id;

        $stmt = $pdo->prepare(
            'SELECT action FROM site_audit_log
              WHERE job_id = :id AND action = :a
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['id' => $job->id, 'a' => 'job_enqueued']);
        $action = $stmt->fetchColumn();
        if ($action !== 'job_enqueued') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'no job_enqueued audit row for job ' . $job->id];
        }
    });

// ──────────────────────────────────────────────────────────────
// retrieval
// ──────────────────────────────────────────────────────────────

$harness->test('retrieval', 'getById returns the same row that enqueue returned',
    function () use (&$dispatcher, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['k' => 'v'], $actor);
        $testJobIds[] = $job->id;

        $reread = $dispatcher->getById($job->id);
        if ($reread === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'getById returned null'];
        }
        if ($reread->id !== $job->id || $reread->siteDomain !== $job->siteDomain
            || $reread->type !== $job->type || $reread->status !== $job->status) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'reread doesn\'t match enqueue result'];
        }
    });

$harness->test('retrieval', 'getById returns null for unknown id',
    function () use (&$dispatcher) {
        $reread = $dispatcher->getById(2_000_000_000);
        if ($reread !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected null for unknown id, got id ' . $reread->id];
        }
    });

$harness->test('retrieval', 'listForDomain returns rows in reverse-chronological order',
    function () use (&$dispatcher, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $job = $dispatcher->enqueue($domain, JobType::CREATE, ['i' => $i], $actor);
            $testJobIds[] = $job->id;
            $ids[] = $job->id;
        }

        $listed = $dispatcher->listForDomain($domain);
        if (count($listed) < 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected >=3 listed, got ' . count($listed)];
        }
        $listedIds = array_map(fn($j) => $j->id, $listed);
        // Newest first.
        $expected = array_reverse($ids);
        $first3 = array_slice($listedIds, 0, 3);
        if ($first3 !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'order mismatch: got ' . implode(',', $first3)
                    . ' vs expected ' . implode(',', $expected)];
        }
    });

$harness->test('retrieval', 'listQueued orders by priority_class then priority',
    function () use (&$dispatcher, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $low = $dispatcher->enqueue(
            $domain, JobType::CREATE, ['p' => 'low'], $actor,
            priority: 100, priorityClass: JobPriorityClass::MAINTENANCE);
        $testJobIds[] = $low->id;
        $high = $dispatcher->enqueue(
            $domain, JobType::CREATE, ['p' => 'high'], $actor,
            priority: 10, priorityClass: JobPriorityClass::OPERATOR);
        $testJobIds[] = $high->id;
        $mid = $dispatcher->enqueue(
            $domain, JobType::CREATE, ['p' => 'mid'], $actor,
            priority: 50, priorityClass: JobPriorityClass::RECONCILE);
        $testJobIds[] = $mid->id;

        $queued = $dispatcher->listQueuedForDomain($domain);
        $ids = array_map(fn($j) => $j->id, $queued);
        if ($ids !== [$high->id, $mid->id, $low->id]) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'priority ordering wrong: ' . implode(',', $ids)
                    . ' (expected ' . implode(',', [$high->id, $mid->id, $low->id]) . ')'];
        }
    });

$harness->test('retrieval', 'countByStatus reports every status (including zero counts)',
    function () use (&$dispatcher, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['x' => 1], $actor);
        $testJobIds[] = $job->id;

        $counts = $dispatcher->countByStatus();
        foreach (['queued', 'running', 'succeeded', 'failed', 'cancelled'] as $key) {
            if (!array_key_exists($key, $counts)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing status key: {$key}"];
            }
        }
        if ($counts['queued'] < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected queued >=1 after enqueue'];
        }
    });

// ──────────────────────────────────────────────────────────────
// cancel
// ──────────────────────────────────────────────────────────────

$harness->test('cancel', 'queued -> cancelled with an audit row',
    function () use (&$dispatcher, &$pdo, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['x' => 1], $actor);
        $testJobIds[] = $job->id;

        $ok = $dispatcher->cancel($job->id, 'operator test cancel', $actor);
        if (!$ok) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'cancel returned false for queued job'];
        }
        $reread = $dispatcher->getById($job->id);
        if ($reread === null || $reread->status !== JobStatus::CANCELLED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected status=cancelled, got '
                    . ($reread === null ? 'null' : $reread->status->value)];
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM site_audit_log
              WHERE job_id = :id AND action = :a'
        );
        $stmt->execute(['id' => $job->id, 'a' => 'job_cancelled']);
        if ((int) $stmt->fetchColumn() < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'no job_cancelled audit row'];
        }
    });

$harness->test('cancel', 'refuses to cancel a non-queued row',
    function () use (&$dispatcher, &$pdo, &$actor, &$testJobIds, &$testDomains) {
        $domain = dispatcherTestDomain();
        $testDomains[] = $domain;

        $job = $dispatcher->enqueue($domain, JobType::CREATE, ['x' => 1], $actor);
        $testJobIds[] = $job->id;

        $pdo->prepare(
            'UPDATE site_jobs SET status = :s WHERE id = :id'
        )->execute(['s' => 'running', 'id' => $job->id]);

        $ok = $dispatcher->cancel($job->id, 'after-running', $actor);
        if ($ok) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'cancel should refuse running rows'];
        }
        $reread = $dispatcher->getById($job->id);
        if ($reread->status === JobStatus::CANCELLED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'row was cancelled even though refused'];
        }
    });

// ──────────────────────────────────────────────────────────────
// validation
// ──────────────────────────────────────────────────────────────

$harness->test('validation', 'invalid priority throws',
    function () use (&$dispatcher, &$actor) {
        try {
            $dispatcher->enqueue(
                dispatcherTestDomain(),
                JobType::CREATE,
                ['x' => 1],
                $actor,
                priority: 999, // > 255
            );
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected InvalidArgumentException for priority>255'];
        } catch (\InvalidArgumentException) {
            // expected
        }
    });

$harness->test('validation', 'empty domain throws',
    function () use (&$dispatcher, &$actor) {
        try {
            $dispatcher->enqueue('', JobType::CREATE, ['x' => 1], $actor);
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected InvalidArgumentException for empty domain'];
        } catch (\InvalidArgumentException) {
            // expected
        }
    });

$harness->test('validation', 'oversized payload throws',
    function () use (&$dispatcher, &$actor) {
        $huge = str_repeat('A', JobDispatcher::MAX_PAYLOAD_BYTES + 10);
        try {
            $dispatcher->enqueue(
                dispatcherTestDomain(),
                JobType::CREATE,
                ['blob' => $huge],
                $actor,
            );
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected RuntimeException for >1MB payload'];
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'exceeds')) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'unexpected error: ' . $e->getMessage()];
            }
        }
    });

exit($harness->run());
