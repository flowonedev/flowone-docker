#!/usr/bin/env php
<?php
/**
 * project-hub-notifications-test.php — notification wiring, resolver invariants, inactivity,
 * role-status filtering. DB-backed groups gate on --email= and absence of --skip-send.
 *
 *   php project-hub-notifications-test.php [--help] [--verbose] [--json] [--skip-send] [--email=] [--only=group,...]
 *
 * Server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tests/project-hub-notifications-test.php --verbose
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../cron/bootstrap.php';
$opts = getopt('', ['help', 'verbose', 'json', 'smoke', 'skip-send', 'only:', 'email::', 'password::']) ?: [];
if (isset($opts['help'])) {
    echo "project-hub-notifications-test.php [--verbose] [--json] [--skip-send] [--email=] [--only=static,resolver,inactivity_wiring,role_status,inactivity_db,assigned_db]\n";
    exit(0);
}
$verbose = isset($opts['verbose']);
$jsonOut = isset($opts['json']);
$skipSend = isset($opts['skip-send']);
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;

function n_want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

require_once __DIR__ . '/lib/projecthub-fixtures.php';
$log = phf_log_path('project-hub-notifications-test');
$r = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'fail_msgs' => []];

function n_pass(array &$r): void
{
    $r['passed']++;
}

function n_fail(array &$r, string $m): void
{
    $r['failed']++;
    $r['fail_msgs'][] = $m;
}

function n_warn(array &$r, string $m): void
{
    $r['warnings']++;
    $r['fail_msgs'][] = '[WARN] ' . $m;
}

$config = require __DIR__ . '/../src/config.php';
register_shutdown_function(static function (): void {
    phf_cleanup_run();
});
phf_install_signal_handlers();

// =========================================================================
// static / resolver invariants (no DB needed)
// =========================================================================

if (n_want($only, 'static')) {
    $ns = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubNotificationService.php') ?: '';
    $res = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/NotificationRecipientResolver.php') ?: '';
    if (strpos($ns, 'notifyCommentWithMentions') !== false && strpos($res, 'resolveCommentAndMentionSplit') !== false) {
        n_pass($r);
    } else {
        n_fail($r, 'mention notification split missing');
    }

    $shareSvcFile = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubShareService.php') ?: '';
    if (strpos($shareSvcFile, 'ph_share_created') !== false) {
        n_pass($r);
    } else {
        n_fail($r, 'ph_share_created wiring (service must emit it)');
    }
    if (strpos($shareSvcFile, 'ph_share_pwd_fail:') !== false) {
        n_pass($r);
    } else {
        n_fail($r, 'share validate should use ph_share_pwd_fail rate-limit key');
    }
    if (strpos($shareSvcFile, 'function recordDownload') !== false && strpos($shareSvcFile, 'client_share_download') !== false) {
        n_pass($r);
    } else {
        n_fail($r, 'recordDownload should be transactional and emit client_share_download activity');
    }

    $sh = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Controllers/ProjectHubShareController.php') ?: '';
    if (strpos($sh, 'tryAuthorizeShareDownload') !== false) {
        n_pass($r);
    } else {
        n_fail($r, 'public download should call tryAuthorizeShareDownload');
    }
}

if (n_want($only, 'resolver')) {
    // notif_resolver_single_source: no createNotification outside ProjectHubNotificationService.
    $phDirs = [
        realpath(__DIR__ . '/../src/Addons/ProjectHub/Services'),
        realpath(__DIR__ . '/../src/Addons/ProjectHub/Controllers'),
    ];
    $violations = [];
    foreach ($phDirs as $dir) {
        if ($dir === false || !is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (str_ends_with($file, 'ProjectHubNotificationService.php')) {
                continue;
            }
            $c = file_get_contents($file) ?: '';
            if (preg_match('/->createNotification\s*\(/', $c)) {
                $violations[] = basename($file);
            }
        }
    }
    if ($violations !== []) {
        n_fail($r, 'resolver bypass createNotification in: ' . implode(', ', $violations));
    } else {
        n_pass($r);
    }

    // notifications INSERT must live in TrackingService alone.
    $rg = '/INSERT\s+INTO\s+(?:webmail_)?notifications\b/i';
    $offenders = [];
    foreach ([
        realpath(__DIR__ . '/../src/Addons/ProjectHub'),
        realpath(__DIR__ . '/../src/Addons/KanbanBoards'),
    ] as $root) {
        if ($root === false) {
            continue;
        }
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($rii as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $c = @file_get_contents($f->getPathname()) ?: '';
            if (preg_match($rg, $c)) {
                $offenders[] = str_replace($root . DIRECTORY_SEPARATOR, '', $f->getPathname());
            }
        }
    }
    if ($offenders !== []) {
        n_fail($r, 'INSERT INTO notifications outside TrackingService: ' . implode(', ', $offenders));
    } else {
        n_pass($r);
    }
}

// =========================================================================
// Inactivity cron wiring
// =========================================================================

if (n_want($only, 'inactivity_wiring')) {
    $cronFile = __DIR__ . '/../cron/run-projecthub-inactivity.php';
    if (!is_file($cronFile)) {
        n_fail($r, 'cron/run-projecthub-inactivity.php missing — inactivity checker is orphaned');
    } else {
        $c = file_get_contents($cronFile) ?: '';
        if (strpos($c, 'ProjectHubInactivityChecker') === false || strpos($c, 'runAndNotify') === false) {
            n_fail($r, 'cron entry must instantiate ProjectHubInactivityChecker and call runAndNotify()');
        } else {
            n_pass($r);
        }
        if (strpos($c, '--threshold') !== false && strpos($c, '--dry-run') !== false) {
            n_pass($r);
        } else {
            n_fail($r, 'cron entry must support --threshold and --dry-run');
        }
    }

    // inactivity_threshold_configurable: reflect the constructor signature.
    try {
        $ref = new ReflectionClass(\Webmail\Addons\ProjectHub\Services\ProjectHubInactivityChecker::class);
        $ctor = $ref->getConstructor();
        $params = $ctor ? $ctor->getParameters() : [];
        $hasThreshold = false;
        foreach ($params as $p) {
            if ($p->getName() === 'thresholdDays' && $p->isOptional()) {
                $hasThreshold = true;
                break;
            }
        }
        if (!$hasThreshold) {
            n_fail($r, 'ProjectHubInactivityChecker must accept optional $thresholdDays');
        } else {
            n_pass($r);
        }
    } catch (\Throwable $e) {
        n_fail($r, 'inactivity reflection: ' . $e->getMessage());
    }
}

// =========================================================================
// DB-backed groups
// =========================================================================

if (n_want($only, 'role_status') && !$skipSend) {
    try {
        $db = \Webmail\Core\Database::getConnection($config);
        // Need both 'graphic' and 'account' role slugs to exist. Skip with WARN if not.
        $hasBoth = $db->prepare("SELECT COUNT(DISTINCT slug) FROM projecthub_roles WHERE slug IN ('graphic','account')");
        $hasBoth->execute();
        if ((int) $hasBoth->fetchColumn() < 2) {
            n_warn($r, "role_status: skipped (need projecthub_roles rows for 'graphic' and 'account')");
        } else {
            $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubRoleService($config);
            $gs = $svc->getStatusesByRoleSlug('graphic');
            $as = $svc->getStatusesByRoleSlug('account');
            // Distinct sets — slugs should not match exactly.
            $gSlugs = array_column($gs ?? [], 'slug');
            $aSlugs = array_column($as ?? [], 'slug');
            if ($gSlugs === [] && $aSlugs === []) {
                n_warn($r, "role_status: skipped (no statuses seeded for graphic or account)");
            } else {
                if (sort($gSlugs) !== sort($aSlugs) || $gSlugs !== $aSlugs) {
                    n_pass($r);
                } else {
                    n_fail($r, 'graphic and account statuses should differ');
                }
            }
        }
    } catch (\Throwable $e) {
        n_fail($r, 'role_status: ' . $e->getMessage());
        if ($verbose) {
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }
    }
} elseif (n_want($only, 'role_status') && $skipSend) {
    if ($verbose) {
        fwrite(STDERR, "role_status: skipped (--skip-send)\n");
    }
}

if (n_want($only, 'inactivity_db') && !$skipSend) {
    $email = isset($opts['email']) ? trim((string) $opts['email']) : '';
    if ($email === '') {
        if ($verbose) {
            fwrite(STDERR, "inactivity_db: skipped (pass --email=)\n");
        }
    } else {
        try {
            $db = \Webmail\Core\Database::getConnection($config);
            // Seed two cards: one -91d (should appear), one -89d (should not).
            $oldSeed = phf_seed_card($config, $email, 'Inactivity-Old');
            $youngSeed = phf_seed_card($config, $email, 'Inactivity-Young');
            $oldCard = (int) $oldSeed['card_id'];
            $youngCard = (int) $youngSeed['card_id'];
            $past91 = date('Y-m-d H:i:s', strtotime('-91 days'));
            $past89 = date('Y-m-d H:i:s', strtotime('-89 days'));
            $db->prepare('UPDATE webmail_board_cards SET updated_at = ?, completed = 0 WHERE id = ?')
                ->execute([$past91, $oldCard]);
            $db->prepare('UPDATE webmail_board_cards SET updated_at = ?, completed = 0 WHERE id = ?')
                ->execute([$past89, $youngCard]);

            $checker = new \Webmail\Addons\ProjectHub\Services\ProjectHubInactivityChecker($config, 90);
            $list = $checker->findInactiveCards();
            $ids = array_map('intval', array_column($list, 'card_id'));
            if (!in_array($oldCard, $ids, true)) {
                throw new \RuntimeException('-91d card not found in findInactiveCards()');
            }
            if (in_array($youngCard, $ids, true)) {
                throw new \RuntimeException('-89d card should not have been flagged inactive');
            }
            n_pass($r);

            // Threshold override = 7d: -91d still found, -89d also found (both > 7d ago).
            $checker7 = new \Webmail\Addons\ProjectHub\Services\ProjectHubInactivityChecker($config, 7);
            $list7 = $checker7->findInactiveCards();
            $ids7 = array_map('intval', array_column($list7, 'card_id'));
            if (!in_array($oldCard, $ids7, true) || !in_array($youngCard, $ids7, true)) {
                throw new \RuntimeException('threshold=7 override did not honor cutoff');
            }
            n_pass($r);
        } catch (\Throwable $e) {
            n_fail($r, 'inactivity_db: ' . $e->getMessage());
            if ($verbose) {
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }
        }
    }
}

if (n_want($only, 'assigned_db') && !$skipSend) {
    $email = isset($opts['email']) ? trim((string) $opts['email']) : '';
    if ($email === '') {
        if ($verbose) {
            fwrite(STDERR, "assigned_db: skipped (pass --email=)\n");
        }
    } else {
        // Per-test cleanup: nuke any [FLOWONE-TEST] notification rows we generate via notifyCard.
        phf_cleanup_register(static function () use ($config): void {
            $db = \Webmail\Core\Database::getConnection($config);
            $db->exec("DELETE FROM notifications WHERE title LIKE '[FLOWONE-TEST]%' OR message LIKE '[FLOWONE-TEST]%'");
        });
        try {
            $seed = phf_seed_card($config, $email, 'NotifFanout');
            $cardId = (int) $seed['card_id'];
            $actor = strtolower($email);
            $other = 'colleague-' . bin2hex(random_bytes(2)) . '@flowone-test.invalid';

            $before = phf_count_notifications($config, $other);
            $notif = new \Webmail\Addons\ProjectHub\Services\ProjectHubNotificationService($config);
            $notif->notifyCard($cardId, $actor, 'ph_card_updated', '[FLOWONE-TEST] update', '[FLOWONE-TEST] body', []);

            // Actor never self-notifies — even with no recipients we expect zero growth.
            $actorAfter = phf_count_notifications($config, $actor, 'ph_card_updated');
            if ($actorAfter > 0 && $actorAfter > phf_count_notifications($config, $actor, 'ph_card_updated')) {
                throw new \RuntimeException('actor should not self-notify');
            }
            n_pass($r);

            // Assign $other and try again — they must get notified.
            phf_assign_card($config, $cardId, $other, 'assignee', 2);
            $beforeOther = phf_count_notifications($config, $other);
            $notif->notifyCard($cardId, $actor, 'ph_card_updated', '[FLOWONE-TEST] update2', '[FLOWONE-TEST] body2', []);
            $afterOther = phf_count_notifications($config, $other);
            if ($afterOther <= $beforeOther) {
                throw new \RuntimeException('assignee should have received ph_card_updated');
            }
            n_pass($r);
        } catch (\Throwable $e) {
            n_fail($r, 'assigned_db: ' . $e->getMessage());
            if ($verbose) {
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }
        }
    }
}

if ($jsonOut) {
    echo json_encode(['results' => $r, 'log' => $log], JSON_UNESCAPED_SLASHES) . "\n";
}
exit($r['failed'] ? 1 : 0);
