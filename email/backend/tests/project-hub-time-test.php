#!/usr/bin/env php
<?php
/**
 * project-hub-time-test.php — time/work-session symbol presence + time-budget reset wiring.
 *
 *   php project-hub-time-test.php [--verbose] [--json] [--smoke] [--only=symbols,sessions_summary,time_budget]
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../cron/bootstrap.php';
$opts = getopt('', ['help', 'verbose', 'json', 'smoke', 'only:']) ?: [];
if (isset($opts['help'])) {
    echo "project-hub-time-test.php [--verbose] [--json] [--smoke] [--only=symbols,sessions_summary,time_budget]\n";
    exit(0);
}
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;
$json = isset($opts['json']);
$verbose = isset($opts['verbose']);

function t_want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

require_once __DIR__ . '/lib/projecthub-fixtures.php';
$log = phf_log_path('project-hub-time-test');
$r = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'fail_msgs' => []];

function t_ok(array &$r): void
{
    $r['passed']++;
}

function t_fail(array &$r, string $m): void
{
    $r['failed']++;
    $r['fail_msgs'][] = $m;
}

$wt = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubWorkTrackingService.php') ?: '';
$tb = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubTimeBudgetService.php') ?: '';
$routes = file_get_contents(__DIR__ . '/../routes.php') ?: '';

if (t_want($only, 'symbols')) {
    if (strpos($wt, 'getTrafficData') !== false && strpos($wt, 'projecthub_work_sessions') !== false) {
        t_ok($r);
    } else {
        t_fail($r, 'time tracking symbols (getTrafficData / projecthub_work_sessions)');
    }
    foreach (['logWorkSession', 'getCardWorkSessions', 'getAssigneeTime', 'copyWorkSessions'] as $m) {
        if (preg_match('/public function ' . $m . '\b/', $wt)) {
            t_ok($r);
        } else {
            t_fail($r, 'WorkTrackingService missing ' . $m);
        }
    }
}

if (t_want($only, 'sessions_summary')) {
    if (preg_match('/public function getSessionsSummaryByBoard\b/', $wt)) {
        t_ok($r);
    } else {
        t_fail($r, 'WorkTrackingService::getSessionsSummaryByBoard missing');
    }
    if (strpos($routes, '/project-hub/work-sessions/summary') !== false || strpos($routes, '/work-sessions/summary') !== false) {
        t_ok($r);
    } else {
        t_fail($r, '/work-sessions/summary route missing');
    }
}

if (t_want($only, 'time_budget')) {
    if (preg_match('/public function resetAlert\b/', $tb)) {
        t_ok($r);
    } else {
        t_fail($r, 'ProjectHubTimeBudgetService::resetAlert missing');
    }
    // Reset wiring through controller / route
    $ctl = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Controllers/ProjectHubController.php') ?: '';
    if (strpos($ctl, 'resetAlert') !== false) {
        t_ok($r);
    } else {
        t_fail($r, 'ProjectHubController missing resetAlert wiring');
    }
}

if ($json) {
    echo json_encode(['results' => $r, 'log' => $log]) . "\n";
}
exit($r['failed'] ? 1 : 0);
