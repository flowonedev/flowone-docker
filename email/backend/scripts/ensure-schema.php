#!/usr/bin/env php
<?php
/**
 * Schema warm-up + verification for fleet deployments.
 *
 * Several tables (boards, lists, cards, ProjectHub) are created lazily by
 * service constructors on the first matching request. On a freshly deployed
 * server this means install-time migrations that ALTER those tables fail
 * silently, and the first user to open e.g. My Work hits
 * "Unknown column 'c.parent_card_id'". Running this script right after
 * migrations makes the schema deterministic before any user request.
 *
 * Run on server (CLI only):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/scripts/ensure-schema.php --verbose
 *
 * Flags:
 *   --check-only   verify critical columns only, do not run ensure logic
 *   --verbose      print each step
 *   --json         machine-readable result on stdout
 *   --help         this message
 *
 * Exit code: 0 when all critical columns are present, 1 otherwise.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$opts = getopt('', ['check-only', 'verbose', 'json', 'help']);
if (isset($opts['help'])) {
    echo <<<HELP
Schema warm-up + verification for fleet deployments.

Constructs the lazy-ensure services (boards, ProjectHub, Drive) so a freshly
deployed server has its full schema before the first user request, then
verifies the critical columns the request paths depend on.

Usage:
  /usr/local/lsws/lsphp83/bin/php scripts/ensure-schema.php [--check-only] [--verbose] [--json]

Flags:
  --check-only   verify critical columns only, do not run ensure logic
  --verbose      print each step
  --json         machine-readable result on stdout
  --help         this message

Exit code: 0 when all critical columns are present, 1 otherwise.

HELP;
    exit(0);
}
$checkOnly = isset($opts['check-only']);
$verbose   = isset($opts['verbose']);
$asJson    = isset($opts['json']);

$say = function (string $msg) use ($verbose, $asJson) {
    if ($verbose && !$asJson) {
        echo '[' . date('H:i:s') . "] $msg\n";
    }
};

require_once __DIR__ . '/../cron/bootstrap.php';
$config = require __DIR__ . '/../src/config.php';

$result = ['warmed_up' => [], 'errors' => [], 'missing' => [], 'ok' => false];

// -- 1. Warm-up: construct the lazy-ensure services in dependency order. ----
if (!$checkOnly) {
    $services = [
        'BoardService'                  => fn() => new \Webmail\Addons\KanbanBoards\Services\BoardService($config),
        'ProjectHubService'             => fn() => new \Webmail\Addons\ProjectHub\Services\ProjectHubService($config),
        'ProjectHubWorkTrackingService' => fn() => new \Webmail\Addons\ProjectHub\Services\ProjectHubWorkTrackingService($config),
        'DriveService'                  => fn() => new \Webmail\Services\DriveService($config),
    ];
    foreach ($services as $name => $build) {
        try {
            $say("warming up {$name}...");
            $build();
            $result['warmed_up'][] = $name;
        } catch (\Throwable $e) {
            $result['errors'][] = "{$name}: " . $e->getMessage();
            $say("ERROR {$name}: " . $e->getMessage());
        }
    }
}

// -- 2. Verify critical columns the request paths depend on. ----------------
$critical = [
    ['webmail_board_cards', 'parent_card_id'],
    ['webmail_board_cards', 'time_estimate_seconds'],
    ['webmail_board_cards', 'time_budget_alert_sent'],
    ['webmail_board_cards', 'card_color'],
    ['webmail_board_cards', 'full_task_visibility'],
    ['webmail_board_cards', 'simulation_run_id'],
    ['drive_files', 'storage_location'],
];

try {
    $db = \Webmail\Core\Database::getConnection($config);
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    foreach ($critical as [$table, $column]) {
        $stmt->execute([$table, $column]);
        if (!$stmt->fetchColumn()) {
            $result['missing'][] = "{$table}.{$column}";
            $say("MISSING {$table}.{$column}");
        } else {
            $say("ok {$table}.{$column}");
        }
    }
} catch (\Throwable $e) {
    $result['errors'][] = 'verification: ' . $e->getMessage();
}

$result['ok'] = $result['missing'] === [] && $result['errors'] === [];

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} else {
    echo $result['ok']
        ? "Schema OK (" . count($critical) . " critical columns verified)\n"
        : "Schema INCOMPLETE - missing: " . implode(', ', $result['missing'])
          . ($result['errors'] ? ' | errors: ' . implode(' | ', $result['errors']) : '') . "\n";
}

exit($result['ok'] ? 0 : 1);
