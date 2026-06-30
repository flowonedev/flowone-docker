#!/usr/bin/env php
<?php
/**
 * Step Contract Test Suite
 *
 * Validates the StepInterface / AbstractStep / StepResult / StepState /
 * StepEvent / SiteContext / CompensationPolicy / StepOutcome layer.
 *
 *   - StepState immutability: with*() returns a fresh instance.
 *   - StepState::hash() is stable across key order.
 *   - StepState::fromArray() round-trips toArray().
 *   - StepResult factory helpers carry the right outcome + events.
 *   - StepEvent factories produce the right level.
 *   - SiteContext::remainingMs handles missing/expired deadlines.
 *   - AbstractStep's default verify() reflects check().
 *   - AbstractStep's compensate() throws for DEGRADE_ONLY steps.
 *   - CompensationPolicy enum helpers.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/step-contract-test.php --verbose
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
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepOutcome;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('StepContracts', $opts);

// ── StepState immutability + serialization ────────────────────
$harness->test('state', 'fresh() returns an empty state',
    function () {
        $s = StepState::fresh('foo');
        if ($s->stepName !== 'foo') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wrong name'];
        }
        if ($s->data !== []) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected empty data'];
        }
        if ($s->attemptCount !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected attemptCount 0'];
        }
    });

$harness->test('state', 'withData returns a new instance, original untouched',
    function () {
        $s = StepState::fresh('foo');
        $s2 = $s->withData(['x' => 1]);
        if ($s === $s2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'same instance returned'];
        }
        if ($s->data !== []) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'original was mutated'];
        }
        if ($s2->data['x'] !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'new data missing'];
        }
    });

$harness->test('state', 'mergeData preserves existing keys',
    function () {
        $s = StepState::fresh('foo')->withData(['a' => 1])->mergeData(['b' => 2]);
        if ($s->data !== ['a' => 1, 'b' => 2]) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'merge wrong'];
        }
    });

$harness->test('state', 'hash is stable across key order',
    function () {
        $a = StepState::fresh('foo')->withData(['x' => 1, 'y' => 2]);
        $b = StepState::fresh('foo')->withData(['y' => 2, 'x' => 1]);
        if ($a->hash() !== $b->hash()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'hash diverges by key order'];
        }
    });

$harness->test('state', 'toArray/fromArray round-trip',
    function () {
        $s = StepState::fresh('foo')
            ->withData(['k' => 'v'])
            ->withStarted(new \DateTimeImmutable('2026-05-18 12:00:00'))
            ->withAttemptIncremented();
        $arr = $s->toArray();
        $s2 = StepState::fromArray($arr);
        if ($s->hash() !== $s2->hash()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'round-trip hash differs'];
        }
        if ($s2->attemptCount !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'attemptCount lost'];
        }
    });

// ── StepResult factories ──────────────────────────────────────
$harness->test('result', 'success() carries SUCCESS outcome',
    function () {
        $r = StepResult::success(StepState::fresh('foo'));
        if ($r->outcome !== StepOutcome::SUCCESS) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wrong outcome'];
        }
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'isSuccess false'];
        }
    });

$harness->test('result', 'failure() carries error + error event',
    function () {
        $r = StepResult::failure(StepState::fresh('foo'), 'boom');
        if ($r->outcome !== StepOutcome::FAILURE) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wrong outcome'];
        }
        if ($r->error !== 'boom') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'error not stored'];
        }
        $errEvents = array_filter($r->events, fn(StepEvent $e) => $e->level === StepEvent::LEVEL_ERROR);
        if (count($errEvents) === 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'no error event emitted'];
        }
    });

$harness->test('result', 'retryLater() carries retry_after_ms metric',
    function () {
        $r = StepResult::retryLater(StepState::fresh('foo'), 'rate-limited', 30000);
        if ($r->outcome !== StepOutcome::RETRY_LATER) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'wrong outcome'];
        }
        if (($r->metrics['retry_after_ms'] ?? null) !== 30000) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'metric missing'];
        }
    });

$harness->test('result', 'withEvent/withMetric return new instances',
    function () {
        $base = StepResult::success(StepState::fresh('foo'));
        $r2 = $base->withEvent(StepEvent::info('hi'));
        if ($r2 === $base) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'same instance'];
        }
        if (count($base->events) !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'base mutated'];
        }
        if (count($r2->events) !== 1) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'event not added'];
        }
    });

// ── StepEvent factories ───────────────────────────────────────
$harness->test('event', 'level factories produce correct level',
    function () {
        $cases = [
            'debug' => StepEvent::LEVEL_DEBUG,
            'info' => StepEvent::LEVEL_INFO,
            'warning' => StepEvent::LEVEL_WARNING,
            'error' => StepEvent::LEVEL_ERROR,
        ];
        foreach ($cases as $factoryName => $expectedLevel) {
            $ev = StepEvent::$factoryName('msg');
            if ($ev->level !== $expectedLevel) {
                return ['outcome' => TestHarness::FAIL, 'message' => "{$factoryName} wrong level"];
            }
        }
    });

$harness->test('event', 'withMetadata merges',
    function () {
        $ev = StepEvent::info('hi', ['a' => 1])->withMetadata(['b' => 2]);
        if ($ev->metadata !== ['a' => 1, 'b' => 2]) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'merge wrong'];
        }
    });

// ── CompensationPolicy enum ───────────────────────────────────
$harness->test('compensation', 'helpers return correct booleans',
    function () {
        if (!CompensationPolicy::SAFE_ROLLBACK->isSafeToRollback()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'SAFE_ROLLBACK should be safe'];
        }
        if (CompensationPolicy::DEGRADE_ONLY->isSafeToRollback()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DEGRADE_ONLY should NOT be safe'];
        }
        if (!CompensationPolicy::DEGRADE_ONLY->requiresDegradeOnFailure()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DEGRADE_ONLY should require degrade'];
        }
    });

// ── SiteContext deadline tracking ─────────────────────────────
$harness->test('context', 'no deadline -> remainingMs is null',
    function () {
        $ctx = buildContext();
        if ($ctx->remainingMs() !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected null'];
        }
        if ($ctx->isDeadlineExceeded()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'should not be exceeded'];
        }
    });

$harness->test('context', 'expired deadline -> remainingMs is 0 and isDeadlineExceeded',
    function () {
        $ctx = buildContext(deadline: microtime(true) - 1.0);
        if ($ctx->remainingMs() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected 0'];
        }
        if (!$ctx->isDeadlineExceeded()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'should be exceeded'];
        }
    });

$harness->test('context', 'future deadline -> remainingMs is roughly positive',
    function () {
        $ctx = buildContext(deadline: microtime(true) + 1.5);
        $r = $ctx->remainingMs();
        if ($r === null || $r < 1000 || $r > 2000) {
            return ['outcome' => TestHarness::FAIL, 'message' => "remainingMs out of range: {$r}"];
        }
    });

// ── AbstractStep default behavior ─────────────────────────────
$harness->test('abstract_step', 'verify() defaults to re-running check()',
    function () {
        $step = new class extends AbstractStep {
            public bool $checkReturns = true;
            public function name(): string { return 'flowone_test_step_a'; }
            public function compensationPolicy(): CompensationPolicy { return CompensationPolicy::SAFE_ROLLBACK; }
            public function check(SiteContext $ctx, StepState $state): bool { return $this->checkReturns; }
            public function execute(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
        };
        $ctx = buildContext();
        $state = StepState::fresh('flowone_test_step_a');
        $step->checkReturns = true;
        if (!$step->verify($ctx, $state)->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'verify should succeed when check true'];
        }
        $step->checkReturns = false;
        if (!$step->verify($ctx, $state)->isFailure()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'verify should fail when check false'];
        }
    });

$harness->test('abstract_step', 'compensate() throws for DEGRADE_ONLY policy',
    function () {
        $step = new class extends AbstractStep {
            public function name(): string { return 'flowone_test_step_b'; }
            public function compensationPolicy(): CompensationPolicy { return CompensationPolicy::DEGRADE_ONLY; }
            public function check(SiteContext $ctx, StepState $state): bool { return false; }
            public function execute(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
        };
        $ctx = buildContext();
        try {
            $step->compensate($ctx, StepState::fresh('flowone_test_step_b'));
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected LogicException'];
        } catch (\LogicException) {
            // ok
        }
    });

$harness->test('abstract_step', 'compensate() succeeds as no-op for SAFE_ROLLBACK',
    function () {
        $step = new class extends AbstractStep {
            public function name(): string { return 'flowone_test_step_c'; }
            public function compensationPolicy(): CompensationPolicy { return CompensationPolicy::SAFE_ROLLBACK; }
            public function check(SiteContext $ctx, StepState $state): bool { return false; }
            public function execute(SiteContext $ctx, StepState $state): StepResult { return StepResult::success($state); }
        };
        $ctx = buildContext();
        $r = $step->compensate($ctx, StepState::fresh('flowone_test_step_c'));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate should succeed'];
        }
    });

exit($harness->run());

/**
 * Build a SiteContext we can use without the DB. Audit/Vault still get
 * real instances (they only act when called), but we don't call them
 * here so they remain idle.
 */
function buildContext(?float $deadline = null): SiteContext
{
    $db = PanelDatabase::fromDefaultConfigFiles();
    $masker = new SecretMasker();
    $audit = new AuditLogger($db, $masker);
    // Fake master key for tests that don't actually call vault.
    $tmpKey = sys_get_temp_dir() . '/flowone_test_step_ctx_master.key';
    if (!file_exists($tmpKey)) {
        file_put_contents($tmpKey, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        chmod($tmpKey, 0400);
    }
    $vault = new SecretVault($db, $tmpKey);
    $caps = new ServerCapabilities();

    return new SiteContext(
        siteRow: ['id' => 1, 'domain' => 'flowone_test_ctx.local'],
        jobId: 999,
        requestId: 'req-flowone-test',
        actor: ActorContext::cli('step-contract-test'),
        audit: $audit,
        vault: $vault,
        capabilities: $caps,
        database: $db,
        payload: [],
        dryRun: false,
        deadlineUnixMicro: $deadline,
    );
}
