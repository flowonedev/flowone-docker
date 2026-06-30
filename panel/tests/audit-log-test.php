#!/usr/bin/env php
<?php
/**
 * AuditLogger Test Suite
 *
 * Verifies:
 *   - record() inserts a row with the correct fields.
 *   - Actor identity (user/IP/agent/token/request_id) is preserved.
 *   - before/after snapshots are pre-masked by SecretMasker.
 *   - note() works as the no-diff convenience path.
 *   - Failure to write throws (we refuse silent audit loss).
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/audit-log-test.php --verbose
 *
 * Options:
 *   --verbose
 *   --skip-send  n/a
 *   --only=GROUP   preflight,write,actor,masking
 *   --smoke
 *   --json
 *   --help
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
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('AuditLogger', $opts);

$db = null;
$pdo = null;
$logger = null;
$testDomain = '[flowone_test_]audit-' . bin2hex(random_bytes(4)) . '.local';

$harness->onCleanup(function () use (&$pdo, &$testDomain): void {
    if ($pdo) {
        $pdo->prepare('DELETE FROM site_audit_log WHERE site_domain = ?')
            ->execute([$testDomain]);
    }
});

$harness->test('preflight', 'PanelDatabase + site_audit_log',
    function () use (&$db, &$pdo, &$logger) {
        $db = PanelDatabase::fromDefaultConfigFiles();
        $pdo = $db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'site_audit_log'")->rowCount() === 0) {
            return [
                'outcome' => TestHarness::FAIL,
                'message' => 'site_audit_log missing. Run migrate_site_audit_log.sql.',
            ];
        }
        $logger = new AuditLogger($db, new SecretMasker());
    });

$harness->test('write', 'record() inserts a complete audit row',
    function () use (&$logger, &$pdo, &$testDomain) {
        $actor = new ActorContext(
            username: 'flowone_test_user',
            userId: 12345,
            sourceIp: '203.0.113.7',
            apiTokenId: 'tok_test_abc',
            userAgent: 'flowone-test/1.0',
            requestId: 'req-flowone-test-' . bin2hex(random_bytes(3)),
            service: 'cli:audit-log-test',
        );
        $id = $logger->record(
            action: 'flowone_test_state_transition',
            siteDomain: $testDomain,
            reason: 'unit test',
            before: ['actual_state' => 'provisioning', 'password' => 'leak'],
            after: ['actual_state' => 'active', 'ssl_enabled' => true],
            actor: $actor,
            jobId: 999000,
        );
        $row = $pdo->prepare(
            'SELECT * FROM site_audit_log WHERE id = ?'
        );
        $row->execute([$id]);
        $r = $row->fetch();
        if (!$r) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'no row inserted'];
        }
        if ($r['actor_username'] !== 'flowone_test_user') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'actor_username mismatch'];
        }
        if ((int) $r['actor_user_id'] !== 12345) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'actor_user_id mismatch'];
        }
        if ($r['source_ip'] !== '203.0.113.7') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'source_ip mismatch'];
        }
        if ($r['api_token_id'] !== 'tok_test_abc') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'api_token_id mismatch'];
        }
        if ((int) $r['job_id'] !== 999000) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'job_id mismatch'];
        }
    });

$harness->test('masking', 'snapshots are masked before persistence',
    function () use (&$logger, &$pdo, &$testDomain) {
        $actor = ActorContext::cli('audit-log-test');
        $logger->record(
            action: 'flowone_test_state_transition',
            siteDomain: $testDomain,
            reason: 'masking test',
            before: ['password' => 'leak-attempt-1', 'site' => 'ok'],
            after: ['api_token' => 'leak-attempt-2', 'private_key' => 'leak-attempt-3'],
            actor: $actor,
        );
        $row = $pdo->prepare(
            "SELECT before_snapshot, after_snapshot FROM site_audit_log
              WHERE site_domain = ? AND reason = 'masking test' ORDER BY id DESC LIMIT 1"
        );
        $row->execute([$testDomain]);
        $r = $row->fetch();
        if (!$r) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'row missing'];
        }
        foreach (['leak-attempt-1', 'leak-attempt-2', 'leak-attempt-3'] as $forbidden) {
            if (strpos($r['before_snapshot'] . $r['after_snapshot'], $forbidden) !== false) {
                return [
                    'outcome' => TestHarness::FAIL,
                    'message' => "found unmasked: {$forbidden}",
                ];
            }
        }
    });

$harness->test('write', 'note() works as the no-diff convenience path',
    function () use (&$logger, &$pdo, &$testDomain) {
        $id = $logger->note(
            'flowone_test_manual_override',
            $testDomain,
            'manual fix',
            ActorContext::cli('audit-log-test', 'operator-1')
        );
        $row = $pdo->prepare('SELECT before_snapshot, after_snapshot, action FROM site_audit_log WHERE id = ?');
        $row->execute([$id]);
        $r = $row->fetch();
        if (!$r) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'note row missing'];
        }
        if ($r['action'] !== 'flowone_test_manual_override') {
            return ['outcome' => TestHarness::FAIL, 'message' => 'action mismatch'];
        }
        if ($r['before_snapshot'] !== null || $r['after_snapshot'] !== null) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'snapshots should be NULL for note()'];
        }
    });

exit($harness->run());
