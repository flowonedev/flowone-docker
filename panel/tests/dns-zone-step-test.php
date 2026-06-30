#!/usr/bin/env php
<?php
/**
 * Provisioning Steps :: DNS Zone Lifecycle
 *
 * Exercises DnsZoneCreateStep + DnsZoneRemoveStep against the real
 * panel database (devc_vps_dash). All test fixtures use the
 * `flowone_test_*` prefix so cleanup is unambiguous if a test crashes.
 *
 * Coverage:
 *   - templateRecords() shape (no DB calls; pure)
 *   - skip rule: single-label hostnames
 *   - skip rule: payload['dns_enabled'] === false
 *   - execute() seeds 15 records (SOA, NS x2, A x2, CNAME x4, MX, SRV x3, TXT x2)
 *   - execute() is idempotent on second pass (no duplicate inserts)
 *   - check() returns true after execute, false before
 *   - compensate() drops the zone we created
 *   - compensate() preserves a pre-existing zone (only removes our records)
 *   - DnsZoneRemoveStep cleans up zones the create step left behind
 *   - DnsZoneRemoveStep also drops the zone from the legacy native
 *     pdns tables (domains/records) on migrated servers (SKIPs when
 *     those tables don't exist)
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/dns-zone-step-test.php --verbose
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
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';
require_once __DIR__ . '/lib/StepTestContext.php';

use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Step\Steps\Create\DnsZoneCreateStep;
use VpsAdmin\Agent\Provisioner\Step\Steps\Delete\DnsZoneRemoveStep;
use VpsAdmin\Tests\Lib\StepTestContext;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('DnsZoneStep', $opts);

$serverIp = '198.51.100.7';
$ns1 = 'ns1.flowone-test.invalid';
$ns2 = 'ns2.flowone-test.invalid';

$createdZones = [];
$bundles = [];
$harness->onCleanup(function () use (&$createdZones, &$bundles) {
    if (empty($createdZones)) {
        try {
            $db = \VpsAdmin\Agent\Provisioner\Support\PanelDatabase::fromDefaultConfigFiles();
            $pdo = $db->pdo();
            $stmt = $pdo->query("SELECT id, name FROM dns_domains WHERE name LIKE 'flowone-test-%.example.invalid'");
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ?")->execute([(int) $row['id']]);
                $pdo->prepare("DELETE FROM dns_domains WHERE id = ?")->execute([(int) $row['id']]);
            }
        } catch (\Throwable) {
        }
    }
    foreach ($createdZones as $row) {
        try {
            $pdo = $row['pdo'];
            $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ?")->execute([$row['zone_id']]);
            $pdo->prepare("DELETE FROM dns_domains WHERE id = ?")->execute([$row['zone_id']]);
        } catch (\Throwable) {
        }
    }
    foreach ($bundles as $b) {
        StepTestContext::teardown($b);
    }
});

$probe = StepTestContext::build();
$bundles[] = $probe;

$harness->test('preflight', 'panel db reachable + dns tables exist',
    function () use ($probe) {
        $pdo = $probe['ctx']->database->pdo();
        $pdo->query('SELECT 1');
        $stmt = $pdo->query("SHOW TABLES LIKE 'dns_domains'");
        if ($stmt->fetchColumn() === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'dns_domains table missing'];
        }
        $stmt = $pdo->query("SHOW TABLES LIKE 'dns_records'");
        if ($stmt->fetchColumn() === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'dns_records table missing'];
        }
    });

$harness->test('template', 'templateRecords returns 15 canonical records',
    function () use ($serverIp, $ns1, $ns2) {
        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        $records = $step->templateRecords('example.test');
        if (count($records) !== 15) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 15 records, got ' . count($records)];
        }
        $byKey = [];
        foreach ($records as $r) {
            $byKey[$r['type'] . '|' . $r['name']] = ($byKey[$r['type'] . '|' . $r['name']] ?? 0) + 1;
            if (!isset($r['ttl'], $r['prio'], $r['content'])) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'record shape missing fields: ' . json_encode($r)];
            }
        }
        $expected = [
            'SOA|example.test', 'NS|example.test', 'A|example.test',
            'A|mail.example.test', 'CNAME|www.example.test', 'CNAME|ftp.example.test',
            'CNAME|autodiscover.example.test', 'CNAME|autoconfig.example.test',
            'MX|example.test',
            'SRV|_autodiscover._tcp.example.test', 'SRV|_imaps._tcp.example.test',
            'SRV|_submission._tcp.example.test',
            'TXT|example.test', 'TXT|_dmarc.example.test',
        ];
        foreach ($expected as $key) {
            if (!isset($byKey[$key])) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "missing record key: {$key}"];
            }
        }
        // NS appears twice (ns1 + ns2)
        if (($byKey['NS|example.test'] ?? 0) !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 2 NS records, got ' . ($byKey['NS|example.test'] ?? 0)];
        }
    });

$harness->test('template', 'MX has prio=10, others have prio=0',
    function () use ($serverIp, $ns1, $ns2) {
        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        foreach ($step->templateRecords('example.test') as $r) {
            $expected = $r['type'] === 'MX' ? 10 : 0;
            if ($r['prio'] !== $expected) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => "wrong prio on {$r['type']}|{$r['name']}: got {$r['prio']}, want {$expected}"];
            }
        }
    });

$harness->test('template', 'SPF and DMARC content are RFC-shaped',
    function () use ($serverIp, $ns1, $ns2) {
        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        $records = $step->templateRecords('example.test');
        $spf = null; $dmarc = null;
        foreach ($records as $r) {
            if ($r['name'] === 'example.test' && $r['type'] === 'TXT') $spf = $r['content'];
            if ($r['name'] === '_dmarc.example.test' && $r['type'] === 'TXT') $dmarc = $r['content'];
        }
        if ($spf === null || strpos($spf, 'v=spf1 ') !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'SPF malformed: ' . ($spf ?? 'missing')];
        }
        if (strpos($spf, 'ip4:' . $serverIp) === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'SPF missing ip4 token: ' . $spf];
        }
        if ($dmarc === null || strpos($dmarc, 'v=DMARC1') !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DMARC malformed: ' . ($dmarc ?? 'missing')];
        }
        if (strpos($dmarc, 'p=reject') === false || strpos($dmarc, 'adkim=s') === false) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'DMARC not strict: ' . $dmarc];
        }
    });

$harness->test('skip', 'single-label hostname skips with reason',
    function () use ($serverIp, $ns1, $ns2, &$bundles) {
        $bundle = StepTestContext::build([
            'domain' => 'flowone-test-singlelabel-' . substr(bin2hex(random_bytes(2)), 0, 4),
        ]);
        $bundles[] = $bundle;
        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        $state = StepState::fresh($step->name());
        $result = $step->execute($bundle['ctx'], $state);
        if ($result->outcome !== \VpsAdmin\Agent\Provisioner\Step\StepOutcome::SKIPPED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SKIPPED, got ' . $result->outcome->value];
        }
        if (($result->newState->data['dns_skipped'] ?? null) !== 'single-label') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected dns_skipped=single-label, got '
                    . json_encode($result->newState->data['dns_skipped'] ?? null)];
        }
    });

$harness->test('skip', 'payload dns_enabled=false skips with reason',
    function () use ($serverIp, $ns1, $ns2, &$bundles) {
        $domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
        $bundle = StepTestContext::build([
            'domain' => $domain,
            'payload' => ['dns_enabled' => false],
        ]);
        $bundles[] = $bundle;
        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        $state = StepState::fresh($step->name());
        $result = $step->execute($bundle['ctx'], $state);
        if ($result->outcome !== \VpsAdmin\Agent\Provisioner\Step\StepOutcome::SKIPPED) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected SKIPPED, got ' . $result->outcome->value];
        }
        if (($result->newState->data['dns_skipped'] ?? null) !== 'dns_enabled=false') {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected dns_skipped=dns_enabled=false, got '
                    . json_encode($result->newState->data['dns_skipped'] ?? null)];
        }
    });

$harness->test('seed', 'execute creates zone + 15 records on FQDN',
    function () use ($serverIp, $ns1, $ns2, &$createdZones, &$bundles) {
        $domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
        $bundle = StepTestContext::build(['domain' => $domain]);
        $bundles[] = $bundle;
        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        $state = StepState::fresh($step->name());

        if ($step->check($bundle['ctx'], $state)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() returned true before execute()'];
        }

        $result = $step->execute($bundle['ctx'], $state);
        if (!$result->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute failed: ' . ($result->error ?? 'unknown')];
        }

        $zoneId = $result->newState->data['dns_zone_id'] ?? null;
        if (!is_int($zoneId) || $zoneId <= 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'no dns_zone_id persisted in state'];
        }
        $createdZones[] = ['pdo' => $bundle['ctx']->database->pdo(), 'zone_id' => $zoneId];

        if (!$step->check($bundle['ctx'], $result->newState)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() returned false after execute()'];
        }

        $pdo = $bundle['ctx']->database->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dns_records WHERE domain_id = ?");
        $stmt->execute([$zoneId]);
        $count = (int) $stmt->fetchColumn();
        if ($count !== 15) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "expected 15 records, got {$count}"];
        }
    });

$harness->test('seed', 'execute is idempotent (no duplicates on rerun)',
    function () use ($serverIp, $ns1, $ns2, &$createdZones, &$bundles) {
        $domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
        $bundle = StepTestContext::build(['domain' => $domain]);
        $bundles[] = $bundle;
        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);

        $state1 = StepState::fresh($step->name());
        $r1 = $step->execute($bundle['ctx'], $state1);
        if (!$r1->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'first execute failed: ' . $r1->error];
        }
        $createdZones[] = [
            'pdo' => $bundle['ctx']->database->pdo(),
            'zone_id' => (int) $r1->newState->data['dns_zone_id'],
        ];

        $r2 = $step->execute($bundle['ctx'], $r1->newState);
        if (!$r2->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'second execute failed: ' . $r2->error];
        }

        $pdo = $bundle['ctx']->database->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dns_records WHERE domain_id = ?");
        $stmt->execute([(int) $r1->newState->data['dns_zone_id']]);
        $count = (int) $stmt->fetchColumn();
        if ($count !== 15) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "after rerun expected 15 records, got {$count} (duplicates inserted)"];
        }
    });

$harness->test('compensate', 'compensate drops zone we created',
    function () use ($serverIp, $ns1, $ns2, &$createdZones, &$bundles) {
        $domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
        $bundle = StepTestContext::build(['domain' => $domain]);
        $bundles[] = $bundle;
        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);

        $state = StepState::fresh($step->name());
        $r = $step->execute($bundle['ctx'], $state);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $r->error];
        }
        $zoneId = (int) $r->newState->data['dns_zone_id'];
        $createdZones[] = ['pdo' => $bundle['ctx']->database->pdo(), 'zone_id' => $zoneId];

        $compResult = $step->compensate($bundle['ctx'], $r->newState);
        if (!$compResult->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed: ' . $compResult->error];
        }

        $pdo = $bundle['ctx']->database->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dns_domains WHERE id = ?");
        $stmt->execute([$zoneId]);
        $remaining = (int) $stmt->fetchColumn();
        if ($remaining !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "zone still present after compensate ({$remaining} rows)"];
        }
    });

$harness->test('compensate', 'compensate preserves pre-existing zone',
    function () use ($serverIp, $ns1, $ns2, &$createdZones, &$bundles) {
        $domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
        $bundle = StepTestContext::build(['domain' => $domain]);
        $bundles[] = $bundle;
        $pdo = $bundle['ctx']->database->pdo();

        // Operator-pre-created zone with one hand-rolled record.
        $pdo->prepare("INSERT INTO dns_domains (name, type) VALUES (?, 'NATIVE')")->execute([$domain]);
        $preexistingId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO dns_records (domain_id, name, type, content, ttl, prio)
             VALUES (?, ?, 'TXT', 'pre-existing manual record', 3600, 0)"
        )->execute([$preexistingId, $domain]);
        $createdZones[] = ['pdo' => $pdo, 'zone_id' => $preexistingId];

        $step = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        $state = StepState::fresh($step->name());
        $r = $step->execute($bundle['ctx'], $state);
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'execute failed: ' . $r->error];
        }
        if (($r->newState->data['created_zone'] ?? null) !== false) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'created_zone should be false for pre-existing zone'];
        }

        $compResult = $step->compensate($bundle['ctx'], $r->newState);
        if (!$compResult->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'compensate failed: ' . $compResult->error];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dns_domains WHERE id = ?");
        $stmt->execute([$preexistingId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'pre-existing zone was destroyed by compensate'];
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM dns_records
             WHERE domain_id = ? AND content = 'pre-existing manual record'"
        );
        $stmt->execute([$preexistingId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'operator hand-rolled record was destroyed by compensate'];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dns_records WHERE domain_id = ?");
        $stmt->execute([$preexistingId]);
        $remaining = (int) $stmt->fetchColumn();
        if ($remaining !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => "compensate left extra records behind ({$remaining}, expected 1)"];
        }
    });

$harness->test('remove', 'DnsZoneRemoveStep drops zone + all records',
    function () use ($serverIp, $ns1, $ns2, &$bundles) {
        $domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
        $bundle = StepTestContext::build(['domain' => $domain]);
        $bundles[] = $bundle;

        $createStep = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        $createState = StepState::fresh($createStep->name());
        $createR = $createStep->execute($bundle['ctx'], $createState);
        if (!$createR->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'create failed: ' . $createR->error];
        }
        $zoneId = (int) $createR->newState->data['dns_zone_id'];

        $removeStep = new DnsZoneRemoveStep();
        if ($removeStep->check($bundle['ctx'], StepState::fresh($removeStep->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'remove check() returned true before execute (zone present)'];
        }

        $removeR = $removeStep->execute($bundle['ctx'], StepState::fresh($removeStep->name()));
        if (!$removeR->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'remove execute failed: ' . $removeR->error];
        }

        if (!$removeStep->check($bundle['ctx'], $removeR->newState)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'remove check() returned false after execute (zone still present)'];
        }

        $pdo = $bundle['ctx']->database->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dns_records WHERE domain_id = ?");
        $stmt->execute([$zoneId]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'records survived remove'];
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dns_domains WHERE id = ?");
        $stmt->execute([$zoneId]);
        if ((int) $stmt->fetchColumn() !== 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'zone survived remove'];
        }
    });

$harness->test('remove', 'DnsZoneRemoveStep also drops the zone from native pdns tables',
    function () use ($serverIp, $ns1, $ns2, &$bundles) {
        $domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
        $bundle = StepTestContext::build(['domain' => $domain]);
        $bundles[] = $bundle;
        $pdo = $bundle['ctx']->database->pdo();

        // Native gmysql tables only exist on migrated servers.
        try {
            $pdo->query("SELECT 1 FROM domains LIMIT 1");
            $pdo->query("SELECT 1 FROM records LIMIT 1");
        } catch (\Throwable) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'native pdns tables (domains/records) absent on this install'];
        }

        // Seed the zone in BOTH table pairs - the migrated-server
        // shape that produced the testsite.hu leftover.
        $createStep = new DnsZoneCreateStep($serverIp, $ns1, $ns2);
        $createR = $createStep->execute($bundle['ctx'], StepState::fresh($createStep->name()));
        if (!$createR->isSuccess()) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'create failed: ' . $createR->error];
        }
        $pdo->prepare("INSERT INTO domains (name, type) VALUES (?, 'NATIVE')")->execute([$domain]);
        $nativeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO records (domain_id, name, type, content, ttl, prio)
             VALUES (?, ?, 'A', '198.51.100.7', 3600, 0)"
        )->execute([$nativeId, $domain]);

        try {
            $removeStep = new DnsZoneRemoveStep();
            if ($removeStep->check($bundle['ctx'], StepState::fresh($removeStep->name()))) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'check() true while the native zone still exists'];
            }

            $removeR = $removeStep->execute($bundle['ctx'], StepState::fresh($removeStep->name()));
            if (!$removeR->isSuccess()) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'remove execute failed: ' . $removeR->error];
            }
            if ((int) ($removeR->newState->data['removed_native_records'] ?? 0) < 2) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'removed_native_records < 2 (zone + record rows expected)'];
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM domains WHERE name = ?");
            $stmt->execute([$domain]);
            if ((int) $stmt->fetchColumn() !== 0) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'native domains row survived'];
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM records WHERE domain_id = ?");
            $stmt->execute([$nativeId]);
            if ((int) $stmt->fetchColumn() !== 0) {
                return ['outcome' => TestHarness::FAIL, 'message' => 'native records survived'];
            }
            if (!$removeStep->check($bundle['ctx'], $removeR->newState)) {
                return ['outcome' => TestHarness::FAIL,
                    'message' => 'check() still false after native cleanup'];
            }
        } finally {
            // Belt-and-braces: never leak native fixture rows even on FAIL.
            try {
                $pdo->prepare("DELETE FROM records WHERE domain_id = ?")->execute([$nativeId]);
                $pdo->prepare("DELETE FROM domains WHERE id = ?")->execute([$nativeId]);
            } catch (\Throwable) {
            }
        }
    });

$harness->test('remove', 'DnsZoneRemoveStep is no-op when zone absent',
    function () use (&$bundles) {
        $domain = 'flowone-test-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.example.invalid';
        $bundle = StepTestContext::build(['domain' => $domain]);
        $bundles[] = $bundle;
        $removeStep = new DnsZoneRemoveStep();
        if (!$removeStep->check($bundle['ctx'], StepState::fresh($removeStep->name()))) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'check() should return true when zone is already absent'];
        }
        $r = $removeStep->execute($bundle['ctx'], StepState::fresh($removeStep->name()));
        if (!$r->isSuccess()) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'execute failed when zone absent: ' . $r->error];
        }
    });

exit($harness->run());
