<?php
/**
 * SSL Certificate Action Handler
 * 
 * Manages SSL certificates for virtual hosts.
 * Handles inspection, issuance, and cleanup.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class SslAction extends BaseAction
{
    public function getNamespace(): string
    {
        return 'ssl';
    }

    public function getMethods(): array
    {
        return ['list', 'inspect', 'issue', 'renew', 'cleanup', 'delete', 'preflight', 'health', 'fixHealth', 'dnsTest', 'testCertificate', 'comprehensiveCheck', 'fixSslConfig'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['issue', 'cleanup', 'fixSslConfig']);
    }

    /**
     * List all SSL certificates
     */
    protected function actionList(array $params, string $actor): array
    {
        $certPath = $this->config['paths']['ssl_certs'];
        $certs = [];

        if (!is_dir($certPath)) {
            return $this->success(['certificates' => []]);
        }

        $domains = glob($certPath . '/*', GLOB_ONLYDIR);
        
        foreach ($domains as $domainPath) {
            $domain = basename($domainPath);
            $certFile = $domainPath . '/fullchain.pem';
            
            if (file_exists($certFile)) {
                $certs[] = $this->parseCertificate($domain, $certFile);
            }
        }

        return $this->success(['certificates' => $certs]);
    }

    /**
     * Inspect a specific certificate
     */
    protected function actionInspect(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $certFile = $this->config['paths']['ssl_certs'] . '/' . $domain . '/fullchain.pem';
        
        if (!file_exists($certFile)) {
            return $this->error("Certificate not found for: {$domain}");
        }

        $cert = $this->parseCertificate($domain, $certFile, true);

        return $this->success(['certificate' => $cert]);
    }

    /**
     * Run preflight checks before issuing certificate
     */
    protected function actionPreflight(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $checks = [
            'domain_valid' => true,
            'dns_resolves' => false,
            'webroot_exists' => false,
            'webroot_writable' => false,
            'acme_accessible' => false,
            'port_80_open' => false,
        ];

        $issues = [];

        // Check DNS resolution (try multiple methods)
        $ip = $this->resolveDomain($domain);
        if ($ip) {
            $checks['dns_resolves'] = true;
            $checks['resolved_ip'] = $ip;
        } else {
            $issues[] = "DNS does not resolve for {$domain}";
        }

        // Check webroot
        $webroot = $this->config['paths']['webroot'] . '/' . $domain . '/public_html';
        if (is_dir($webroot)) {
            $checks['webroot_exists'] = true;
            if (is_writable($webroot)) {
                $checks['webroot_writable'] = true;
            } else {
                $issues[] = "Webroot is not writable: {$webroot}";
            }
        } else {
            $issues[] = "Webroot does not exist: {$webroot}";
        }

        // Ensure ACME challenge directory exists
        $acmePath = $webroot . '/.well-known/acme-challenge';
        if (!is_dir($acmePath)) {
            if (is_dir($webroot) && is_writable($webroot)) {
                @mkdir($acmePath, 0755, true);
            }
        }

        if (is_dir($acmePath) || (is_dir($webroot) && is_writable($webroot))) {
            $checks['acme_accessible'] = true;
        } else {
            $issues[] = "Cannot create ACME challenge directory";
        }

        // Check port 80 accessibility (use resolved IP to avoid DNS issues)
        $targetHost = $ip ?: $domain;
        $socket = @fsockopen($targetHost, 80, $errno, $errstr, 5);
        if ($socket) {
            fclose($socket);
            $checks['port_80_open'] = true;
        } else {
            $issues[] = "Port 80 is not accessible from this server";
        }

        // Test actual HTTP reachability of ACME challenge (most critical check)
        // This catches vhost misconfigurations, rewrites, and wrong document roots
        if ($checks['acme_accessible'] && $checks['port_80_open'] && $checks['dns_resolves']) {
            $acmeReachable = $this->testAcmeReachability($domain, $acmePath);
            $checks['acme_http_reachable'] = $acmeReachable;
            if (!$acmeReachable) {
                // Try to fix by ensuring vhost has the .well-known/acme-challenge context
                $fixed = $this->ensureAcmeContext($domain);
                if ($fixed) {
                    // Reload OLS and retry
                    $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
                    sleep(1);
                    $acmeReachable = $this->testAcmeReachability($domain, $acmePath);
                    $checks['acme_http_reachable'] = $acmeReachable;
                    if ($acmeReachable) {
                        $checks['acme_vhost_fixed'] = true;
                    }
                }
                if (!$acmeReachable) {
                    $issues[] = "ACME challenge files are not reachable via HTTP at http://{$domain}/.well-known/acme-challenge/ - the vhost document root may not match the webroot, or a rewrite rule is interfering";
                }
            }
        }

        $ready = empty($issues);

        return $this->success([
            'domain' => $domain,
            'ready' => $ready,
            'checks' => $checks,
            'issues' => $issues,
        ], $ready ? 'All preflight checks passed' : 'Some checks failed');
    }

    /**
     * Comprehensive DNS test for SSL certificate issuance
     * Tests DNS resolution from multiple sources to identify propagation issues
     */
    protected function actionDnsTest(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        // Get server's public IP
        $serverIp = $this->getServerPublicIp();
        
        // Domains to test
        $domainsToTest = [
            $domain => ['type' => 'main', 'required' => true],
            'www.' . $domain => ['type' => 'www', 'required' => false],
            'mail.' . $domain => ['type' => 'mail', 'required' => false],
        ];

        $results = [];
        $summary = [
            'server_ip' => $serverIp,
            'all_domains_ready' => true,
            'main_ready' => false,
            'www_ready' => false,
            'mail_ready' => false,
            'issues' => [],
            'warnings' => [],
        ];

        foreach ($domainsToTest as $testDomain => $info) {
            $result = $this->testDomainDns($testDomain, $serverIp);
            $results[$testDomain] = $result;

            $isReady = $result['ready'];
            $summary[$info['type'] . '_ready'] = $isReady;

            if ($info['required'] && !$isReady) {
                $summary['all_domains_ready'] = false;
                $summary['issues'][] = "Main domain {$testDomain} is not resolving correctly";
            }

            // Check for potential issues
            if (!$isReady && $result['authoritative'] && !$result['external']) {
                $summary['warnings'][] = "{$testDomain}: Authoritative NS has the record but external DNS doesn't see it yet (negative caching - wait ~1 hour)";
            }

            if ($result['local'] && $result['resolved_ip'] !== $serverIp) {
                $summary['warnings'][] = "{$testDomain}: Resolves to {$result['resolved_ip']} but server IP is {$serverIp}";
            }
        }

        // Determine which domains will be included in cert
        $willInclude = [];
        $willSkip = [];
        foreach ($domainsToTest as $testDomain => $info) {
            if ($results[$testDomain]['ready']) {
                $willInclude[] = $testDomain;
            } else {
                $willSkip[] = [
                    'domain' => $testDomain,
                    'reason' => $results[$testDomain]['failure_reason'] ?? 'DNS not resolving',
                ];
            }
        }

        return $this->success([
            'domain' => $domain,
            'server_ip' => $serverIp,
            'summary' => $summary,
            'domains' => $results,
            'will_include' => $willInclude,
            'will_skip' => $willSkip,
            'ready_to_issue' => $summary['main_ready'],
        ], $summary['main_ready'] ? 'DNS checks passed - ready to issue certificate' : 'DNS checks failed');
    }

    /**
     * Test SSL certificate by making HTTPS requests to all covered domains
     */
    protected function actionTestCertificate(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        // First get the certificate SANs to know what domains to test
        $certFile = $this->config['paths']['ssl_certs'] . '/' . $domain . '/fullchain.pem';
        if (!file_exists($certFile)) {
            return $this->error('No SSL certificate found for this domain');
        }

        $certInfo = $this->parseCertificate($domain, $certFile);
        $domainsToTest = $certInfo['sans'] ?? [$domain];

        $results = [];
        $allValid = true;

        foreach ($domainsToTest as $testDomain) {
            $testResult = $this->testHttps($testDomain);
            $results[$testDomain] = $testResult;
            
            if (!$testResult['valid']) {
                $allValid = false;
            }
        }

        return $this->success([
            'domain' => $domain,
            'certificate' => [
                'issuer' => $certInfo['issuer'] ?? 'Unknown',
                'valid_to' => $certInfo['valid_to'] ?? null,
                'days_remaining' => $certInfo['days_remaining'] ?? null,
            ],
            'domains' => $results,
            'all_valid' => $allValid,
            'domains_tested' => count($domainsToTest),
            'domains_passed' => count(array_filter($results, fn($r) => $r['valid'])),
        ], $allValid ? 'All domains have valid SSL' : 'Some domains failed SSL test');
    }

    /**
     * Test HTTPS connectivity for a single domain
     */
    private function testHttps(string $domain): array
    {
        $result = [
            'valid' => false,
            'http_code' => null,
            'ssl_verify' => null,
            'error' => null,
        ];

        // Use curl to test HTTPS
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$domain}/",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOBODY => true, // HEAD request
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sslVerify = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
        $error = curl_error($ch);
        curl_close($ch);

        $result['http_code'] = $httpCode;
        $result['ssl_verify'] = $sslVerify;

        // SSL is valid if: verify result is 0 AND we got an HTTP response
        if ($sslVerify === 0 && $httpCode > 0) {
            $result['valid'] = true;
        } else {
            $result['error'] = $error ?: ($sslVerify !== 0 ? 'SSL verification failed' : 'Connection failed');
        }

        return $result;
    }

    /**
     * Test DNS resolution for a single domain from multiple sources
     */
    private function testDomainDns(string $domain, string $serverIp): array
    {
        $result = [
            'domain' => $domain,
            'local' => false,
            'local_ip' => null,
            'authoritative' => false,
            'authoritative_ip' => null,
            'authoritative_ns' => null,
            'external' => false,
            'external_ip' => null,
            'resolved_ip' => null,
            'matches_server' => false,
            'ready' => false,
            'failure_reason' => null,
        ];

        // 1. Test local resolver (what the server sees)
        $localIp = @gethostbyname($domain);
        if ($localIp !== $domain) {
            $result['local'] = true;
            $result['local_ip'] = $localIp;
            $result['resolved_ip'] = $localIp;
        }

        // 2. Find and test authoritative nameservers (try all of them)
        $authNameservers = $this->getAuthoritativeNameservers($domain);
        foreach ($authNameservers as $authNs) {
            $result['authoritative_ns'] = $authNs;
            $authIp = $this->digQuery($domain, $authNs);
            if ($authIp) {
                $result['authoritative'] = true;
                $result['authoritative_ip'] = $authIp;
                if (!$result['resolved_ip']) {
                    $result['resolved_ip'] = $authIp;
                }
                break; // Found a working NS, stop trying
            }
        }

        // 3. Test external DNS (Google)
        $externalIp = $this->digQuery($domain, '8.8.8.8');
        if ($externalIp) {
            $result['external'] = true;
            $result['external_ip'] = $externalIp;
        }

        // Determine if ready for SSL
        // Must resolve AND point to this server
        if ($result['resolved_ip']) {
            $result['matches_server'] = ($result['resolved_ip'] === $serverIp);
            
            if ($result['matches_server']) {
                // Ready if ANY resolution method works (local, authoritative, or external)
                // Let's Encrypt will be able to verify if it resolves from anywhere
                $result['ready'] = $result['local'] || $result['authoritative'] || $result['external'];
                
                // Add warning if only local works (might have propagation issues)
                if ($result['local'] && !$result['authoritative'] && !$result['external']) {
                    $result['failure_reason'] = 'Only resolves locally - may have external DNS issues';
                    $result['ready'] = true; // Still try, local resolution is good enough
                }
            } else {
                $result['failure_reason'] = "Resolves to {$result['resolved_ip']} instead of server IP {$serverIp}";
            }
        } else {
            $result['failure_reason'] = 'DNS does not resolve';
        }

        return $result;
    }

    /**
     * Get all authoritative nameservers for a domain
     */
    private function getAuthoritativeNameservers(string $domain): array
    {
        // Try to find NS records for the domain or parent
        $parts = explode('.', $domain);
        
        while (count($parts) > 1) {
            $checkDomain = implode('.', $parts);
            $output = [];
            exec("dig +short NS {$checkDomain} 2>/dev/null", $output);
            
            if (!empty($output)) {
                // Return all NS records
                return array_map(fn($ns) => rtrim($ns, '.'), $output);
            }
            
            array_shift($parts);
        }
        
        return [];
    }

    /**
     * Get authoritative nameserver for a domain (first one that works)
     */
    private function getAuthoritativeNameserver(string $domain): ?string
    {
        $nameservers = $this->getAuthoritativeNameservers($domain);
        return $nameservers[0] ?? null;
    }

    /**
     * Query DNS using dig with specific nameserver
     */
    private function digQuery(string $domain, string $nameserver): ?string
    {
        $output = [];
        exec("dig +short @{$nameserver} {$domain} 2>/dev/null", $output);
        
        if (empty($output)) {
            return null;
        }

        // Follow CNAME chain if needed (up to 5 levels)
        $result = trim($output[0]);
        $maxCnameDepth = 5;
        $depth = 0;
        
        while (!filter_var($result, FILTER_VALIDATE_IP) && $depth < $maxCnameDepth) {
            $depth++;
            $cnameTarget = rtrim($result, '.');
            
            // Try to resolve the CNAME target using same nameserver
            $cnameOutput = [];
            exec("dig +short @{$nameserver} {$cnameTarget} 2>/dev/null", $cnameOutput);
            
            if (!empty($cnameOutput)) {
                $result = trim($cnameOutput[0]);
            } else {
                // Nameserver doesn't have the target, try local resolver
                $localIp = @gethostbyname($cnameTarget);
                if ($localIp !== $cnameTarget && filter_var($localIp, FILTER_VALIDATE_IP)) {
                    return $localIp;
                }
                return null;
            }
        }

        return filter_var($result, FILTER_VALIDATE_IP) ? $result : null;
    }

    /**
     * Resolve domain using multiple methods (handles negative caching issues)
     * Tries: local resolver, authoritative NS, external DNS
     */
    private function resolveDomain(string $domain): ?string
    {
        // Method 1: Local resolver (gethostbyname)
        $ip = @gethostbyname($domain);
        if ($ip !== $domain && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        // Method 2: Try 'host' command (sometimes uses different resolver)
        $output = [];
        exec("host {$domain} 2>/dev/null | grep 'has address' | head -1 | awk '{print \$NF}'", $output);
        if (!empty($output) && filter_var(trim($output[0]), FILTER_VALIDATE_IP)) {
            return trim($output[0]);
        }

        // Method 3: Query authoritative nameserver directly
        $authNs = $this->getAuthoritativeNameserver($domain);
        if ($authNs) {
            $authIp = $this->digQuery($domain, $authNs);
            if ($authIp) {
                return $authIp;
            }
        }

        // Method 4: Try multiple external DNS servers
        $externalDns = ['8.8.8.8', '1.1.1.1', '9.9.9.9'];
        foreach ($externalDns as $dns) {
            $extIp = $this->digQuery($domain, $dns);
            if ($extIp) {
                return $extIp;
            }
        }

        return null;
    }

    /**
     * Test if ACME challenge files are reachable via HTTP for a given domain.
     * Creates a temporary test file in the ACME directory and tries to fetch it.
     * This catches cases where DNS resolves but the webroot isn't served (e.g. mail subdomain
     * vhost not mapping to the same document root, or rewrites blocking .well-known).
     */
    private function testAcmeReachability(string $domain, string $acmeDir): bool
    {
        $token = 'vps-admin-acme-test-' . bin2hex(random_bytes(8));
        $testFile = $acmeDir . '/' . $token;
        $testContent = 'acme-reachability-' . time();

        // Write the test file
        if (@file_put_contents($testFile, $testContent) === false) {
            return false;
        }

        // Try to fetch it via HTTP (same way Let's Encrypt would)
        $url = "http://{$domain}/.well-known/acme-challenge/{$token}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'VPS-Admin-ACME-Test/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Clean up test file
        @unlink($testFile);

        $reachable = ($httpCode === 200 && trim($response) === $testContent);

        if (!$reachable) {
            $this->logger->info("ACME reachability test failed for {$domain}: HTTP {$httpCode}, will skip from certificate");
        }

        return $reachable;
    }

    /**
     * Ensure the OLS vhost config has a .well-known/acme-challenge context
     * that serves files from the correct webroot path (not $DOC_ROOT which may differ).
     * This fixes 404 errors when Let's Encrypt tries to validate the domain.
     */
    private function ensureAcmeContext(string $domain): bool
    {
        $vhostPath = $this->config['paths']['ols_vhosts'] ?? '/usr/local/lsws/conf/vhosts';
        $configFile = $vhostPath . '/' . $domain . '/vhost.conf';

        if (!file_exists($configFile)) {
            $configFile = $vhostPath . '/' . $domain . '/vhconf.conf';
        }

        if (!file_exists($configFile)) {
            $this->logger->info("ensureAcmeContext: No vhost config found for {$domain}");
            return false;
        }

        $config = file_get_contents($configFile);
        $webroot = $this->config['paths']['webroot'] . '/' . $domain . '/public_html';
        $acmeAbsolutePath = $webroot . '/.well-known/acme-challenge';
        $changed = false;

        // Skip if the webroot doesn't exist (domain may have been removed)
        if (!is_dir($webroot)) {
            $this->logger->info("ensureAcmeContext: Webroot {$webroot} does not exist for {$domain}, skipping");
            return false;
        }

        if (strpos($config, '.well-known/acme-challenge') !== false) {
            // Context exists - check if it uses $DOC_ROOT (which may point to wrong dir)
            // Replace $DOC_ROOT/.well-known/acme-challenge with the absolute webroot path
            if (strpos($config, '$DOC_ROOT/.well-known/acme-challenge') !== false) {
                $config = str_replace(
                    '$DOC_ROOT/.well-known/acme-challenge',
                    $acmeAbsolutePath,
                    $config
                );
                $changed = true;
                $this->logger->info("ensureAcmeContext: Fixed ACME context location from \$DOC_ROOT to {$acmeAbsolutePath} for {$domain}");
            } elseif (strpos($config, $acmeAbsolutePath) !== false) {
                // Already pointing to correct absolute path
                return false;
            }
        } else {
            // No ACME context at all - add one with absolute path
            // Ensure the ACME directory exists first
            if (!is_dir($acmeAbsolutePath)) {
                @mkdir($acmeAbsolutePath, 0755, true);
            }

            $acmeContext = <<<ACME

context /.well-known/acme-challenge {
  location                {$acmeAbsolutePath}
  allowBrowse             1

  rewrite  {
    enable                0
  }
  addDefaultCharset       off

  phpIniOverride  {
  }
}
ACME;
            $config = rtrim($config) . "\n" . $acmeContext . "\n";
            $changed = true;
            $this->logger->info("ensureAcmeContext: Added ACME context with absolute path for {$domain}");
        }

        if (!$changed) {
            return false;
        }

        // Get original file ownership and permissions
        $origStat = stat($configFile);
        $origOwner = $origStat['uid'];
        $origGroup = $origStat['gid'];
        $origPerms = $origStat['mode'] & 0777;

        if (file_put_contents($configFile, $config) === false) {
            $this->logger->info("ensureAcmeContext: Failed to write vhost config for {$domain}");
            return false;
        }

        chown($configFile, $origOwner);
        chgrp($configFile, $origGroup);
        chmod($configFile, $origPerms);

        return true;
    }

    /**
     * Ensure all domains with Let's Encrypt certs have proper ACME contexts in their vhost configs.
     * Called before "renew all" to prevent failures across multiple domains.
     */
    private function ensureAllAcmeContexts(): void
    {
        $certPath = $this->config['paths']['ssl_certs'];
        if (!is_dir($certPath)) {
            return;
        }

        $fixed = 0;
        $domains = glob($certPath . '/*', GLOB_ONLYDIR);

        foreach ($domains as $domainPath) {
            $domain = basename($domainPath);
            if ($this->ensureAcmeContext($domain)) {
                $fixed++;
            }
        }

        if ($fixed > 0) {
            $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
            sleep(1);
            $this->logger->info("ensureAllAcmeContexts: Fixed ACME context for {$fixed} domain(s)");
        }
    }

    /**
     * Get the server's public IP address
     */
    private function getServerPublicIp(): string
    {
        // Try multiple methods to get public IP
        
        // Method 1: Query external service
        $ip = @file_get_contents('http://ipv4.icanhazip.com', false, stream_context_create([
            'http' => ['timeout' => 5]
        ]));
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            return trim($ip);
        }

        // Method 2: Try another service
        $ip = @file_get_contents('http://api.ipify.org', false, stream_context_create([
            'http' => ['timeout' => 5]
        ]));
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            return trim($ip);
        }

        // Method 3: Use hostname -I
        $output = [];
        exec('hostname -I | awk \'{print $1}\'', $output);
        if (!empty($output) && filter_var(trim($output[0]), FILTER_VALIDATE_IP)) {
            return trim($output[0]);
        }

        return 'unknown';
    }

    /**
     * Issue a new Let's Encrypt certificate
     */
    protected function actionIssue(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        // Run preflight first
        $preflight = $this->actionPreflight($params, $actor);
        if (!$preflight['data']['ready']) {
            return $this->error('Preflight checks failed', $preflight['data']);
        }

        $webroot = $this->config['paths']['webroot'] . '/' . $domain . '/public_html';
        $email = $params['email'] ?? 'admin@' . $domain;
        $force = !empty($params['force']);
        $includeMail = $params['include_mail'] ?? true; // Include mail subdomain by default
        $skipWww = !empty($params['skip_www']);

        // If force renewal, clean up any broken/stale certificate configs first
        if ($force) {
            $this->cleanupStaleCertificateConfigs($domain);
        }

        // Ensure ACME challenge directory exists and is writable
        $acmeDir = $webroot . '/.well-known/acme-challenge';
        if (!is_dir($acmeDir)) {
            mkdir($acmeDir, 0755, true);
        }

        // Build list of domains to include
        $domains = [$domain];
        $skippedDomains = [];
        
        // Add www subdomain if not skipped
        if (!$skipWww) {
            $wwwDomain = 'www.' . $domain;
            $wwwIp = $this->resolveDomain($wwwDomain);
            if ($wwwIp) {
                // Verify ACME challenge is actually reachable via HTTP
                if ($this->testAcmeReachability($wwwDomain, $acmeDir)) {
                    $domains[] = $wwwDomain;
                } else {
                    $skippedDomains[] = $wwwDomain . ' (ACME challenge not reachable via HTTP)';
                }
            } else {
                $skippedDomains[] = $wwwDomain . ' (DNS not resolved)';
            }
        }
        
        // Add mail subdomain if requested and resolves
        if ($includeMail) {
            $mailDomain = 'mail.' . $domain;
            $mailIp = $this->resolveDomain($mailDomain);
            if ($mailIp) {
                // Verify ACME challenge is actually reachable via HTTP
                if ($this->testAcmeReachability($mailDomain, $acmeDir)) {
                    $domains[] = $mailDomain;
                } else {
                    $skippedDomains[] = $mailDomain . ' (ACME challenge not reachable via HTTP)';
                }
            } else {
                $skippedDomains[] = $mailDomain . ' (DNS not resolved)';
            }
        }

        // Issue certificate using certbot
        $args = [
            'certonly',
            '--webroot',
            '-w', $webroot,
        ];
        
        // Add all domains
        foreach ($domains as $d) {
            $args[] = '-d';
            $args[] = $d;
        }
        
        $args = array_merge($args, [
            '--email', $email,
            '--agree-tos',
            '--non-interactive',
            '--expand',
        ]);

        // Add --force-renewal if user wants to re-issue existing cert
        if ($force) {
            $args[] = '--force-renewal';
        }

        $result = $this->execCommand('certbot', $args);

        if ($result['success']) {
            // Check if certbot created a -0001 version and consolidate
            $consolidated = $this->consolidateCertificate($domain);
            
            // Add SSL block to vhost config if not already present
            $sslAdded = $this->addSslToVhostConfig($domain);
            
            // Reload OpenLiteSpeed to apply SSL config
            $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);

            $certFile = $this->config['paths']['ssl_certs'] . '/' . $domain . '/fullchain.pem';
            $cert = file_exists($certFile) ? $this->parseCertificate($domain, $certFile) : null;

            $message = "SSL certificate issued for: " . implode(', ', $domains);
            if (!empty($skippedDomains)) {
                $message .= ". Skipped: " . implode(', ', $skippedDomains);
            }
            if ($consolidated) {
                $message .= " (consolidated from duplicate)";
            }
            if ($sslAdded) {
                $message .= " Vhost config updated.";
            }

            return $this->success([
                'domain' => $domain,
                'domains_included' => $domains,
                'domains_skipped' => $skippedDomains,
                'certificate' => $cert,
                'output' => $result['output'],
                'consolidated' => $consolidated,
                'vhost_updated' => $sslAdded,
            ], $message);
        }

        return $this->error("Failed to issue certificate: " . $result['output']);
    }

    /**
     * Clean up stale/broken certificate configs before renewal
     * This prevents certbot from creating -0001 duplicates
     */
    private function cleanupStaleCertificateConfigs(string $domain): void
    {
        $renewalConfig = '/etc/letsencrypt/renewal/' . $domain . '.conf';
        $livePath = $this->config['paths']['ssl_certs'] . '/' . $domain;
        $archivePath = '/etc/letsencrypt/archive/' . $domain;
        
        // Check if renewal config exists but references broken paths
        if (file_exists($renewalConfig)) {
            $configContent = file_get_contents($renewalConfig);
            
            // Check if the referenced cert files actually exist
            if (preg_match('/cert\s*=\s*(.+)/', $configContent, $matches)) {
                $certPath = trim($matches[1]);
                if (!file_exists($certPath)) {
                    // Config is broken, remove it
                    @unlink($renewalConfig);
                }
            }
        }
        
        // Check if live path is a broken symlink
        if (is_link($livePath) && !file_exists($livePath)) {
            @unlink($livePath);
        }
        
        // If live directory exists but is empty or has no valid cert
        if (is_dir($livePath) && !is_link($livePath)) {
            $certFile = $livePath . '/fullchain.pem';
            if (!file_exists($certFile)) {
                // Remove empty/broken directory
                $this->execCommand('rm', ['-rf', $livePath]);
            }
        }
    }

    /**
     * Consolidate certificate if certbot created a -0001 version
     * Returns true if consolidation was performed
     */
    private function consolidateCertificate(string $domain): bool
    {
        $sslLive = $this->config['paths']['ssl_certs'];
        $basePath = $sslLive . '/' . $domain;
        
        // Find the highest -NNNN version
        $duplicatePath = null;
        $highestNum = 0;
        
        for ($i = 1; $i <= 10; $i++) {
            $suffix = sprintf('-%04d', $i);
            $testPath = $sslLive . '/' . $domain . $suffix;
            if (is_dir($testPath)) {
                $duplicatePath = $testPath;
                $highestNum = $i;
            }
        }
        
        if (!$duplicatePath) {
            return false; // No duplicates found
        }
        
        // We have a duplicate - consolidate it
        $duplicateSuffix = sprintf('-%04d', $highestNum);
        $duplicateDomain = $domain . $duplicateSuffix;
        
        // 1. Remove old broken paths if they exist
        if (is_link($basePath)) {
            @unlink($basePath);
        } elseif (is_dir($basePath)) {
            $this->execCommand('rm', ['-rf', $basePath]);
        }
        
        // Remove old archive if exists
        $oldArchive = '/etc/letsencrypt/archive/' . $domain;
        if (is_dir($oldArchive)) {
            $this->execCommand('rm', ['-rf', $oldArchive]);
        }
        
        // Remove old renewal config if exists
        $oldRenewal = '/etc/letsencrypt/renewal/' . $domain . '.conf';
        if (file_exists($oldRenewal)) {
            @unlink($oldRenewal);
        }
        
        // 2. Rename the -0001 version to the base name
        // Rename live directory
        $this->execCommand('mv', [$duplicatePath, $basePath]);
        
        // Rename archive directory
        $duplicateArchive = '/etc/letsencrypt/archive/' . $duplicateDomain;
        $newArchive = '/etc/letsencrypt/archive/' . $domain;
        if (is_dir($duplicateArchive)) {
            $this->execCommand('mv', [$duplicateArchive, $newArchive]);
        }
        
        // 3. Update renewal config
        $duplicateRenewal = '/etc/letsencrypt/renewal/' . $duplicateDomain . '.conf';
        $newRenewal = '/etc/letsencrypt/renewal/' . $domain . '.conf';
        
        if (file_exists($duplicateRenewal)) {
            // Read and update paths in renewal config
            $configContent = file_get_contents($duplicateRenewal);
            $configContent = str_replace($duplicateDomain, $domain, $configContent);
            file_put_contents($newRenewal, $configContent);
            @unlink($duplicateRenewal);
        }
        
        // 4. Update symlinks in live directory to point to correct archive
        if (is_dir($basePath)) {
            $files = ['cert.pem', 'chain.pem', 'fullchain.pem', 'privkey.pem'];
            foreach ($files as $file) {
                $linkPath = $basePath . '/' . $file;
                if (is_link($linkPath)) {
                    $target = readlink($linkPath);
                    // Update target to use correct archive path
                    $newTarget = str_replace($duplicateDomain, $domain, $target);
                    if ($newTarget !== $target) {
                        @unlink($linkPath);
                        symlink($newTarget, $linkPath);
                    }
                }
            }
        }
        
        return true;
    }

    /**
     * Renew certificates
     */
    protected function actionRenew(array $params, string $actor): array
    {
        $domain = $params['domain'] ?? null;

        if ($domain) {
            if (!Validator::domain($domain)) {
                return $this->error('Invalid domain format');
            }

            // Pre-validate: ensure ACME challenge is reachable for this domain
            $webroot = $this->config['paths']['webroot'] . '/' . $domain . '/public_html';
            $acmeDir = $webroot . '/.well-known/acme-challenge';
            if (!is_dir($acmeDir)) {
                @mkdir($acmeDir, 0755, true);
            }

            if (!$this->testAcmeReachability($domain, $acmeDir)) {
                $this->ensureAcmeContext($domain);
                $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
                sleep(1);
                if (!$this->testAcmeReachability($domain, $acmeDir)) {
                    return $this->error(
                        "Cannot renew {$domain}: ACME challenge files are not reachable at " .
                        "http://{$domain}/.well-known/acme-challenge/ - " .
                        "the vhost document root may not serve from {$webroot}. " .
                        "Try re-issuing the certificate from the site's SSL tab instead."
                    );
                }
            }

            $args = ['renew', '--cert-name', $domain, '--non-interactive'];
        } else {
            // Renew all: ensure ACME contexts exist for all domains with certs
            $this->ensureAllAcmeContexts();
            $args = ['renew', '--non-interactive'];
        }

        $result = $this->execCommand('certbot', $args);

        if ($result['success']) {
            $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);

            return $this->success([
                'output' => $result['output'],
            ], 'Certificates renewed successfully');
        }

        return $this->error("Renewal failed: " . $result['output']);
    }

    /**
     * Clean up invalid/expired certificates (certbot managed)
     */
    protected function actionCleanup(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $certPath = $this->config['paths']['ssl_certs'] . '/' . $domain;
        
        if (!is_dir($certPath)) {
            return $this->error("Certificate not found for: {$domain}");
        }

        // Backup before deletion
        $this->backupFile($certPath . '/fullchain.pem', 'cleanup', $actor);

        // Use certbot to delete
        $result = $this->execCommand('certbot', ['delete', '--cert-name', $domain, '--non-interactive']);

        if ($result['success']) {
            return $this->success([
                'domain' => $domain,
            ], "Certificate cleaned up for {$domain}");
        }

        return $this->error("Failed to cleanup certificate: " . $result['output']);
    }

    /**
     * Delete certificate (works for both certbot and self-signed)
     */
    protected function actionDelete(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $certPath = $this->config['paths']['ssl_certs'] . '/' . $domain;
        
        if (!is_dir($certPath)) {
            return $this->error("Certificate not found for: {$domain}");
        }

        // Check if it's a certbot-managed cert by looking for renewal config
        $renewalConfig = '/etc/letsencrypt/renewal/' . $domain . '.conf';
        $isCertbot = file_exists($renewalConfig);

        // Backup before deletion
        $backupDir = $this->config['paths']['backups'] . '/deleted_certs/' . $domain . '_' . date('Y-m-d_H-i-s');
        if (!is_dir(dirname($backupDir))) {
            mkdir(dirname($backupDir), 0755, true);
        }

        // Copy cert files to backup
        $this->execCommand('cp', ['-r', $certPath, $backupDir]);

        if ($isCertbot) {
            // Use certbot to delete
            $result = $this->execCommand('certbot', ['delete', '--cert-name', $domain, '--non-interactive']);
            
            if (!$result['success']) {
                // If certbot fails, try manual deletion
                $this->deleteDirectory($certPath);
                if (file_exists($renewalConfig)) {
                    unlink($renewalConfig);
                }
            }
        } else {
            // Manual/self-signed cert - just delete the directory
            $this->deleteDirectory($certPath);
        }

        // Also check and clean archive/live symlinks if they exist
        $archivePath = '/etc/letsencrypt/archive/' . $domain;
        if (is_dir($archivePath)) {
            $this->deleteDirectory($archivePath);
        }

        // Reload OLS to apply changes
        $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);

        return $this->success([
            'domain' => $domain,
            'backup' => $backupDir,
            'was_certbot' => $isCertbot,
        ], "Certificate deleted for {$domain}");
    }

    /**
     * Recursively delete a directory
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Parse certificate details
     */
    private function parseCertificate(string $domain, string $certFile, bool $detailed = false): array
    {
        $cert = [
            'domain' => $domain,
            'valid' => false,
            'issuer' => null,
            'subject' => null,
            'sans' => [],
            'valid_from' => null,
            'valid_to' => null,
            'days_remaining' => null,
            'is_self_signed' => false,
            'is_expired' => false,
        ];

        $certData = file_get_contents($certFile);
        $parsed = openssl_x509_parse($certData);

        if (!$parsed) {
            return $cert;
        }

        $cert['valid'] = true;
        $cert['subject'] = $parsed['subject']['CN'] ?? null;
        $cert['issuer'] = $parsed['issuer']['CN'] ?? $parsed['issuer']['O'] ?? null;
        $cert['valid_from'] = date('Y-m-d H:i:s', $parsed['validFrom_time_t']);
        $cert['valid_to'] = date('Y-m-d H:i:s', $parsed['validTo_time_t']);

        // Calculate days remaining
        $now = time();
        $expiry = $parsed['validTo_time_t'];
        $cert['days_remaining'] = max(0, floor(($expiry - $now) / 86400));
        $cert['is_expired'] = $now > $expiry;

        // Check if self-signed
        $cert['is_self_signed'] = $cert['issuer'] === $cert['subject'];

        // Parse SANs
        if (isset($parsed['extensions']['subjectAltName'])) {
            $sans = explode(', ', $parsed['extensions']['subjectAltName']);
            $cert['sans'] = array_map(function ($san) {
                return str_replace('DNS:', '', $san);
            }, $sans);
        }

        // Add detailed info if requested
        if ($detailed) {
            $cert['serial'] = $parsed['serialNumberHex'] ?? null;
            $cert['signature_algorithm'] = $parsed['signatureTypeSN'] ?? null;
            $cert['fingerprint'] = openssl_x509_fingerprint($certData, 'sha256');
        }

        return $cert;
    }

    /**
     * Check SSL health - detect issues like broken symlinks, duplicates, Dovecot misconfigs
     */
    protected function actionHealth(array $params, string $actor): array
    {
        $issues = [];
        $sslLive = $this->config['paths']['ssl_certs'];
        
        // 1. Check for broken symlinks in /etc/letsencrypt/live/
        $dirs = glob($sslLive . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $domain = basename($dir);
            $fullchainPath = $dir . '/fullchain.pem';
            
            if (is_link($fullchainPath) && !file_exists($fullchainPath)) {
                $target = readlink($fullchainPath);
                $issues[] = [
                    'id' => 'broken_symlink_' . $domain,
                    'type' => 'broken_symlink',
                    'severity' => 'error',
                    'domain' => $domain,
                    'message' => "Broken SSL symlink for {$domain}",
                    'details' => "Symlink points to non-existent: {$target}",
                    'fixable' => true,
                    'fix_action' => 'fix_symlinks',
                ];
            }
        }
        
        // 2. Check for -0001 duplicates (sign of certbot issues)
        foreach ($dirs as $dir) {
            $domain = basename($dir);
            if (preg_match('/-(\d{4})$/', $domain, $matches)) {
                $baseDomain = preg_replace('/-\d{4}$/', '', $domain);
                $issues[] = [
                    'id' => 'duplicate_cert_' . $domain,
                    'type' => 'duplicate_cert',
                    'severity' => 'warning',
                    'domain' => $domain,
                    'message' => "Duplicate certificate: {$domain}",
                    'details' => "This is a duplicate of {$baseDomain}. Should be consolidated.",
                    'fixable' => true,
                    'fix_action' => 'consolidate_cert',
                    'fix_params' => ['base_domain' => $baseDomain],
                ];
            }
        }
        
        // 3. Check Dovecot config for missing certificates
        // Note: System now uses a global mail.devcon1.hu cert for all mail.* domains via SAN
        $dovecotConf = '/etc/dovecot/dovecot.conf';
        if (file_exists($dovecotConf)) {
            $content = file_get_contents($dovecotConf);
            
            // Split content into lines to check for comments
            $lines = explode("\n", $content);
            $uncommentedContent = '';
            foreach ($lines as $line) {
                $trimmed = ltrim($line);
                // Skip lines that are fully commented (start with # after whitespace)
                if (!empty($trimmed) && $trimmed[0] !== '#') {
                    $uncommentedContent .= $line . "\n";
                }
            }
            
            // Check for local_name blocks with ssl_cert references (only in uncommented lines)
            if (preg_match_all('/local_name\s+([^\s{]+)\s*\{[^}]*ssl_cert\s*=\s*<([^\n]+)/s', $uncommentedContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $domain = trim($match[1]);
                    $certPath = trim($match[2]);
                    
                    if (!file_exists($certPath)) {
                        $issues[] = [
                            'id' => 'dovecot_missing_cert_' . $domain,
                            'type' => 'dovecot_missing_cert',
                            'severity' => 'error',
                            'domain' => $domain,
                            'message' => "Dovecot references missing certificate for {$domain}",
                            'details' => "Certificate not found: {$certPath}",
                            'fixable' => true,
                            'fix_action' => 'remove_dovecot_entry',
                        ];
                    }
                }
            }
            
            // Check for global mail cert (new strategy)
            $globalMailCert = '/etc/letsencrypt/live/mail.devcon1.hu/fullchain.pem';
            $globalMailKey = '/etc/letsencrypt/live/mail.devcon1.hu/privkey.pem';
            
            // Check if Dovecot is configured to use the global cert
            if (preg_match('/ssl_cert\s*=\s*<([^\n]+)/', $uncommentedContent, $globalMatch)) {
                $configuredCert = trim($globalMatch[1]);
                if ($configuredCert === $globalMailCert || $configuredCert === '/etc/letsencrypt/live/mail.devcon1.hu/fullchain.pem') {
                    // Dovecot is using global cert - verify it exists and is valid
                    if (!file_exists($globalMailCert)) {
                        $issues[] = [
                            'id' => 'dovecot_global_cert_missing',
                            'type' => 'dovecot_global_cert_missing',
                            'severity' => 'error',
                            'domain' => 'mail.devcon1.hu',
                            'message' => "Dovecot global mail certificate missing",
                            'details' => "Global cert not found: {$globalMailCert}",
                            'fixable' => true,
                            'fix_action' => 'issue_global_mail_cert',
                        ];
                    } elseif (!file_exists($globalMailKey)) {
                        $issues[] = [
                            'id' => 'dovecot_global_key_missing',
                            'type' => 'dovecot_global_key_missing',
                            'severity' => 'error',
                            'domain' => 'mail.devcon1.hu',
                            'message' => "Dovecot global mail certificate key missing",
                            'details' => "Global key not found: {$globalMailKey}",
                            'fixable' => true,
                            'fix_action' => 'issue_global_mail_cert',
                        ];
                    } else {
                        // Verify cert is valid and not expired
                        $certData = file_get_contents($globalMailCert);
                        $parsed = openssl_x509_parse($certData);
                        if ($parsed) {
                            $daysRemaining = floor(($parsed['validTo_time_t'] - time()) / 86400);
                            if ($daysRemaining <= 0) {
                                $issues[] = [
                                    'id' => 'dovecot_global_cert_expired',
                                    'type' => 'dovecot_global_cert_expired',
                                    'severity' => 'error',
                                    'domain' => 'mail.devcon1.hu',
                                    'message' => "Dovecot global mail certificate expired",
                                    'details' => "Expired " . abs($daysRemaining) . " days ago",
                                    'fixable' => true,
                                    'fix_action' => 'renew_cert',
                                ];
                            } elseif ($daysRemaining <= 14) {
                                $issues[] = [
                                    'id' => 'dovecot_global_cert_expiring',
                                    'type' => 'dovecot_global_cert_expiring',
                                    'severity' => 'warning',
                                    'domain' => 'mail.devcon1.hu',
                                    'message' => "Dovecot global mail certificate expiring soon",
                                    'details' => "Expires in {$daysRemaining} days",
                                    'fixable' => true,
                                    'fix_action' => 'renew_cert',
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        // 4. Check for expiring certificates (within 14 days)
        foreach ($dirs as $dir) {
            $domain = basename($dir);
            $certFile = $dir . '/fullchain.pem';
            
            if (file_exists($certFile)) {
                $certData = file_get_contents($certFile);
                $parsed = openssl_x509_parse($certData);
                
                if ($parsed) {
                    $daysRemaining = floor(($parsed['validTo_time_t'] - time()) / 86400);
                    
                    if ($daysRemaining <= 0) {
                        $issues[] = [
                            'id' => 'expired_cert_' . $domain,
                            'type' => 'expired_cert',
                            'severity' => 'error',
                            'domain' => $domain,
                            'message' => "Certificate expired for {$domain}",
                            'details' => "Expired " . abs($daysRemaining) . " days ago",
                            'fixable' => true,
                            'fix_action' => 'renew_cert',
                        ];
                    } elseif ($daysRemaining <= 14) {
                        $issues[] = [
                            'id' => 'expiring_cert_' . $domain,
                            'type' => 'expiring_cert',
                            'severity' => 'warning',
                            'domain' => $domain,
                            'message' => "Certificate expiring soon for {$domain}",
                            'details' => "Expires in {$daysRemaining} days",
                            'fixable' => true,
                            'fix_action' => 'renew_cert',
                        ];
                    }
                }
            }
        }
        
        // 5. Check renewal configs for broken references
        $renewalDir = '/etc/letsencrypt/renewal';
        if (is_dir($renewalDir)) {
            $configs = glob($renewalDir . '/*.conf');
            foreach ($configs as $configFile) {
                $configContent = file_get_contents($configFile);
                $domain = basename($configFile, '.conf');
                
                // Check if cert path in config exists
                if (preg_match('/cert\s*=\s*(.+)/', $configContent, $match)) {
                    $certPath = trim($match[1]);
                    if (!file_exists($certPath)) {
                        $issues[] = [
                            'id' => 'broken_renewal_' . $domain,
                            'type' => 'broken_renewal',
                            'severity' => 'warning',
                            'domain' => $domain,
                            'message' => "Broken renewal config for {$domain}",
                            'details' => "References non-existent: {$certPath}",
                            'fixable' => true,
                            'fix_action' => 'cleanup_renewal',
                        ];
                    }
                }
            }
        }
        
        // 6. Check file permissions and ownership
        foreach ($dirs as $dir) {
            $domain = basename($dir);
            
            // Skip duplicates (already reported)
            if (preg_match('/-\d{4}$/', $domain)) {
                continue;
            }
            
            $privkeyPath = $dir . '/privkey.pem';
            
            if (file_exists($privkeyPath) && !is_link($privkeyPath)) {
                // Check privkey permissions (should be 600 or 640)
                $perms = fileperms($privkeyPath) & 0777;
                if ($perms > 0640) {
                    $issues[] = [
                        'id' => 'insecure_privkey_' . $domain,
                        'type' => 'insecure_permissions',
                        'severity' => 'warning',
                        'domain' => $domain,
                        'message' => "Insecure private key permissions for {$domain}",
                        'details' => sprintf("privkey.pem has %o, should be 600 or 640", $perms),
                        'fixable' => true,
                        'fix_action' => 'fix_permissions',
                    ];
                }
            } elseif (is_link($privkeyPath) && file_exists($privkeyPath)) {
                // Check the actual file the symlink points to
                $realPath = realpath($privkeyPath);
                if ($realPath) {
                    $perms = fileperms($realPath) & 0777;
                    if ($perms > 0640) {
                        $issues[] = [
                            'id' => 'insecure_privkey_' . $domain,
                            'type' => 'insecure_permissions',
                            'severity' => 'warning',
                            'domain' => $domain,
                            'message' => "Insecure private key permissions for {$domain}",
                            'details' => sprintf("privkey.pem has %o, should be 600 or 640", $perms),
                            'fixable' => true,
                            'fix_action' => 'fix_permissions',
                        ];
                    }
                }
            }
            
            // Check directory permissions (live dir should be readable)
            $dirPerms = fileperms($dir) & 0777;
            $stat = stat($dir);
            $owner = $stat ? posix_getpwuid($stat['uid'])['name'] ?? $stat['uid'] : 'unknown';
            
            // live directories should be owned by root
            if ($stat && $stat['uid'] !== 0) {
                $issues[] = [
                    'id' => 'wrong_owner_' . $domain,
                    'type' => 'wrong_ownership',
                    'severity' => 'warning',
                    'domain' => $domain,
                    'message' => "Wrong ownership for {$domain} certificate",
                    'details' => "Owned by {$owner}, should be root",
                    'fixable' => true,
                    'fix_action' => 'fix_ownership',
                ];
            }
        }
        
        $summary = [
            'total_issues' => count($issues),
            'errors' => count(array_filter($issues, fn($i) => $i['severity'] === 'error')),
            'warnings' => count(array_filter($issues, fn($i) => $i['severity'] === 'warning')),
            'fixable' => count(array_filter($issues, fn($i) => $i['fixable'])),
        ];
        
        return $this->success([
            'issues' => $issues,
            'summary' => $summary,
            'checked_at' => date('Y-m-d H:i:s'),
        ], $summary['total_issues'] === 0 ? 'No SSL issues detected' : "Found {$summary['total_issues']} issue(s)");
    }

    /**
     * Fix SSL health issues
     */
    protected function actionFixHealth(array $params, string $actor): array
    {
        if (!isset($params['issue_id']) && !isset($params['fix_all'])) {
            return $this->error('issue_id or fix_all is required');
        }
        
        $fixAll = !empty($params['fix_all']);
        $issueId = $params['issue_id'] ?? null;
        
        // Get current issues
        $healthResult = $this->actionHealth([], $actor);
        $issues = $healthResult['data']['issues'] ?? [];
        
        if (empty($issues)) {
            return $this->success(['fixed' => 0], 'No issues to fix');
        }
        
        $fixed = [];
        $failed = [];
        
        foreach ($issues as $issue) {
            // Skip if not fixing all and this isn't the target issue
            if (!$fixAll && $issue['id'] !== $issueId) {
                continue;
            }
            
            if (!$issue['fixable']) {
                $failed[] = ['issue' => $issue['id'], 'reason' => 'Not fixable'];
                continue;
            }
            
            $result = $this->fixIssue($issue);
            
            if ($result['success']) {
                $fixed[] = $issue['id'];
            } else {
                $failed[] = ['issue' => $issue['id'], 'reason' => $result['error']];
            }
        }
        
        // Restart Dovecot if we modified its config
        $dovecotModified = count(array_filter($fixed, fn($id) => strpos($id, 'dovecot_') === 0)) > 0;
        if ($dovecotModified) {
            $this->execCommand('systemctl', ['restart', 'dovecot']);
        }
        
        return $this->success([
            'fixed' => $fixed,
            'failed' => $failed,
            'fixed_count' => count($fixed),
            'failed_count' => count($failed),
        ], "Fixed " . count($fixed) . " issue(s)");
    }

    /**
     * Fix a specific issue
     */
    private function fixIssue(array $issue): array
    {
        $domain = $issue['domain'] ?? null;
        
        switch ($issue['fix_action']) {
            case 'fix_symlinks':
                return $this->fixBrokenSymlinks($domain);
                
            case 'consolidate_cert':
                $baseDomain = $issue['fix_params']['base_domain'] ?? null;
                if ($baseDomain) {
                    return $this->consolidateCertificateFromDuplicate($domain, $baseDomain);
                }
                return ['success' => false, 'error' => 'Missing base_domain'];
                
            case 'remove_dovecot_entry':
                return $this->removeDovecotEntry($domain);
                
            case 'renew_cert':
                return $this->renewCertificate($domain);
                
            case 'cleanup_renewal':
                return $this->cleanupRenewalConfig($domain);
                
            case 'fix_permissions':
                return $this->fixCertPermissions($domain);
                
            case 'fix_ownership':
                return $this->fixCertOwnership($domain);
                
            default:
                return ['success' => false, 'error' => 'Unknown fix action: ' . $issue['fix_action']];
        }
    }

    /**
     * Fix broken symlinks for a domain
     */
    private function fixBrokenSymlinks(string $domain): array
    {
        $livePath = $this->config['paths']['ssl_certs'] . '/' . $domain;
        $archivePath = '/etc/letsencrypt/archive/' . $domain;
        
        // Check if archive exists
        if (!is_dir($archivePath)) {
            // Try to find -0001 version
            for ($i = 1; $i <= 5; $i++) {
                $suffix = sprintf('-%04d', $i);
                if (is_dir($archivePath . $suffix)) {
                    $archivePath = $archivePath . $suffix;
                    break;
                }
            }
        }
        
        if (!is_dir($archivePath)) {
            return ['success' => false, 'error' => 'Archive directory not found'];
        }
        
        // Find the latest cert version in archive
        $certFiles = glob($archivePath . '/cert*.pem');
        if (empty($certFiles)) {
            return ['success' => false, 'error' => 'No certificate files in archive'];
        }
        
        // Get the highest numbered cert
        $latestNum = 1;
        foreach ($certFiles as $cert) {
            if (preg_match('/cert(\d+)\.pem$/', $cert, $m)) {
                $latestNum = max($latestNum, (int)$m[1]);
            }
        }
        
        // Calculate relative path from live to archive
        $relArchive = '../../archive/' . basename($archivePath);
        
        // Recreate symlinks
        $files = ['cert', 'chain', 'fullchain', 'privkey'];
        foreach ($files as $file) {
            $linkPath = $livePath . '/' . $file . '.pem';
            $target = $relArchive . '/' . $file . $latestNum . '.pem';
            
            if (is_link($linkPath)) {
                @unlink($linkPath);
            }
            
            if (!symlink($target, $linkPath)) {
                return ['success' => false, 'error' => "Failed to create symlink for {$file}.pem"];
            }
        }
        
        return ['success' => true];
    }

    /**
     * Consolidate a duplicate certificate
     */
    private function consolidateCertificateFromDuplicate(string $duplicateDomain, string $baseDomain): array
    {
        // This is handled by consolidateCertificate() but called with explicit domains
        $sslLive = $this->config['paths']['ssl_certs'];
        $duplicatePath = $sslLive . '/' . $duplicateDomain;
        $basePath = $sslLive . '/' . $baseDomain;
        
        if (!is_dir($duplicatePath)) {
            return ['success' => false, 'error' => 'Duplicate path not found'];
        }
        
        // Create backup of the duplicate before consolidation
        $backupDir = $this->config['paths']['backups'] . '/ssl_consolidation/' . date('Y-m-d_H-i-s');
        @mkdir($backupDir, 0755, true);
        
        // Backup the duplicate live directory
        $this->execCommand('cp', ['-r', $duplicatePath, $backupDir . '/live_' . $duplicateDomain]);
        
        // Backup duplicate archive if exists
        $duplicateArchive = '/etc/letsencrypt/archive/' . $duplicateDomain;
        if (is_dir($duplicateArchive)) {
            $this->execCommand('cp', ['-r', $duplicateArchive, $backupDir . '/archive_' . $duplicateDomain]);
        }
        
        // Backup duplicate renewal config if exists
        $duplicateRenewal = '/etc/letsencrypt/renewal/' . $duplicateDomain . '.conf';
        if (file_exists($duplicateRenewal)) {
            copy($duplicateRenewal, $backupDir . '/renewal_' . $duplicateDomain . '.conf');
        }
        
        // Remove old base if exists (broken)
        if (is_link($basePath)) {
            @unlink($basePath);
        } elseif (is_dir($basePath)) {
            $this->execCommand('rm', ['-rf', $basePath]);
        }
        
        // Rename duplicate to base
        $this->execCommand('mv', [$duplicatePath, $basePath]);
        
        // Handle archive
        $baseArchive = '/etc/letsencrypt/archive/' . $baseDomain;
        
        if (is_dir($duplicateArchive)) {
            if (is_dir($baseArchive)) {
                $this->execCommand('rm', ['-rf', $baseArchive]);
            }
            $this->execCommand('mv', [$duplicateArchive, $baseArchive]);
        }
        
        // Handle renewal config
        $baseRenewal = '/etc/letsencrypt/renewal/' . $baseDomain . '.conf';
        
        if (file_exists($duplicateRenewal)) {
            $content = file_get_contents($duplicateRenewal);
            $content = str_replace($duplicateDomain, $baseDomain, $content);
            file_put_contents($baseRenewal, $content);
            @unlink($duplicateRenewal);
        }
        
        // Fix symlinks in the renamed directory
        $this->fixBrokenSymlinks($baseDomain);
        
        return ['success' => true, 'backup' => $backupDir];
    }

    /**
     * Remove Dovecot entry for a domain
     */
    private function removeDovecotEntry(string $domain): array
    {
        $dovecotConf = '/etc/dovecot/dovecot.conf';
        
        if (!file_exists($dovecotConf)) {
            return ['success' => false, 'error' => 'Dovecot config not found'];
        }
        
        // Create backup before modifying
        $backupPath = $dovecotConf . '.bak.' . date('Y-m-d_H-i-s');
        if (!copy($dovecotConf, $backupPath)) {
            return ['success' => false, 'error' => 'Failed to create backup'];
        }
        
        $content = file_get_contents($dovecotConf);
        $originalContent = $content;
        $escapedDomain = preg_quote($domain, '/');
        
        // Remove the local_name block for this domain
        // Pattern explanation:
        // - local_name\s+ : matches "local_name " with whitespace
        // - $escapedDomain : the domain name (escaped for regex)
        // - \s*\{ : optional whitespace then opening brace
        // - [^}]* : any characters except closing brace (the block content)
        // - \} : closing brace
        // - \s* : optional trailing whitespace/newlines
        $pattern = '/local_name\s+' . $escapedDomain . '\s*\{[^}]*\}\s*/s';
        $newContent = preg_replace($pattern, '', $content, 1, $count);
        
        // Verify the replacement worked and didn't corrupt the file
        if ($count === 0) {
            @unlink($backupPath);
            return ['success' => false, 'error' => 'Entry not found in config'];
        }
        
        // Sanity check - make sure we didn't remove too much
        $removedLength = strlen($originalContent) - strlen($newContent);
        if ($removedLength > 500) {
            // Removed more than expected for a simple local_name block
            @unlink($backupPath);
            return ['success' => false, 'error' => 'Safety check failed - removal too large'];
        }
        
        // Write the new content
        if (file_put_contents($dovecotConf, $newContent) === false) {
            // Restore backup
            copy($backupPath, $dovecotConf);
            @unlink($backupPath);
            return ['success' => false, 'error' => 'Failed to write config'];
        }
        
        // Test the config before restarting
        $testResult = $this->execCommand('doveconf', ['-n']);
        if (!$testResult['success']) {
            // Config is broken, restore backup
            copy($backupPath, $dovecotConf);
            @unlink($backupPath);
            return ['success' => false, 'error' => 'Config validation failed - restored backup'];
        }
        
        // Config is valid, remove backup
        @unlink($backupPath);
        
        return ['success' => true];
    }

    /**
     * Renew a certificate
     */
    private function renewCertificate(string $domain): array
    {
        // Ensure ACME context exists before attempting renewal
        $webroot = $this->config['paths']['webroot'] . '/' . $domain . '/public_html';
        $acmeDir = $webroot . '/.well-known/acme-challenge';
        if (!is_dir($acmeDir)) {
            @mkdir($acmeDir, 0755, true);
        }
        $this->ensureAcmeContext($domain);

        $result = $this->execCommand('certbot', ['renew', '--cert-name', $domain, '--non-interactive', '--force-renewal']);
        
        if ($result['success']) {
            $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => $result['output']];
    }

    /**
     * Cleanup broken renewal config
     */
    private function cleanupRenewalConfig(string $domain): array
    {
        $renewalConfig = '/etc/letsencrypt/renewal/' . $domain . '.conf';
        
        if (file_exists($renewalConfig)) {
            // Backup before deleting
            $backupDir = $this->config['paths']['backups'] . '/ssl_cleanup/' . date('Y-m-d_H-i-s');
            @mkdir($backupDir, 0755, true);
            copy($renewalConfig, $backupDir . '/' . $domain . '.conf');
            
            @unlink($renewalConfig);
            return ['success' => true, 'backup' => $backupDir];
        }
        
        return ['success' => false, 'error' => 'Config not found'];
    }

    /**
     * Fix certificate file permissions
     */
    private function fixCertPermissions(string $domain): array
    {
        $sslLive = $this->config['paths']['ssl_certs'];
        $livePath = $sslLive . '/' . $domain;
        $archivePath = '/etc/letsencrypt/archive/' . $domain;
        
        $fixed = [];
        
        // Fix privkey permissions in live directory (if not symlink)
        $privkeyLive = $livePath . '/privkey.pem';
        if (file_exists($privkeyLive) && !is_link($privkeyLive)) {
            chmod($privkeyLive, 0600);
            $fixed[] = $privkeyLive;
        }
        
        // Fix privkey permissions in archive directory
        if (is_dir($archivePath)) {
            $privkeys = glob($archivePath . '/privkey*.pem');
            foreach ($privkeys as $privkey) {
                chmod($privkey, 0600);
                $fixed[] = $privkey;
            }
        }
        
        // If live has symlinks, fix the actual files they point to
        if (is_link($privkeyLive)) {
            $realPath = realpath($privkeyLive);
            if ($realPath && file_exists($realPath)) {
                chmod($realPath, 0600);
                $fixed[] = $realPath;
            }
        }
        
        if (empty($fixed)) {
            return ['success' => false, 'error' => 'No files to fix'];
        }
        
        return ['success' => true, 'fixed_files' => $fixed];
    }

    /**
     * Fix certificate ownership
     */
    private function fixCertOwnership(string $domain): array
    {
        $sslLive = $this->config['paths']['ssl_certs'];
        $livePath = $sslLive . '/' . $domain;
        $archivePath = '/etc/letsencrypt/archive/' . $domain;
        
        $fixed = [];
        
        // Fix live directory ownership
        if (is_dir($livePath)) {
            $this->execCommand('chown', ['-R', 'root:root', $livePath]);
            $fixed[] = $livePath;
        }
        
        // Fix archive directory ownership
        if (is_dir($archivePath)) {
            $this->execCommand('chown', ['-R', 'root:root', $archivePath]);
            $fixed[] = $archivePath;
        }
        
        if (empty($fixed)) {
            return ['success' => false, 'error' => 'No directories to fix'];
        }
        
        return ['success' => true, 'fixed_dirs' => $fixed];
    }

    /**
     * Add SSL block to vhost config after certificate is issued
     * This enables HTTPS for the domain in OpenLiteSpeed
     */
    private function addSslToVhostConfig(string $domain): bool
    {
        $vhostPath = $this->config['paths']['ols_vhosts'] ?? '/usr/local/lsws/conf/vhosts';
        $configFile = $vhostPath . '/' . $domain . '/vhost.conf';
        
        if (!file_exists($configFile)) {
            $configFile = $vhostPath . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            return false;
        }

        // Check if SSL block already exists
        $config = file_get_contents($configFile);
        if (preg_match('/vhssl\s*\{/i', $config)) {
            // SSL block already exists
            return true;
        }

        // Check if certificate exists
        $certPath = $this->config['paths']['ssl_certs'] . '/' . $domain;
        if (!file_exists($certPath . '/fullchain.pem') || !file_exists($certPath . '/privkey.pem')) {
            return false;
        }

        // Get original file ownership and permissions
        $origStat = stat($configFile);
        $origOwner = $origStat['uid'];
        $origGroup = $origStat['gid'];
        $origPerms = $origStat['mode'] & 0777;

        // Add SSL block to config
        $sslBlock = <<<SSL

vhssl  {
  keyFile                 /etc/letsencrypt/live/\$VH_NAME/privkey.pem
  certFile                /etc/letsencrypt/live/\$VH_NAME/fullchain.pem
  certChain               1
  sslProtocol             24
  ciphers                 ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
  enableQuic              1
  enableStapling          1
  ocspRespMaxAge          86400
}
SSL;

        // Append SSL block to config
        $newConfig = rtrim($config) . "\n" . $sslBlock . "\n";

        // Write updated config
        if (file_put_contents($configFile, $newConfig) === false) {
            return false;
        }

        // Restore original ownership and permissions
        chown($configFile, $origOwner);
        chgrp($configFile, $origGroup);
        chmod($configFile, $origPerms);

        return true;
    }

    /**
     * Run comprehensive SSL/TLS security check using testssl.sh
     * Analyzes protocols, ciphers, vulnerabilities, and calculates a grade
     */
    protected function actionComprehensiveCheck(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $testsslPath = '/opt/testssl.sh/testssl.sh';
        
        // Check if testssl.sh is installed
        if (!file_exists($testsslPath)) {
            return $this->error('testssl.sh is not installed. Run: cd /opt && git clone --depth 1 https://github.com/drwetter/testssl.sh.git');
        }

        $startTime = microtime(true);
        $jsonFile = '/tmp/ssl_check_' . md5($domain) . '.json';
        
        // Clean up any previous result file
        if (file_exists($jsonFile)) {
            @unlink($jsonFile);
        }

        // Run testssl.sh with JSON output
        // --fast: Skip some time-consuming tests
        // --quiet: Less verbose output
        // --jsonfile-pretty: Output results to JSON file
        $cmd = sprintf(
            '%s --fast --quiet --jsonfile-pretty %s %s 2>&1',
            escapeshellcmd($testsslPath),
            escapeshellarg($jsonFile),
            escapeshellarg($domain)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $duration = round(microtime(true) - $startTime, 2);

        // Check if JSON output was created
        if (!file_exists($jsonFile)) {
            return $this->error('SSL check failed: No output generated. Exit code: ' . $exitCode);
        }

        $jsonContent = file_get_contents($jsonFile);
        $results = json_decode($jsonContent, true);
        
        // Clean up temp file
        @unlink($jsonFile);

        if (!$results) {
            return $this->error('SSL check failed: Could not parse results. JSON error: ' . json_last_error_msg());
        }

        // testssl.sh JSON structure with --jsonfile-pretty is CATEGORIZED:
        // {
        //   "scanResult": [{
        //     "targetHost": "...",
        //     "protocols": [{id: "TLS1_2", finding: "offered"}, ...],
        //     "ciphers": [{id: "...", finding: "..."}, ...],
        //     "serverDefaults": [...],
        //     "headerResponse": [...],
        //     "vulnerabilities": [...],
        //     ...
        //   }]
        // }
        
        // Extract the first scan target
        $scanTarget = null;
        if (isset($results['scanResult'][0])) {
            $scanTarget = $results['scanResult'][0];
        } elseif (isset($results[0]) && isset($results[0]['protocols'])) {
            $scanTarget = $results[0];
        } else {
            return $this->error('SSL check failed: Unexpected JSON structure');
        }
        
        // Log available categories
        $categories = array_keys($scanTarget);
        error_log("testssl.sh categories: " . implode(', ', $categories));

        // Parse categorized results
        $protocols = $this->extractProtocols($scanTarget['protocols'] ?? []);
        $ciphers = $this->extractCiphers($scanTarget['ciphers'] ?? [], $scanTarget['pfs'] ?? [], $scanTarget['serverPreferences'] ?? []);
        $vulnerabilities = $this->extractVulnerabilities($scanTarget['vulnerabilities'] ?? [], $domain);
        $certInfo = $this->extractCertificateInfo($scanTarget['serverDefaults'] ?? []);
        $headers = $this->extractSecurityHeaders($scanTarget['headerResponse'] ?? []);
        
        // Calculate grade
        $gradeInfo = $this->calculateSslGrade($protocols, $ciphers, $vulnerabilities, $headers);

        return $this->success([
            'domain' => $domain,
            'grade' => $gradeInfo['grade'],
            'score' => $gradeInfo['score'],
            'deductions' => $gradeInfo['deductions'],
            'protocols' => $protocols,
            'ciphers' => $ciphers,
            'vulnerabilities' => $vulnerabilities,
            'certificate' => $certInfo,
            'security_headers' => $headers,
            'scan_duration' => $duration,
            'scanned_at' => date('Y-m-d H:i:s'),
        ], "SSL analysis complete for {$domain} - Grade: {$gradeInfo['grade']}");
    }

    /**
     * Extract protocol support from testssl.sh protocols array
     */
    private function extractProtocols(array $protocolResults): array
    {
        $protocols = [
            'SSLv2' => ['supported' => false, 'secure' => false],
            'SSLv3' => ['supported' => false, 'secure' => false],
            'TLS1.0' => ['supported' => false, 'secure' => false],
            'TLS1.1' => ['supported' => false, 'secure' => false],
            'TLS1.2' => ['supported' => false, 'secure' => true],
            'TLS1.3' => ['supported' => false, 'secure' => true],
        ];

        // Map testssl.sh IDs to our protocol names
        $idMap = [
            'sslv2' => 'SSLv2',
            'sslv3' => 'SSLv3',
            'tls1' => 'TLS1.0',
            'tls1_1' => 'TLS1.1',
            'tls1_2' => 'TLS1.2',
            'tls1_3' => 'TLS1.3',
        ];

        foreach ($protocolResults as $item) {
            if (!isset($item['id'])) continue;
            
            $id = strtolower($item['id']);
            $finding = strtolower($item['finding'] ?? '');
            
            // Match the ID to our protocol map
            if (isset($idMap[$id])) {
                $protocolName = $idMap[$id];
                // Check if offered (but not "not offered")
                $isOffered = strpos($finding, 'offered') !== false && strpos($finding, 'not offered') === false;
                $protocols[$protocolName]['supported'] = $isOffered;
            }
        }

        return $protocols;
    }

    /**
     * Extract cipher information from testssl.sh categorized results
     */
    private function extractCiphers(array $cipherResults, array $pfsResults = [], array $serverPrefs = []): array
    {
        $ciphers = [
            'strong' => [],
            'weak' => [],
            'insecure' => [],
            'forward_secrecy' => false,
            'aead' => false,
        ];

        // Check PFS (Perfect Forward Secrecy) results
        foreach ($pfsResults as $item) {
            $id = strtolower($item['id'] ?? '');
            $finding = $item['finding'] ?? '';
            
            if ($id === 'pfs' || strpos($id, 'pfs') !== false) {
                if (stripos($finding, 'offered') !== false && stripos($finding, 'not offered') === false) {
                    $ciphers['forward_secrecy'] = true;
                }
                // Also check for ECDHE or DHE ciphers mentioned
                if (stripos($finding, 'ECDHE') !== false || stripos($finding, 'DHE') !== false) {
                    $ciphers['forward_secrecy'] = true;
                }
            }
        }

        // Check server preferences for AEAD and FS
        foreach ($serverPrefs as $item) {
            $finding = $item['finding'] ?? '';
            if (stripos($finding, 'ECDHE') !== false || stripos($finding, 'DHE') !== false) {
                $ciphers['forward_secrecy'] = true;
            }
            if (stripos($finding, 'GCM') !== false || stripos($finding, 'CHACHA') !== false) {
                $ciphers['aead'] = true;
            }
        }

        // Process cipher results
        foreach ($cipherResults as $item) {
            if (!isset($item['id'])) continue;
            
            $id = strtolower($item['id']);
            $finding = $item['finding'] ?? '';
            $severity = $item['severity'] ?? 'INFO';
            
            // Skip "not offered" ciphers
            if (stripos($finding, 'not offered') !== false) continue;
            
            // Check for AEAD
            if (stripos($finding, 'GCM') !== false || stripos($finding, 'CHACHA') !== false || stripos($finding, 'AEAD') !== false) {
                $ciphers['aead'] = true;
            }
            
            // Categorize by severity
            if ($severity === 'CRITICAL' || $severity === 'HIGH') {
                if (!empty($finding)) {
                    $ciphers['insecure'][] = $finding;
                }
            } elseif ($severity === 'MEDIUM' || $severity === 'LOW') {
                if (!empty($finding)) {
                    $ciphers['weak'][] = $finding;
                }
            } elseif ($severity === 'OK' || $severity === 'INFO') {
                if (!empty($finding) && stripos($id, 'strong') !== false) {
                    $ciphers['strong'][] = $finding;
                }
            }
        }

        // Limit arrays to prevent huge responses
        $ciphers['strong'] = array_slice($ciphers['strong'], 0, 10);
        $ciphers['weak'] = array_slice($ciphers['weak'], 0, 10);
        $ciphers['insecure'] = array_slice($ciphers['insecure'], 0, 10);

        return $ciphers;
    }

    /**
     * Extract vulnerability information from testssl.sh vulnerabilities array
     * @param array $vulnResults - vulnerability results from testssl.sh
     * @param string|null $domain - optional domain to check for mitigations
     */
    private function extractVulnerabilities(array $vulnResults, ?string $domain = null): array
    {
        $vulns = [];
        
        // Known vulnerability IDs from testssl.sh
        $vulnMap = [
            'heartbleed' => ['name' => 'Heartbleed', 'severity' => 'critical'],
            'ccs' => ['name' => 'CCS Injection', 'severity' => 'high'],
            'ticketbleed' => ['name' => 'Ticketbleed', 'severity' => 'high'],
            'robot' => ['name' => 'ROBOT', 'severity' => 'high'],
            'secure_renego' => ['name' => 'Secure Renegotiation', 'severity' => 'medium'],
            'secure_client_renego' => ['name' => 'Client Renegotiation', 'severity' => 'medium'],
            'crime_tls' => ['name' => 'CRIME', 'severity' => 'high'],
            'breach' => ['name' => 'BREACH', 'severity' => 'medium'],
            'poodle_ssl' => ['name' => 'POODLE (SSL)', 'severity' => 'high'],
            'fallback_scsv' => ['name' => 'TLS Fallback SCSV', 'severity' => 'low'],
            'sweet32' => ['name' => 'SWEET32', 'severity' => 'medium'],
            'freak' => ['name' => 'FREAK', 'severity' => 'high'],
            'drown' => ['name' => 'DROWN', 'severity' => 'critical'],
            'logjam' => ['name' => 'LOGJAM', 'severity' => 'high'],
            'beast' => ['name' => 'BEAST', 'severity' => 'medium'],
            'lucky13' => ['name' => 'LUCKY13', 'severity' => 'low'],
            'rc4' => ['name' => 'RC4', 'severity' => 'medium'],
        ];

        // Check if gzip is disabled in vhost config (mitigates BREACH)
        $gzipDisabled = false;
        $isStaticSite = false;
        if ($domain) {
            $gzipDisabled = $this->checkGzipDisabled($domain);
            $isStaticSite = $this->checkIfStaticSite($domain);
        }

        foreach ($vulnResults as $item) {
            if (!isset($item['id'])) continue;
            
            $id = strtolower($item['id']);
            $finding = $item['finding'] ?? '';
            $severity = strtoupper($item['severity'] ?? 'INFO');
            
            // Check if this is a known vulnerability
            foreach ($vulnMap as $vulnId => $vulnInfo) {
                if (strpos($id, $vulnId) !== false) {
                    $vulnerable = stripos($finding, 'vulnerable') !== false ||
                                  $severity === 'CRITICAL' ||
                                  $severity === 'HIGH';
                    
                    $notVulnerable = stripos($finding, 'not vulnerable') !== false ||
                                     $severity === 'OK';
                    
                    // Special handling for BREACH
                    if ($vulnId === 'breach') {
                        $breachInfo = $this->assessBreachVulnerability($finding, $gzipDisabled, $isStaticSite);
                        $vulns[$vulnId] = [
                            'name' => $vulnInfo['name'],
                            'severity' => $breachInfo['severity'],
                            'vulnerable' => $breachInfo['vulnerable'],
                            'finding' => $breachInfo['finding'],
                            'mitigated' => $breachInfo['mitigated'],
                            'mitigation_note' => $breachInfo['note'],
                        ];
                    } else {
                        $vulns[$vulnId] = [
                            'name' => $vulnInfo['name'],
                            'severity' => $vulnInfo['severity'],
                            'vulnerable' => $vulnerable && !$notVulnerable,
                            'finding' => $finding,
                        ];
                    }
                    break;
                }
            }
        }

        return $vulns;
    }

    /**
     * Check if gzip is disabled in vhost config
     */
    private function checkGzipDisabled(string $domain): bool
    {
        $vhostPath = $this->config['paths']['ols_vhosts'] ?? '/usr/local/lsws/conf/vhosts';
        $configFile = $vhostPath . '/' . $domain . '/vhost.conf';
        
        if (!file_exists($configFile)) {
            $configFile = $vhostPath . '/' . $domain . '/vhconf.conf';
        }
        
        if (!file_exists($configFile)) {
            return false;
        }
        
        $config = file_get_contents($configFile);
        
        // Check for enableGzip 0
        if (preg_match('/enableGzip\s+0/i', $config)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if site is static (no PHP/dynamic content)
     */
    private function checkIfStaticSite(string $domain): bool
    {
        $docRoot = '/home/' . $domain . '/public_html';
        
        if (!is_dir($docRoot)) {
            return false;
        }
        
        // Check for common dynamic site indicators
        $dynamicIndicators = [
            'wp-config.php',      // WordPress
            'index.php',          // PHP
            'configuration.php',  // Joomla
            'config.php',         // Generic PHP
            'artisan',            // Laravel
            'composer.json',      // PHP Composer
        ];
        
        foreach ($dynamicIndicators as $indicator) {
            if (file_exists($docRoot . '/' . $indicator)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Assess BREACH vulnerability with context
     */
    private function assessBreachVulnerability(string $finding, bool $gzipDisabled, bool $isStaticSite): array
    {
        $result = [
            'vulnerable' => true,
            'severity' => 'medium',
            'finding' => $finding,
            'mitigated' => false,
            'note' => '',
        ];
        
        // Check if testssl.sh says not vulnerable
        if (stripos($finding, 'not vulnerable') !== false) {
            $result['vulnerable'] = false;
            $result['severity'] = 'info';
            $result['mitigated'] = true;
            $result['note'] = 'Not vulnerable according to scan';
            return $result;
        }
        
        // Check for "potentially" vulnerable (less severe)
        $isPotential = stripos($finding, 'potentially') !== false;
        
        // Static sites are not vulnerable to BREACH (no secrets to leak)
        if ($isStaticSite) {
            $result['vulnerable'] = false;
            $result['severity'] = 'info';
            $result['mitigated'] = true;
            $result['note'] = 'Static site - no dynamic secrets to leak';
            $result['finding'] = $finding . ' (Mitigated: Static site)';
            return $result;
        }
        
        // Gzip disabled significantly reduces BREACH risk
        if ($gzipDisabled) {
            $result['severity'] = 'low';
            $result['mitigated'] = true;
            $result['note'] = 'Gzip compression disabled in vhost config';
            $result['finding'] = $finding . ' (Mitigated: Gzip disabled)';
            
            // If only "potentially" vulnerable and gzip is off, consider not vulnerable
            if ($isPotential) {
                $result['vulnerable'] = false;
                $result['severity'] = 'info';
            }
            return $result;
        }
        
        // Still potentially vulnerable
        if ($isPotential) {
            $result['severity'] = 'low';
            $result['note'] = 'Potentially vulnerable - consider disabling gzip';
        } else {
            $result['note'] = 'Vulnerable - disable gzip compression or use CSRF tokens';
        }
        
        return $result;
    }

    /**
     * Extract certificate information from testssl.sh serverDefaults array
     */
    private function extractCertificateInfo(array $serverDefaults): array
    {
        $cert = [
            'subject' => null,
            'issuer' => null,
            'valid_from' => null,
            'valid_to' => null,
            'days_remaining' => null,
            'key_size' => null,
            'signature_algorithm' => null,
            'san' => [],
            'chain_valid' => false,
            'trusted' => false,
        ];

        foreach ($serverDefaults as $item) {
            if (!isset($item['id'])) continue;
            
            $id = strtolower($item['id']);
            $finding = $item['finding'] ?? '';
            
            // Match common testssl.sh certificate IDs (case insensitive)
            if (strpos($id, 'commonname') !== false || $id === 'cert_cn' || strpos($id, 'subject') !== false) {
                if (empty($cert['subject'])) $cert['subject'] = $finding;
            } elseif (strpos($id, 'caissuer') !== false || strpos($id, 'issuer') !== false) {
                if (empty($cert['issuer'])) $cert['issuer'] = $finding;
            } elseif (strpos($id, 'notbefore') !== false) {
                $cert['valid_from'] = $finding;
            } elseif (strpos($id, 'notafter') !== false) {
                $cert['valid_to'] = $finding;
            } elseif (strpos($id, 'expiration') !== false || strpos($id, 'expires') !== false) {
                if (preg_match('/(\d+)\s*days?/', $finding, $m)) {
                    $cert['days_remaining'] = (int)$m[1];
                }
            } elseif (strpos($id, 'keysize') !== false || strpos($id, 'key_size') !== false) {
                $cert['key_size'] = $finding;
            } elseif (strpos($id, 'signaturealgorithm') !== false || strpos($id, 'signature') !== false) {
                if (empty($cert['signature_algorithm'])) $cert['signature_algorithm'] = $finding;
            } elseif (strpos($id, 'subjectaltname') !== false || strpos($id, 'san') !== false) {
                $cert['san'] = array_filter(array_map('trim', explode(' ', $finding)));
            } elseif (strpos($id, 'chain') !== false || strpos($id, 'trust') !== false) {
                $isValid = stripos($finding, 'ok') !== false || 
                           stripos($finding, 'passed') !== false ||
                           stripos($finding, 'valid') !== false;
                if (strpos($id, 'chain') !== false) {
                    $cert['chain_valid'] = $isValid;
                }
                if (strpos($id, 'trust') !== false) {
                    $cert['trusted'] = $isValid;
                }
            }
        }

        return $cert;
    }

    /**
     * Extract security headers from testssl.sh headerResponse array
     * testssl.sh uses specific IDs:
     * - HSTS_time, HSTS_subdomains, HSTS_preload for HSTS
     * - security_headers contains X-Frame-Options, X-Content-Type-Options, CSP info
     */
    private function extractSecurityHeaders(array $headerResponse): array
    {
        $headers = [
            'hsts' => ['present' => false, 'value' => null],
            'hsts_preload' => ['present' => false],
            'x_frame_options' => ['present' => false, 'value' => null],
            'x_content_type_options' => ['present' => false, 'value' => null],
            'csp' => ['present' => false, 'value' => null],
        ];

        foreach ($headerResponse as $item) {
            if (!isset($item['id'])) continue;
            
            $id = strtolower($item['id']);
            $finding = $item['finding'] ?? '';
            $severity = strtoupper($item['severity'] ?? 'INFO');
            
            // HSTS detection - testssl.sh uses HSTS_time, HSTS_subdomains, HSTS_preload
            if ($id === 'hsts_time' || $id === 'hsts') {
                // Check if severity is OK or finding contains valid time
                $headers['hsts']['present'] = ($severity === 'OK') || 
                                               (stripos($finding, 'days') !== false && stripos($finding, 'not') === false);
                $headers['hsts']['value'] = $finding;
            }
            
            if ($id === 'hsts_preload') {
                $headers['hsts_preload']['present'] = ($severity === 'OK') || 
                                                       stripos($finding, 'marked for preloading') !== false;
            }
            
            // security_headers contains other headers - parse the finding
            if ($id === 'security_headers') {
                // If finding is "--" or empty, headers are not present
                if ($finding !== '--' && !empty($finding)) {
                    // Check for each header in the finding string
                    if (stripos($finding, 'X-Frame-Options') !== false) {
                        $headers['x_frame_options']['present'] = true;
                        $headers['x_frame_options']['value'] = $finding;
                    }
                    if (stripos($finding, 'X-Content-Type-Options') !== false) {
                        $headers['x_content_type_options']['present'] = true;
                        $headers['x_content_type_options']['value'] = $finding;
                    }
                    if (stripos($finding, 'Content-Security-Policy') !== false) {
                        $headers['csp']['present'] = true;
                        $headers['csp']['value'] = $finding;
                    }
                }
            }
            
            // Also check for individual header IDs (some testssl versions use these)
            if (stripos($id, 'x-frame') !== false || stripos($id, 'x_frame') !== false) {
                $headers['x_frame_options']['present'] = ($severity === 'OK') || 
                    (!empty($finding) && stripos($finding, 'not offered') === false && $finding !== '--');
                $headers['x_frame_options']['value'] = $finding;
            }
            
            if (stripos($id, 'x-content-type') !== false || stripos($id, 'x_content_type') !== false) {
                $headers['x_content_type_options']['present'] = ($severity === 'OK') ||
                    (!empty($finding) && stripos($finding, 'not offered') === false && $finding !== '--');
                $headers['x_content_type_options']['value'] = $finding;
            }
            
            if (stripos($id, 'csp') !== false || stripos($id, 'content-security') !== false || stripos($id, 'content_security') !== false) {
                $headers['csp']['present'] = ($severity === 'OK') ||
                    (!empty($finding) && stripos($finding, 'not offered') === false && $finding !== '--');
                $headers['csp']['value'] = $finding;
            }
        }

        return $headers;
    }

    /**
     * Calculate SSL grade based on analysis results
     * Following SSL Labs methodology
     */
    private function calculateSslGrade(array $protocols, array $ciphers, array $vulnerabilities, array $headers): array
    {
        $score = 100;
        $deductions = [];

        // Protocol deductions
        if ($protocols['SSLv2']['supported']) {
            $score -= 30;
            $deductions[] = ['reason' => 'SSLv2 enabled', 'points' => -30, 'severity' => 'critical'];
        }
        if ($protocols['SSLv3']['supported']) {
            $score -= 25;
            $deductions[] = ['reason' => 'SSLv3 enabled', 'points' => -25, 'severity' => 'critical'];
        }
        if ($protocols['TLS1.0']['supported']) {
            $score -= 10;
            $deductions[] = ['reason' => 'TLS 1.0 enabled (deprecated)', 'points' => -10, 'severity' => 'medium'];
        }
        if ($protocols['TLS1.1']['supported']) {
            $score -= 5;
            $deductions[] = ['reason' => 'TLS 1.1 enabled (deprecated)', 'points' => -5, 'severity' => 'low'];
        }
        if (!$protocols['TLS1.2']['supported'] && !$protocols['TLS1.3']['supported']) {
            $score -= 30;
            $deductions[] = ['reason' => 'No modern TLS (1.2/1.3) support', 'points' => -30, 'severity' => 'critical'];
        }

        // Bonus for TLS 1.3
        if ($protocols['TLS1.3']['supported']) {
            $score += 5;
            $deductions[] = ['reason' => 'TLS 1.3 supported', 'points' => 5, 'severity' => 'bonus'];
        }

        // Vulnerability deductions
        foreach ($vulnerabilities as $vulnId => $vuln) {
            // Check if vulnerability is mitigated
            $isMitigated = $vuln['mitigated'] ?? false;
            
            if ($vuln['vulnerable'] && !$isMitigated) {
                $penalty = match($vuln['severity']) {
                    'critical' => 30,
                    'high' => 20,
                    'medium' => 10,
                    'low' => 5,
                    'info' => 0,
                    default => 5,
                };
                $score -= $penalty;
                $deductions[] = [
                    'reason' => "Vulnerable to {$vuln['name']}",
                    'points' => -$penalty,
                    'severity' => $vuln['severity'],
                ];
            } elseif ($isMitigated && isset($vuln['mitigation_note'])) {
                // Show mitigated vulnerability as info
                $deductions[] = [
                    'reason' => "{$vuln['name']}: {$vuln['mitigation_note']}",
                    'points' => 0,
                    'severity' => 'info',
                ];
            }
        }

        // Cipher deductions
        if (!empty($ciphers['insecure'])) {
            $score -= 15;
            $deductions[] = ['reason' => 'Insecure ciphers offered', 'points' => -15, 'severity' => 'high'];
        }
        if (!empty($ciphers['weak'])) {
            $score -= 5;
            $deductions[] = ['reason' => 'Weak ciphers offered', 'points' => -5, 'severity' => 'medium'];
        }
        if (!$ciphers['forward_secrecy']) {
            $score -= 10;
            $deductions[] = ['reason' => 'No forward secrecy', 'points' => -10, 'severity' => 'medium'];
        }

        // Security header bonuses/deductions
        if (!$headers['hsts']['present']) {
            $score -= 5;
            $deductions[] = ['reason' => 'No HSTS header', 'points' => -5, 'severity' => 'low'];
        }

        // Ensure score is within bounds
        $score = max(0, min(100, $score));

        // Calculate grade
        $grade = match(true) {
            $score >= 95 => 'A+',
            $score >= 85 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };

        return [
            'grade' => $grade,
            'score' => $score,
            'deductions' => $deductions,
        ];
    }

    /**
     * Fix SSL configuration issues by updating vhost config
     * Uses OLS heredoc syntax (<<<END_NAME) for security headers which is the only reliable method
     */
    protected function actionFixSslConfig(array $params, string $actor): array
    {
        if (!isset($params['domain'])) {
            return $this->error('Domain is required');
        }

        $domain = $params['domain'];
        
        if (!Validator::domain($domain)) {
            return $this->error('Invalid domain format');
        }

        $vhostPath = $this->config['paths']['ols_vhosts'] ?? '/usr/local/lsws/conf/vhosts';
        $configFile = $vhostPath . '/' . $domain . '/vhconf.conf';
        
        if (!file_exists($configFile)) {
            $configFile = $vhostPath . '/' . $domain . '/vhost.conf';
        }
        
        if (!file_exists($configFile)) {
            return $this->error("Vhost config not found for {$domain}. Checked: vhconf.conf and vhost.conf");
        }

        // Check if SSL certificate exists
        $certPath = $this->config['paths']['ssl_certs'] . '/' . $domain;
        if (!file_exists($certPath . '/fullchain.pem')) {
            return $this->error('No SSL certificate found. Issue a certificate first.');
        }

        // Read current config
        $config = file_get_contents($configFile);
        $originalConfig = $config;
        $changes = [];

        // Define optimal SSL settings for vhssl block
        $optimalVhssl = [
            'sslProtocol' => '24',  // TLS 1.2 + 1.3
            'ciphers' => 'ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES256-GCM-SHA384',
            'enableECDHE' => '1',
            'renegProtection' => '1',
            'sslSessionCache' => '1',
            'sslSessionTickets' => '1',
            'enableSpdy' => '15',
            'enableQuic' => '1',
            'enableStapling' => '1',
            'ocspRespMaxAge' => '86400',
            'certChain' => '1',
        ];

        // 1. Handle vhssl block
        if (preg_match('/vhssl\s*\{([^}]*)\}/s', $config, $vhsslMatch)) {
            $vhsslContent = $vhsslMatch[1];
            $vhsslUpdated = false;
            
            foreach ($optimalVhssl as $key => $value) {
                if (preg_match('/^\s*' . preg_quote($key, '/') . '\s+.*/m', $vhsslContent)) {
                    $currentPattern = '/^(\s*)' . preg_quote($key, '/') . '\s+(.*)$/m';
                    if (preg_match($currentPattern, $vhsslContent, $currentMatch)) {
                        $currentValue = trim($currentMatch[2]);
                        if ($currentValue !== $value) {
                            $vhsslContent = preg_replace(
                                $currentPattern,
                                '$1' . $key . str_repeat(' ', max(1, 20 - strlen($key))) . $value,
                                $vhsslContent
                            );
                            $changes[] = "Updated {$key}";
                            $vhsslUpdated = true;
                        }
                    }
                } else {
                    $vhsslContent = rtrim($vhsslContent) . "\n  " . $key . str_repeat(' ', max(1, 20 - strlen($key))) . $value;
                    $changes[] = "Added {$key}";
                    $vhsslUpdated = true;
                }
            }
            
            if (stripos($vhsslContent, 'keyFile') === false) {
                $vhsslContent = "\n  keyFile                 /etc/letsencrypt/live/\$VH_NAME/privkey.pem" . $vhsslContent;
                $changes[] = 'Added keyFile path';
                $vhsslUpdated = true;
            }
            if (stripos($vhsslContent, 'certFile') === false) {
                $vhsslContent = "\n  certFile                /etc/letsencrypt/live/\$VH_NAME/fullchain.pem" . $vhsslContent;
                $changes[] = 'Added certFile path';
                $vhsslUpdated = true;
            }
            
            if ($vhsslUpdated) {
                $config = preg_replace('/vhssl\s*\{[^}]*\}/s', "vhssl  {\n" . trim($vhsslContent) . "\n}", $config);
            }
        } else {
            // Add new vhssl block
            $modernVhssl = "vhssl  {\n" .
                "  keyFile                 /etc/letsencrypt/live/\$VH_NAME/privkey.pem\n" .
                "  certFile                /etc/letsencrypt/live/\$VH_NAME/fullchain.pem\n";
            foreach ($optimalVhssl as $key => $value) {
                $modernVhssl .= "  " . $key . str_repeat(' ', max(1, 20 - strlen($key))) . $value . "\n";
            }
            $modernVhssl .= "}";
            $config = rtrim($config) . "\n\n" . $modernVhssl . "\n";
            $changes[] = 'Added vhssl block with TLS 1.2/1.3 and modern ciphers';
        }

        // 2. Handle enableGzip - disable to mitigate BREACH
        if (preg_match('/enableGzip\s+1/i', $config)) {
            $config = preg_replace('/enableGzip\s+1/i', 'enableGzip                0', $config);
            $changes[] = 'Disabled gzip compression (BREACH mitigation)';
        }

        // 3. Handle security headers using OLS heredoc syntax
        // Check which headers are missing
        $missingHeaders = [];
        // Note: CSP removed - it's site-specific and can break resources
        $headerChecks = [
            'Strict-Transport-Security' => 'HSTS',
            'X-Frame-Options' => 'X-Frame-Options',
            'X-Content-Type-Options' => 'X-Content-Type',
            'Referrer-Policy' => 'Referrer-Policy',
        ];
        
        foreach ($headerChecks as $header => $name) {
            if (stripos($config, $header) === false) {
                $missingHeaders[] = $name;
            }
        }
        
        // Also check if allowBrowse needs fixing (must be 1 for static files)
        $needsAllowBrowseFix = preg_match('/allowBrowse\s+0/', $config);
        
        // Check if CSP is present (we need to remove it as it breaks sites)
        $hasBrokenCsp = stripos($config, 'Content-Security-Policy') !== false;

        // If any security headers are missing, allowBrowse is wrong, or CSP exists, replace/create the entire context block
        // This is the only reliable way in OLS - heredoc syntax
        if (!empty($missingHeaders) || $needsAllowBrowseFix || $hasBrokenCsp) {
            if ($needsAllowBrowseFix) {
                $changes[] = 'Fixed allowBrowse (set to 1 for static files)';
            }
            if ($hasBrokenCsp) {
                $changes[] = 'Removed CSP header (site-specific, can break resources)';
            }
            // Build the optimized context block with heredoc syntax
            $optimalContext = <<<'CONTEXT'
context / {
  location                $DOC_ROOT/
  allowBrowse             1
  rewrite  {
    enable                1
    rules                 <<<END_RULES
RewriteRule .* - [E=XFO:SAMEORIGIN]
RewriteRule .* - [E=XCTO:nosniff]
RewriteRule .* - [E=RP:strict-origin-when-cross-origin]
END_RULES
  }
  extraHeaders            <<<END_HEADERS
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Frame-Options: %{XFO}e
X-Content-Type-Options: %{XCTO}e
Referrer-Policy: %{RP}e
END_HEADERS
}
CONTEXT;

            // Remove existing context / block if present (handles heredoc syntax)
            $config = $this->removeContextBlock($config);
            
            // Add the new context block before vhssl
            if (preg_match('/(vhssl\s*\{)/', $config)) {
                $config = preg_replace('/(vhssl\s*\{)/', $optimalContext . "\n\n$1", $config);
            } else {
                $config = rtrim($config) . "\n\n" . $optimalContext . "\n";
            }
            
            $changes[] = 'Added security headers: ' . implode(', ', $missingHeaders);
        }

        // Clean up any double newlines
        $config = preg_replace('/\n{3,}/', "\n\n", $config);

        // Check if anything changed
        if (trim($config) === trim($originalConfig)) {
            return $this->success([
                'domain' => $domain,
                'changes' => ['No changes needed - configuration already optimal'],
                'reloaded' => false,
                'current_config' => $this->analyzeVhostConfig($originalConfig),
            ], 'SSL configuration already optimal');
        }

        // Backup original config
        $backupPath = $this->backupFile($configFile, 'fixSslConfig', $actor);

        // Write updated config
        if (file_put_contents($configFile, $config) === false) {
            return $this->error('Failed to write config file');
        }

        // Reload OpenLiteSpeed
        $reloadResult = $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);
        
        return $this->success([
            'domain' => $domain,
            'changes' => $changes,
            'backup' => $backupPath,
            'reloaded' => $reloadResult['success'],
        ], 'SSL configuration updated successfully');
    }

    /**
     * Analyze current vhost config and return what settings are present
     */
    private function analyzeVhostConfig(string $config): array
    {
        $analysis = [
            'has_vhssl' => false,
            'ssl_settings' => [],
            'security_headers' => [],
            'has_context_block' => false,
        ];
        
        // Check for vhssl block
        if (preg_match('/vhssl\s*\{([^}]*)\}/s', $config, $vhsslMatch)) {
            $analysis['has_vhssl'] = true;
            $vhsslContent = $vhsslMatch[1];
            
            // Extract SSL settings
            $settingsToCheck = ['sslProtocol', 'ciphers', 'enableECDHE', 'enableQuic', 'enableStapling'];
            foreach ($settingsToCheck as $setting) {
                if (preg_match('/^\s*' . preg_quote($setting, '/') . '\s+(.*)$/m', $vhsslContent, $m)) {
                    $analysis['ssl_settings'][$setting] = trim($m[1]);
                }
            }
        }
        
        // Check for security headers
        $headers = ['Strict-Transport-Security', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy'];
        foreach ($headers as $header) {
            $analysis['security_headers'][$header] = stripos($config, $header) !== false;
        }
        
        // Check for context block
        $analysis['has_context_block'] = preg_match('/context\s*\/\s*\{/', $config) === 1;
        
        return $analysis;
    }
    
    /**
     * Remove context / block from vhost config (handles heredoc syntax)
     */
    private function removeContextBlock(string $config): string
    {
        // Find the start of context / block
        if (!preg_match('/context\s*\/\s*\{/', $config, $matches, PREG_OFFSET_CAPTURE)) {
            return $config;
        }
        
        $startPos = $matches[0][1];
        $len = strlen($config);
        $braceCount = 0;
        $inHeredoc = false;
        $heredocEnd = '';
        $endPos = $startPos;
        
        // Find the matching closing brace, accounting for heredocs
        for ($i = $startPos; $i < $len; $i++) {
            $char = $config[$i];
            
            // Check for heredoc start
            if (!$inHeredoc && substr($config, $i, 3) === '<<<') {
                // Find heredoc identifier
                $restOfLine = substr($config, $i + 3, 50);
                if (preg_match('/^(\w+)/', $restOfLine, $m)) {
                    $inHeredoc = true;
                    $heredocEnd = $m[1];
                }
            }
            
            // Check for heredoc end
            if ($inHeredoc) {
                // Look for heredoc end at start of line
                $lineStart = strrpos(substr($config, 0, $i), "\n");
                if ($lineStart !== false) {
                    $lineContent = substr($config, $lineStart + 1, strlen($heredocEnd));
                    if ($lineContent === $heredocEnd) {
                        $inHeredoc = false;
                        $heredocEnd = '';
                    }
                }
            }
            
            // Count braces only outside heredocs
            if (!$inHeredoc) {
                if ($char === '{') {
                    $braceCount++;
                } elseif ($char === '}') {
                    $braceCount--;
                    if ($braceCount === 0) {
                        $endPos = $i + 1;
                        break;
                    }
                }
            }
        }
        
        // Remove the context block and clean up whitespace
        $before = rtrim(substr($config, 0, $startPos));
        $after = ltrim(substr($config, $endPos));
        
        return $before . "\n\n" . $after;
    }
}

