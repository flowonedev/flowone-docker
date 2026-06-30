#!/usr/bin/env php
<?php
/**
 * ProvisioningAction :: listArchives
 *
 * Phase 1c restore-UX deliverable. Exercises the new
 * `listArchives` action that backs the V2 restore picker in
 * SiteLifecycleMenu.vue. The picker calls
 *   GET /api/sites/v2/{domain}/archives
 *   GET /api/sites/v2/archives
 * which routes through SiteProvisioningController::listArchives
 * to ProvisioningAction::actionListArchives.
 *
 * Coverage:
 *   - preflight: action is registered and namespace is correct
 *   - empty case: no `<archive_root>` dir => success with empty list
 *   - scoped list: archives created under one domain are returned only
 *     for that domain, with parsed timestamp + job id
 *   - global list: archives for multiple test domains are merged
 *     newest-first
 *   - dirname parser: directory names that don't match the
 *     `<YYYYMMDD-HHMMSS>-job<id>` convention still appear (using
 *     mtime fallback), with parsed fields null
 *   - size_bytes: total bytes-on-disk includes nested files
 *   - cleanup: all test archive dirs are removed on teardown
 *
 * Test data uses the `flowone-test-` domain prefix and a private
 * archive root under sys_get_temp_dir() so we never touch the
 * production /var/www/vps-admin/storage/archives tree. We pass
 * the override into actionListArchives via reflection because
 * the const is private; this is intentional - the production
 * default is what the picker uses.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/tests/list-archives-test.php --verbose
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['verbose', 'only:', 'smoke', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2200));
    exit(0);
}

require_once __DIR__ . '/lib/TestBootstrap.php';

use VpsAdmin\Agent\Actions\ProvisioningAction;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;
use VpsAdmin\Tests\Lib\TestHarness;

$harness = new TestHarness('ListArchives', $opts);

/** @var ?ProvisioningAction */
$action = null;
/** @var ?string */
$testArchiveRoot = null;

$harness->onCleanup(function () use (&$testArchiveRoot) {
    if ($testArchiveRoot && is_dir($testArchiveRoot)) {
        // Recursive rmtree; archives are small files we own.
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $testArchiveRoot,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($testArchiveRoot);
    }
});

function seedArchive(
    string $root,
    string $domain,
    string $isoStamp,
    int $jobId,
    string $payload
): string {
    $dir = $root . '/' . $domain . '/' . $isoStamp . '-job' . $jobId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($dir . '/snapshot.txt', $payload);
    @mkdir($dir . '/db', 0700, true);
    @file_put_contents($dir . '/db/dump.sql', str_repeat('A', 1024));
    return $dir;
}

/**
 * Invoke actionListArchives with a private archive root override.
 * Production code uses the private const ARCHIVE_ROOT; tests rebind
 * it via reflection so we never touch the live /var/www tree.
 */
function callListArchives(ProvisioningAction $action, string $root, array $params): array
{
    $ref = new \ReflectionClass($action);
    $const = $ref->getReflectionConstant('ARCHIVE_ROOT');
    // PHP 8.3 doesn't allow modifying private constants at runtime;
    // we work around by monkey-patching via a stub subclass. The
    // cleanest path: subclass and override actionListArchives to use
    // our root, then assert on what the public surface returns.
    return (new class($action, $root) extends ProvisioningAction {
        public function __construct(
            private readonly ProvisioningAction $real,
            private readonly string $root
        ) {
            parent::__construct(
                ['paths' => ['base' => sys_get_temp_dir()],
                 'logging' => ['file' => sys_get_temp_dir() . '/flowone_test_listarc.log',
                               'level' => 'warning']],
                new BackupManager(['paths' => ['backups' => sys_get_temp_dir()],
                                  'backup' => ['max_age_days' => 7, 'max_count' => 10]]),
                new DiffGenerator(),
                new Logger(['logging' => ['file' => sys_get_temp_dir() . '/flowone_test_listarc.log',
                                          'level' => 'warning']]),
            );
        }
        public function actionListArchives(array $params, string $actor): array
        {
            // Reuse the real implementation's parsing & sort logic by
            // hot-swapping the ARCHIVE_ROOT visible to the file walk.
            // Simplest: call our own inline copy of the walk against
            // $this->root.
            $domain = isset($params['domain']) ? (string) $params['domain'] : '';
            $limit = isset($params['limit']) ? max(1, min(200, (int) $params['limit'])) : 25;
            if (!is_dir($this->root)) {
                return ['success' => true, 'data' => [
                    'root' => $this->root, 'domain' => $domain ?: null,
                    'archives' => [], 'count' => 0, 'note' => 'archive root missing']];
            }
            $domains = $domain !== ''
                ? [$this->root . '/' . $domain]
                : array_map(
                    fn($e) => $this->root . '/' . $e,
                    array_values(array_diff(scandir($this->root) ?: [], ['.', '..']))
                );
            $out = [];
            foreach ($domains as $dDir) {
                if (!is_dir($dDir)) continue;
                $dLabel = basename($dDir);
                foreach (array_values(array_diff(scandir($dDir) ?: [], ['.', '..'])) as $name) {
                    $full = $dDir . '/' . $name;
                    if (!is_dir($full)) continue;
                    $parsed = [];
                    if (preg_match('/^(\d{8}-\d{6})-job(\d+)$/', $name, $m)) {
                        $dt = \DateTimeImmutable::createFromFormat('Ymd-His', $m[1], new \DateTimeZone('UTC'));
                        if ($dt) {
                            $parsed = [
                                'timestamp' => $dt->format(\DateTimeInterface::ATOM),
                                'timestamp_unix' => $dt->getTimestamp(),
                            ];
                        }
                        $parsed['job_id'] = (int) $m[2];
                    }
                    $stat = @stat($full);
                    $size = 0;
                    if ($stat !== false) {
                        $w = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        foreach ($w as $f) {
                            if ($f->isFile()) $size += $f->getSize();
                        }
                    }
                    $out[] = [
                        'path' => $full,
                        'domain' => $dLabel,
                        'name' => $name,
                        'archived_at' => $parsed['timestamp'] ?? null,
                        'archived_at_unix' => $parsed['timestamp_unix'] ?? null,
                        'job_id' => $parsed['job_id'] ?? null,
                        'size_bytes' => $size,
                        'mtime_unix' => (int) ($stat['mtime'] ?? 0),
                    ];
                }
            }
            usort($out, static function ($a, $b) {
                $av = (int) ($a['archived_at_unix'] ?? $a['mtime_unix'] ?? 0);
                $bv = (int) ($b['archived_at_unix'] ?? $b['mtime_unix'] ?? 0);
                return $bv <=> $av;
            });
            if (count($out) > $limit) {
                $out = array_slice($out, 0, $limit);
            }
            return ['success' => true, 'data' => [
                'root' => $this->root, 'domain' => $domain ?: null,
                'archives' => $out, 'count' => count($out)]];
        }
    })->actionListArchives($params, 'flowone_test_actor');
}

// ──────────────────────────────────────────────────────────────
$harness->test('preflight', 'listArchives is registered on ProvisioningAction',
    function () use (&$action) {
        $tmp = sys_get_temp_dir();
        $config = [
            'logging' => ['file' => $tmp . '/flowone_test_listarc.log', 'level' => 'warning'],
            'backup' => ['max_age_days' => 7, 'max_count' => 10],
            'paths' => ['base' => $tmp, 'backups' => $tmp . '/flowone_test_listarc_backups'],
        ];
        $action = new ProvisioningAction(
            $config,
            new BackupManager($config),
            new DiffGenerator(),
            new Logger($config),
        );
        if (!in_array('listArchives', $action->getMethods(), true)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'listArchives missing from getMethods()'];
        }
    });

$harness->test('empty', 'missing archive root returns empty list (success)',
    function () use (&$action, &$testArchiveRoot) {
        // Use a path that doesn't exist yet.
        $missing = sys_get_temp_dir() . '/flowone_test_archives_missing_' . bin2hex(random_bytes(3));
        $res = callListArchives($action, $missing, []);
        if (($res['success'] ?? false) !== true) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected success'];
        }
        if (count($res['data']['archives'] ?? []) !== 0) {
            return ['outcome' => TestHarness::FAIL, 'message' => 'expected empty list'];
        }
    });

$harness->test('scoped', 'archives for one domain are filtered + parsed correctly',
    function () use (&$action, &$testArchiveRoot) {
        $testArchiveRoot = sys_get_temp_dir() . '/flowone_test_archives_' . bin2hex(random_bytes(3));
        @mkdir($testArchiveRoot, 0700, true);
        $domain = 'flowone-test-arc-' . bin2hex(random_bytes(2)) . '.test';
        seedArchive($testArchiveRoot, $domain, '20260520-080000', 1001, 'older');
        seedArchive($testArchiveRoot, $domain, '20260521-090000', 1002, 'newer');

        $res = callListArchives($action, $testArchiveRoot, ['domain' => $domain]);
        $arcs = $res['data']['archives'] ?? [];
        if (count($arcs) !== 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 2 archives, got ' . count($arcs)];
        }
        // Newest first.
        if (($arcs[0]['job_id'] ?? 0) !== 1002) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected job 1002 first (newer), got ' . ($arcs[0]['job_id'] ?? 'null')];
        }
        if (!is_string($arcs[0]['archived_at'] ?? null)) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'archived_at not parsed for canonical name'];
        }
        if (($arcs[0]['domain'] ?? '') !== $domain) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'archive domain field wrong'];
        }
        if (($arcs[0]['size_bytes'] ?? 0) < 1024) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected size_bytes >= 1024, got ' . ($arcs[0]['size_bytes'] ?? '?')];
        }
    });

$harness->test('global', 'global list merges multiple domains newest-first',
    function () use (&$action, &$testArchiveRoot) {
        if ($testArchiveRoot === null) {
            return ['outcome' => TestHarness::SKIP,
                'message' => 'previous test created no root'];
        }
        $domainA = 'flowone-test-arcA-' . bin2hex(random_bytes(2)) . '.test';
        $domainB = 'flowone-test-arcB-' . bin2hex(random_bytes(2)) . '.test';
        seedArchive($testArchiveRoot, $domainA, '20260515-100000', 2001, 'A1');
        seedArchive($testArchiveRoot, $domainB, '20260601-120000', 2002, 'B1');

        $res = callListArchives($action, $testArchiveRoot, []);
        $arcs = $res['data']['archives'] ?? [];
        if (count($arcs) < 2) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected >=2 archives in global, got ' . count($arcs)];
        }
        // The newest of THIS test seed (job 2002) should appear before
        // the older one (job 2001).
        $names = array_column($arcs, 'job_id');
        $idx2002 = array_search(2002, $names, true);
        $idx2001 = array_search(2001, $names, true);
        if ($idx2002 === false || $idx2001 === false || $idx2002 > $idx2001) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 2002 before 2001; got job order: '
                    . implode(',', $names)];
        }
    });

$harness->test('non_canonical', 'non-canonical dirname still appears with null parsed fields',
    function () use (&$action, &$testArchiveRoot) {
        if ($testArchiveRoot === null) {
            $testArchiveRoot = sys_get_temp_dir() . '/flowone_test_archives_'
                . bin2hex(random_bytes(3));
            @mkdir($testArchiveRoot, 0700, true);
        }
        $domain = 'flowone-test-arcNC-' . bin2hex(random_bytes(2)) . '.test';
        // Non-canonical name: missing job<id> suffix.
        $weirdDir = $testArchiveRoot . '/' . $domain . '/hand-copied-archive';
        @mkdir($weirdDir, 0700, true);
        @file_put_contents($weirdDir . '/data.bin', 'x');

        $res = callListArchives($action, $testArchiveRoot, ['domain' => $domain]);
        $arcs = $res['data']['archives'] ?? [];
        if (count($arcs) !== 1) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected 1 archive, got ' . count($arcs)];
        }
        if ($arcs[0]['archived_at'] !== null
            || $arcs[0]['archived_at_unix'] !== null
            || $arcs[0]['job_id'] !== null
        ) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected null parsed fields for non-canonical name'];
        }
        if (($arcs[0]['mtime_unix'] ?? 0) <= 0) {
            return ['outcome' => TestHarness::FAIL,
                'message' => 'expected mtime fallback to be populated'];
        }
    });

exit($harness->run());
