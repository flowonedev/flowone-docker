<?php

declare(strict_types=1);

/**
 * Shared helpers for Project Hub CLI tests ([FLOWONE-TEST] prefix discipline).
 *
 * Coverage anchors (projecthub-coverage-check.php): class name substrings must stay in sync
 * with src/Addons/ProjectHub/Services/*.php — CardCommentMentionParser NotificationRecipientResolver
 * ProjectHubActivityService ProjectHubCalendarBridge ProjectHubCardUrlService ProjectHubFileService
 * ProjectHubInactivityChecker ProjectHubNotificationService ProjectHubRoleService ProjectHubService
 * ProjectHubShareService ProjectHubTimeBreakdownService ProjectHubTimeBudgetService ProjectHubWorkTrackingService
 */

function phf_log_path(string $prefix): string
{
    $dir = __DIR__ . '/../../storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir . '/' . $prefix . '-' . gmdate('Ymd-His') . '.log';
}

/** @var array<int, callable> */
$GLOBALS['phf_cleanup_stack'] = $GLOBALS['phf_cleanup_stack'] ?? [];

function phf_cleanup_register(callable $fn): void
{
    $GLOBALS['phf_cleanup_stack'][] = $fn;
}

function phf_cleanup_run(): void
{
    $stack = $GLOBALS['phf_cleanup_stack'] ?? [];
    $GLOBALS['phf_cleanup_stack'] = [];
    foreach (array_reverse($stack) as $fn) {
        try {
            $fn();
        } catch (\Throwable $e) {
            fwrite(STDERR, '[phf_cleanup] ' . $e->getMessage() . "\n");
        }
    }
}

function phf_install_signal_handlers(): void
{
    if (!function_exists('pcntl_signal')) {
        return;
    }
    $handler = static function (): void {
        phf_cleanup_run();
        exit(130);
    };
    @pcntl_signal(SIGINT, $handler);
    @pcntl_signal(SIGTERM, $handler);
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $row  projecthub_card_shares row subset
 * @param array<int, int> $driveFileIds
 */
function phf_create_test_share(array $config, array $row, array $driveFileIds): int
{
    $db = \Webmail\Core\Database::getConnection($config);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('
            INSERT INTO projecthub_card_shares
              (card_id, share_token, created_by, title, message, expires_at, max_downloads, password_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
        ');
        $stmt->execute([
            (int) $row['card_id'],
            (string) $row['share_token'],
            (string) $row['created_by'],
            $row['title'] ?? null,
            $row['message'] ?? null,
            $row['expires_at'] ?? null,
            $row['max_downloads'] ?? null,
        ]);
        $shareId = (int) $db->lastInsertId();
        $ins = $db->prepare('INSERT INTO projecthub_card_share_files (share_id, drive_file_id, sort_order) VALUES (?, ?, ?)');
        $o = 0;
        foreach ($driveFileIds as $fid) {
            $ins->execute([$shareId, (int) $fid, $o++]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    phf_cleanup_register(static function () use ($config, $shareId): void {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare('DELETE FROM projecthub_card_shares WHERE id = ?')->execute([$shareId]);
    });

    return $shareId;
}

/**
 * @param array<string, mixed> $config
 */
function phf_assign_role(array $config, string $userEmail, string $roleSlug): void
{
    $db = \Webmail\Core\Database::getConnection($config);
    $email = strtolower(trim($userEmail));
    $slug = strtolower(trim($roleSlug));
    $rid = $db->prepare('SELECT id FROM projecthub_roles WHERE slug = ? LIMIT 1');
    $rid->execute([$slug]);
    $roleId = $rid->fetchColumn();
    if (!$roleId) {
        throw new \RuntimeException('Role slug not found: ' . $slug);
    }
    $db->prepare('
        INSERT INTO projecthub_user_roles (user_email, role_id, assigned_by) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)
    ')->execute([$email, (int) $roleId, 'flowone-test']);
}

/**
 * @param array<int, array{email: string, name: string}> $mentions
 * @return array<string, mixed>|null
 */
function phf_post_comment_with_mentions(
    array $config,
    int $cardId,
    string $authorEmail,
    string $html,
    array $mentions
): ?array {
    $svc = new \Webmail\Addons\KanbanBoards\Services\BoardService($config);

    return $svc->addComment($authorEmail, $cardId, $html, null, $mentions);
}

/**
 * Seed a board owned by $ownerEmail with one list and a single card (auto-cleanup).
 *
 * @param array<string, mixed> $config
 * @return array{board_id: int, list_id: int, card_id: int}
 */
function phf_seed_card(array $config, string $ownerEmail, string $titleSuffix = ''): array
{
    $db = \Webmail\Core\Database::getConnection($config);
    $owner = strtolower(trim($ownerEmail));
    $suffix = $titleSuffix !== '' ? ' ' . $titleSuffix : '';

    $db->prepare('INSERT INTO webmail_boards (name, owner_email) VALUES (?, ?)')
        ->execute(['[FLOWONE-TEST] Board' . $suffix, $owner]);
    $boardId = (int) $db->lastInsertId();

    $db->prepare("INSERT INTO webmail_board_members (board_id, user_email, role) VALUES (?, ?, 'owner')")
        ->execute([$boardId, $owner]);

    $db->prepare('INSERT INTO webmail_board_lists (board_id, name, position) VALUES (?, ?, 0)')
        ->execute([$boardId, '[FLOWONE-TEST] List' . $suffix]);
    $listId = (int) $db->lastInsertId();

    $db->prepare('
        INSERT INTO webmail_board_cards (list_id, title, position, created_by)
        VALUES (?, ?, 0, ?)
    ')->execute([$listId, '[FLOWONE-TEST] Card' . $suffix, $owner]);
    $cardId = (int) $db->lastInsertId();

    phf_cleanup_register(static function () use ($config, $boardId): void {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare('DELETE FROM webmail_boards WHERE id = ?')->execute([$boardId]);
    });

    return ['board_id' => $boardId, 'list_id' => $listId, 'card_id' => $cardId];
}

/**
 * Update card fields directly (e.g. seed due_date for urgency-sort).
 *
 * @param array<string, mixed> $config
 * @param array<string, mixed> $fields
 */
function phf_update_card(array $config, int $cardId, array $fields): void
{
    if ($fields === []) {
        return;
    }
    $db = \Webmail\Core\Database::getConnection($config);
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        $sets[] = "{$k} = ?";
        $vals[] = $v;
    }
    $vals[] = $cardId;
    $db->prepare('UPDATE webmail_board_cards SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}

/**
 * Assign a user to a card with optional difficulty weight (auto-cleans up via card row cascade).
 *
 * @param array<string, mixed> $config
 */
function phf_assign_card(
    array $config,
    int $cardId,
    string $userEmail,
    string $role = 'assignee',
    int $difficultyWeight = 1
): int {
    $db = \Webmail\Core\Database::getConnection($config);
    $email = strtolower(trim($userEmail));
    $db->prepare("
        INSERT INTO projecthub_card_assignees (card_id, user_email, role, status, difficulty_weight)
        VALUES (?, ?, ?, 'assigned', ?)
        ON DUPLICATE KEY UPDATE role = VALUES(role), difficulty_weight = VALUES(difficulty_weight)
    ")->execute([$cardId, $email, $role, max(1, min(5, $difficultyWeight))]);

    $stmt = $db->prepare('SELECT id FROM projecthub_card_assignees WHERE card_id = ? AND user_email = ? LIMIT 1');
    $stmt->execute([$cardId, $email]);
    return (int) $stmt->fetchColumn();
}

/**
 * Add watcher to a card (auto-cleanup with card).
 *
 * @param array<string, mixed> $config
 */
function phf_add_watcher(array $config, int $cardId, string $userEmail): void
{
    $db = \Webmail\Core\Database::getConnection($config);
    $db->prepare('
        INSERT IGNORE INTO projecthub_watchers (card_id, user_email)
        VALUES (?, ?)
    ')->execute([$cardId, strtolower(trim($userEmail))]);
}

/**
 * Seed a drive file owned by $ownerEmail with a recognizable [FLOWONE-TEST] prefix in original_name.
 * Optionally tag it for a specific card so ProjectHubShareService::assertDriveFilesTaggedForCard passes.
 *
 * @param array<string, mixed> $config
 */
function phf_seed_drive_file(array $config, string $ownerEmail, ?int $tagCardId = null): int
{
    $db = \Webmail\Core\Database::getConnection($config);
    $owner = strtolower(trim($ownerEmail));
    $name = '[FLOWONE-TEST] deliverable';
    if ($tagCardId !== null) {
        $name .= ' [PH-' . $tagCardId . ']';
    }
    $name .= '-' . bin2hex(random_bytes(4)) . '.txt';

    // DriveService::resolveFilePath looks at:
    //   <config.drive.storage_path | /var/www/vps-email/storage/drive>/<md5(lower(email))>/<filename>
    // We must seed the bytes to that path or downloads will return 404/410.
    $localBase = rtrim(
        ($config['drive']['storage_path'] ?? '/var/www/vps-email/storage/drive'),
        '/'
    );
    $userHash = md5($owner);
    $userDir = $localBase . '/' . $userHash;
    if (!is_dir($userDir)) {
        @mkdir($userDir, 0755, true);
    }
    $hashedName = sha1($name) . '.txt';
    $primaryPath = $userDir . '/' . $hashedName;
    if (!@file_put_contents($primaryPath, "FLOWONE-TEST payload\n")) {
        // Fall back to a writable tmp location; tests will then skip the byte-level read.
        $tmpDir = sys_get_temp_dir() . '/flowone-test-drive';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $primaryPath = $tmpDir . '/' . $hashedName;
        @file_put_contents($primaryPath, "FLOWONE-TEST payload\n");
    }

    $db->prepare('
        INSERT INTO drive_files
          (user_email, filename, original_name, mime_type, size, storage_location, is_trashed)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ')->execute([$owner, $hashedName, $name, 'text/plain', filesize($primaryPath) ?: 0, 'local']);
    $id = (int) $db->lastInsertId();

    phf_cleanup_register(static function () use ($config, $id, $primaryPath): void {
        $db = \Webmail\Core\Database::getConnection($config);
        $db->prepare('DELETE FROM drive_files WHERE id = ?')->execute([$id]);
        @unlink($primaryPath);
    });

    return $id;
}

/**
 * Snapshot count of notifications for a user (cap to recent), optional type filter.
 *
 * @param array<string, mixed> $config
 */
function phf_count_notifications(array $config, string $userEmail, ?string $type = null): int
{
    $db = \Webmail\Core\Database::getConnection($config);
    if ($type !== null) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE LOWER(user_email) = ? AND type = ?');
        $stmt->execute([strtolower($userEmail), $type]);
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE LOWER(user_email) = ?');
        $stmt->execute([strtolower($userEmail)]);
    }

    return (int) $stmt->fetchColumn();
}

/**
 * Clean up [FLOWONE-TEST] artefacts in batch (called at end of test runs).
 *
 * @param array<string, mixed> $config
 * @return array{boards: int, cards: int, shares: int, drive_files: int, notifications: int}
 */
function phf_purge_test_artefacts(array $config): array
{
    $db = \Webmail\Core\Database::getConnection($config);
    $stats = [
        'boards' => 0,
        'cards' => 0,
        'shares' => 0,
        'drive_files' => 0,
        'notifications' => 0,
        'calendars' => 0,
        'calendar_events' => 0,
    ];

    // calendar_events first (FK depends on calendars)
    $st = $db->prepare("DELETE FROM calendar_events WHERE title LIKE '[FLOWONE-TEST]%' OR uid LIKE '[FLOWONE-TEST]%'");
    $st->execute();
    $stats['calendar_events'] = $st->rowCount();

    $st = $db->prepare("DELETE FROM calendars WHERE name LIKE '[FLOWONE-TEST]%'");
    $st->execute();
    $stats['calendars'] = $st->rowCount();

    $st = $db->prepare("SELECT id FROM webmail_boards WHERE name LIKE '[FLOWONE-TEST]%'");
    $st->execute();
    foreach ($st->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $bid) {
        $db->prepare('DELETE FROM webmail_boards WHERE id = ?')->execute([(int) $bid]);
        $stats['boards']++;
    }

    $st = $db->prepare("SELECT id FROM webmail_board_cards WHERE title LIKE '[FLOWONE-TEST]%'");
    $st->execute();
    foreach ($st->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $cid) {
        $db->prepare('DELETE FROM webmail_board_cards WHERE id = ?')->execute([(int) $cid]);
        $stats['cards']++;
    }

    $st = $db->prepare("DELETE FROM drive_files WHERE original_name LIKE '[FLOWONE-TEST]%'");
    $st->execute();
    $stats['drive_files'] = $st->rowCount();

    $st = $db->prepare("DELETE FROM projecthub_card_shares WHERE title LIKE '[FLOWONE-TEST]%'");
    $st->execute();
    $stats['shares'] = $st->rowCount();

    $st = $db->prepare("DELETE FROM notifications WHERE title LIKE '[FLOWONE-TEST]%' OR message LIKE '[FLOWONE-TEST]%' OR user_email LIKE '%@flowone-test.invalid'");
    $st->execute();
    $stats['notifications'] = $st->rowCount();

    return $stats;
}
