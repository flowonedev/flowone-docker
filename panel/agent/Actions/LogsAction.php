<?php
/**
 * Logs Action Handler
 * 
 * Reads system logs for various services.
 * Runs as root so it can access all log files.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

class LogsAction extends BaseAction
{
    /**
     * Log file paths for different services
     */
    private array $logPaths = [
        'openlitespeed' => [
            'error' => '/usr/local/lsws/logs/error.log',
            'stderr' => '/usr/local/lsws/logs/stderr.log',
            'access' => '/usr/local/lsws/logs/access.log',
            'modsec_audit' => '/usr/local/lsws/logs/auditmodsec.log',
            'modsec_debug' => '/usr/local/lsws/logs/modsec_debug.log',
        ],
        'php' => [
            'error' => '/usr/local/lsws/logs/error.log',
            'stderr' => '/usr/local/lsws/logs/stderr.log',
        ],
        'mysql' => [
            'error' => '/var/log/mysql/error.log',
            'mariadb' => '/var/log/mariadb/mariadb.log',
            'slow' => '/var/log/mysql/slow.log',
        ],
        'postfix' => [
            'mail' => '/var/log/mail.log',
            'mail_err' => '/var/log/mail.err',
        ],
        'dovecot' => [
            'mail' => '/var/log/mail.log',
            'dovecot' => '/var/log/dovecot.log',
        ],
        // Email app services (journalctl only)
        'mailsync-server' => [],
        'collab-server' => [],
    ];

    /**
     * Journalctl unit names for each service
     */
    private array $journalUnits = [
        'openlitespeed' => ['lsws', 'lshttpd'],
        'php' => ['lsws', 'lshttpd', 'php-fpm', 'php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm'],
        'mysql' => ['mysql', 'mariadb', 'mysqld'],
        'postfix' => ['postfix', 'postfix@-'],
        'dovecot' => ['dovecot'],
        'mailsync-server' => ['mailsync-server'],
        'collab-server' => ['collab-server'],
    ];

    public function getNamespace(): string
    {
        return 'logs';
    }

    public function getMethods(): array
    {
        return ['read', 'types'];
    }

    public function requiresBackup(string $method): bool
    {
        return false;
    }

    /**
     * Get available log types for a service
     */
    protected function actionTypes(array $params, string $actor): array
    {
        $service = $params['service'] ?? null;
        
        if (!$service || !isset($this->logPaths[$service])) {
            return $this->error('Invalid service: ' . $service);
        }

        $types = [];
        
        // Check journalctl first
        foreach ($this->journalUnits[$service] ?? [] as $unit) {
            exec("journalctl -u {$unit} -n 1 --no-pager 2>/dev/null", $output, $exitCode);
            if ($exitCode === 0 && !empty($output)) {
                $types[] = [
                    'id' => 'journalctl',
                    'label' => 'System Journal',
                    'path' => 'journalctl',
                    'exists' => true,
                    'size' => 0,
                    'size_human' => 'systemd',
                ];
                break;
            }
        }

        // Check file-based logs
        foreach ($this->logPaths[$service] as $type => $path) {
            $exists = file_exists($path);
            $size = 0;
            
            if ($exists) {
                $size = filesize($path) ?: 0;
            }
            
            $types[] = [
                'id' => $type,
                'label' => ucfirst(str_replace('_', ' ', $type)),
                'path' => $path,
                'exists' => $exists,
                'size' => $size,
                'size_human' => $exists ? $this->formatBytes($size) : 'N/A',
            ];
        }

        return $this->success([
            'service' => $service,
            'types' => $types,
        ]);
    }

    /**
     * Read logs for a service
     */
    protected function actionRead(array $params, string $actor): array
    {
        $service = $params['service'] ?? null;
        $type = $params['type'] ?? 'journalctl';
        $lines = (int)($params['lines'] ?? 100);
        $filter = $params['filter'] ?? null;
        $search = $params['search'] ?? null;

        if (!$service || !isset($this->logPaths[$service])) {
            return $this->error('Invalid service: ' . $service);
        }

        $lines = min(max($lines, 10), 500); // Clamp between 10-500
        $logLines = [];

        // Try journalctl first
        if ($type === 'journalctl') {
            $logLines = $this->readJournalLogs($service, $lines);
        }
        
        // If journalctl returned nothing or a specific file type requested
        if (empty($logLines) || ($type !== 'journalctl' && isset($this->logPaths[$service][$type]))) {
            $path = $this->logPaths[$service][$type] ?? null;
            if ($path && file_exists($path)) {
                $logLines = $this->readFileLog($path, $lines);
            }
        }

        // If still empty, try any available log file
        if (empty($logLines)) {
            foreach ($this->logPaths[$service] as $logType => $path) {
                if (file_exists($path)) {
                    $logLines = $this->readFileLog($path, $lines);
                    if (!empty($logLines)) {
                        $type = $logType;
                        break;
                    }
                }
            }
        }

        // Apply filter
        if ($filter && !empty($logLines)) {
            $logLines = $this->applyFilter($logLines, $service, $filter);
        }

        // Apply search
        if ($search && !empty($logLines)) {
            $logLines = array_filter($logLines, function($line) use ($search) {
                return stripos($line, $search) !== false;
            });
            $logLines = array_values($logLines);
        }

        // Limit final results
        $logLines = array_slice($logLines, -$lines);

        return $this->success([
            'service' => $service,
            'type' => $type,
            'lines' => $logLines,
            'total' => count($logLines),
        ]);
    }

    /**
     * Read logs from journalctl
     */
    private function readJournalLogs(string $service, int $lines): array
    {
        $units = $this->journalUnits[$service] ?? [];
        $allLines = [];

        foreach ($units as $unit) {
            $cmd = "journalctl -u {$unit} -n " . ($lines * 2) . " --no-pager 2>/dev/null";
            exec($cmd, $output, $exitCode);
            
            if ($exitCode === 0 && !empty($output)) {
                $allLines = array_merge($allLines, $output);
            }
            $output = [];
        }

        // Remove duplicates and get last N lines
        $allLines = array_unique($allLines);
        return array_slice($allLines, -$lines);
    }

    /**
     * Read logs from a file
     */
    private function readFileLog(string $path, int $lines): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $cmd = "tail -n " . escapeshellarg($lines * 2) . " " . escapeshellarg($path) . " 2>/dev/null";
        exec($cmd, $output, $exitCode);
        
        if ($exitCode === 0 && !empty($output)) {
            return array_reverse(array_slice(array_reverse($output), 0, $lines));
        }

        return [];
    }

    /**
     * Apply predefined filter patterns
     */
    private function applyFilter(array $lines, string $service, string $filter): array
    {
        $patterns = $this->getFilterPatterns($service, $filter);
        
        if (empty($patterns)) {
            return $lines;
        }

        return array_values(array_filter($lines, function($line) use ($patterns) {
            foreach ($patterns as $pattern) {
                if (stripos($line, $pattern) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Get filter patterns for a service
     */
    private function getFilterPatterns(string $service, string $filter): array
    {
        $filters = [
            'openlitespeed' => [
                'errors' => ['error', 'Error', 'ERROR', 'fatal', 'Fatal', 'failed', 'crit', 'CRIT'],
                'warnings' => ['warning', 'Warning', 'WARN', 'notice', 'Notice'],
                'access' => ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'],
                'modsec' => ['ModSecurity', 'OWASP', 'Rule', 'blocked', 'SecRule', 'Matched'],
                'ssl_tls' => ['SSL', 'TLS', 'certificate', 'handshake', 'cipher', 'HTTPS'],
                'connection' => ['connection', 'connect', 'accept', 'close', 'timeout'],
                '500_errors' => [' 500 ', ' 502 ', ' 503 ', ' 504 ', 'Internal Server Error', 'Bad Gateway', 'Service Unavailable'],
                '404_errors' => [' 404 ', 'Not Found', 'does not exist'],
                '403_errors' => [' 403 ', 'Forbidden', 'denied', 'Access denied'],
                'redirects' => [' 301 ', ' 302 ', ' 303 ', ' 307 ', ' 308 ', 'Redirect'],
                'cache' => ['cache', 'Cache', 'HIT', 'MISS', 'BYPASS'],
                'rewrites' => ['rewrite', 'Rewrite', 'redirect'],
            ],
            'php' => [
                'errors' => ['error', 'Error', 'ERROR', 'Fatal', 'failed', 'Exception', 'Uncaught'],
                'warnings' => ['warning', 'Warning', 'WARN', 'notice', 'Notice', 'Deprecated'],
                'memory' => ['memory', 'exhausted', 'allocation', 'out of memory', 'Allowed memory'],
                'timeout' => ['timeout', 'execution time', 'max_execution', 'Maximum execution'],
                'permission' => ['Permission denied', 'failed to open', 'Unable to access', 'open_basedir'],
                'database' => ['mysql', 'MySQL', 'PDO', 'database', 'Database', 'query'],
                'stack_trace' => ['Stack trace', 'thrown in', '#0', 'at line'],
                'syntax' => ['Parse error', 'syntax error', 'unexpected'],
            ],
            'mysql' => [
                'errors' => ['ERROR', 'Error', 'failed', 'FATAL'],
                'warnings' => ['Warning', 'WARN', 'Note'],
                'connections' => ['connection', 'connect', 'Aborted', 'Got an error', 'Lost connection'],
                'slow_query' => ['Query_time', 'Rows_examined', 'slow', 'Lock_time'],
                'access_denied' => ['Access denied', 'authentication', 'password', 'using password'],
                'deadlock' => ['Deadlock', 'deadlock', 'waiting for lock', 'lock wait'],
                'replication' => ['slave', 'Slave', 'master', 'Master', 'replication', 'binlog'],
                'startup' => ['Starting', 'started', 'ready', 'Shutdown', 'shutdown'],
            ],
            'postfix' => [
                'errors' => ['error', 'fatal', 'panic', 'failed', 'NOQUEUE'],
                'warnings' => ['warning', 'warn'],
                'bounced' => ['bounced', 'rejected', 'returned', 'undeliverable', 'User unknown'],
                'delivered' => ['status=sent', 'delivered', 'removed', 'relay='],
                'deferred' => ['status=deferred', 'temporarily', 'retry', 'Connection timed out'],
                'spam' => ['spam', 'blocked', 'reject', 'blacklist', 'RBL', 'DNSBL'],
                'auth_failed' => ['authentication failed', 'SASL', 'auth failed', 'incorrect password'],
                'connection' => ['connect from', 'disconnect from', 'lost connection', 'timeout'],
                'tls' => ['TLS', 'SSL', 'certificate', 'cipher'],
                'queue' => ['queue', 'Queue', 'pickup', 'cleanup', 'qmgr'],
            ],
            'dovecot' => [
                'errors' => ['Error', 'error', 'Fatal', 'failed', 'panic'],
                'warnings' => ['Warning', 'warn'],
                'auth_failed' => ['auth failed', 'authentication failure', 'password mismatch', 'unknown user', 'invalid credentials'],
                'login' => ['Login', 'logged in', 'logged out', 'imap-login', 'pop3-login'],
                'connections' => ['connected', 'disconnected', 'Connection', 'closed', 'Aborted'],
                'ssl' => ['SSL', 'TLS', 'certificate', 'handshake'],
                'quota' => ['quota', 'Quota', 'over quota', 'mailbox full'],
                'lda' => ['lda', 'deliver', 'sieve', 'msgid'],
            ],
            'mailsync-server' => [
                'errors' => ['Error', 'error', 'ERROR', 'fatal', 'failed', 'exception', 'ECONNREFUSED'],
                'warnings' => ['Warning', 'warn', 'WARN'],
                'imap' => ['IMAP', 'imap', 'IDLE', 'idle', 'mailbox', 'Mailbox'],
                'redis' => ['Redis', 'redis', 'pub/sub', 'subscribe', 'publish'],
                'websocket' => ['WebSocket', 'websocket', 'ws://', 'wss://', 'connected', 'disconnected'],
                'sync' => ['sync', 'Sync', 'synchroniz', 'update', 'new message'],
            ],
            'collab-server' => [
                'errors' => ['Error', 'error', 'ERROR', 'fatal', 'failed', 'exception'],
                'warnings' => ['Warning', 'warn', 'WARN'],
                'websocket' => ['WebSocket', 'websocket', 'ws://', 'wss://', 'connected', 'disconnected'],
                'hocuspocus' => ['Hocuspocus', 'hocuspocus', 'document', 'Document', 'collaboration'],
                'sync' => ['sync', 'Sync', 'update', 'change', 'awareness'],
            ],
        ];

        return $filters[$service][$filter] ?? [];
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

