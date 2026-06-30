<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class SystemLogsController extends BaseController
{
    /**
     * Filter patterns for different scenarios (for frontend display)
     */
    private array $filters = [
        'openlitespeed' => [
            'errors' => ['error', 'Error', 'ERROR', 'fatal', 'Fatal', 'failed', 'crit', 'CRIT'],
            'warnings' => ['warning', 'Warning', 'WARN', 'notice', 'Notice'],
            'access' => ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'],
            'modsec' => ['ModSecurity', 'OWASP', 'Rule', 'blocked', 'SecRule', 'Matched'],
            'ssl_tls' => ['SSL', 'TLS', 'certificate', 'handshake', 'cipher', 'HTTPS'],
            'connection' => ['connection', 'connect', 'accept', 'close', 'timeout'],
            '500_errors' => [' 500 ', ' 502 ', ' 503 ', ' 504 ', 'Internal Server Error', 'Bad Gateway'],
            '404_errors' => [' 404 ', 'Not Found', 'does not exist'],
            '403_errors' => [' 403 ', 'Forbidden', 'denied', 'Access denied'],
            'redirects' => [' 301 ', ' 302 ', ' 303 ', ' 307 ', ' 308 ', 'Redirect'],
            'cache' => ['cache', 'Cache', 'HIT', 'MISS', 'BYPASS'],
        ],
        'php' => [
            'errors' => ['error', 'Error', 'ERROR', 'Fatal', 'failed', 'Exception', 'Uncaught'],
            'warnings' => ['warning', 'Warning', 'WARN', 'notice', 'Notice', 'Deprecated'],
            'memory' => ['memory', 'exhausted', 'allocation', 'out of memory', 'Allowed memory'],
            'timeout' => ['timeout', 'execution time', 'max_execution', 'Maximum execution'],
            'permission' => ['Permission denied', 'failed to open', 'Unable to access', 'open_basedir'],
            'database' => ['mysql', 'MySQL', 'PDO', 'database', 'Database', 'query'],
            'stack_trace' => ['Stack trace', 'thrown in', '#0', 'at line'],
        ],
        'mysql' => [
            'errors' => ['ERROR', 'Error', 'failed', 'FATAL'],
            'warnings' => ['Warning', 'WARN', 'Note'],
            'connections' => ['connection', 'connect', 'Aborted', 'Got an error', 'Lost connection'],
            'slow_query' => ['Query_time', 'Rows_examined', 'slow', 'Lock_time'],
            'access_denied' => ['Access denied', 'authentication', 'password', 'using password'],
            'deadlock' => ['Deadlock', 'deadlock', 'waiting for lock', 'lock wait'],
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
            'tls' => ['TLS', 'SSL', 'certificate', 'cipher'],
        ],
        'dovecot' => [
            'errors' => ['Error', 'error', 'Fatal', 'failed', 'panic'],
            'warnings' => ['Warning', 'warn'],
            'auth_failed' => ['auth failed', 'authentication failure', 'password mismatch', 'unknown user'],
            'login' => ['Login', 'logged in', 'logged out', 'imap-login', 'pop3-login'],
            'connections' => ['connected', 'disconnected', 'Connection', 'closed', 'Aborted'],
            'ssl' => ['SSL', 'TLS', 'certificate', 'handshake'],
            'quota' => ['quota', 'Quota', 'over quota', 'mailbox full'],
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

    /**
     * Allowed services
     */
    private array $allowedServices = ['openlitespeed', 'php', 'mysql', 'postfix', 'dovecot', 'mailsync-server', 'collab-server'];

    /**
     * Get logs for a specific service
     */
    public function getLogs(Request $request): Response
    {
        $service = $request->getParam('service');
        $type = $request->input('type', 'journalctl');
        $lines = (int)$request->input('lines', 100);
        $filter = $request->input('filter');
        $search = $request->input('search');

        if (!in_array($service, $this->allowedServices)) {
            return Response::error('Invalid service');
        }

        // Use agent to read logs (agent runs as root)
        $result = $this->agent->execute('logs.read', [
            'service' => $service,
            'type' => $type,
            'lines' => $lines,
            'filter' => $filter,
            'search' => $search,
        ], $this->getActor());

        if ($result['success']) {
            return Response::success([
                'service' => $service,
                'type' => $result['data']['type'] ?? $type,
                'lines' => $result['data']['lines'] ?? [],
                'filters' => $this->filters[$service] ?? [],
                'total' => $result['data']['total'] ?? 0,
            ]);
        }

        return Response::error($result['error'] ?? 'Failed to read logs');
    }

    /**
     * Get available log types for a service
     */
    public function getLogTypes(Request $request): Response
    {
        $service = $request->getParam('service');

        if (!in_array($service, $this->allowedServices)) {
            return Response::error('Invalid service');
        }

        // Use agent to get log types (agent runs as root)
        $result = $this->agent->execute('logs.types', [
            'service' => $service,
        ], $this->getActor());

        if ($result['success']) {
            return Response::success([
                'service' => $service,
                'types' => $result['data']['types'] ?? [],
                'filters' => $this->filters[$service] ?? [],
            ]);
        }

        return Response::error($result['error'] ?? 'Failed to get log types');
    }
}
