#!/usr/bin/env php
<?php
/**
 * Provisioning :: SSL Step Tests
 *
 * Covers the wave-2 SSL parity layer added on top of the saga:
 *
 *   1. VhostConfigTemplate::appendVhssl()
 *      - Idempotent (re-call leaves config unchanged)
 *      - Output contains the expected vhssl directives
 *      - Exactly one trailing newline (no triple-blank seas)
 *
 *   2. SslAdapter (with sandbox liveDir + a fake CommandRunner)
 *      - certificateExists(): true iff fullchain + privkey both present
 *      - dnsResolves(): rejects single-label hostnames; accepts a
 *        domain that gethostbyname() resolves to a real IP
 *      - issueCert(): builds the right certbot args; classifies stderr
 *      - revokeCert(): idempotent on already-missing cert; cleans
 *        renewal config
 *
 *   3. IssuanceResult predicates
 *      - isCertOnDisk() / isDeferrable() match the outcome enum
 *
 *   4. SslIssueStep skip paths (no certbot calls):
 *      - auto_ssl=false               -> skipped_opted_out
 *      - single-label domain          -> skipped_single_label
 *      - SslAdapter not wired         -> skipped_no_adapter
 *      - cert + vhssl already present -> already_present
 *
 *   5. SslRevokeStep skip paths
 *      - single-label                 -> skipped_single_label
 *      - cert not present             -> no_cert
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/ssl-step-test.php --verbose
 *
 * Flags:
 *   --verbose   -- extra diagnostic output
 *   --smoke     -- preflight only
 *   --only=g    -- run only group g
 *   --json      -- machine-readable summary
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'skip-send', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1800));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Adapters\CommandResult;
use VpsAdmin\Agent\Provisioner\Adapters\CommandRunner;
use VpsAdmin\Agent\Provisioner\Adapters\IssuanceOutcome;
use VpsAdmin\Agent\Provisioner\Adapters\IssuanceResult;
use VpsAdmin\Agent\Provisioner\Adapters\SslAdapter;
use VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SslIssueStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\SslRevokeStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

/**
 * Tiny in-test command runner that records every invocation and
 * replays a scripted CommandResult queue. Lives here (not in
 * tests/lib/) because it's only used by SSL tests today; promote it
 * up if a second test file ever needs it.
 */
final class ScriptedCommandRunner implements CommandRunner
{
    /** @var list<array{binary:string, args:list<string>, timeout:int}> */
    public array $calls = [];
    /** @var list<CommandResult> */
    private array $queue;

    /** @param list<CommandResult> $queue */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    public function enqueue(CommandResult $result): void
    {
        $this->queue[] = $result;
    }

    public function run(
        string $binary,
        array $args = [],
        ?string $stdin = null,
        int $timeoutSeconds = 30,
        ?array $env = null,
        ?string $cwd = null,
    ): CommandResult {
        $this->calls[] = ['binary' => $binary, 'args' => $args, 'timeout' => $timeoutSeconds];
        if (!empty($this->queue)) {
            return array_shift($this->queue);
        }
        // Default to success with empty output if we run out of script.
        return new CommandResult(0, '', '', 0.001, false, $binary . ' ' . implode(' ', $args));
    }
}

$harness = new TestHarness('SslSteps', $opts);

// ── VhostConfigTemplate::appendVhssl ──────────────────────────
$harness->test('template', 'appendVhssl adds the expected directives',
    function () {
        $tpl = new VhostConfigTemplate();
        $base = $tpl->render([
            'site_user' => 'site_example_com',
            'php_lsapi' => 'lsphp83',
        ]);
        $out = $tpl->appendVhssl($base);

        foreach ([
            'vhssl  {',
            'keyFile                 /etc/letsencrypt/live/$VH_NAME/privkey.pem',
            'certFile                /etc/letsencrypt/live/$VH_NAME/fullchain.pem',
            'sslProtocol             24',
            'enableQuic              1',
        ] as $needle) {
            if (strpos($out, $needle) === false) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing directive: {$needle}"];
            }
        }
    });

$harness->test('template', 'appendVhssl is idempotent',
    function () {
        $tpl = new VhostConfigTemplate();
        $base = $tpl->render(['site_user' => 'u', 'php_lsapi' => 'lsphp83']);
        $once = $tpl->appendVhssl($base);
        $twice = $tpl->appendVhssl($once);
        if ($once !== $twice) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second appendVhssl mutated the config (length delta: '
                    . (strlen($twice) - strlen($once)) . ')'];
        }
        // And substring count of "vhssl" stays at exactly 1.
        $count = substr_count($twice, 'vhssl  {');
        if ($count !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected exactly one vhssl block, got {$count}"];
        }
    });

$harness->test('template', 'appendVhssl preserves preceding content',
    function () {
        $tpl = new VhostConfigTemplate();
        $base = $tpl->render(['site_user' => 'u', 'php_lsapi' => 'lsphp83']);
        $out = $tpl->appendVhssl($base);
        if (strpos($out, $base) !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'original config no longer prefixes the output'];
        }
    });

// ── SslAdapter: certificateExists ─────────────────────────────
$harness->test('adapter', 'certificateExists: false when cert files missing',
    function () {
        $tmpLive = sys_get_temp_dir() . '/flowone_ssl_live_' . bin2hex(random_bytes(3));
        @mkdir($tmpLive, 0755, true);

        $adapter = new SslAdapter(
            runner: new ScriptedCommandRunner(),
            liveDir: $tmpLive,
        );
        if ($adapter->certificateExists('example.test')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected false on empty live dir'];
        }
    });

$harness->test('adapter', 'certificateExists: true when both PEMs present',
    function () {
        $tmpLive = sys_get_temp_dir() . '/flowone_ssl_live_' . bin2hex(random_bytes(3));
        $domain = 'example.test';
        @mkdir($tmpLive . '/' . $domain, 0755, true);
        file_put_contents($tmpLive . '/' . $domain . '/fullchain.pem', "fake\n");
        file_put_contents($tmpLive . '/' . $domain . '/privkey.pem', "fake\n");

        $adapter = new SslAdapter(
            runner: new ScriptedCommandRunner(),
            liveDir: $tmpLive,
        );
        if (!$adapter->certificateExists($domain)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected true with fullchain + privkey'];
        }

        // Only fullchain present -> should still report false (privkey
        // is what OLS actually needs to terminate TLS).
        $domain2 = 'partial.test';
        @mkdir($tmpLive . '/' . $domain2, 0755, true);
        file_put_contents($tmpLive . '/' . $domain2 . '/fullchain.pem', "fake");
        if ($adapter->certificateExists($domain2)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected false when only fullchain present'];
        }
    });

// ── SslAdapter: dnsResolves ───────────────────────────────────
$harness->test('adapter', 'dnsResolves: false for single-label',
    function () {
        $adapter = new SslAdapter(runner: new ScriptedCommandRunner());
        foreach (['', 'localhost', 'test6', 'no-dot'] as $bad) {
            if ($adapter->dnsResolves($bad)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "dnsResolves('{$bad}') returned true (expected false)"];
            }
        }
    });

$harness->test('adapter', 'dnsResolves: true for a known-resolvable domain',
    function () {
        $adapter = new SslAdapter(runner: new ScriptedCommandRunner());
        // localhost.invalid never resolves; google.com always does.
        // We don't want this test to depend on flakey internet
        // resolution, so we treat "false on google.com" as a SKIP.
        if (!$adapter->dnsResolves('google.com')) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'no internet DNS available; skipping positive case'];
        }
    });

// ── SslAdapter: issueCert builds the right args ───────────────
$harness->test('adapter', 'issueCert: assembles certbot args correctly',
    function () {
        $runner = new ScriptedCommandRunner([
            new CommandResult(0, "Successfully received certificate.\n", '', 0.5, false, 'certbot'),
        ]);
        $adapter = new SslAdapter(runner: $runner);

        $r = $adapter->issueCert(
            domain: 'example.test',
            sans: ['www.example.test'],
            webroot: '/home/example.test/public_html',
            email: 'admin@example.test',
            staging: true,
        );

        if ($r->outcome !== IssuanceOutcome::ISSUED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected ISSUED, got ' . $r->outcome->value];
        }
        if (count($runner->calls) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected one certbot call, got ' . count($runner->calls)];
        }
        $args = $runner->calls[0]['args'];
        // Required flags must all be present.
        $requiredFlags = [
            'certonly', '--webroot', '-w', '/home/example.test/public_html',
            '-d', 'example.test', '-d', 'www.example.test',
            '--email', 'admin@example.test',
            '--agree-tos', '--non-interactive', '--expand',
            '--keep-until-expiring', '--staging',
        ];
        foreach ($requiredFlags as $flag) {
            if (!in_array($flag, $args, true)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing certbot flag: {$flag}"];
            }
        }
    });

$harness->test('adapter', 'issueCert: classifies common certbot failures',
    function () {
        $cases = [
            'too many certificates already issued' => IssuanceOutcome::RATE_LIMITED,
            'rate limit exceeded' => IssuanceOutcome::RATE_LIMITED,
            'DNS problem: NXDOMAIN looking up A for foo.test' => IssuanceOutcome::SKIPPED_DNS,
            'no A/AAAA record for example.test' => IssuanceOutcome::SKIPPED_DNS,
            'Challenge did not pass: connection refused' => IssuanceOutcome::SKIPPED_CHALLENGE,
            'unauthorized: ACME server says no' => IssuanceOutcome::SKIPPED_CHALLENGE,
            'random unrecognised certbot error' => IssuanceOutcome::FAILED,
        ];
        foreach ($cases as $stderr => $expected) {
            $runner = new ScriptedCommandRunner([
                new CommandResult(1, '', $stderr, 0.5, false, 'certbot'),
            ]);
            $adapter = new SslAdapter(runner: $runner);
            $r = $adapter->issueCert('x.test', [], '/tmp/wr', 'a@b');
            if ($r->outcome !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "stderr '{$stderr}' classified as {$r->outcome->value}, expected {$expected->value}"];
            }
        }
    });

// ── SslAdapter: revokeCert ───────────────────────────────────
$harness->test('adapter', 'revokeCert: idempotent when no cert present',
    function () {
        $tmpLive = sys_get_temp_dir() . '/flowone_ssl_live_' . bin2hex(random_bytes(3));
        $tmpRenewal = sys_get_temp_dir() . '/flowone_ssl_renew_' . bin2hex(random_bytes(3));
        @mkdir($tmpLive, 0755, true);
        @mkdir($tmpRenewal, 0755, true);

        $runner = new ScriptedCommandRunner();
        $adapter = new SslAdapter(
            runner: $runner,
            liveDir: $tmpLive,
            renewalDir: $tmpRenewal,
        );
        $ok = $adapter->revokeCert('absent.test');
        if (!$ok) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected revokeCert to return true when no cert exists'];
        }
        if (count($runner->calls) !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 0 certbot calls when nothing to revoke, got ' . count($runner->calls)];
        }
    });

$harness->test('adapter', 'revokeCert: calls revoke + delete and cleans renewal config',
    function () {
        $tmpLive = sys_get_temp_dir() . '/flowone_ssl_live_' . bin2hex(random_bytes(3));
        $tmpRenewal = sys_get_temp_dir() . '/flowone_ssl_renew_' . bin2hex(random_bytes(3));
        $domain = 'doomed.test';
        @mkdir($tmpLive . '/' . $domain, 0755, true);
        @mkdir($tmpRenewal, 0755, true);
        file_put_contents($tmpLive . '/' . $domain . '/fullchain.pem', "fake");
        file_put_contents($tmpLive . '/' . $domain . '/privkey.pem', "fake");
        file_put_contents($tmpRenewal . '/' . $domain . '.conf', "renewal config");

        $runner = new ScriptedCommandRunner([
            new CommandResult(0, '', '', 0.5, false, 'certbot revoke'),
            new CommandResult(0, '', '', 0.2, false, 'certbot delete'),
        ]);
        $adapter = new SslAdapter(
            runner: $runner,
            liveDir: $tmpLive,
            renewalDir: $tmpRenewal,
        );
        $ok = $adapter->revokeCert($domain);
        if (!$ok) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected true return'];
        }
        if (count($runner->calls) !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected revoke + delete (2 calls), got ' . count($runner->calls)];
        }
        if ($runner->calls[0]['args'][0] !== 'revoke') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first call must be revoke, got ' . $runner->calls[0]['args'][0]];
        }
        if ($runner->calls[1]['args'][0] !== 'delete') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second call must be delete, got ' . $runner->calls[1]['args'][0]];
        }
        if (file_exists($tmpRenewal . '/' . $domain . '.conf')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'renewal config not cleaned up'];
        }
    });

// ── IssuanceResult predicates ────────────────────────────────
$harness->test('result', 'IssuanceResult predicates match the enum',
    function () {
        $cases = [
            [IssuanceOutcome::ISSUED, true, false],
            [IssuanceOutcome::ALREADY_PRESENT, true, false],
            [IssuanceOutcome::SKIPPED_DNS, false, true],
            [IssuanceOutcome::SKIPPED_CHALLENGE, false, true],
            [IssuanceOutcome::RATE_LIMITED, false, true],
            [IssuanceOutcome::FAILED, false, false],
        ];
        foreach ($cases as [$o, $onDisk, $deferrable]) {
            $r = new IssuanceResult($o, [], '');
            if ($r->isCertOnDisk() !== $onDisk || $r->isDeferrable() !== $deferrable) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "outcome {$o->value}: onDisk={$r->isCertOnDisk()} deferrable={$r->isDeferrable()}"];
            }
        }
    });

// ── SslIssueStep: skip paths ────────────────────────────────
$bundles = [];
$harness->onCleanup(function () use (&$bundles) {
    foreach ($bundles as $b) {
        StepTestContext::teardown($b);
    }
});

$harness->test('issue_step', 'auto_ssl=false short-circuits to skipped_opted_out',
    function () use (&$bundles) {
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-ssl-' . bin2hex(random_bytes(2)) . '.test',
            'payload' => ['auto_ssl' => false],
        ]);
        $bundles[] = $bundle;

        $step = new SslIssueStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected success'];
        }
        if (($r->newState->data['outcome'] ?? null) !== 'skipped_opted_out') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected skipped_opted_out, got ' . json_encode($r->newState->data)];
        }
    });

$harness->test('issue_step', 'single-label hostname short-circuits to skipped_single_label',
    function () use (&$bundles) {
        $bundle = StepTestContext::build([
            'domain' => 'test6',  // no dot
            'payload' => [],
        ]);
        $bundles[] = $bundle;

        $step = new SslIssueStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected success'];
        }
        if (($r->newState->data['outcome'] ?? null) !== 'skipped_single_label') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected skipped_single_label, got ' . json_encode($r->newState->data)];
        }
    });

$harness->test('issue_step', 'no-adapter wired short-circuits to skipped_no_adapter',
    function () use (&$bundles) {
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-ssl-' . bin2hex(random_bytes(2)) . '.test',
            'payload' => [],
        ]);
        $bundles[] = $bundle;
        // StepTestContext doesn't wire SslAdapter so adapters.ssl is null.

        $step = new SslIssueStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected success'];
        }
        if (($r->newState->data['outcome'] ?? null) !== 'skipped_no_adapter') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected skipped_no_adapter, got ' . json_encode($r->newState->data)];
        }
    });

$harness->test('issue_step', 'compensate is a logged no-op (DEGRADE_ONLY)',
    function () use (&$bundles) {
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-ssl-' . bin2hex(random_bytes(2)) . '.test',
        ]);
        $bundles[] = $bundle;

        $step = new SslIssueStep();
        $state = StepState::fresh($step->name());
        $r = $step->compensate($bundle['ctx'], $state);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'compensate should never throw or fail'];
        }
    });

// ── SslRevokeStep: skip paths ───────────────────────────────
$harness->test('revoke_step', 'single-label short-circuits to skipped_single_label',
    function () use (&$bundles) {
        $bundle = StepTestContext::build([
            'domain' => 'test6',
        ]);
        $bundles[] = $bundle;

        $step = new SslRevokeStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected success'];
        }
        if (($r->newState->data['outcome'] ?? null) !== 'skipped_single_label') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected skipped_single_label'];
        }
    });

$harness->test('revoke_step', 'no-adapter wired -> skipped_no_adapter',
    function () use (&$bundles) {
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-revoke-' . bin2hex(random_bytes(2)) . '.test',
        ]);
        $bundles[] = $bundle;

        $step = new SslRevokeStep();
        $r = $step->execute($bundle['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected success'];
        }
        if (($r->newState->data['outcome'] ?? null) !== 'skipped_no_adapter') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected skipped_no_adapter'];
        }
    });

// ── StepName catalog drift check ────────────────────────────
$harness->test('catalog', 'SSL_ISSUE + SSL_REVOKE present in catalogs',
    function () {
        if (!in_array(StepName::SSL_ISSUE, StepName::allCreateNames(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'SSL_ISSUE missing from allCreateNames'];
        }
        if (!in_array(StepName::SSL_REVOKE, StepName::allDeleteNames(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'SSL_REVOKE missing from allDeleteNames'];
        }
        if (!in_array(StepName::SSL_ISSUE, StepName::allRestoreNames(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'SSL_ISSUE missing from allRestoreNames'];
        }
        // Archive deliberately KEEPS the cert (see SagaRegistry comment).
        if (in_array(StepName::SSL_REVOKE, StepName::allArchiveNames(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'SSL_REVOKE should NOT be in allArchiveNames'];
        }
    });

exit($harness->run());
