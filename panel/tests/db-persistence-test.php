#!/usr/bin/env php
<?php
/**
 * DB-Backed Orchestrator Persistence :: DbStepStateStore + DbSagaEventSink
 *
 * Verifies the DB implementations of StepStateStore and SagaEventSink
 * against the live MariaDB schema. Tests are written so they:
 *
 *   - require migrations site_jobs / site_step_executions /
 *     site_job_events to be applied (preflight fails clearly otherwise),
 *   - never touch real production rows (every job + execution + event
 *     created here uses a [flowone_test_] domain prefix and is removed
 *     by the cleanup callback, including SIGINT/SIGTERM),
 *   - exercise the full round-trip: write a state, read it back,
 *     compare. Same for events.
 *
 * Coverage:
 *   - preflight: tables present with the expected columns
 *   - state_store: round-trip save -> load returns equal StepState
 *   - state_store: load returns the LATEST row when many exist
 *   - state_store: all() returns latest per step_name
 *   - state_store: secret masking happens on output_snapshot
 *   - state_store: clear() removes every row for the job
 *   - event_sink: emit() writes a row with the right step_name
 *   - event_sink: emitSaga() writes step_name=NULL
 *   - event_sink: drain() returns events in chronological order
 *   - event_sink: secret masking happens on metadata
 *   - event_sink: purge() removes every row for the job
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/db-persistence-test.php --verbose
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

use VpsAdmin\Agent\Provisioner\Orchestrator\DbSagaEventSink;
use VpsAdmin\Agent\Provisioner\Orchestrator\DbStepStateStore;
use VpsAdmin\Agent\Provisioner\Orchestrator\InMemorySagaEventSink;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('DbPersistence', $opts);

// ── shared state + cleanup ────────────────────────────────────
$db = null;
$pdo = null;
$masker = null;

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
        @$pdo->prepare("DELETE FROM site_job_events WHERE job_id IN ({$in})")
            ->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_step_executions WHERE job_id IN ({$in})")
            ->execute($testJobIds);
        @$pdo->prepare("DELETE FROM site_jobs WHERE id IN ({$in})")
            ->execute($testJobIds);
    }
    if ($testDomains) {
        $in = implode(',', array_fill(0, count($testDomains), '?'));
        @$pdo->prepare("DELETE FROM site_job_events WHERE site_domain IN ({$in})")
            ->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_step_executions WHERE site_domain IN ({$in})")
            ->execute($testDomains);
        @$pdo->prepare("DELETE FROM site_jobs WHERE site_domain IN ({$in})")
            ->execute($testDomains);
    }
});

// ── helpers ───────────────────────────────────────────────────

function seedTestJob(\PDO $pdo, string $domain, array &$testJobIds, array &$testDomains): int
{
    $testDomains[] = $domain;
    $stmt = $pdo->prepare(
        'INSERT INTO site_jobs
            (site_domain, type, status, priority, priority_class, payload,
             actor, request_id)
         VALUES
            (:domain, :type, :status, :priority, :pc, :payload,
             :actor, :request_id)'
    );
    $stmt->execute([
        'domain' => $domain,
        'type' => 'create',
        'status' => 'queued',
        'priority' => 50,
        'pc' => 'operator',
        'payload' => json_encode(['php_version' => 'lsphp83']),
        'actor' => 'flowone_test_user',
        'request_id' => 'req-' . bin2hex(random_bytes(4)),
    ]);
    $id = (int) $pdo->lastInsertId();
    $testJobIds[] = $id;
    return $id;
}

function testDomain(): string
{
    return '[flowone_test_]dbp-' . bin2hex(random_bytes(3)) . '.local';
}

// ──────────────────────────────────────────────────────────────
// preflight
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'PanelDatabase + tables present',
    function () use (&$db, &$pdo, &$masker) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        $masker = new SecretMasker();

        foreach (['site_jobs', 'site_step_executions', 'site_job_events'] as $t) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . $t . "'");
            if ($stmt->rowCount() === 0) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing table: {$t} -- run the matching migration"];
            }
        }
    });

// ──────────────────────────────────────────────────────────────
// DbStepStateStore
// ──────────────────────────────────────────────────────────────

$harness->test('state_store', 'save then load round-trips a StepState',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $store = new DbStepStateStore(
            database: $db,
            masker: $masker,
            jobId: $jobId,
            siteDomain: $domain,
            requestId: 'req-rt',
            workerId: 'flowone_test_worker',
        );

        $started = new \DateTimeImmutable('2026-05-18 19:00:00.123');
        $completed = new \DateTimeImmutable('2026-05-18 19:00:01.456');
        $state = new StepState(
            stepName: 'home_dir_create',
            schemaVersion: 1,
            data: ['path' => '/home/flowone_test', 'created' => true],
            startedAt: $started,
            completedAt: $completed,
            attemptCount: 1,
        );

        $store->save($state, StepOutcome::SUCCESS, null);
        $loaded = $store->load('home_dir_create');

        if ($loaded === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'load returned null'];
        }
        if ($loaded->stepName !== 'home_dir_create') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'stepName mismatch'];
        }
        if (($loaded->data['path'] ?? null) !== '/home/flowone_test') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'data.path mismatch: ' . var_export($loaded->data['path'] ?? null, true)];
        }
        if (!$loaded->isComplete()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'completedAt should be set'];
        }
        if ($loaded->attemptCount !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "attemptCount={$loaded->attemptCount} (expected 1)"];
        }
    });

$harness->test('state_store', 'load returns the LATEST row when many exist',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $store = new DbStepStateStore($db, $masker, $jobId, $domain, 'req-latest');

        // Save THREE attempts of the same step. Each save appends.
        for ($i = 1; $i <= 3; $i++) {
            $state = new StepState(
                stepName: 'sftp_user_create',
                schemaVersion: 1,
                data: ['attempt_id' => $i, 'note' => "attempt {$i}"],
                startedAt: new \DateTimeImmutable("2026-05-18 19:0{$i}:00"),
                completedAt: $i === 3 ? new \DateTimeImmutable("2026-05-18 19:0{$i}:05") : null,
                attemptCount: $i,
            );
            $store->save($state, $i === 3 ? StepOutcome::SUCCESS : StepOutcome::FAILURE,
                $i === 3 ? null : "attempt {$i} failed");
        }

        $loaded = $store->load('sftp_user_create');
        if ($loaded === null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'load returned null'];
        }
        if (($loaded->data['attempt_id'] ?? null) !== 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected latest attempt_id=3, got "
                    . var_export($loaded->data['attempt_id'] ?? null, true)];
        }
        if ($loaded->attemptCount !== 3) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected attemptCount=3, got {$loaded->attemptCount}"];
        }
    });

$harness->test('state_store', 'all() returns one StepState per step_name (latest each)',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $store = new DbStepStateStore($db, $masker, $jobId, $domain, 'req-all');

        // Two different steps, each with two attempts.
        foreach (['alpha', 'beta'] as $stepName) {
            for ($i = 1; $i <= 2; $i++) {
                $state = new StepState(
                    stepName: $stepName,
                    schemaVersion: 1,
                    data: ['attempt' => $i, 'step' => $stepName],
                    startedAt: new \DateTimeImmutable('now'),
                    completedAt: new \DateTimeImmutable('now'),
                    attemptCount: $i,
                );
                $store->save($state, StepOutcome::SUCCESS);
            }
        }

        $all = $store->all();
        if (count($all) !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 2 entries, got ' . count($all) . ': ' . implode(',', array_keys($all))];
        }
        foreach (['alpha', 'beta'] as $name) {
            if (!isset($all[$name])) {
                return ['outcome' => TestHarness::FAIL, 'message' => "missing step {$name}"];
            }
            if (($all[$name]->data['attempt'] ?? null) !== 2) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "{$name} not the latest attempt"];
            }
        }
    });

$harness->test('state_store', 'output_snapshot is masked before being written',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $store = new DbStepStateStore($db, $masker, $jobId, $domain, 'req-mask');

        $state = new StepState(
            stepName: 'db_user_create',
            schemaVersion: 1,
            // Bad-actor step that put a plaintext password in its state.
            // The vault contract says this shouldn't happen; we still
            // mask defensively.
            data: ['db_user' => 'site_flowone', 'password' => 'super-secret-plaintext-456'],
            startedAt: new \DateTimeImmutable('now'),
            completedAt: new \DateTimeImmutable('now'),
            attemptCount: 1,
        );
        $store->save($state, StepOutcome::SUCCESS);

        $row = $pdo->prepare(
            'SELECT output_snapshot FROM site_step_executions
              WHERE job_id = :job_id AND step_name = :step
              ORDER BY id DESC LIMIT 1'
        );
        $row->execute(['job_id' => $jobId, 'step' => 'db_user_create']);
        $snapshot = (string) $row->fetchColumn();

        if (str_contains($snapshot, 'super-secret-plaintext-456')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'plaintext password leaked into output_snapshot'];
        }
        if (!str_contains($snapshot, 'REDACTED')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected [REDACTED] marker in masked snapshot'];
        }
    });

$harness->test('state_store', 'clear() removes every row for the job',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $store = new DbStepStateStore($db, $masker, $jobId, $domain, 'req-clear');

        $state = new StepState(
            stepName: 'vhost_config_write',
            schemaVersion: 1,
            data: ['path' => '/tmp/flowone_test'],
            startedAt: new \DateTimeImmutable('now'),
            completedAt: new \DateTimeImmutable('now'),
            attemptCount: 1,
        );
        $store->save($state, StepOutcome::SUCCESS);

        // Confirm row exists.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM site_step_executions WHERE job_id = :id'
        );
        $stmt->execute(['id' => $jobId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected at least one row before clear'];
        }

        $store->clear();
        $stmt->execute(['id' => $jobId]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'rows still present after clear()'];
        }
    });

// ──────────────────────────────────────────────────────────────
// DbSagaEventSink
// ──────────────────────────────────────────────────────────────

$harness->test('event_sink', 'emit() writes a row with the right step_name',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $sink = new DbSagaEventSink($db, $masker, $jobId, $domain, 'req-emit');

        $sink->emit('home_dir_create', StepEvent::info('home dir created', ['path' => '/home/test']));

        $stmt = $pdo->prepare(
            'SELECT step_name, level, message
               FROM site_job_events
              WHERE job_id = :id'
        );
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'no row written'];
        }
        if (($row['step_name'] ?? null) !== 'home_dir_create') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "step_name = " . var_export($row['step_name'] ?? null, true)];
        }
        if (($row['level'] ?? null) !== 'info') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'level mismatch'];
        }
    });

$harness->test('event_sink', 'emitSaga() writes step_name=NULL',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $sink = new DbSagaEventSink($db, $masker, $jobId, $domain, 'req-saga');

        $sink->emitSaga(StepEvent::warning('saga DEGRADED', ['barrier' => 'database_create']));

        $stmt = $pdo->prepare(
            'SELECT step_name FROM site_job_events WHERE job_id = :id'
        );
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch();
        if ($row === false || $row['step_name'] !== null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected step_name NULL, got '
                    . var_export($row['step_name'] ?? '<missing>', true)];
        }
    });

$harness->test('event_sink', 'drain() returns events in chronological order',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $sink = new DbSagaEventSink($db, $masker, $jobId, $domain, 'req-order');

        $sink->emitSaga(StepEvent::info('saga started'));
        $sink->emit('alpha', StepEvent::info('alpha begin'));
        $sink->emit('alpha', StepEvent::info('alpha done'));
        $sink->emit('beta', StepEvent::info('beta begin'));
        $sink->emitSaga(StepEvent::info('saga ending'));

        $rows = $sink->drain();
        if (count($rows) !== 5) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 5 events, got ' . count($rows)];
        }
        $names = array_column($rows, 'step_name');
        $expected = [
            InMemorySagaEventSink::SAGA_STEP_NAME,
            'alpha', 'alpha', 'beta',
            InMemorySagaEventSink::SAGA_STEP_NAME,
        ];
        if ($names !== $expected) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'order mismatch: ' . implode(',', $names)];
        }
        // Sanity: messages came back too.
        $messages = array_map(fn($r) => $r['event']->message, $rows);
        if ($messages[0] !== 'saga started' || $messages[4] !== 'saga ending') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'messages not round-tripped: ' . implode(' / ', $messages)];
        }
    });

$harness->test('event_sink', 'event metadata is masked before being written',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $sink = new DbSagaEventSink($db, $masker, $jobId, $domain, 'req-emask');

        $sink->emit(
            'db_user_create',
            StepEvent::info('created user', [
                'username' => 'site_test',
                // A step that accidentally embedded plaintext password
                // metadata. Defensive masking should redact it.
                'password' => 'leak-via-event-789',
            ]),
        );

        $stmt = $pdo->prepare(
            'SELECT metadata FROM site_job_events WHERE job_id = :id'
        );
        $stmt->execute(['id' => $jobId]);
        $meta = (string) $stmt->fetchColumn();
        if (str_contains($meta, 'leak-via-event-789')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'plaintext leaked into event metadata'];
        }
        if (!str_contains($meta, 'REDACTED')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected [REDACTED] marker in masked metadata'];
        }
    });

$harness->test('event_sink', 'purge() removes every row for the job',
    function () use (&$db, &$pdo, &$masker, &$testJobIds, &$testDomains) {
        $domain = testDomain();
        $jobId = seedTestJob($pdo, $domain, $testJobIds, $testDomains);
        $sink = new DbSagaEventSink($db, $masker, $jobId, $domain, 'req-purge');

        $sink->emitSaga(StepEvent::info('x'));
        $sink->emit('alpha', StepEvent::info('y'));

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM site_job_events WHERE job_id = :id'
        );
        $stmt->execute(['id' => $jobId]);
        if ((int) $stmt->fetchColumn() !== 2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected 2 rows pre-purge'];
        }

        $sink->purge();
        $stmt->execute(['id' => $jobId]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'rows still present after purge'];
        }
    });

exit($harness->run());
