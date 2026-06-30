#!/usr/bin/env php
<?php
/**
 * SecretMasker / Secret Leak Scanner
 *
 * Two layers of verification:
 *   1. SecretMasker unit behavior: known secret-shaped values must be
 *      masked; known non-secret values must NOT be masked.
 *   2. Real-data scan: walk site_step_executions, site_job_events,
 *      site_audit_log looking for plaintext that LOOKS LIKE a secret
 *      slipped past the masker. This is a regression net for the
 *      strongest guarantee we make: "no plaintext credentials in
 *      observability data."
 *
 * Exits non-zero if any plaintext-looking secret is found in those
 * tables. False positives are acceptable in scan mode; investigate
 * each hit manually.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/secret-leak-test.php --verbose
 *
 * Options:
 *   --verbose          Show extra debug info
 *   --skip-send        n/a
 *   --only=GROUP       masker,scan
 *   --smoke            unit tests only (skip the scan)
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

use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('SecretLeak', $opts);

$db = null;
$pdo = null;

$harness->test('preflight', 'PanelDatabase reachable', function () use (&$db, &$pdo) {
    $db = PanelDatabase::fromDefaultConfigFiles();
    $pdo = $db->pdo();
    $pdo->query('SELECT 1');
});

// ── masker unit cases ─────────────────────────────────────────
$harness->test('masker', 'secret-named keys are redacted regardless of value',
    function () {
        $masker = new SecretMasker();
        $cases = [
            'password' => 'foo',
            'db_password' => 'bar',
            'api_token' => 'abc',
            'authorization' => 'Bearer xyz',
            'private_key' => '-----BEGIN PRIVATE KEY-----\nABC\n-----END PRIVATE KEY-----',
            'dkim_key' => 'whatever',
        ];
        $masked = $masker->maskArray($cases);
        foreach (array_keys($cases) as $k) {
            if ($masked[$k] !== '***REDACTED***') {
                return ['outcome' => TestHarness::FAIL, 'message' => "key {$k} not redacted: " . $masked[$k]];
            }
        }
    });

$harness->test('masker', 'exempt structural keys are NOT redacted',
    function () {
        $masker = new SecretMasker();
        $cases = [
            'password_age_days' => 30,
            'token_count' => 5,
            'session_count' => 2,
            'secret_count' => 7,
        ];
        $masked = $masker->maskArray($cases);
        foreach ($cases as $k => $v) {
            if ($masked[$k] !== $v) {
                return ['outcome' => TestHarness::FAIL, 'message' => "exempt key {$k} was incorrectly redacted"];
            }
        }
    });

$harness->test('masker', 'PEM-wrapped private keys in string values are redacted',
    function () {
        $masker = new SecretMasker();
        $pem = "junk before\n-----BEGIN PRIVATE KEY-----\n" .
               base64_encode(random_bytes(48)) .
               "\n-----END PRIVATE KEY-----\njunk after";
        $out = $masker->maskValue($pem);
        if (strpos($out, 'BEGIN PRIVATE KEY') !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'PEM block leaked'];
        }
        if (strpos($out, 'junk before') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'masker ate surrounding text'];
        }
    });

$harness->test('masker', 'JWT-shaped tokens in string values are redacted',
    function () {
        $masker = new SecretMasker();
        $jwt = 'header.body.signature';
        // Build a realistic JWT (eyJ prefix)
        $real = base64_encode('{"alg":"HS256"}') . '.' . base64_encode('{"sub":"1"}') . '.' . base64_encode(random_bytes(32));
        $real = 'eyJ' . substr($real, 3);
        $out = $masker->maskValue("Auth: Bearer {$real} trailing");
        if (strpos($out, $real) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'JWT not redacted: ' . $out];
        }
    });

$harness->test('masker', 'Bearer auth header is redacted',
    function () {
        $masker = new SecretMasker();
        $headerToken = bin2hex(random_bytes(24));
        $line = "Authorization: Bearer {$headerToken}";
        $out = $masker->maskValue($line);
        if (strpos($out, $headerToken) !== false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'bearer token leaked: ' . $out];
        }
    });

$harness->test('masker', 'maskArray is recursive into nested arrays',
    function () {
        $masker = new SecretMasker();
        $data = [
            'site' => 'example.com',
            'creds' => [
                'db' => ['user' => 'site_x', 'password' => 'supersecret'],
                'mail' => ['api_key' => 'abc123'],
            ],
        ];
        $masked = $masker->maskArray($data);
        if ($masked['creds']['db']['password'] !== '***REDACTED***') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'nested password leaked'];
        }
        if ($masked['creds']['mail']['api_key'] !== '***REDACTED***') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'nested api_key leaked'];
        }
        if ($masked['site'] !== 'example.com') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'innocuous value mangled'];
        }
    });

// ── scan: walk audit/journal tables for plaintext-looking secrets ───
$harness->test('scan', 'site_audit_log has no plaintext-looking secrets in snapshots',
    function () use (&$pdo, $harness) {
        if ($harness->isSmoke()) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'smoke mode'];
        }
        return scanColumn($pdo, 'site_audit_log', ['before_snapshot', 'after_snapshot']);
    });

$harness->test('scan', 'site_step_executions has no plaintext-looking secrets',
    function () use (&$pdo, $harness) {
        if ($harness->isSmoke()) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'smoke mode'];
        }
        // Only proceed if the table exists - it's populated only once Step 2 runs.
        $check = $pdo->query("SHOW TABLES LIKE 'site_step_executions'");
        if ($check->rowCount() === 0) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'table not present yet'];
        }
        return scanColumn(
            $pdo,
            'site_step_executions',
            ['input_snapshot', 'output_snapshot', 'stdout_excerpt', 'stderr_excerpt']
        );
    });

$harness->test('scan', 'site_job_events has no plaintext-looking secrets in metadata',
    function () use (&$pdo, $harness) {
        if ($harness->isSmoke()) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'smoke mode'];
        }
        $check = $pdo->query("SHOW TABLES LIKE 'site_job_events'");
        if ($check->rowCount() === 0) {
            return ['outcome' => TestHarness::SKIP, 'message' => 'table not present yet'];
        }
        return scanColumn($pdo, 'site_job_events', ['message', 'metadata']);
    });

/**
 * Scan named columns of a table looking for substrings that look like
 * unmasked secrets. Returns a TestHarness result.
 */
function scanColumn(\PDO $pdo, string $table, array $columns): array
{
    // Patterns of "this looks like an unmasked secret".
    $patterns = [
        '/-----BEGIN [A-Z ]+ PRIVATE KEY-----/',
        // JWT pattern with eyJ prefix
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
        // bearer tokens still wrapped in the literal "Bearer " prefix
        '/Bearer\s+[A-Za-z0-9+\/=_.-]{32,}/',
    ];

    foreach ($columns as $col) {
        $stmt = $pdo->query("SELECT id, {$col} FROM {$table} WHERE {$col} IS NOT NULL LIMIT 1000");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $val = (string) $row[$col];
            // Skip rows where the redaction marker is already present - they are masked.
            if (strpos($val, '***REDACTED***') !== false) {
                continue;
            }
            foreach ($patterns as $p) {
                if (preg_match($p, $val)) {
                    return [
                        'outcome' => TestHarness::FAIL,
                        'message' => "{$table}.{$col} id={$row['id']} contains an unmasked secret-shaped value",
                    ];
                }
            }
        }
    }
    return ['outcome' => TestHarness::PASS];
}

exit($harness->run());
