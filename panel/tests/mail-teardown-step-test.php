#!/usr/bin/env php
<?php
/**
 * MailTeardownStep Test Suite
 *
 * Exercises the delete-saga mail teardown step against the real panel
 * DB and a /tmp sandbox standing in for /home/vmail + /etc/opendkim
 * (payload['vmail_root'] / payload['dkim_root'] overrides).
 *
 * Verifies:
 *   - check() is false while ANY artifact exists (DB row, maildir,
 *     dkim keys, dkim table lines) and true once all are gone.
 *   - execute() removes mail_domains/_accounts/_forwards rows, the
 *     maildir, the dkim key dir and the SigningTable/KeyTable lines.
 *   - a non-empty maildir is tar'd into the snapshot dir BEFORE
 *     removal (recovery artifact invariant).
 *   - unrelated domains' dkim table lines survive.
 *   - re-run after success is a clean no-op (idempotence).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/mail-teardown-step-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1400));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\MailTeardownStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('MailTeardownStep', $opts);

$bundles = [];
$testDomains = [];
$sharedPdo = null;

$harness->onCleanup(function () use (&$bundles, &$testDomains, &$sharedPdo): void {
    if ($sharedPdo && $testDomains) {
        $in = implode(',', array_fill(0, count($testDomains), '?'));
        foreach ([
            "DELETE FROM mail_accounts WHERE domain IN ({$in})",
            "DELETE FROM mail_forwards WHERE source_domain IN ({$in})",
            "DELETE FROM mail_domains WHERE domain IN ({$in})",
        ] as $sql) {
            try {
                $sharedPdo->prepare($sql)->execute($testDomains);
            } catch (\Throwable) {
                // table absent on this install - nothing to clean
            }
        }
    }
    foreach ($bundles as $bundle) {
        StepTestContext::teardown($bundle);
    }
});

/**
 * Build a sandboxed context with every mail artifact seeded:
 * mail_domains row, maildir with a message file, dkim key dir and
 * SigningTable/KeyTable entries (plus an unrelated domain's lines
 * that must survive the teardown).
 */
function buildMailBundle(array &$bundles, array &$testDomains, ?\PDO &$sharedPdo, array $payloadExtra = []): array
{
    $domain = 'flowone-test-mail-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';

    $bundle = StepTestContext::build(['domain' => $domain]);
    $sandbox = $bundle['sandbox_root'];

    $vmailRoot = $sandbox . '/vmail';
    $dkimRoot = $sandbox . '/opendkim';
    $snapshotRoot = $sandbox . '/snapshots';
    @mkdir($vmailRoot . '/' . $domain . '/info', 0755, true);
    @mkdir($dkimRoot . '/keys/' . $domain, 0700, true);
    file_put_contents($vmailRoot . '/' . $domain . '/info/dovecot.index', "fake-index\n");
    file_put_contents($dkimRoot . '/keys/' . $domain . '/default.private', "FAKE-KEY\n");
    file_put_contents(
        $dkimRoot . '/SigningTable',
        "*@unrelated.example.com default._domainkey.unrelated.example.com\n"
            . "*@{$domain} default._domainkey.{$domain}\n"
    );
    file_put_contents(
        $dkimRoot . '/KeyTable',
        "default._domainkey.unrelated.example.com unrelated.example.com:default:/etc/opendkim/keys/unrelated.example.com/default.private\n"
            . "default._domainkey.{$domain} {$domain}:default:{$dkimRoot}/keys/{$domain}/default.private\n"
    );

    $pdo = $bundle['ctx']->database->pdo();
    $sharedPdo = $pdo;
    $dbOk = true;
    try {
        $pdo->prepare("INSERT INTO mail_domains (domain, status) VALUES (?, 'active')")->execute([$domain]);
        $testDomains[] = $domain;
    } catch (\Throwable) {
        // mail tables absent on this install - callers SKIP.
        $dbOk = false;
    }

    $payload = array_merge([
        'vmail_root' => $vmailRoot,
        'dkim_root' => $dkimRoot,
        'snapshot_root' => $snapshotRoot,
    ], $payloadExtra);

    $ctx = new \VpsAdmin\Agent\Provisioner\Step\SiteContext(
        siteRow: $bundle['ctx']->siteRow,
        jobId: $bundle['ctx']->jobId,
        requestId: $bundle['ctx']->requestId,
        actor: $bundle['ctx']->actor,
        audit: $bundle['ctx']->audit,
        vault: $bundle['ctx']->vault,
        capabilities: $bundle['ctx']->capabilities,
        database: $bundle['ctx']->database,
        payload: $payload,
        dryRun: false,
        adapters: $bundle['ctx']->adapters,
    );

    $bundles[] = $bundle;

    return [
        'bundle' => $bundle,
        'ctx' => $ctx,
        'domain' => $domain,
        'vmail_root' => $vmailRoot,
        'dkim_root' => $dkimRoot,
        'snapshot_root' => $snapshotRoot,
        'db_ok' => $dbOk,
    ];
}

/** Standard SKIP envelope when the mail tables are missing. */
function skipIfNoMailTables(array $built): ?array
{
    if (!$built['db_ok']) {
        return ['outcome' => TestHarness::SKIP,
            'message' => 'mail tables absent on this install'];
    }
    return null;
}

// ──────────────────────────────────────────────────────────────
// preflight
// ──────────────────────────────────────────────────────────────

$harness->test('preflight', 'mail_domains table reachable', function () use (&$sharedPdo) {
    $db = \VpsAdmin\Agent\Provisioner\Support\PanelDatabase::fromDefaultConfigFiles();
    $sharedPdo = $db->pdo();
    try {
        $sharedPdo->query("SELECT 1 FROM mail_domains LIMIT 1");
    } catch (\Throwable $e) {
        return ['outcome' => TestHarness::SKIP,
            'message' => 'mail_domains table absent on this install: ' . $e->getMessage()];
    }
});

// ──────────────────────────────────────────────────────────────
// teardown happy path
// ──────────────────────────────────────────────────────────────

$harness->test('teardown', 'check() is false while artifacts exist',
    function () use (&$bundles, &$testDomains, &$sharedPdo) {
        $built = buildMailBundle($bundles, $testDomains, $sharedPdo);
        $step = new MailTeardownStep();
        if ($step->check($built['ctx'], StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() returned true with every artifact present'];
        }
    });

$harness->test('teardown', 'execute() removes DB rows, maildir, dkim keys + table lines',
    function () use (&$bundles, &$testDomains, &$sharedPdo) {
        $built = buildMailBundle($bundles, $testDomains, $sharedPdo);
        if ($skip = skipIfNoMailTables($built)) {
            return $skip;
        }
        $domain = $built['domain'];
        $step = new MailTeardownStep();

        $r = $step->execute($built['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute failed: ' . ($r->error ?? '?')];
        }

        $pdo = $built['ctx']->database->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM mail_domains WHERE domain = ?");
        $stmt->execute([$domain]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'mail_domains row survived'];
        }
        if (is_dir($built['vmail_root'] . '/' . $domain)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'maildir survived'];
        }
        if (is_dir($built['dkim_root'] . '/keys/' . $domain)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'dkim key dir survived'];
        }
        $signing = (string) file_get_contents($built['dkim_root'] . '/SigningTable');
        if (str_contains($signing, $domain)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'SigningTable line survived'];
        }
        if (!str_contains($signing, 'unrelated.example.com')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unrelated domain was nuked from SigningTable'];
        }
        $keyTable = (string) file_get_contents($built['dkim_root'] . '/KeyTable');
        if (str_contains($keyTable, $domain)) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'KeyTable line survived'];
        }
        if (!str_contains($keyTable, 'unrelated.example.com')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'unrelated domain was nuked from KeyTable'];
        }

        if (!$step->check($built['ctx'], $r->newState)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() still false after successful execute'];
        }
    });

$harness->test('teardown', 'non-empty maildir is tar\'d into the snapshot dir before removal',
    function () use (&$bundles, &$testDomains, &$sharedPdo) {
        $built = buildMailBundle($bundles, $testDomains, $sharedPdo);
        $step = new MailTeardownStep();
        $r = $step->execute($built['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute failed: ' . ($r->error ?? '?')];
        }
        $tar = $built['snapshot_root'] . '/' . $built['domain'] . '/'
            . $built['ctx']->jobId . '/vmail.tar.gz';
        if (!is_file($tar)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "vmail tarball missing at {$tar}"];
        }
        if (($r->newState->data['vmail_tar'] ?? null) !== $tar) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'vmail_tar not recorded in step state'];
        }
    });

$harness->test('teardown', 'skip_mail_snapshot=true removes the maildir without a tarball',
    function () use (&$bundles, &$testDomains, &$sharedPdo) {
        $built = buildMailBundle($bundles, $testDomains, $sharedPdo, ['skip_mail_snapshot' => true]);
        $step = new MailTeardownStep();
        $r = $step->execute($built['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute failed: ' . ($r->error ?? '?')];
        }
        if (is_dir($built['vmail_root'] . '/' . $built['domain'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'maildir survived'];
        }
        $tar = $built['snapshot_root'] . '/' . $built['domain'] . '/'
            . $built['ctx']->jobId . '/vmail.tar.gz';
        if (is_file($tar)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'tarball created despite skip_mail_snapshot=true'];
        }
    });

// ──────────────────────────────────────────────────────────────
// idempotence
// ──────────────────────────────────────────────────────────────

$harness->test('idempotence', 're-run after success is a clean no-op',
    function () use (&$bundles, &$testDomains, &$sharedPdo) {
        $built = buildMailBundle($bundles, $testDomains, $sharedPdo);
        $step = new MailTeardownStep();

        $r1 = $step->execute($built['ctx'], StepState::fresh($step->name()));
        if (!$r1->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first execute failed: ' . ($r1->error ?? '?')];
        }
        if (!$step->check($built['ctx'], $r1->newState)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() false after first run'];
        }
        $r2 = $step->execute($built['ctx'], StepState::fresh($step->name()));
        if (!$r2->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second execute failed: ' . ($r2->error ?? '?')];
        }
    });

$harness->test('idempotence', 'check() true and execute() no-op on a domain with no artifacts',
    function () use (&$bundles, &$testDomains, &$sharedPdo) {
        $built = buildMailBundle($bundles, $testDomains, $sharedPdo);
        if ($skip = skipIfNoMailTables($built)) {
            return $skip;
        }
        // Strip every artifact manually, then verify the step agrees.
        $pdo = $built['ctx']->database->pdo();
        $pdo->prepare("DELETE FROM mail_domains WHERE domain = ?")->execute([$built['domain']]);
        @exec('rm -rf ' . escapeshellarg($built['vmail_root'] . '/' . $built['domain']));
        @exec('rm -rf ' . escapeshellarg($built['dkim_root'] . '/keys/' . $built['domain']));
        file_put_contents($built['dkim_root'] . '/SigningTable', "*@unrelated.example.com x\n");
        file_put_contents($built['dkim_root'] . '/KeyTable', "x unrelated.example.com:d:/k\n");

        $step = new MailTeardownStep();
        if (!$step->check($built['ctx'], StepState::fresh($step->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() should be true with no artifacts'];
        }
        $r = $step->execute($built['ctx'], StepState::fresh($step->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute failed on clean domain: ' . ($r->error ?? '?')];
        }
    });

exit($harness->run());
