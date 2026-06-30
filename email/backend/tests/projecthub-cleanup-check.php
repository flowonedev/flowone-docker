#!/usr/bin/env php
<?php
/**
 * projecthub-cleanup-check.php — confirm zero leftover [FLOWONE-TEST] rows after a test run.
 *
 *   php projecthub-cleanup-check.php [--purge] [--verbose]
 *
 * --purge   Actively delete stragglers (calls phf_purge_test_artefacts) and report counts.
 *           Default (no --purge) is a read-only audit: fails when any artefact is left over.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/projecthub-fixtures.php';

$opts = getopt('', ['help', 'purge', 'verbose']) ?: [];
if (isset($opts['help'])) {
    echo "projecthub-cleanup-check.php [--purge] [--verbose]\n";
    exit(0);
}
$purge = isset($opts['purge']);
$verbose = isset($opts['verbose']);

try {
    $config = require __DIR__ . '/../src/config.php';
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, 'cleanup_check: DB unavailable — skipping (' . $e->getMessage() . ")\n");
    exit(0);
}

$checks = [
    'boards'           => "SELECT COUNT(*) FROM webmail_boards WHERE name LIKE '[FLOWONE-TEST]%'",
    'cards'            => "SELECT COUNT(*) FROM webmail_board_cards WHERE title LIKE '[FLOWONE-TEST]%'",
    'shares'           => "SELECT COUNT(*) FROM projecthub_card_shares WHERE title LIKE '[FLOWONE-TEST]%'",
    'drive_files'      => "SELECT COUNT(*) FROM drive_files WHERE original_name LIKE '[FLOWONE-TEST]%'",
    'notifications'    => "SELECT COUNT(*) FROM notifications WHERE title LIKE '[FLOWONE-TEST]%' OR message LIKE '[FLOWONE-TEST]%' OR user_email LIKE '%@flowone-test.invalid'",
    'comments'         => "SELECT COUNT(*) FROM webmail_card_comments WHERE content LIKE '%[FLOWONE-TEST]%'",
    'calendars'        => "SELECT COUNT(*) FROM calendars WHERE name LIKE '[FLOWONE-TEST]%'",
    'calendar_events'  => "SELECT COUNT(*) FROM calendar_events WHERE title LIKE '[FLOWONE-TEST]%' OR uid LIKE '[FLOWONE-TEST]%'",
];

$pre = [];
foreach ($checks as $name => $sql) {
    try {
        $pre[$name] = (int) $db->query($sql)->fetchColumn();
    } catch (\Throwable $e) {
        $pre[$name] = -1;
        if ($verbose) {
            fwrite(STDERR, $name . ': query failed ' . $e->getMessage() . "\n");
        }
    }
}

if ($purge) {
    $deleted = phf_purge_test_artefacts($config);
    echo 'purged=' . json_encode($deleted) . "\n";
    $post = [];
    foreach ($checks as $name => $sql) {
        try {
            $post[$name] = (int) $db->query($sql)->fetchColumn();
        } catch (\Throwable $e) {
            $post[$name] = -1;
        }
    }
    $still = array_filter($post, static fn ($n) => $n > 0);
    echo 'remaining=' . json_encode($post) . "\n";
    exit($still === [] ? 0 : 1);
}

$leftover = array_filter($pre, static fn ($n) => $n > 0);
echo 'audit=' . json_encode($pre) . "\n";
if ($leftover !== []) {
    fwrite(STDERR, "cleanup_check: leftover [FLOWONE-TEST] rows: " . json_encode($leftover) . "\n");
    exit(1);
}
exit(0);
