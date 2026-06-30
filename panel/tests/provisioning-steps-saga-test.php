#!/usr/bin/env php
<?php
/**
 * Provisioning Steps :: Saga + Pure-Unit Tests
 *
 * Validates the structure of the Step 4a step library WITHOUT touching
 * system resources (no SFTP, no MySQL, no OLS). Covers:
 *
 *   - StepName constants exist and match the catalog
 *   - SagaSequence rejects duplicates and non-StepInterface entries
 *   - SagaRegistry::createSequence() returns the canonical 10-step list
 *     in the expected order
 *   - Each step's compensationPolicy() matches the saga design table
 *   - Each step's name() matches a StepName constant
 *   - VhostConfigTemplate renders a parseable config with overrides
 *   - Step name derivations: derived defaults are POSIX-safe and capped
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/provisioning-steps-saga-test.php --verbose
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

use VpsAdmin\Agent\Provisioner\Ols\OlsConfigParser;
use VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaRegistry;
use VpsAdmin\Agent\Provisioner\Step\Saga\SagaSequence;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseGrantStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DatabaseUserCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\HomeDirCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsMainConfigInsertStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\OlsRestartStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpGroupCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\SftpUserCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\VhostConfigWriteStep;
use VpsAdmin\Agent\Provisioner\Step\StepInterface;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ProvisioningStepsSaga', $opts);

// ── StepName constants ────────────────────────────────────────
$harness->test('step_name', 'create-direction constants match the catalog',
    function () {
        $expected = [
            StepName::SFTP_GROUP_CREATE,
            StepName::SFTP_USER_CREATE,
            StepName::HOME_DIR_CREATE,
            StepName::VHOST_CONFIG_WRITE,
            StepName::OLS_MAIN_CONFIG_INSERT,
            StepName::DATABASE_CREATE,
            StepName::DATABASE_USER_CREATE,
            StepName::DATABASE_GRANT,
            StepName::DNS_ZONE_CREATE,
            StepName::OLS_RESTART,
            StepName::SSL_ISSUE,
        ];
        $catalog = StepName::allCreateNames();
        if ($expected !== $catalog) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'allCreateNames out of sync with constants'];
        }
        // No duplicates
        if (count(array_unique($catalog)) !== count($catalog)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'duplicates in catalog'];
        }
    });

$harness->test('step_name', 'each constant is snake_case + non-empty',
    function () {
        foreach (StepName::allCreateNames() as $n) {
            if (!preg_match('/^[a-z][a-z0-9_]+$/', $n)) {
                return ['outcome' => TestHarness::FAIL, 'message' => "bad name format: '{$n}'"];
            }
        }
    });

// ── SagaSequence DTO validation ───────────────────────────────
$harness->test('sequence', 'rejects duplicate step names',
    function () {
        try {
            new SagaSequence('dup', [new SftpGroupCreateStep(), new SftpGroupCreateStep()]);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected InvalidArgumentException'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('sequence', 'count + stepNames are consistent',
    function () {
        $seq = new SagaSequence('test', [new SftpGroupCreateStep(), new SftpUserCreateStep()]);
        if ($seq->count() !== 2) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'count mismatch'];
        }
        if ($seq->stepNames() !== [StepName::SFTP_GROUP_CREATE, StepName::SFTP_USER_CREATE]) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'stepNames out of order'];
        }
    });

$harness->test('sequence', 'findByName returns the right step',
    function () {
        $seq = new SagaSequence('test', [new SftpGroupCreateStep(), new SftpUserCreateStep()]);
        $found = $seq->findByName(StepName::SFTP_USER_CREATE);
        if (!$found instanceof SftpUserCreateStep) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'lookup failed'];
        }
        if ($seq->findByName('nope') !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'lookup should return null for missing'];
        }
    });

// ── SagaRegistry canonical create sequence ────────────────────
$harness->test('registry', 'createSequence yields the canonical 11-step order',
    function () {
        $reg = new SagaRegistry();
        $seq = $reg->createSequence();
        $expected = StepName::allCreateNames();
        if ($seq->stepNames() !== $expected) {
            return [
                'outcome' => TestHarness::FAIL,
                'message' => 'order mismatch: ' . implode(',', $seq->stepNames()),
            ];
        }
    });

$harness->test('registry', 'each step in the sequence implements StepInterface',
    function () {
        $reg = new SagaRegistry();
        foreach ($reg->createSequence()->steps as $step) {
            if (!$step instanceof StepInterface) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'not a StepInterface'];
            }
        }
    });

// ── Compensation policy table ─────────────────────────────────
$harness->test('policy', 'each step exposes the documented policy',
    function () {
        $expected = [
            StepName::SFTP_GROUP_CREATE => CompensationPolicy::SAFE_ROLLBACK,
            StepName::SFTP_USER_CREATE => CompensationPolicy::SAFE_ROLLBACK,
            StepName::HOME_DIR_CREATE => CompensationPolicy::PARTIAL,
            StepName::VHOST_CONFIG_WRITE => CompensationPolicy::SAFE_ROLLBACK,
            StepName::OLS_MAIN_CONFIG_INSERT => CompensationPolicy::SAFE_ROLLBACK,
            StepName::DATABASE_CREATE => CompensationPolicy::DEGRADE_ONLY,
            StepName::DATABASE_USER_CREATE => CompensationPolicy::DEGRADE_ONLY,
            StepName::DATABASE_GRANT => CompensationPolicy::SAFE_ROLLBACK,
            StepName::DNS_ZONE_CREATE => CompensationPolicy::SAFE_ROLLBACK,
            StepName::OLS_RESTART => CompensationPolicy::SAFE_ROLLBACK,
            StepName::SSL_ISSUE => CompensationPolicy::DEGRADE_ONLY,
        ];
        $reg = new SagaRegistry();
        foreach ($reg->createSequence()->steps as $step) {
            $want = $expected[$step->name()] ?? null;
            if ($want === null) {
                return ['outcome' => TestHarness::FAIL, 'message' => "unmapped step {$step->name()}"];
            }
            if ($step->compensationPolicy() !== $want) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "{$step->name()} expected {$want->value}, got " . $step->compensationPolicy()->value];
            }
        }
    });

// ── Step.name() matches StepName::* ───────────────────────────
$harness->test('policy', 'concrete step names match StepName catalog (no typos)',
    function () {
        $catalogClasses = [
            SftpGroupCreateStep::class => StepName::SFTP_GROUP_CREATE,
            SftpUserCreateStep::class => StepName::SFTP_USER_CREATE,
            HomeDirCreateStep::class => StepName::HOME_DIR_CREATE,
            OlsMainConfigInsertStep::class => StepName::OLS_MAIN_CONFIG_INSERT,
            DatabaseCreateStep::class => StepName::DATABASE_CREATE,
            DatabaseUserCreateStep::class => StepName::DATABASE_USER_CREATE,
            DatabaseGrantStep::class => StepName::DATABASE_GRANT,
            OlsRestartStep::class => StepName::OLS_RESTART,
        ];
        foreach ($catalogClasses as $class => $expectedName) {
            $step = new $class();
            if ($step->name() !== $expectedName) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "{$class}: expected name '{$expectedName}', got '{$step->name()}'"];
            }
        }
        // VhostConfigWriteStep takes a constructor arg, do it separately.
        $vh = new VhostConfigWriteStep(new VhostConfigTemplate());
        if ($vh->name() !== StepName::VHOST_CONFIG_WRITE) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "VhostConfigWriteStep name mismatch"];
        }
        // DnsZoneCreateStep takes server-IP + NS hostnames as ctor args.
        $dns = new \VpsAdmin\Agent\Provisioner\Step\Steps\Create\DnsZoneCreateStep(
            '198.51.100.1',
            'ns1.example.invalid',
            'ns2.example.invalid',
        );
        if ($dns->name() !== StepName::DNS_ZONE_CREATE) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "DnsZoneCreateStep name mismatch"];
        }
        // SslIssueStep takes a VhostConfigTemplate (defaulted) plus an
        // optional staging flag - construct via defaults so the test
        // doesn't need to inject anything.
        $ssl = new \VpsAdmin\Agent\Provisioner\Step\Steps\Create\SslIssueStep();
        if ($ssl->name() !== StepName::SSL_ISSUE) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "SslIssueStep name mismatch"];
        }
    });

// ── VhostConfigTemplate ───────────────────────────────────────
$harness->test('template', 'renders with required vars',
    function () {
        $tpl = new VhostConfigTemplate();
        $out = $tpl->render([
            'site_user' => 'site_example_com',
            'site_group' => 'site_example_com',
            'php_lsapi' => 'lsphp83',
            'admin_email' => 'admin@example.com',
        ]);
        if (!str_contains($out, 'extprocessor site_example_com {')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'extprocessor block missing'];
        }
        if (!str_contains($out, 'adminEmails               admin@example.com')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'adminEmails not substituted'];
        }
        if (!str_contains($out, '/usr/local/lsws/lsphp83/bin/lsphp')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'php_lsapi path missing'];
        }
        // OLS variables must SURVIVE rendering (not get expanded by PHP).
        if (!str_contains($out, '$VH_ROOT/public_html')) {
            return ['outcome' => TestHarness::FAIL, 'message' => '$VH_ROOT should be preserved'];
        }
        if (!str_contains($out, '$VH_NAME')) {
            return ['outcome' => TestHarness::FAIL, 'message' => '$VH_NAME should be preserved'];
        }
    });

$harness->test('template', 'rejects missing required var',
    function () {
        $tpl = new VhostConfigTemplate();
        try {
            $tpl->render([]);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected InvalidArgumentException'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('template', 'rejects malformed php_lsapi',
    function () {
        $tpl = new VhostConfigTemplate();
        try {
            $tpl->render(['site_user' => 'u', 'php_lsapi' => '$(rm -rf /)']);
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected InvalidArgumentException'];
        } catch (\InvalidArgumentException) {
            // ok
        }
    });

$harness->test('template', 'rendered output is parseable by OlsConfigParser',
    function () {
        $tpl = new VhostConfigTemplate();
        $out = $tpl->render(['site_user' => 'site_example_com']);
        $parser = new OlsConfigParser();
        try {
            $doc = $parser->parseString($out);
        } catch (\Throwable $e) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'parse failed: ' . $e->getMessage()];
        }
        // Should contain the expected top-level directives + blocks.
        $names = [];
        foreach ($doc->children as $node) {
            $cls = (new \ReflectionClass($node))->getShortName();
            if ($cls === 'DirectiveNode' || $cls === 'BlockNode') {
                $names[] = $node->name;
            }
        }
        if (!in_array('docRoot', $names, true)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'docRoot missing from parsed tree'];
        }
    });

// ── Name derivation safety ────────────────────────────────────
// SftpGroupCreateStep used to own deriveFromDomain() as a private
// method; the logic now lives in ResourceNameDeriver::sftpName() so
// SftpGroupCreateStep and SftpUserCreateStep both call into the same
// canonical helper. The test asserts the helper directly.
$harness->test('derivation', 'group name from arbitrary domain is POSIX-safe',
    function () {
        // Exact-match expected outputs after dashes/dots are replaced
        // with `_` and the prefix is rtrim'd before the hash suffix.
        $exactCases = [
            'example.com' => 'site_example_com',
            'sub.example.com' => 'site_sub_example_com',
            'WeIrD.MiXeDcAsE.Com' => 'site_weird_mixedcase_com',
        ];
        foreach ($exactCases as $input => $expected) {
            $out = ResourceNameDeriver::sftpName($input);
            if ($out !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "derive('{$input}') = '{$out}', expected '{$expected}'"];
            }
        }
        // For very long input we only assert the shape, since the hash
        // is content-dependent. Must be <=31, start with 'site_', end
        // with '_<6hex>'.
        $long = 'a-very-long-domain-name-that-exceeds-31-chars.com';
        $out = ResourceNameDeriver::sftpName($long);
        if (strlen($out) > 31) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "derived '{$out}' exceeds 31 chars (" . strlen($out) . ")"];
        }
        if (!preg_match('/^site_[a-z0-9_]+_[a-f0-9]{6}$/', $out)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "long-domain derivation shape wrong: '{$out}'"];
        }
        // No characters other than [a-z0-9_] anywhere (POSIX safety).
        if (preg_match('/[^a-z0-9_]/', $out)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "derived '{$out}' contains non-POSIX-safe character"];
        }
        // Sanity: the SftpGroupCreateStep + SftpUserCreateStep both
        // route through this helper, so naming must be deterministic
        // for repeated calls on the same domain.
        if (ResourceNameDeriver::sftpName('example.com') !== ResourceNameDeriver::sftpName('example.com')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'derivation is non-deterministic - saga group/user names will diverge'];
        }
    });

$harness->test('derivation', 'db name from domain is <=64 chars and prefixed',
    function () {
        $step = new DatabaseCreateStep();
        $r = new \ReflectionMethod($step, 'deriveFromDomain');
        $r->setAccessible(true);
        $cases = [
            'example.com' => 'flowone_example_com',
        ];
        foreach ($cases as $input => $expected) {
            $out = $r->invoke($step, $input);
            if ($out !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "derive('{$input}') = '{$out}' (expected '{$expected}')"];
            }
        }
        $long = str_repeat('a', 80) . '.example.com';
        $out = $r->invoke($step, $long);
        if (strlen($out) > 64) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "long-name derivation produced {$out} (" . strlen($out) . " chars > 64)"];
        }
        if (!str_starts_with($out, 'flowone_')) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'missing flowone_ prefix'];
        }
    });

exit($harness->run());
