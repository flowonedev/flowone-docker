#!/usr/bin/env php
<?php
/**
 * project-hub-cards-test.php — parser, token entropy, assignee field, share/calendar static checks,
 * optional DB groups when --email= and NOT --skip-send.
 *
 *   php project-hub-cards-test.php [--help] [--verbose] [--json] [--smoke] [--skip-send] [--only=group,...] [--email=] [--password=]
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../cron/bootstrap.php';
$opts = getopt('', ['help', 'verbose', 'json', 'smoke', 'skip-send', 'only:', 'email::', 'password::']) ?: [];
if (isset($opts['help'])) {
    echo "project-hub-cards-test.php [--verbose] [--json] [--smoke] [--skip-send] [--only=mentions,tokens,share_static,calendar,worktracking,proxy_routes,activity_pagination,share_db,share_download_db,calendar_db,mention_db]\n";
    echo "  *_db groups require --email= and omit --skip-send (uses [FLOWONE-TEST] cleanup).\n";
    exit(0);
}
$verbose = isset($opts['verbose']);
$jsonOut = isset($opts['json']);
$skipSend = isset($opts['skip-send']);
$only = !empty($opts['only']) ? array_map('trim', explode(',', (string) $opts['only'])) : null;

function want(?array $only, string $g): bool
{
    return $only === null || in_array($g, $only, true);
}

require_once __DIR__ . '/lib/projecthub-fixtures.php';
$logFile = phf_log_path('project-hub-cards-test');
$results = ['passed' => 0, 'failed' => 0, 'warnings' => 0, 'fail_msgs' => []];

function _ok(array &$r): void
{
    $r['passed']++;
}

function _fail(array &$r, string $m): void
{
    $r['failed']++;
    $r['fail_msgs'][] = $m;
}

function _warn(array &$r, string $m): void
{
    $r['warnings']++;
    $r['fail_msgs'][] = '[WARN] ' . $m;
}

$config = require __DIR__ . '/../src/config.php';

register_shutdown_function(static function (): void {
    phf_cleanup_run();
});
phf_install_signal_handlers();

if (want($only, 'mentions')) {
    try {
        $m = \Webmail\Addons\ProjectHub\Services\CardCommentMentionParser::mergeMentions(
            'Hello @a@example.com and <b>@b@example.org</b>',
            [['email' => 'c@example.net', 'name' => 'See']]
        );
        if (count($m) < 3) {
            _fail($results, 'mergeMentions expected >=3 unique');
        } else {
            _ok($results);
        }
    } catch (\Throwable $e) {
        _fail($results, $e->getMessage());
    }

    try {
        $m = \Webmail\Addons\ProjectHub\Services\CardCommentMentionParser::mergeMentions('x', [['email' => 'z@z.com', 'name' => 'Zed']]);
        $row = $m[0] ?? null;
        if (!$row || !array_key_exists('email', $row) || !array_key_exists('name', $row)) {
            _fail($results, 'structured_payload shape');
        } else {
            _ok($results);
        }
    } catch (\Throwable $e) {
        _fail($results, $e->getMessage());
    }
}

if (want($only, 'tokens')) {
    try {
        $t1 = \Webmail\Addons\ProjectHub\Services\ProjectHubShareService::generateToken();
        $t2 = \Webmail\Addons\ProjectHub\Services\ProjectHubShareService::generateToken();
        if (strlen($t1) !== 32 || !ctype_xdigit($t1) || $t1 === $t2) {
            _fail($results, 'token entropy');
        } else {
            _ok($results);
        }
    } catch (\Throwable $e) {
        _fail($results, $e->getMessage());
    }

    $seen = [];
    $hexDigits = array_merge(
        array_map('strval', range(0, 9)),
        range('a', 'f')
    );
    $counts = array_fill_keys($hexDigits, 0);
    for ($i = 0; $i < 1000; $i++) {
        $t = \Webmail\Addons\ProjectHub\Services\ProjectHubShareService::generateToken();
        $seen[$t] = true;
        for ($j = 0; $j < 32; $j++) {
            $c = $t[$j];
            if (!array_key_exists($c, $counts)) {
                _fail($results, 'token_entropy invalid char');
                break 2;
            }
            $counts[$c]++;
        }
    }
    if (count($seen) !== 1000) {
        _fail($results, 'token_entropy duplicate in 1000');
    } else {
        _ok($results);
    }
    $expected = (1000 * 32) / 16;
    $chi = 0.0;
    foreach ($counts as $obs) {
        $chi += ($obs - $expected) ** 2 / $expected;
    }
    if ($chi > 45.0) {
        _fail($results, 'token_entropy chi-square too skewed: ' . round($chi, 2));
    } else {
        _ok($results);
    }
}

if (want($only, 'share_static')) {
    $shareSvc = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubShareService.php') ?: '';
    if (strpos($shareSvc, 'tryAuthorizeShareDownload') === false || strpos($shareSvc, 'isDriveFileInShare') === false) {
        _fail($results, 'ShareService missing tryAuthorizeShareDownload / isDriveFileInShare');
    } else {
        _ok($results);
    }
    if (strpos($shareSvc, 'client_share_created') === false || strpos($shareSvc, 'ProjectHubActivityService') === false) {
        _fail($results, 'createCardShare should log client_share_created inside transaction');
    } else {
        _ok($results);
    }
    if (strpos($shareSvc, 'notifyCardAudience') === false || strpos($shareSvc, 'ph_share_created') === false) {
        _fail($results, 'createCardShare should notify watchers (ph_share_created)');
    } else {
        _ok($results);
    }
    // Failed password attempts: rate limit key only after verify failure (not on success path)
    $posVerify = strpos($shareSvc, 'password_verify($password');
    $posRl = strpos($shareSvc, 'ph_share_pwd_fail:');
    if ($posVerify === false || $posRl === false || $posRl < $posVerify) {
        _fail($results, 'validatePasswordWithRateLimit should apply IP cap after failed password_verify');
    } else {
        _ok($results);
    }
    if (strpos($shareSvc, 'incrementDownloadCounters') === false || strpos($shareSvc, 'beginTransaction') === false) {
        _fail($results, 'recordDownload / incrementDownloadCounters transaction');
    } else {
        _ok($results);
    }
}

if (want($only, 'calendar')) {
    $cal = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubCalendarBridge.php') ?: '';
    if (strpos($cal, 'REVERSE_SYNC_FIELDS') === false || strpos($cal, 'foreach (self::REVERSE_SYNC_FIELDS') === false) {
        _fail($results, 'CalendarBridge missing REVERSE_SYNC_FIELDS whitelist loop');
    } else {
        _ok($results);
    }
    try {
        $ref = new ReflectionClass(\Webmail\Addons\ProjectHub\Services\ProjectHubCalendarBridge::class);
        $c = $ref->getConstant('REVERSE_SYNC_FIELDS');
        if ($c !== ['start_date', 'due_date']) {
            _fail($results, 'REVERSE_SYNC_FIELDS must be start_date,due_date only');
        } else {
            _ok($results);
        }
    } catch (\Throwable $e) {
        _fail($results, $e->getMessage());
    }

    // whitelist_enforcement: scan the onCalendarEventUpdated body — no `title`, `description`,
    // `labels`, `assignees`, `status` may be persisted from the inbound event payload.
    if (preg_match('/function\s+onCalendarEventUpdated\b.*?(?=\n\s*(?:public|private|protected|static)\s+function\b)/s', $cal, $body)) {
        $segment = $body[0];
        $forbidden = ['title', 'description', 'labels', 'assignees', 'status'];
        $leak = [];
        foreach ($forbidden as $field) {
            // Forbidden when used as an event-data key or as a column being written.
            if (preg_match('/\$eventData\[[\'"]' . preg_quote($field, '/') . '[\'"]\s*\]/', $segment)
                || preg_match('/\$updates\[[\'"]' . preg_quote($field, '/') . '[\'"]\s*\]/', $segment)) {
                $leak[] = $field;
            }
        }
        if ($leak !== []) {
            _fail($results, 'onCalendarEventUpdated reads/writes forbidden field(s): ' . implode(',', $leak));
        } else {
            _ok($results);
        }
        // The final UPDATE must build its column list from $sets (derived from the filtered $updates loop).
        if (preg_match('/UPDATE\s+webmail_board_cards\s+SET\s*"\s*\.\s*implode\(\s*[\'"], [\'"]\s*,\s*\$sets/i', $segment)
            || strpos($segment, "implode(', ', \$sets)") !== false) {
            _ok($results);
        } else {
            _fail($results, 'onCalendarEventUpdated must build UPDATE column list from filtered $updates');
        }
    } else {
        _fail($results, 'onCalendarEventUpdated body not found');
    }
}

if (want($only, 'worktracking')) {
    $wt = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubWorkTrackingService.php') ?: '';
    if (strpos($wt, 'difficulty_weight') === false || strpos($wt, 'buildRoleSlugExistsFilter') === false) {
        _fail($results, 'WorkTrackingService missing difficulty or role filter');
    } else {
        _ok($results);
    }
}

if (want($only, 'proxy_routes')) {
    $ctl = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Controllers/ProjectHubController.php') ?: '';
    foreach (['proxyUpdateCard', 'proxyAddComment', 'promoteFromSubtask'] as $method) {
        if (preg_match('/public function ' . $method . '\b/', $ctl)) {
            _ok($results);
        } else {
            _fail($results, 'controller missing ' . $method);
        }
    }
    $wt = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubWorkTrackingService.php') ?: '';
    if (preg_match('/public function copyWorkSessions\b/', $wt)) {
        _ok($results);
    } else {
        _fail($results, 'WorkTrackingService missing copyWorkSessions');
    }
    // start_date + due_date must round-trip through BoardService::updateCard whitelist (CU-feature parity).
    $bs = file_get_contents(__DIR__ . '/../src/Addons/KanbanBoards/Services/BoardService.php') ?: '';
    if (strpos($bs, "'start_date'") !== false && strpos($bs, "'due_date'") !== false) {
        _ok($results);
    } else {
        _fail($results, 'BoardService::updateCard must whitelist start_date + due_date');
    }
}

if (want($only, 'activity_pagination')) {
    $act = file_get_contents(__DIR__ . '/../src/Addons/ProjectHub/Services/ProjectHubActivityService.php') ?: '';
    foreach (['getCardTimeline', 'getCardTimelineCount'] as $method) {
        if (preg_match('/public function ' . $method . '\b/', $act)) {
            _ok($results);
        } else {
            _fail($results, 'ActivityService missing ' . $method);
        }
    }
    // getCardTimeline must accept limit + offset, getCardTimelineCount must not.
    if (preg_match('/function\s+getCardTimeline\s*\(\s*int\s+\$cardId\s*,\s*int\s+\$limit/', $act)) {
        _ok($results);
    } else {
        _fail($results, 'getCardTimeline signature must include $limit (and offset)');
    }
}

if (want($only, 'share_db') && !$skipSend) {
    $email = isset($opts['email']) ? trim((string) $opts['email']) : '';
    if ($email === '') {
        if ($verbose) {
            fwrite(STDERR, "share_db: skipped (pass --email= and omit --skip-send)\n");
        }
    } else {
        try {
            $rl = new \Webmail\Services\RateLimiter($config);
            $db = \Webmail\Core\Database::getConnection($config);
            $stCard = $db->prepare('
                SELECT c.id FROM webmail_board_cards c
                JOIN webmail_board_lists l ON l.id = c.list_id
                JOIN webmail_boards b ON b.id = l.board_id
                WHERE LOWER(b.owner_email) = ? LIMIT 1
            ');
            $stCard->execute([strtolower($email)]);
            $cardId = (int) $stCard->fetchColumn();
            if ($cardId <= 0) {
                throw new \RuntimeException('No board card found for email (need at least one card)');
            }

            $sameIp = '203.0.113.55';
            $tokenA = \Webmail\Addons\ProjectHub\Services\ProjectHubShareService::generateToken();
            $hashA = password_hash('secretpw', PASSWORD_DEFAULT);
            $db->prepare('
                INSERT INTO projecthub_card_shares
                  (card_id, share_token, created_by, title, message, expires_at, max_downloads, password_hash, failed_password_attempts, locked_until)
                VALUES (?, ?, ?, NULL, NULL, NULL, NULL, ?, 0, NULL)
            ')->execute([$cardId, $tokenA, strtolower($email), $hashA]);
            $shareA = (int) $db->lastInsertId();
            phf_cleanup_register(static function () use ($db, $shareA): void {
                $db->prepare('DELETE FROM projecthub_card_shares WHERE id = ?')->execute([$shareA]);
            });

            $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubShareService($config);
            if ($rl->isAvailable()) {
                for ($i = 1; $i <= 5; $i++) {
                    $r = $svc->validatePasswordWithRateLimit($tokenA, 'wrong', $sameIp, $rl);
                    if ($r['http'] !== 403) {
                        throw new \RuntimeException('Expected 403 on wrong password attempt ' . $i . ', got ' . $r['http']);
                    }
                }
                $r6 = $svc->validatePasswordWithRateLimit($tokenA, 'wrong', $sameIp, $rl);
                if ($r6['http'] !== 429) {
                    throw new \RuntimeException('Expected 429 on 6th failed attempt same IP/token window, got ' . $r6['http']);
                }
                $rOk = $svc->validatePasswordWithRateLimit($tokenA, 'secretpw', $sameIp, $rl);
                if (!$rOk['ok'] || $rOk['http'] !== 200) {
                    throw new \RuntimeException('Correct password should succeed even after IP rate-limit on failures');
                }
                _ok($results);
            } else {
                _warn($results, 'Redis unavailable: skip IP rate-limit assertion');
            }

            $tokenB = \Webmail\Addons\ProjectHub\Services\ProjectHubShareService::generateToken();
            $hashB = password_hash('otherpw', PASSWORD_DEFAULT);
            $db->prepare('
                INSERT INTO projecthub_card_shares
                  (card_id, share_token, created_by, title, message, expires_at, max_downloads, password_hash, failed_password_attempts, locked_until)
                VALUES (?, ?, ?, NULL, NULL, NULL, NULL, ?, 0, NULL)
            ')->execute([$cardId, $tokenB, strtolower($email), $hashB]);
            $shareB = (int) $db->lastInsertId();
            phf_cleanup_register(static function () use ($db, $shareB): void {
                $db->prepare('DELETE FROM projecthub_card_shares WHERE id = ?')->execute([$shareB]);
            });

            $db->prepare('UPDATE projecthub_card_shares SET failed_password_attempts = 19, locked_until = NULL WHERE id = ?')->execute([$shareB]);
            $almost = $svc->validatePasswordWithRateLimit($tokenB, 'bad', '198.51.100.200', $rl);
            if ($almost['http'] !== 403) {
                throw new \RuntimeException('20th cumulative fail should return 403, got ' . $almost['http']);
            }
            $row = $db->prepare('SELECT failed_password_attempts, locked_until FROM projecthub_card_shares WHERE id = ?');
            $row->execute([$shareB]);
            $row = $row->fetch(\PDO::FETCH_ASSOC);
            if ((int) ($row['failed_password_attempts'] ?? 0) < 20) {
                throw new \RuntimeException('failed_password_attempts should reach 20');
            }
            if (empty($row['locked_until'])) {
                throw new \RuntimeException('locked_until should be set after 20 failures');
            }
            $locked = $svc->validatePasswordWithRateLimit($tokenB, 'otherpw', '198.51.100.50', $rl);
            if ($locked['http'] !== 423) {
                throw new \RuntimeException('Locked share should return 423, got ' . $locked['http']);
            }
            _ok($results);
        } catch (\Throwable $e) {
            _fail($results, 'share_db: ' . $e->getMessage());
            if ($verbose) {
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }
        }
    }
} elseif (want($only, 'share_db') && $skipSend) {
    if ($verbose) {
        fwrite(STDERR, "share_db: skipped (--skip-send)\n");
    }
}

if (want($only, 'share_download_db') && !$skipSend) {
    $email = isset($opts['email']) ? trim((string) $opts['email']) : '';
    if ($email === '') {
        if ($verbose) {
            fwrite(STDERR, "share_download_db: skipped (pass --email=)\n");
        }
    } else {
        phf_cleanup_register(static function () use ($config): void {
            $db = \Webmail\Core\Database::getConnection($config);
            $db->exec("DELETE FROM notifications WHERE title LIKE '[FLOWONE-TEST]%' OR message LIKE '[FLOWONE-TEST]%'");
        });
        try {
            $db = \Webmail\Core\Database::getConnection($config);
            $seed = phf_seed_card($config, $email, 'ShareDownload');
            $cardId = (int) $seed['card_id'];

            // Seed two drive files tagged for the card, then a third NOT tagged (enumeration target).
            $fA = phf_seed_drive_file($config, $email, $cardId);
            $fB = phf_seed_drive_file($config, $email, $cardId);
            $fOther = phf_seed_drive_file($config, $email, null);

            $svc = new \Webmail\Addons\ProjectHub\Services\ProjectHubShareService($config);
            $created = $svc->createCardShare($cardId, $email, [$fA, $fB], [
                'title' => '[FLOWONE-TEST] Deliverables',
                'max_downloads' => 3,
            ]);
            $shareId = (int) ($created['id'] ?? 0);
            if ($shareId <= 0) {
                throw new \RuntimeException('share creation returned no id');
            }
            phf_cleanup_register(static function () use ($config, $shareId): void {
                $db = \Webmail\Core\Database::getConnection($config);
                $db->prepare('DELETE FROM projecthub_card_shares WHERE id = ?')->execute([$shareId]);
            });

            // enumeration_guard: download via service authorize for a file NOT in the share -> 403.
            $token = (string) $created['share_token'];
            $drive = new \Webmail\Services\DriveService($config);
            $authEnum = $svc->tryAuthorizeShareDownload($token, $fOther, null, $drive);
            if (!isset($authEnum['ok']) || $authEnum['ok'] !== false || (int) $authEnum['http'] !== 403 || ($authEnum['error'] ?? '') !== 'file_not_in_share') {
                throw new \RuntimeException('enumeration_guard expected 403/file_not_in_share, got ' . json_encode($authEnum));
            }
            _ok($results);

            // happy path: authorize fA then record one download — counters move atomically.
            $authOk = $svc->tryAuthorizeShareDownload($token, $fA, null, $drive);
            if (!$authOk['ok']) {
                throw new \RuntimeException('expected authorized download, got ' . json_encode($authOk));
            }
            $counters = $svc->recordDownload($shareId, $fA);
            if ($counters['share_download_count'] !== 1 || $counters['file_download_count'] !== 1) {
                throw new \RuntimeException('counters after first download: ' . json_encode($counters));
            }
            _ok($results);

            // transaction_safety: force recordDownload to fail mid-flow by handing an unknown drive file id.
            $before = $db->query("SELECT download_count FROM projecthub_card_shares WHERE id = " . (int) $shareId)->fetchColumn();
            try {
                $svc->recordDownload($shareId, 999999999);
                throw new \RuntimeException('recordDownload should have thrown for unknown file id');
            } catch (\RuntimeException $expected) {
                // expected
            }
            $after = $db->query("SELECT download_count FROM projecthub_card_shares WHERE id = " . (int) $shareId)->fetchColumn();
            if ((int) $after !== (int) $before) {
                throw new \RuntimeException('share download_count must not change on failed recordDownload (rollback): before=' . $before . ' after=' . $after);
            }
            _ok($results);

            // removed_drive_file: delete fB, share /info still resolves; tryAuthorize returns file_unavailable.
            $db->prepare('DELETE FROM drive_files WHERE id = ?')->execute([$fB]);
            $payload = $svc->getPublicSharePayload($token, false);
            if (!$payload || empty($payload['files'])) {
                throw new \RuntimeException('public payload missing after removed drive file');
            }
            $marked = false;
            foreach ($payload['files'] as $f) {
                if ((int) $f['drive_file_id'] === (int) $fB && !empty($f['unavailable'])) {
                    $marked = true;
                    break;
                }
            }
            if (!$marked) {
                throw new \RuntimeException('removed drive file must be returned with unavailable=true');
            }
            $authMissing = $svc->tryAuthorizeShareDownload($token, $fB, null, $drive);
            if ($authMissing['ok'] !== false || (int) $authMissing['http'] !== 410 || ($authMissing['error'] ?? '') !== 'file_unavailable') {
                throw new \RuntimeException('removed file download should return 410 file_unavailable: ' . json_encode($authMissing));
            }
            _ok($results);

            // revoke then assert 410 on next authorize.
            $svc->revokeShare($shareId, $email);
            $authRevoked = $svc->tryAuthorizeShareDownload($token, $fA, null, $drive);
            if ($authRevoked['ok'] !== false || (int) $authRevoked['http'] !== 410) {
                throw new \RuntimeException('revoked share should return 410, got ' . json_encode($authRevoked));
            }
            _ok($results);
        } catch (\Throwable $e) {
            _fail($results, 'share_download_db: ' . $e->getMessage());
            if ($verbose) {
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }
        }
    }
}

if (want($only, 'mention_db') && !$skipSend) {
    $email = isset($opts['email']) ? trim((string) $opts['email']) : '';
    if ($email === '') {
        if ($verbose) {
            fwrite(STDERR, "mention_db: skipped (pass --email= and omit --skip-send)\n");
        }
    } else {
        // Mentions write notification rows to the alice/bob test users — clean by email pattern.
        phf_cleanup_register(static function () use ($config): void {
            $db = \Webmail\Core\Database::getConnection($config);
            $db->exec("DELETE FROM notifications WHERE user_email LIKE '%@flowone-test.invalid'");
        });
        try {
            $db = \Webmail\Core\Database::getConnection($config);
            $seed = phf_seed_card($config, $email, 'MentionDB');
            $cardId = (int) $seed['card_id'];
            $author = strtolower($email);
            $alice = 'alice-' . bin2hex(random_bytes(2)) . '@flowone-test.invalid';
            $bob = 'bob-' . bin2hex(random_bytes(2)) . '@flowone-test.invalid';

            // Bob is a watcher (used by overlap dedupe assertion).
            phf_add_watcher($config, $cardId, $bob);

            $beforeAlice = phf_count_notifications($config, $alice);
            $beforeBob = phf_count_notifications($config, $bob);
            $beforeAuthor = phf_count_notifications($config, $author);

            $body = '<p>Hi @' . $alice . ' and @' . $bob . ' please review</p>';
            $row = phf_post_comment_with_mentions($config, $cardId, $author, $body, [
                ['email' => $alice, 'name' => 'Alice'],
                ['email' => $bob, 'name' => 'Bob Watcher'],
            ]);

            if (!$row || empty($row['id'])) {
                throw new \RuntimeException('addComment returned null');
            }
            $commentId = (int) $row['id'];
            phf_cleanup_register(static function () use ($config, $commentId): void {
                $db = \Webmail\Core\Database::getConnection($config);
                $db->prepare('DELETE FROM webmail_card_comments WHERE id = ?')->execute([$commentId]);
            });

            // structured_payload subgroup: mentions JSON must be [{email,name}], not flat strings.
            $mentionsRaw = $row['mentions'] ?? null;
            $mentionsDecoded = is_string($mentionsRaw) ? json_decode($mentionsRaw, true) : $mentionsRaw;
            if (!is_array($mentionsDecoded) || count($mentionsDecoded) < 2) {
                throw new \RuntimeException('mentions JSON missing or empty');
            }
            $first = $mentionsDecoded[0];
            if (!is_array($first) || !array_key_exists('email', $first) || !array_key_exists('name', $first)) {
                throw new \RuntimeException('mentions JSON must be [{email,name}] objects, got: ' . json_encode($first));
            }
            _ok($results);

            // mention_actor_self_skip subgroup
            $afterAuthor = phf_count_notifications($config, $author);
            if ($afterAuthor !== $beforeAuthor) {
                throw new \RuntimeException('actor should never be self-notified');
            }
            _ok($results);

            // Alice was NOT a watcher / assignee — she should receive ph_mention (no ph_comment_added duplicate).
            $aliceMention = phf_count_notifications($config, $alice, 'ph_mention');
            if ($aliceMention < 1) {
                throw new \RuntimeException('ph_mention not delivered to mentioned non-watcher');
            }
            _ok($results);

            // notif_mention_watcher_overlap: Bob is a watcher AND mentioned, but must
            // receive exactly one row total (the resolver collapses ph_mention/ph_comment overlap).
            $bobTotal = phf_count_notifications($config, $bob);
            if ($bobTotal - $beforeBob !== 1) {
                throw new \RuntimeException('watcher+mention overlap should produce exactly 1 row, got ' . ($bobTotal - $beforeBob));
            }
            _ok($results);
        } catch (\Throwable $e) {
            _fail($results, 'mention_db: ' . $e->getMessage());
            if ($verbose) {
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }
        }
    }
}

if (want($only, 'calendar_db') && !$skipSend) {
    $email = isset($opts['email']) ? trim((string) $opts['email']) : '';
    if ($email === '') {
        if ($verbose) {
            fwrite(STDERR, "calendar_db: skipped (pass --email= and omit --skip-send)\n");
        }
    } else {
        try {
            $db = \Webmail\Core\Database::getConnection($config);
            $seed = phf_seed_card($config, $email, 'CalReverse');
            $cardId = (int) $seed['card_id'];
            $originalDesc = '[FLOWONE-TEST] original-description-do-not-touch';
            $originalTitle = '[FLOWONE-TEST] original-title-do-not-touch';
            phf_update_card($config, $cardId, [
                'title' => $originalTitle,
                'description' => $originalDesc,
                'start_date' => '2026-01-01',
                'due_date' => '2026-01-02',
            ]);

            // Seed a real calendar row to satisfy FK on calendar_events.calendar_id.
            $calName = '[FLOWONE-TEST] cal-' . bin2hex(random_bytes(3));
            $db->prepare("
                INSERT INTO calendars (user_email, name, color, timezone, created_at)
                VALUES (?, ?, '#3b82f6', 'UTC', NOW())
            ")->execute([strtolower($email), $calName]);
            $calendarId = (int) $db->lastInsertId();
            phf_cleanup_register(static function () use ($config, $calendarId): void {
                $db = \Webmail\Core\Database::getConnection($config);
                $db->prepare('DELETE FROM calendars WHERE id = ?')->execute([$calendarId]);
            });

            $calUid = '[FLOWONE-TEST]-' . bin2hex(random_bytes(6)) . '@flowone-test';
            $db->prepare("
                INSERT INTO calendar_events (calendar_id, uid, title, start_time, end_time, all_day, created_at)
                VALUES (?, ?, '[FLOWONE-TEST] mock', '2026-01-01 00:00:00', '2026-01-02 23:59:59', 1, NOW())
            ")->execute([$calendarId, $calUid]);
            $calEventId = (int) $db->lastInsertId();
            phf_cleanup_register(static function () use ($config, $calEventId): void {
                $db = \Webmail\Core\Database::getConnection($config);
                $db->prepare('DELETE FROM calendar_events WHERE id = ?')->execute([$calEventId]);
            });

            $db->prepare('
                INSERT INTO projecthub_card_calendar_map (card_id, calendar_event_id, user_email, sync_enabled)
                VALUES (?, ?, ?, 1)
            ')->execute([$cardId, $calEventId, strtolower($email)]);
            $mapId = (int) $db->lastInsertId();
            phf_cleanup_register(static function () use ($config, $mapId): void {
                $db = \Webmail\Core\Database::getConnection($config);
                $db->prepare('DELETE FROM projecthub_card_calendar_map WHERE id = ?')->execute([$mapId]);
            });

            // Bridge reads `start_date`/`start_time` and `end_date`/`end_time` from incoming
            // calendar event payload. Cards translate end_date → due_date. Send the calendar-side
            // field names (with hostile extras) so we exercise the whitelist correctly.
            $bridge = new \Webmail\Addons\ProjectHub\Services\ProjectHubCalendarBridge($config);
            $bridge->onCalendarEventUpdated($calEventId, [
                'title' => 'HOSTILE',
                'description' => 'HOSTILE',
                'labels' => ['urgent'],
                'assignees' => ['evil@x.com'],
                'status' => 'archived',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-30',
                'due_date' => '2026-06-30', // also send the card-side name; bridge must ignore
            ]);

            $row = $db->prepare('SELECT title, description, start_date, due_date FROM webmail_board_cards WHERE id = ?');
            $row->execute([$cardId]);
            $card = $row->fetch(\PDO::FETCH_ASSOC) ?: [];

            // start_date/due_date columns are DATETIME — strip time suffix before comparing.
            $startCmp = substr((string) ($card['start_date'] ?? ''), 0, 10);
            $dueCmp = substr((string) ($card['due_date'] ?? ''), 0, 10);

            $failures = [];
            if (($card['title'] ?? '') !== $originalTitle) {
                $failures[] = 'title was overwritten';
            }
            if (($card['description'] ?? '') !== $originalDesc) {
                $failures[] = 'description was overwritten';
            }
            if ($startCmp !== '2026-06-01') {
                $failures[] = 'start_date should equal 2026-06-01, got ' . ($card['start_date'] ?? 'NULL');
            }
            if ($dueCmp !== '2026-06-30') {
                $failures[] = 'due_date should equal 2026-06-30, got ' . ($card['due_date'] ?? 'NULL');
            }

            if ($failures !== []) {
                _fail($results, 'calendar_db whitelist_enforcement: ' . implode('; ', $failures));
            } else {
                _ok($results);
            }
        } catch (\Throwable $e) {
            _fail($results, 'calendar_db: ' . $e->getMessage());
            if ($verbose) {
                fwrite(STDERR, $e->getTraceAsString() . "\n");
            }
        }
    }
}

if ($jsonOut) {
    echo json_encode(['results' => $results, 'log' => $logFile], JSON_UNESCAPED_SLASHES) . "\n";
}
exit($results['failed'] ? 1 : 0);
