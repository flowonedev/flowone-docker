#!/usr/bin/env php
<?php
/**
 * project-hub-views-test.php — workload/traffic shape, difficulty wiring, urgency-sort,
 * and shape_drift presence checks for the Vue components the frontend reads from.
 *
 *   php project-hub-views-test.php [--help] [--verbose] [--json] [--smoke] [--only=group,...]
 *
 * Run on server (project-info.mdc):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/project-hub-views-test.php --verbose
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../cron/bootstrap.php';
$opts = getopt('', ['help', 'verbose', 'json', 'smoke', 'skip-send', 'only:']) ?: [];
if (isset($opts['help'])) {
    echo "project-hub-views-test.php [--verbose] [--json] [--only=traffic_role,difficulty,urgency_sort,shape_drift]\n";
    exit(0);
}
$verbose = isset($opts['verbose']);
$jsonOut = isset($opts['json']);
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;

function v_want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

require_once __DIR__ . '/lib/projecthub-fixtures.php';
$log = phf_log_path('project-hub-views-test');
$r = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'fail_msgs' => []];

// On production deploys only `frontend/dist/` ships alongside the backend; skip Vue source checks gracefully.
$feRoot = __DIR__ . '/../../frontend/src/addons/project-hub/components/';
$frontendSrcAvailable = is_dir($feRoot);
if (!$frontendSrcAvailable && ($verbose ?? false)) {
    fwrite(STDERR, "views-test: frontend/src not present on this host — skipping Vue source checks\n");
}

function v_pass(array &$r): void
{
    $r['passed']++;
}

function v_fail(array &$r, string $m): void
{
    $r['failed']++;
    $r['fail_msgs'][] = $m;
}

if (v_want($only, 'traffic_role')) {
    $php = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubWorkTrackingService.php') ?: '';
    if (strpos($php, 'fetchRoleSlugsByEmails') !== false) {
        v_pass($r);
    } else {
        v_fail($r, 'fetchRoleSlugsByEmails helper missing in WorkTrackingService');
    }
    if (strpos($php, 'buildRoleSlugExistsFilter') !== false) {
        v_pass($r);
    } else {
        v_fail($r, 'buildRoleSlugExistsFilter helper expected in WorkTrackingService');
    }
    if ($frontendSrcAvailable) {
        $vue = file_get_contents($feRoot . 'TrafficTableView.vue') ?: '';
        if (strpos($vue, 'role_slug') !== false) {
            v_pass($r);
        } else {
            v_fail($r, 'TrafficTableView.vue missing role_slug wiring');
        }
        $dd = file_get_contents($feRoot . 'DirectorDashboard.vue') ?: '';
        if (strpos($dd, 'role_slug') !== false) {
            v_pass($r);
        } else {
            v_fail($r, 'DirectorDashboard.vue missing role_slug filter');
        }
    }
}

if (v_want($only, 'difficulty')) {
    $php = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubWorkTrackingService.php') ?: '';
    if (preg_match('/difficulty_weight\s*=\s*\?/', $php) && strpos($php, "array_key_exists('difficulty_weight', \$data)") !== false) {
        v_pass($r);
    } else {
        v_fail($r, 'updateAssignee should whitelist difficulty_weight');
    }
    if (preg_match('/\$w\s*<\s*1/', $php) && preg_match('/\$w\s*>\s*5/', $php)) {
        v_pass($r);
    } else {
        v_fail($r, 'difficulty_weight must clamp to 1..5');
    }
    if ($frontendSrcAvailable) {
        $panel = file_get_contents($feRoot . 'CardAssigneesPanel.vue') ?: '';
        if (strpos($panel, 'difficulty_weight') !== false) {
            v_pass($r);
        } else {
            v_fail($r, 'CardAssigneesPanel.vue should bind difficulty_weight');
        }
    }

    // multi_role_no_double_count: getDirectorSummary / getTrafficData must use the EXISTS subquery,
    // NOT a naïve LEFT JOIN projecthub_user_roles that multiplies SUM rows by role count.
    $multiplyingJoinPattern = '/(LEFT|INNER)\s+JOIN\s+projecthub_user_roles\b/i';
    if (preg_match($multiplyingJoinPattern, $php)) {
        v_fail($r, 'workload SQL uses LEFT/INNER JOIN projecthub_user_roles directly — aggregates will multiply');
    } else {
        v_pass($r);
    }

    // Both reporting methods must source role slugs via the normalised helper.
    $needsHelper = ['getDirectorSummary', 'getTrafficData'];
    foreach ($needsHelper as $method) {
        if (!preg_match('/function\s+' . $method . '\b.*?(?=\n\s*(?:public|private|protected|static)\s+function\b)/s', $php, $body)) {
            v_fail($r, $method . ' body not found');
            continue;
        }
        $segment = $body[0];
        if (strpos($segment, 'buildRoleSlugExistsFilter') !== false && strpos($segment, 'fetchRoleSlugsByEmails') !== false) {
            v_pass($r);
        } else {
            v_fail($r, $method . ' must use buildRoleSlugExistsFilter + fetchRoleSlugsByEmails');
        }
    }
}

if (v_want($only, 'urgency_sort')) {
    // Reflect duePriority WITHOUT calling the constructor (avoids PDO/MySQL deps on Windows CI).
    try {
        $ref = new ReflectionClass(\Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        $m = $ref->getMethod('duePriority');
        $m->setAccessible(true);
        $today = date('Y-m-d');
        $past = date('Y-m-d', strtotime('-2 days'));
        $future = date('Y-m-d', strtotime('+5 days'));
        $cases = [
            ['blocked', ['status' => 'blocked', 'due_date' => $future]],
            ['overdue', ['status' => null, 'due_date' => $past]],
            ['today', ['status' => null, 'due_date' => $today]],
            ['future', ['status' => null, 'due_date' => $future]],
            ['no_due', ['status' => null, 'due_date' => null]],
        ];
        $priorities = [];
        foreach ($cases as [$label, $card]) {
            $priorities[$label] = $m->invoke($svc, $card);
        }
        // Documented ladder (matches ProjectHubWorkTrackingService::duePriority):
        //   blocked=0 < overdue=1 < today=2 < future=3 < no_due=4
        $expected = ['blocked' => 0, 'overdue' => 1, 'today' => 2, 'future' => 3, 'no_due' => 4];
        if ($priorities !== $expected) {
            v_fail($r, 'duePriority buckets disagree with documented ladder: ' . json_encode($priorities));
        } else {
            v_pass($r);
        }

        // Replay the usort comparator on a fixture.
        $cards = [
            ['id' => 1, 'completed' => 1, 'status' => null, 'due_date' => date('Y-m-d', strtotime('-3 days'))],
            ['id' => 2, 'completed' => 0, 'status' => null, 'due_date' => null],
            ['id' => 3, 'completed' => 0, 'status' => 'blocked', 'due_date' => date('Y-m-d', strtotime('+5 days'))],
            ['id' => 4, 'completed' => 0, 'status' => null, 'due_date' => date('Y-m-d', strtotime('-1 day'))],
            ['id' => 5, 'completed' => 0, 'status' => null, 'due_date' => date('Y-m-d')],
        ];
        usort($cards, static function ($a, $b) use ($svc, $m) {
            $aComp = $a['completed'] ? 1 : 0;
            $bComp = $b['completed'] ? 1 : 0;
            if ($aComp !== $bComp) {
                return $aComp - $bComp;
            }
            $aPri = $m->invoke($svc, $a);
            $bPri = $m->invoke($svc, $b);
            if ($aPri !== $bPri) {
                return $aPri - $bPri;
            }
            $aDue = $a['due_date'] ?? '9999-12-31';
            $bDue = $b['due_date'] ?? '9999-12-31';

            return strcmp($aDue, $bDue);
        });
        $order = array_column($cards, 'id');
        // Expected: 3 (blocked future, prio 0), 4 (overdue, prio 1), 5 (today, prio 2), 2 (no_due, prio 4), 1 (completed last)
        $expectedOrder = [3, 4, 5, 2, 1];
        if ($order !== $expectedOrder) {
            v_fail($r, 'my_work_urgency_sort order mismatch: ' . implode(',', $order));
        } else {
            v_pass($r);
        }
    } catch (\Throwable $e) {
        v_fail($r, 'urgency_sort: ' . $e->getMessage());
        if ($verbose) {
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }
    }
}

if (v_want($only, 'shape_drift')) {
    if (!$frontendSrcAvailable) {
        // Frontend source not present (e.g., production-only deploy). Cannot perform Vue source contracts.
        if ($verbose) {
            fwrite(STDERR, "shape_drift: skipped (frontend/src not deployed)\n");
        }
        if ($jsonOut) {
            echo json_encode(['results' => $r, 'log' => $log], JSON_UNESCAPED_SLASHES) . "\n";
        }
        exit($r['failed'] ? 1 : 0);
    }
    $vueFiles = [
        'MemberWorkPanel.vue',
        'BreakdownTab.vue',
        'CardTimeBreakdown.vue',
        'TaskCalendarSync.vue',
        'WatcherFollowerPanel.vue',
        'CardAssigneesPanel.vue',
        'CardActivityTimeline.vue',
        'CardClientFiles.vue',
        'TrafficTableView.vue',
        'DirectorDashboard.vue',
        'EnhancedComments.vue',
    ];
    $missingVue = [];
    foreach ($vueFiles as $vf) {
        if (!is_file($feRoot . $vf)) {
            $missingVue[] = $vf;
        }
    }
    if ($missingVue !== []) {
        v_fail($r, 'missing vue: ' . implode(', ', $missingVue));
    } else {
        v_pass($r);
    }

    // Per-file additive contract: each Vue must contain the API path it consumes
    // (asserting backward-compatibility, not snapshot equality — see plan).
    $contracts = [
        'CardClientFiles.vue' => ['/project-hub/cards/', '/client-files'],
        'TrafficTableView.vue' => ['/project-hub/workload/traffic', 'role_slug'],
        'DirectorDashboard.vue' => ['/project-hub/director-summary', 'role_slug'],
        'CardAssigneesPanel.vue' => ['difficulty_weight', 'updateAssignee'],
        'EnhancedComments.vue' => ['@', 'mention'],
        'CardActivityTimeline.vue' => ['/project-hub/cards/', '/activity'],
        'TaskCalendarSync.vue' => ['/project-hub/cards/', '/calendar-sync'],
    ];
    foreach ($contracts as $file => $needles) {
        $c = @file_get_contents($feRoot . $file) ?: '';
        $miss = [];
        foreach ($needles as $needle) {
            if (strpos($c, $needle) === false) {
                $miss[] = $needle;
            }
        }
        if ($miss !== []) {
            v_fail($r, $file . ' missing tokens: ' . implode(',', $miss));
        } else {
            v_pass($r);
        }
    }
}

if ($jsonOut) {
    echo json_encode(['results' => $r, 'log' => $log], JSON_UNESCAPED_SLASHES) . "\n";
}
exit($r['failed'] ? 1 : 0);
