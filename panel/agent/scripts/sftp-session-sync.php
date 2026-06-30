#!/usr/bin/env php
<?php
/**
 * FlowOne SFTP session sync.
 *
 * Drains new sshd / internal-sftp journal entries into the panel's
 * sftp_sessions table (login/logout/duration/transfer-bytes per additional
 * restricted SFTP user). Designed to run every minute from
 * /etc/cron.d/flowone-sftp-sessions, and is also invokable on demand by the
 * agent (sftpSession.sync) or the dashboard "Refresh" button.
 *
 * Self-contained (its own panel-DB connection, like mailsec-event-sync.php)
 * so it works from cron without the panel API or the agent socket. The heavy
 * lifting lives in VpsAdmin\Agent\Sftp\SftpSession{Parser,Store,Ingestor};
 * this is just the CLI wrapper.
 *
 * Requires the sshd Match block to run `internal-sftp -l INFO` (managed by
 * SshdSftpConfigurator) so per-file transfer sizes are logged.
 *
 * Suggested cron (/etc/cron.d/flowone-sftp-sessions):
 *   * * * * * root /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-admin/agent/scripts/sftp-session-sync.php >/dev/null 2>&1
 *
 * Usage:
 *   sftp-session-sync.php [--json] [--reset] [--retention=DAYS] [--help]
 *     --json            machine-readable summary on stdout
 *     --reset           ignore the stored journal cursor (re-scan recent window)
 *     --retention=DAYS  prune sessions older than DAYS (default 90)
 *     --help            this header
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['json', 'reset', 'retention:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, (string) file_get_contents(__FILE__, false, null, 0, 1700));
    exit(0);
}

$asJson = isset($opts['json']);
$reset = isset($opts['reset']);
$retention = isset($opts['retention']) ? max(1, (int) $opts['retention']) : 90;

// Agent autoloader (local dev: this file's parent; production: /var/www/...).
$agentRoot = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
spl_autoload_register(function (string $class) use ($agentRoot): void {
    $prefix = 'VpsAdmin\\Agent\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $agentRoot . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use VpsAdmin\Agent\Sftp\SftpSessionIngestor;
use VpsAdmin\Agent\Sftp\SftpSessionParser;
use VpsAdmin\Agent\Sftp\SftpSessionStore;

try {
    $pdo = connectPanelDb();
    if ($pdo === null) {
        fwrite(STDERR, "sftp-session-sync: database unavailable\n");
        exit(75);
    }

    $store = new SftpSessionStore($pdo);
    if ($reset) {
        $store->ensureSchema();
        $store->setCursor('');
    }

    $ingestor = new SftpSessionIngestor($store, new SftpSessionParser());
    $summary = $ingestor->run($retention);
} catch (\Throwable $e) {
    if ($asJson) {
        fwrite(STDOUT, json_encode(['success' => false, 'error' => $e->getMessage()]) . "\n");
    } else {
        fwrite(STDERR, 'sftp-session-sync: ' . $e->getMessage() . "\n");
    }
    exit(1);
}

if ($asJson) {
    fwrite(STDOUT, json_encode(['success' => true] + $summary) . "\n");
} else {
    fwrite(STDOUT, sprintf(
        "sftp-session-sync: read=%d logins=%d logouts=%d transfers=%d skipped=%d pruned=%d stale_closed=%d\n",
        $summary['read'],
        $summary['logins'],
        $summary['logouts'],
        $summary['transfers'],
        $summary['skipped'],
        $summary['pruned'],
        $summary['stale_closed']
    ));
}

exit(0);

// ---------------------------------------------------------------------------

function connectPanelDb(): ?PDO
{
    $candidates = [
        ['/var/www/vps-admin/api/config.php', '/var/www/vps-admin/api/config.local.php'],
        [__DIR__ . '/../../api/config.php', __DIR__ . '/../../api/config.local.php'],
    ];
    $config = null;
    foreach ($candidates as [$main, $local]) {
        if (file_exists($main)) {
            $config = require $main;
            if (file_exists($local)) {
                $config = array_replace_recursive($config, require $local);
            }
            break;
        }
    }
    if (!is_array($config)) {
        return null;
    }
    $db = $config['database'] ?? [];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'] ?? 'localhost',
        $db['port'] ?? 3306,
        $db['name'] ?? 'devc_vps_dash'
    );
    return new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
