<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class LogController extends BaseController
{
    /**
     * Get audit logs with full filtering
     */
    public function index(Request $request): Response
    {
        $pagination = $this->getPagination($request);
        
        $filters = [
            'source_app' => $request->getQuery('source_app'),
            'severity' => $request->getQuery('severity'),
            'action' => $request->getQuery('action'),
            'actor' => $request->getQuery('actor'),
            'user_email' => $request->getQuery('user_email'),
            'target' => $request->getQuery('target'),
            'outcome' => $request->getQuery('outcome'),
            'from' => $request->getQuery('from'),
            'to' => $request->getQuery('to'),
            'search' => $request->getQuery('search'),
        ];

        $logs = $this->audit->getLogs(
            array_filter($filters),
            $pagination['page'],
            $pagination['per_page']
        );

        return Response::success($logs);
    }

    /**
     * Get single log entry
     */
    public function show(Request $request): Response
    {
        $id = (int)$request->getParam('id');
        
        $log = $this->audit->getLog($id);
        
        if (!$log) {
            return Response::notFound('Log entry not found');
        }

        return Response::success(['log' => $log]);
    }

    /**
     * Get audit log statistics (dashboard summary)
     */
    public function stats(Request $request): Response
    {
        $hours = (int)$request->getQuery('hours', 24);
        $hours = max(1, min(720, $hours)); // Between 1 hour and 30 days

        $stats = $this->audit->getStats($hours);

        return Response::success($stats);
    }

    /**
     * Export audit logs as CSV
     */
    public function export(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;

        $filters = [
            'source_app' => $request->getQuery('source_app'),
            'severity' => $request->getQuery('severity'),
            'outcome' => $request->getQuery('outcome'),
            'from' => $request->getQuery('from'),
            'to' => $request->getQuery('to'),
        ];

        $logs = $this->audit->exportLogs(array_filter($filters));

        // Build CSV content
        $csv = "ID,Source App,Severity,Action,Actor,IP Address,User Email,Target,Outcome,Timestamp\n";
        foreach ($logs as $log) {
            $csv .= sprintf(
                "%d,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $log['id'],
                $this->csvEscape($log['source_app']),
                $this->csvEscape($log['severity']),
                $this->csvEscape($log['action']),
                $this->csvEscape($log['actor']),
                $this->csvEscape($log['ip_address'] ?? ''),
                $this->csvEscape($log['user_email'] ?? ''),
                $this->csvEscape($log['target']),
                $this->csvEscape($log['outcome']),
                $this->csvEscape($log['created_at'])
            );
        }

        $this->logAction('audit_export', 'audit_logs', 'success', [
            'filters' => array_filter($filters),
            'count' => count($logs),
        ]);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="audit-logs-' . date('Y-m-d-His') . '.csv"',
        ]);
    }

    /**
     * Ingest audit events from external apps (API key auth)
     */
    public function ingest(Request $request): Response
    {
        // Validate API key (timing-safe comparison)
        $apiKey = $request->getHeader('X-Api-Key') ?? $request->getQuery('api_key');
        $validKeys = $this->container->getConfig('external_api.keys') ?? [];
        
        $sourceApp = 'unknown';
        $keyValid = false;
        if ($apiKey) {
            foreach ($validKeys as $name => $validKey) {
                if (hash_equals((string) $validKey, (string) $apiKey)) {
                    $keyValid = true;
                    $sourceApp = $name;
                    break;
                }
            }
        }
        if (!$keyValid) {
            return Response::unauthorized('Invalid or missing API key');
        }

        $body = $request->getBody();

        // Accept single event or batch
        $events = [];
        if (isset($body['events']) && is_array($body['events'])) {
            $events = $body['events'];
        } elseif (isset($body['action'])) {
            // Single event
            $events = [$body];
        } else {
            return Response::error('Invalid payload. Provide "events" array or single event with "action" field.', 400);
        }

        // Limit batch size
        if (count($events) > 100) {
            return Response::error('Maximum 100 events per batch', 400);
        }

        // Map source app name from key name
        $appName = $body['source_app'] ?? $sourceApp;
        $allowedApps = ['email', 'mailsync', 'collab', 'panel', 'fleet'];
        if (!in_array($appName, $allowedApps)) {
            $appName = $sourceApp;
        }

        $result = $this->audit->ingestBatch($events, $appName);

        return Response::success($result, "Ingested {$result['inserted']} events");
    }

    /**
     * Escape a value for CSV
     */
    private function csvEscape(string $value): string
    {
        // If value contains comma, quote, or newline, wrap in quotes
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
