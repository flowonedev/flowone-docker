#!/usr/bin/env php
<?php
/**
 * Backfill Sites :: backfill-sites-from-vhosts.php
 *
 * Exercises the legacy-vhost backfill CLI end to end:
 *   - parses vhost.conf in a sandboxed /tmp dir (no live OLS reads)
 *   - dry-run prints the would-be config without inserting
 *   - real run inserts a row via SiteStateMachine::adoptExisting()
 *   - re-run reports skipped_existing (idempotent)
 *   - overwrite re-run rewrites columns without changing the site id
 *
 * All test data uses the `flowone-test-bf-*.test` domain shape so
 * cleanup is grep-safe and never collides with real sites.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/backfill-sites-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'smoke', 'only:', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('BackfillSites', $opts);

$db = null;
$pdo = null;
/** @var list<string> */
$testDomains = [];
/** @var list<string> */
$sandboxDirs = [];

$harness->onCleanup(function () use (&$pdo, &$testDomains, &$sandboxDirs): void {
    if ($pdo && $testDomains) {
        $in = implode(',', array_fill(0, count($testDomains), '?'));
        @$pdo->prepare("DELETE FROM site_audit_log WHERE site_domain IN ({$in})")->execute($testDomains);
        @$pdo->prepare("DELETE FROM sites WHERE domain IN ({$in})")->execute($testDomains);
    }
    foreach ($sandboxDirs as $sandbox) {
        if (is_dir($sandbox) && str_starts_with($sandbox, sys_get_temp_dir())) {
            @exec('rm -rf ' . escapeshellarg($sandbox));
        }
    }
});

// Domains shaped like the action validator allows (no underscores,
// hyphens OK, `.test` TLD reserved by RFC 6761).
function bfTestDomain(): string
{
    return 'flowone-test-bf-' . bin2hex(random_bytes(3)) . '.test';
}

function bfBuildSandbox(string $domain, array $vhostFields = []): string
{
    $sandbox = sys_get_temp_dir() . '/flowone_test_bf_' . bin2hex(random_bytes(4));
    @mkdir($sandbox . '/' . $domain, 0755, true);
    $configPath = $sandbox . '/' . $domain . '/vhost.conf';
    $extUser = $vhostFields['ext_user'] ?? ('site_' . preg_replace('/[^a-z0-9]/', '', strtolower($domain)));
    $extUser = substr($extUser, 0, 30);
    $phpHandler = $vhostFields['php_handler'] ?? 'lsphp83';
    // Mirrors the CyberPanel format VhostAction parses today.
    $conf = <<<CONF
docRoot                   \$VH_ROOT/public_html
extUser                   {$extUser}
context / {
  type                    appserver
}
extprocessor {$phpHandler} {
  type                    lsapi
  address                 uds:///tmp/{$extUser}.sock
  path                    /usr/local/lsws/{$phpHandler}/bin/lsphp
  extUser                 {$extUser}
}
CONF;
    file_put_contents($configPath, $conf);
    return $sandbox;
}

function bfRunCli(array $args): array
{
    $php = PHP_BINARY ?: '/usr/local/lsws/lsphp83/bin/php';
    $script = realpath(__DIR__ . '/../agent/backfill-sites-from-vhosts.php');
    $argv = array_merge(['--json'], $args);
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    foreach ($argv as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $cmd .= ' 2>&1';
    $output = shell_exec($cmd);
    if ($output === null || $output === false) {
        return ['error' => 'cli produced no output', 'raw' => null];
    }
    $decoded = json_decode((string) $output, true);
    if (!is_array($decoded)) {
        return ['error' => 'cli output was not JSON', 'raw' => $output];
    }
    return $decoded;
}

// ─── Preflight ───────────────────────────────────────────────
$harness->test('preflight', 'PanelDatabase + sites table reachable',
    function () use (&$db, &$pdo) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        $r = $pdo->query("SHOW TABLES LIKE 'sites'");
        if ($r->rowCount() === 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'sites table missing (run migrate_sites_state.sql)'];
        }
    });

$harness->test('preflight', 'backfill CLI prints --help without exploding',
    function () {
        $php = PHP_BINARY ?: '/usr/local/lsws/lsphp83/bin/php';
        $script = realpath(__DIR__ . '/../agent/backfill-sites-from-vhosts.php');
        $out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --help 2>&1');
        if (!is_string($out) || !str_contains($out, 'backfill-sites-from-vhosts')) {
            return ['outcome' => TestHarness::FAIL,
                'message' => '--help missing expected banner; got: ' . substr((string) $out, 0, 200)];
        }
    });

// ─── Dry-run does not insert ─────────────────────────────────
$harness->test('dry_run', 'dry-run reports the would-be config without writing',
    function () use (&$pdo, &$testDomains, &$sandboxDirs) {
        $domain = bfTestDomain();
        $sandbox = bfBuildSandbox($domain);
        $testDomains[] = $domain;
        $sandboxDirs[] = $sandbox;

        $result = bfRunCli([
            '--vhosts=' . $sandbox,
            '--only=' . $domain,
            '--dry-run',
        ]);
        if (isset($result['error'])) {
            return ['outcome' => TestHarness::FAIL,
                'message' => $result['error'] . ' raw=' . substr((string) ($result['raw'] ?? ''), 0, 400)];
        }
        if (($result['scanned'] ?? 0) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected scanned=1, got ' . ($result['scanned'] ?? '<missing>')];
        }
        if (($result['inserted'] ?? 0) !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'dry-run inserted=' . ($result['inserted'] ?? '<missing>') . ' (must be 0)'];
        }
        // Confirm the database really wasn't touched.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'dry-run still inserted a sites row'];
        }
    });

// ─── Real run inserts ────────────────────────────────────────
$harness->test('insert', 'real run inserts a row in active state with parsed fields',
    function () use (&$pdo, &$testDomains, &$sandboxDirs) {
        $domain = bfTestDomain();
        $sandbox = bfBuildSandbox($domain, ['php_handler' => 'lsphp82', 'ext_user' => 'site_bftest']);
        $testDomains[] = $domain;
        $sandboxDirs[] = $sandbox;

        $result = bfRunCli([
            '--vhosts=' . $sandbox,
            '--only=' . $domain,
        ]);
        if (isset($result['error'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => $result['error']];
        }
        if (($result['inserted'] ?? 0) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected inserted=1, got ' . json_encode($result)];
        }

        $stmt = $pdo->prepare('SELECT actual_state, desired_state, php_version, sftp_user,
                                      document_root, home_dir, imported_at
                                 FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'row not visible after insert'];
        }
        if ($row['actual_state'] !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "actual_state={$row['actual_state']}, expected active"];
        }
        if ($row['desired_state'] !== 'active') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "desired_state={$row['desired_state']}, expected active"];
        }
        if ($row['php_version'] !== '8.2') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "php_version='{$row['php_version']}', expected '8.2'"];
        }
        if ($row['sftp_user'] !== 'site_bftest') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "sftp_user='{$row['sftp_user']}', expected 'site_bftest'"];
        }
        if ($row['home_dir'] !== '/home/' . $domain) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "home_dir='{$row['home_dir']}', expected '/home/{$domain}'"];
        }
        if ($row['document_root'] !== '/home/' . $domain . '/public_html') {
            return ['outcome' => TestHarness::FAIL,
                'message' => "document_root='{$row['document_root']}'"];
        }
        if ($row['imported_at'] === null) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'imported_at must be populated for backfilled rows'];
        }
    });

// ─── Re-run is idempotent ────────────────────────────────────
$harness->test('idempotent', 're-run reports skipped_existing without touching the row',
    function () use (&$pdo, &$testDomains, &$sandboxDirs) {
        $domain = bfTestDomain();
        $sandbox = bfBuildSandbox($domain);
        $testDomains[] = $domain;
        $sandboxDirs[] = $sandbox;

        $first = bfRunCli(['--vhosts=' . $sandbox, '--only=' . $domain]);
        if (($first['inserted'] ?? 0) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first run did not insert: ' . json_encode($first)];
        }

        $stmt = $pdo->prepare('SELECT id, updated_at FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        $before = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Sleep one second so any accidental UPDATE would bump updated_at
        // to a visibly different timestamp.
        sleep(1);

        $second = bfRunCli(['--vhosts=' . $sandbox, '--only=' . $domain]);
        if (($second['inserted'] ?? 0) !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second run inserted again: ' . json_encode($second)];
        }
        if (($second['skipped_existing'] ?? 0) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'second run did not report skipped_existing=1: ' . json_encode($second)];
        }
        $stmt->execute(['d' => $domain]);
        $after = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($before['id'] !== $after['id']) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'site id mutated between runs (was ' . $before['id'] . ', now ' . $after['id'] . ')'];
        }
        if ($before['updated_at'] !== $after['updated_at']) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'updated_at moved on idempotent re-run (' . $before['updated_at'] . ' -> ' . $after['updated_at'] . ')'];
        }
    });

// ─── Overwrite rewrites in place ─────────────────────────────
$harness->test('overwrite', '--overwrite refreshes columns but keeps the same site id',
    function () use (&$pdo, &$testDomains, &$sandboxDirs) {
        $domain = bfTestDomain();
        $sandbox = bfBuildSandbox($domain, ['php_handler' => 'lsphp82']);
        $testDomains[] = $domain;
        $sandboxDirs[] = $sandbox;

        $first = bfRunCli(['--vhosts=' . $sandbox, '--only=' . $domain]);
        if (($first['inserted'] ?? 0) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'first run did not insert: ' . json_encode($first)];
        }
        $stmt = $pdo->prepare('SELECT id, php_version FROM sites WHERE domain = :d');
        $stmt->execute(['d' => $domain]);
        $before = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($before['php_version'] !== '8.2') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'pre-overwrite php_version=' . $before['php_version']];
        }

        // Build a fresh sandbox with a different PHP handler then run
        // the backfill with --overwrite. The site row's php_version
        // must reflect the new value while the site id stays put.
        $newSandbox = bfBuildSandbox($domain, ['php_handler' => 'lsphp84']);
        $sandboxDirs[] = $newSandbox;

        $second = bfRunCli(['--vhosts=' . $newSandbox, '--only=' . $domain, '--overwrite']);
        if (isset($second['error'])) {
            return ['outcome' => TestHarness::FAIL, 'message' => $second['error']];
        }
        $stmt->execute(['d' => $domain]);
        $after = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($after['id'] !== $before['id']) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'overwrite mutated site id'];
        }
        if ($after['php_version'] !== '8.4') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'overwrite did not update php_version (still ' . $after['php_version'] . ')'];
        }
    });

// ─── Audit row was written ───────────────────────────────────
$harness->test('audit', 'backfill writes a site_adopted_existing row to site_audit_log',
    function () use (&$pdo, &$testDomains, &$sandboxDirs) {
        $domain = bfTestDomain();
        $sandbox = bfBuildSandbox($domain);
        $testDomains[] = $domain;
        $sandboxDirs[] = $sandbox;

        $r = bfRunCli(['--vhosts=' . $sandbox, '--only=' . $domain]);
        if (($r['inserted'] ?? 0) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'insert did not run: ' . json_encode($r)];
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM site_audit_log
              WHERE site_domain = :d AND action = 'site_adopted_existing'"
        );
        $stmt->execute(['d' => $domain]);
        if ((int) $stmt->fetchColumn() < 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'no site_adopted_existing audit row found'];
        }
    });

exit($harness->run());
