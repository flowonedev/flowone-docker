<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

class SslController extends BaseController
{
    /**
     * List all SSL certificates - cached for 5 minutes
     */
    public function index(Request $request): Response
    {
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = 'ssl:list';

        if ($forceRefresh) {
            $this->cache->delete($cacheKey);
        }

        $data = $this->cache->remember($cacheKey, 300, function() {
            $result = $this->agent->execute('ssl.list');
            return $result['success'] ? $result['data'] : null;
        });

        if ($data === null) {
            return Response::error('Failed to fetch SSL certificates');
        }

        return Response::success($data);
    }

    /**
     * Get certificate details
     */
    public function show(Request $request): Response
    {
        $domain = $request->getParam('domain');
        return $this->agentAction('ssl.inspect', ['domain' => $domain]);
    }

    /**
     * Run preflight checks
     */
    public function preflight(Request $request): Response
    {
        $domain = $request->getParam('domain');
        return $this->agentAction('ssl.preflight', ['domain' => $domain]);
    }

    /**
     * Run comprehensive DNS test
     */
    public function dnsTest(Request $request): Response
    {
        $domain = $request->getParam('domain');
        return $this->agentAction('ssl.dnsTest', ['domain' => $domain]);
    }

    /**
     * Test SSL certificate connectivity for all domains (main, www, mail)
     */
    public function testCertificate(Request $request): Response
    {
        $domain = $request->getParam('domain');
        return $this->agentAction('ssl.testCertificate', ['domain' => $domain]);
    }

    /**
     * Issue SSL certificate
     */
    public function issue(Request $request): Response
    {
        set_time_limit(120);

        $domain = $request->getParam('domain');
        $email = $request->input('email');
        $force = $request->input('force', false);

        $params = ['domain' => $domain];
        if ($email) {
            $params['email'] = $email;
        }
        if ($force) {
            $params['force'] = true;
        }

        $result = $this->agent->execute('ssl.issue', $params, $this->getActor(), 90);
        
        if ($result['success']) {
            $this->cache->delete('ssl:list'); // Invalidate SSL cache
            $this->cache->delete('sites:list'); // Invalidate sites cache so SSL status updates
            $this->logAction('ssl.issue', $domain, 'success');
            return Response::success($result['data'], $result['message'] ?? "SSL issued for {$domain}");
        }
        
        $this->logAction('ssl.issue', $domain, 'failed', ['error' => $result['error']]);
        
        // Build detailed error message for preflight failures
        $errorMessage = $result['error'] ?? 'Failed to issue SSL';
        $preflightData = $result['data'] ?? null;
        
        if ($preflightData && isset($preflightData['issues']) && !empty($preflightData['issues'])) {
            $issues = $preflightData['issues'];
            $errorMessage = 'SSL preflight checks failed: ' . implode('; ', $issues);
        }
        
        // Return error with preflight data for frontend to display
        return Response::json([
            'success' => false,
            'error' => $errorMessage,
            'preflight' => $preflightData,
        ], 400);
    }

    /**
     * Renew certificates
     */
    public function renew(Request $request): Response
    {
        set_time_limit(120);

        $domain = $request->input('domain');

        $params = [];
        if ($domain) {
            $params['domain'] = $domain;
        }

        $result = $this->agent->execute('ssl.renew', $params, $this->getActor(), 90);
        
        if ($result['success']) {
            $this->cache->delete('ssl:list'); // Invalidate SSL cache
            $this->cache->delete('sites:list'); // Invalidate sites cache so SSL status updates
            $target = $domain ?? 'all';
            $this->logAction('ssl.renew', $target, 'success');
            return Response::success($result['data'], $result['message'] ?? 'Certificates renewed');
        }
        
        $this->logAction('ssl.renew', $domain ?? 'all', 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to renew certificates');
    }

    /**
     * Delete certificate (works for both certbot and self-signed)
     */
    public function cleanup(Request $request): Response
    {
        $domain = $request->getParam('domain');

        // Use ssl.delete which handles both certbot and self-signed certs
        $result = $this->agent->execute('ssl.delete', ['domain' => $domain], $this->getActor());
        
        if ($result['success']) {
            $this->cache->delete('ssl:list'); // Invalidate SSL cache
            $this->cache->delete('sites:list'); // Invalidate sites cache so SSL status updates
            $this->logAction('ssl.delete', $domain, 'success');
            return Response::success($result['data'], $result['message'] ?? "Certificate deleted for {$domain}");
        }
        
        $this->logAction('ssl.delete', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to delete certificate');
    }

    /**
     * Check SSL health - detect issues
     */
    public function health(Request $request): Response
    {
        $result = $this->agent->execute('ssl.health', [], $this->getActor());
        
        if ($result['success']) {
            return Response::success($result['data'], $result['message']);
        }
        
        return Response::error($result['error'] ?? 'Failed to check SSL health');
    }

    /**
     * Fix SSL health issues
     */
    public function fixHealth(Request $request): Response
    {
        set_time_limit(120);

        $issueId = $request->input('issue_id');
        $fixAll = $request->input('fix_all', false);

        $params = [];
        if ($issueId) {
            $params['issue_id'] = $issueId;
        }
        if ($fixAll) {
            $params['fix_all'] = true;
        }

        $result = $this->agent->execute('ssl.fixHealth', $params, $this->getActor(), 90);
        
        if ($result['success']) {
            $this->cache->delete('ssl:list'); // Invalidate SSL cache
            $this->cache->delete('sites:list'); // Invalidate sites cache so SSL status updates
            $this->logAction('ssl.fixHealth', $issueId ?? 'all', 'success', $result['data']);
            return Response::success($result['data'], $result['message']);
        }
        
        $this->logAction('ssl.fixHealth', $issueId ?? 'all', 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to fix SSL issues');
    }

    /**
     * Run comprehensive SSL/TLS security check - results saved to database
     * Uses testssl.sh to analyze protocols, ciphers, vulnerabilities
     */
    public function comprehensiveCheck(Request $request): Response
    {
        set_time_limit(120);

        $domain = $request->getParam('domain');
        $forceRefresh = $request->getQuery('refresh') === '1';
        $cacheKey = "ssl_comprehensive:{$domain}";

        // If not forcing refresh, check cache first
        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $cached['from_cache'] = true;
                return Response::success($cached, "SSL analysis for {$domain} (cached)");
            }
            
            // Check database for saved results
            $saved = $this->getSavedSslCheck($domain);
            if ($saved) {
                // Put back in cache
                $this->cache->set($cacheKey, $saved, 21600);
                $saved['from_database'] = true;
                return Response::success($saved, "SSL analysis for {$domain} (saved)");
            }
        } else {
            $this->cache->delete($cacheKey);
        }

        // Run comprehensive check via agent
        // Note: This can take 30-60 seconds, use 90 second timeout
        $result = $this->agent->execute('ssl.comprehensiveCheck', ['domain' => $domain], $this->getActor(), 90);

        if ($result['success']) {
            $data = $result['data'];
            
            // Save to database (upsert)
            $this->saveSslCheck($domain, $data);
            
            // Cache for 6 hours
            $this->cache->set($cacheKey, $data, 21600);
            
            $this->logAction('ssl.comprehensiveCheck', $domain, 'success', [
                'grade' => $data['grade'] ?? 'N/A',
                'score' => $data['score'] ?? 0,
            ]);
            return Response::success($data, $result['message'] ?? "SSL analysis complete for {$domain}");
        }

        $this->logAction('ssl.comprehensiveCheck', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'SSL comprehensive check failed');
    }

    /**
     * Get saved SSL check result for a domain (without running new scan)
     */
    public function getSavedCheck(Request $request): Response
    {
        $domain = $request->getParam('domain');
        
        // Check cache first
        $cacheKey = "ssl_comprehensive:{$domain}";
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $cached['from_cache'] = true;
            return Response::success($cached);
        }
        
        // Check database
        $saved = $this->getSavedSslCheck($domain);
        if ($saved) {
            // Put back in cache
            $this->cache->set($cacheKey, $saved, 21600);
            $saved['from_database'] = true;
            return Response::success($saved);
        }
        
        return Response::success(null, 'No saved SSL check found');
    }

    /**
     * Save SSL check results to database
     */
    private function saveSslCheck(string $domain, array $data): bool
    {
        try {
            $db = $this->container->getDatabase();
            
            $sql = "INSERT INTO ssl_check_results 
                    (domain, grade, score, protocols, ciphers, vulnerabilities, certificate, security_headers, deductions, scan_duration, scanned_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    grade = VALUES(grade),
                    score = VALUES(score),
                    protocols = VALUES(protocols),
                    ciphers = VALUES(ciphers),
                    vulnerabilities = VALUES(vulnerabilities),
                    certificate = VALUES(certificate),
                    security_headers = VALUES(security_headers),
                    deductions = VALUES(deductions),
                    scan_duration = VALUES(scan_duration),
                    scanned_at = VALUES(scanned_at),
                    updated_at = CURRENT_TIMESTAMP";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $domain,
                $data['grade'] ?? 'N/A',
                $data['score'] ?? 0,
                json_encode($data['protocols'] ?? []),
                json_encode($data['ciphers'] ?? []),
                json_encode($data['vulnerabilities'] ?? []),
                json_encode($data['certificate'] ?? []),
                json_encode($data['security_headers'] ?? []),
                json_encode($data['deductions'] ?? []),
                $data['scan_duration'] ?? 0,
                $data['scanned_at'] ?? date('Y-m-d H:i:s'),
            ]);
            
            return true;
        } catch (\Exception $e) {
            debug_log("Failed to save SSL check: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Fix SSL configuration issues (TLS settings, ciphers, security headers)
     */
    public function fixSslConfig(Request $request): Response
    {
        $domain = $request->getParam('domain');

        $result = $this->agent->execute('ssl.fixSslConfig', ['domain' => $domain], $this->getActor());

        if ($result['success']) {
            // Invalidate SSL check cache so next scan shows updated results
            $this->cache->delete("ssl_comprehensive:{$domain}");
            
            $this->logAction('ssl.fixSslConfig', $domain, 'success', $result['data']);
            return Response::success($result['data'], $result['message'] ?? 'SSL configuration fixed');
        }

        $this->logAction('ssl.fixSslConfig', $domain, 'failed', ['error' => $result['error']]);
        return Response::error($result['error'] ?? 'Failed to fix SSL configuration');
    }

    /**
     * Get saved SSL check from database
     */
    private function getSavedSslCheck(string $domain): ?array
    {
        try {
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM ssl_check_results WHERE domain = ?");
            $stmt->execute([$domain]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$row) {
                return null;
            }
            
            return [
                'domain' => $row['domain'],
                'grade' => $row['grade'],
                'score' => (int) $row['score'],
                'protocols' => json_decode($row['protocols'], true) ?: [],
                'ciphers' => json_decode($row['ciphers'], true) ?: [],
                'vulnerabilities' => json_decode($row['vulnerabilities'], true) ?: [],
                'certificate' => json_decode($row['certificate'], true) ?: [],
                'security_headers' => json_decode($row['security_headers'], true) ?: [],
                'deductions' => json_decode($row['deductions'], true) ?: [],
                'scan_duration' => (float) $row['scan_duration'],
                'scanned_at' => $row['scanned_at'],
            ];
        } catch (\Exception $e) {
            debug_log("Failed to get saved SSL check: " . $e->getMessage());
            return null;
        }
    }
}

