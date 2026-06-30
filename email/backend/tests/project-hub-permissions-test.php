#!/usr/bin/env php
<?php
/**
 * project-hub-permissions-test.php — route wiring + auth-gate sanity checks for permission-bearing endpoints.
 *
 *   php project-hub-permissions-test.php [--verbose] [--json] [--only=routes,delete_role,share_auth]
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../cron/bootstrap.php';
$opts = getopt('', ['help', 'verbose', 'json', 'only:']) ?: [];
if (isset($opts['help'])) {
    echo "project-hub-permissions-test.php [--verbose] [--json] [--only=routes,delete_role,share_auth]\n";
    exit(0);
}
require_once __DIR__ . '/lib/projecthub-fixtures.php';
$log = phf_log_path('project-hub-permissions-test');
$r = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'fail_msgs' => []];
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;

function p_want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

function p_ok(array &$r): void
{
    $r['passed']++;
}

function p_fail(array &$r, string $m): void
{
    $r['failed']++;
    $r['fail_msgs'][] = $m;
}

$routes = file_get_contents(__DIR__ . '/../routes.php') ?: '';

if (p_want($only, 'routes')) {
    if (preg_match('#/project-hub/shares/\{id\}#', $routes) && preg_match('#/project-hub/cards/\{id\}/shares#', $routes)) {
        p_ok($r);
    } else {
        p_fail($r, 'two-path-param share routes');
    }

    foreach ([
        '/project-hub/cards/{id}/shares',
        '/project-hub/shares/{id}',
        '/project-hub/share/{token}/info',
        '/project-hub/share/{token}/validate',
        '/project-hub/share/{token}/download/{fid}',
    ] as $needle) {
        if (strpos($routes, $needle) !== false) {
            p_ok($r);
        } else {
            p_fail($r, 'route missing: ' . $needle);
        }
    }
}

if (p_want($only, 'delete_role')) {
    // DELETE /users/{email}/roles/{roleId} (two-path-param) must be present and bound to DELETE verb.
    if (preg_match('#/project-hub/users/\{email\}/roles/\{roleId\}#', $routes)) {
        p_ok($r);
    } else {
        p_fail($r, 'DELETE /project-hub/users/{email}/roles/{roleId} route');
    }
    if (preg_match('#->delete\(\s*[\'"]\/project-hub/users/\{email\}/roles/\{roleId\}#', $routes)) {
        p_ok($r);
    } else {
        p_fail($r, 'DELETE verb binding for /project-hub/users/{email}/roles/{roleId}');
    }
}

if (p_want($only, 'share_auth')) {
    // Owner-only management routes live alongside the other authenticated /project-hub routes;
    // the public /share/{token}/* trio is registered separately (after $phSharePublic instantiation).
    // Verify both blocks exist, and that the public block uses a distinct controller instance.
    $ownerPos = strpos($routes, "'/project-hub/cards/{id}/shares'");
    $publicPos = strpos($routes, "'/project-hub/share/{token}/info'");
    $publicCtl = strpos($routes, '$phSharePublic = new \\Webmail\\Addons\\ProjectHub\\Controllers\\ProjectHubShareController');
    if ($ownerPos === false || $publicPos === false || $publicCtl === false) {
        p_fail($r, 'routes.php missing owner-only OR public share block (' . var_export([$ownerPos, $publicPos, $publicCtl], true) . ')');
    } else {
        p_ok($r);
    }

    $shareSvc = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubShareService.php') ?: '';
    if (strpos($shareSvc, 'function tryAuthorizeShareDownload') !== false) {
        p_ok($r);
    } else {
        p_fail($r, 'ProjectHubShareService::tryAuthorizeShareDownload missing');
    }

    // Public controller method names must match the route handlers.
    $shareCtl = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Controllers/ProjectHubShareController.php') ?: '';
    foreach (['publicShareInfo', 'publicShareValidate', 'publicShareDownload'] as $m) {
        if (preg_match('/public function ' . $m . '\b/', $shareCtl)) {
            p_ok($r);
        } else {
            p_fail($r, 'ProjectHubShareController missing ' . $m);
        }
    }
}

if (isset($opts['json'])) {
    echo json_encode(['results' => $r, 'log' => $log]) . "\n";
}
exit($r['failed'] ? 1 : 0);
