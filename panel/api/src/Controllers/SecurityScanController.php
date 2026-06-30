<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Security Scan Controller
 * 
 * Handles dependency vulnerability scan results.
 * Receives scan data from server cron job and displays in dashboard.
 */
class SecurityScanController extends BaseController
{
    /**
     * Get latest scan results (one per source_app + scan_type)
     */
    public function latest(Request $request): Response
    {
        try {
            $db = $this->container->getDatabase();
            
            // Get latest scan per source_app + scan_type
            $stmt = $db->prepare("
                SELECT d1.*
                FROM dependency_scans d1
                INNER JOIN (
                    SELECT source_app, scan_type, MAX(scanned_at) as max_scanned
                    FROM dependency_scans
                    GROUP BY source_app, scan_type
                ) d2 ON d1.source_app = d2.source_app 
                    AND d1.scan_type = d2.scan_type 
                    AND d1.scanned_at = d2.max_scanned
                ORDER BY d1.source_app, d1.scan_type
            ");
            $stmt->execute();
            $scans = $stmt->fetchAll();
            
            // Decode JSON results
            foreach ($scans as &$scan) {
                $scan['results'] = json_decode($scan['results'], true) ?? [];
            }
            
            // Calculate totals
            $totals = [
                'vulnerabilities' => 0,
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
            ];
            foreach ($scans as $scan) {
                $totals['vulnerabilities'] += (int)$scan['vulnerabilities_found'];
                $totals['critical'] += (int)$scan['critical_count'];
                $totals['high'] += (int)$scan['high_count'];
                $totals['medium'] += (int)$scan['medium_count'];
                $totals['low'] += (int)$scan['low_count'];
            }
            
            return Response::success([
                'scans' => $scans,
                'totals' => $totals,
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get scan history for a specific app
     */
    public function history(Request $request): Response
    {
        try {
            $sourceApp = $request->getQuery('source_app');
            $limit = min(50, max(1, (int)$request->getQuery('limit', 20)));
            
            $db = $this->container->getDatabase();
            
            $where = '';
            $params = [];
            if ($sourceApp) {
                $where = 'WHERE source_app = ?';
                $params[] = $sourceApp;
            }
            
            $stmt = $db->prepare("
                SELECT id, source_app, scan_type, vulnerabilities_found, 
                       critical_count, high_count, medium_count, low_count, scanned_at
                FROM dependency_scans 
                {$where}
                ORDER BY scanned_at DESC 
                LIMIT {$limit}
            ");
            $stmt->execute($params);
            
            return Response::success([
                'history' => $stmt->fetchAll(),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Ingest scan results from security-scan.sh (API key auth)
     */
    public function ingest(Request $request): Response
    {
        // Validate API key (timing-safe comparison)
        $apiKey = $request->getHeader('X-Api-Key') ?? $request->getQuery('api_key');
        $validKeys = $this->container->getConfig('external_api.keys') ?? [];
        
        // Also accept a dedicated scan key if configured
        $scanKey = $this->container->getConfig('external_api.scan_key');
        if ($scanKey) {
            $validKeys['scan'] = $scanKey;
        }
        
        $keyValid = false;
        if ($apiKey) {
            foreach ($validKeys as $name => $validKey) {
                if (hash_equals((string) $validKey, (string) $apiKey)) {
                    $keyValid = true;
                    break;
                }
            }
        }
        if (!$keyValid) {
            return Response::unauthorized('Invalid or missing API key');
        }
        
        $body = $request->getBody();
        
        // Accept single scan or batch
        $scans = [];
        if (isset($body['scans']) && is_array($body['scans'])) {
            $scans = $body['scans'];
        } elseif (isset($body['source_app']) && isset($body['scan_type'])) {
            $scans = [$body];
        } else {
            return Response::error('Invalid payload', 400);
        }
        
        try {
            $db = $this->container->getDatabase();
            $inserted = 0;
            
            $stmt = $db->prepare("
                INSERT INTO dependency_scans 
                (source_app, scan_type, vulnerabilities_found, critical_count, high_count, medium_count, low_count, results)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($scans as $scan) {
                if (empty($scan['source_app']) || empty($scan['scan_type'])) {
                    continue;
                }
                
                $stmt->execute([
                    $scan['source_app'],
                    $scan['scan_type'],
                    (int)($scan['vulnerabilities_found'] ?? 0),
                    (int)($scan['critical_count'] ?? 0),
                    (int)($scan['high_count'] ?? 0),
                    (int)($scan['medium_count'] ?? 0),
                    (int)($scan['low_count'] ?? 0),
                    json_encode($scan['results'] ?? []),
                ]);
                $inserted++;
            }
            
            // Log if critical vulnerabilities found
            $totalCritical = array_sum(array_column($scans, 'critical_count'));
            $totalHigh = array_sum(array_column($scans, 'high_count'));
            
            if ($totalCritical > 0 || $totalHigh > 0) {
                $this->audit->logEvent([
                    'source_app' => 'panel',
                    'severity' => $totalCritical > 0 ? 'critical' : 'high',
                    'action' => 'dependency_scan_vulnerabilities',
                    'actor' => 'system',
                    'target' => 'dependencies',
                    'outcome' => 'success',
                    'details' => [
                        'critical' => $totalCritical,
                        'high' => $totalHigh,
                        'scans_count' => $inserted,
                    ],
                ]);
            }
            
            return Response::success(['inserted' => $inserted], "Stored {$inserted} scan results");
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }
}

