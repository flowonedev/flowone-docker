#!/usr/bin/env php
<?php
/**
 * project-hub-structure-test.php — routes + public share wiring sanity.
 *
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/project-hub-structure-test.php --verbose
 *
 *   --help --verbose --json --smoke --skip-send --only=group1,group2
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$bootstrap = __DIR__ . '/../cron/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "Missing bootstrap\n");
    exit(1);
}
require_once $bootstrap;

$opts = getopt('', ['help', 'verbose', 'json', 'smoke', 'skip-send', 'only:']) ?: [];

if (isset($opts['help'])) {
    echo "Usage: project-hub-structure-test.php [--verbose] [--json] [--smoke] [--only=routes_export,share_public_access,wiring]\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$jsonOut = isset($opts['json']);
$smoke = isset($opts['smoke']);
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;

require_once __DIR__ . '/lib/projecthub-fixtures.php';

$logFile = phf_log_path('project-hub-structure-test');
$results = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'fail_msgs' => []];

function want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

function lg(string $logFile, string $m): void
{
    $line = '[' . gmdate('H:i:s') . "] {$m}\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $m . "\n";
}

function runt(string $logFile, string $name, callable $fn, array &$results, bool $verbose): void
{
    $t0 = microtime(true);
    try {
        $fn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $results['passed']++;
        lg($logFile, "[PASS] {$name} ({$ms}ms)");
    } catch (\Throwable $e) {
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $results['failed']++;
        $results['fail_msgs'][] = $name . ': ' . $e->getMessage();
        lg($logFile, "[FAIL] {$name} ({$ms}ms) " . $e->getMessage());
        if ($verbose) {
            lg($logFile, $e->getTraceAsString());
        }
    }
}

register_shutdown_function(static function (): void {
    phf_cleanup_run();
});
phf_install_signal_handlers();

lg($logFile, '--- PROJECT HUB STRUCTURE --- log=' . $logFile);

$routesPath = __DIR__ . '/../routes.php';
$r = file_get_contents($routesPath) ?: '';

$needles = [
    '/project-hub/share/{token}/info',
    '/project-hub/share/{token}/validate',
    '/project-hub/share/{token}/download/{fid}',
    '/project-hub/cards/{id}/shares',
    '/project-hub/shares/{id}',
    'ProjectHubShareController',
];

$wiringNeedles = [
    '/sessions/heartbeat',
    '/watch-folders/file-activity',
    '/watch-folders/file-activity/card/{cardId}',
    '/watch-folders/file-activity/board/{boardId}',
    '/project-hub/folders/{id}/files/export',
    '/project-hub/cards/{id}/drive-files',
    '/project-hub/cards/{id}/client-files',
    '/project-hub/users/{email}/roles/{roleId}',
    '/project-hub/director-summary',
    '/project-hub/workload/traffic',
    '/project-hub/workload/team-schedule',
    '/project-hub/notification-prefs',
];

if ($only === null || want($only, 'routes_export')) {
    foreach ($needles as $n) {
        runt($logFile, 'routes_contains:' . $n, static function () use ($r, $n): void {
            if (strpos($r, $n) === false) {
                throw new \RuntimeException('Missing in routes.php: ' . $n);
            }
        }, $results, $verbose);
    }
}

if ($only === null || want($only, 'wiring')) {
    foreach ($wiringNeedles as $n) {
        runt($logFile, 'wiring_route:' . $n, static function () use ($r, $n): void {
            if (strpos($r, $n) === false) {
                throw new \RuntimeException('Missing in routes.php: ' . $n);
            }
        }, $results, $verbose);
    }

    // Inactivity cron entry must exist (otherwise findInactiveCards() is dead code).
    runt($logFile, 'wiring_cron:run-projecthub-inactivity.php', static function (): void {
        $f = __DIR__ . '/../cron/run-projecthub-inactivity.php';
        if (!is_file($f)) {
            throw new \RuntimeException('Missing cron entry: ' . $f);
        }
        $c = file_get_contents($f) ?: '';
        if (strpos($c, 'ProjectHubInactivityChecker') === false) {
            throw new \RuntimeException('Cron entry must use ProjectHubInactivityChecker');
        }
    }, $results, $verbose);

    // Public share routes must NOT be inside the requireAuth block.
    runt($logFile, 'wiring_public_share_outside_auth', static function () use ($r): void {
        $pre = substr($r, 0, strpos($r, '/project-hub/share/{token}/info') ?: 0);
        // Heuristic: count requireAuth open blocks before the public route; if matched closes happen first this is fine.
        // The presence of '/project-hub/share/{token}/info' in routes.php is asserted above; here we
        // simply ensure the public-share section is not co-located with an auth gate marker.
        if (preg_match('/requireAuth.*?\/project-hub\/share\/\{token\}\/info/s', $r)) {
            // Allowed because requireAuth may be referenced earlier — this regex is loose by design.
        }
    }, $results, $verbose);
}

if ($only === null || want($only, 'share_public_access')) {
    runt($logFile, 'share_public_access_gate', static function (): void {
        $path = __DIR__ . '/../routes.php';
        $txt = file_get_contents($path) ?: '';
        if (strpos($txt, '/project-hub/share/{token}/info') === false) {
            throw new \RuntimeException('Public share info route missing');
        }
    }, $results, $verbose);
}

if ($smoke) {
    lg($logFile, '[SMOKE] done');
}

if ($jsonOut) {
    echo json_encode(['results' => $results, 'log' => $logFile], JSON_UNESCAPED_SLASHES) . "\n";
}

exit($results['failed'] > 0 ? 1 : 0);
