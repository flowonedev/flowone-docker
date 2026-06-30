<?php
/**
 * ModSecurity Action Handler
 * 
 * Manages ModSecurity WAF settings.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class ModsecAction extends BaseAction
{
    private string $configPath = '/usr/local/lsws/conf/modsec.conf';
    private string $rulesPath = '/usr/local/lsws/conf/modsec';

    public function getNamespace(): string
    {
        return 'modsec';
    }

    public function getMethods(): array
    {
        return ['status', 'setMode', 'rules', 'enableRule', 'disableRule', 'auditLog'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['setMode', 'enableRule', 'disableRule']);
    }

    /**
     * Get ModSecurity status
     */
    protected function actionStatus(array $params, string $actor): array
    {
        $config = $this->parseConfig();

        return $this->success([
            'enabled' => $config['SecRuleEngine'] !== 'Off',
            'mode' => $config['SecRuleEngine'] ?? 'Off',
            'audit_log' => $config['SecAuditLog'] ?? null,
            'rules_loaded' => $this->countLoadedRules(),
        ]);
    }

    /**
     * Set ModSecurity mode
     */
    protected function actionSetMode(array $params, string $actor): array
    {
        if (!isset($params['mode'])) {
            return $this->error('Mode is required');
        }

        $mode = $params['mode'];
        
        if (!Validator::modsecMode($mode)) {
            return $this->error('Invalid mode. Use: On, Off, or DetectionOnly');
        }

        // Backup
        $this->backupFile($this->configPath, 'setMode', $actor);

        // Read current config
        $content = file_exists($this->configPath) ? file_get_contents($this->configPath) : '';

        // Update or add SecRuleEngine directive
        if (preg_match('/^SecRuleEngine\s+/m', $content)) {
            $newContent = preg_replace('/^SecRuleEngine\s+\S+/m', "SecRuleEngine {$mode}", $content);
        } else {
            $newContent = "SecRuleEngine {$mode}\n" . $content;
        }

        file_put_contents($this->configPath, $newContent);

        // Reload OpenLiteSpeed
        $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);

        return $this->success([
            'mode' => $mode,
        ], "ModSecurity mode set to {$mode}");
    }

    /**
     * List loaded rules
     */
    protected function actionRules(array $params, string $actor): array
    {
        $rules = [];

        // Check for OWASP CRS in multiple possible locations
        $crsPaths = [
            $this->rulesPath . '/owasp-crs',
            $this->rulesPath . '/crs',
            $this->rulesPath . '/coreruleset',
        ];
        
        foreach ($crsPaths as $crsPath) {
            if (is_dir($crsPath)) {
                // Check rules subdirectory
                $rulesDir = $crsPath . '/rules';
                if (is_dir($rulesDir)) {
                    $ruleFiles = glob($rulesDir . '/*.conf');
                    foreach ($ruleFiles as $file) {
                        $rules[] = [
                            'id' => basename($file, '.conf'),
                            'name' => basename($file),
                            'file' => basename($file),
                            'path' => $file,
                            'enabled' => $this->isRuleEnabled($file),
                            'type' => 'crs',
                            'description' => $this->getRuleDescription(basename($file)),
                        ];
                    }
                }
                
                // Also check root of CRS directory
                $ruleFiles = glob($crsPath . '/*.conf');
                foreach ($ruleFiles as $file) {
                    $rules[] = [
                        'id' => basename($file, '.conf'),
                        'name' => basename($file),
                        'file' => basename($file),
                        'path' => $file,
                        'enabled' => $this->isRuleEnabled($file),
                        'type' => 'crs',
                        'description' => $this->getRuleDescription(basename($file)),
                    ];
                }
                break; // Found CRS, stop looking
            }
        }

        // Check for custom rules in modsec directory
        $customFiles = glob($this->rulesPath . '/*.conf');
        foreach ($customFiles as $file) {
            $basename = basename($file);
            // Skip main config and already added files
            if ($basename === 'modsec.conf' || strpos($basename, 'modsec') !== false) {
                continue;
            }
            $rules[] = [
                'id' => basename($file, '.conf'),
                'name' => basename($file),
                'file' => basename($file),
                'path' => $file,
                'enabled' => $this->isRuleEnabled($file),
                'type' => 'custom',
                'description' => 'Custom rule file',
            ];
        }

        return $this->success(['rules' => $rules]);
    }
    
    /**
     * Get rule description from filename
     */
    private function getRuleDescription(string $filename): string
    {
        $descriptions = [
            'REQUEST-901' => 'Initialization',
            'REQUEST-905' => 'Common Exceptions',
            'REQUEST-910' => 'IP Reputation',
            'REQUEST-911' => 'Method Enforcement',
            'REQUEST-912' => 'DOS Protection',
            'REQUEST-913' => 'Scanner Detection',
            'REQUEST-920' => 'Protocol Enforcement',
            'REQUEST-921' => 'Protocol Attack',
            'REQUEST-930' => 'Local File Inclusion',
            'REQUEST-931' => 'Remote File Inclusion',
            'REQUEST-932' => 'Remote Code Execution',
            'REQUEST-933' => 'PHP Injection',
            'REQUEST-934' => 'Node.js Injection',
            'REQUEST-941' => 'XSS Attack',
            'REQUEST-942' => 'SQL Injection',
            'REQUEST-943' => 'Session Fixation',
            'REQUEST-944' => 'Java Attack',
            'RESPONSE-950' => 'Data Leakage',
            'RESPONSE-951' => 'Data Leakage SQL',
            'RESPONSE-952' => 'Data Leakage Java',
            'RESPONSE-953' => 'Data Leakage PHP',
            'RESPONSE-954' => 'Data Leakage IIS',
            'rules' => 'Custom Rules',
        ];
        
        foreach ($descriptions as $prefix => $desc) {
            if (stripos($filename, $prefix) !== false) {
                return $desc;
            }
        }
        
        return 'ModSecurity Rule';
    }

    /**
     * Enable a rule file
     */
    protected function actionEnableRule(array $params, string $actor): array
    {
        if (!isset($params['rule'])) {
            return $this->error('Rule file is required');
        }

        $rule = $params['rule'];
        
        // Validate rule file name
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.conf$/', $rule)) {
            return $this->error('Invalid rule file name');
        }

        // Find the rule file
        $rulePath = $this->findRuleFile($rule);
        
        if (!$rulePath) {
            return $this->error("Rule file not found: {$rule}");
        }

        // Backup main config
        $this->backupFile($this->configPath, 'enableRule', $actor);

        // Add include if not present
        $content = file_get_contents($this->configPath);
        $includeLine = "Include {$rulePath}";

        if (strpos($content, $includeLine) === false) {
            $content .= "\n{$includeLine}\n";
            file_put_contents($this->configPath, $content);
        }

        // Reload
        $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);

        return $this->success([
            'rule' => $rule,
            'path' => $rulePath,
        ], "Rule {$rule} enabled");
    }

    /**
     * Disable a rule file
     */
    protected function actionDisableRule(array $params, string $actor): array
    {
        if (!isset($params['rule'])) {
            return $this->error('Rule file is required');
        }

        $rule = $params['rule'];
        
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.conf$/', $rule)) {
            return $this->error('Invalid rule file name');
        }

        $rulePath = $this->findRuleFile($rule);
        
        if (!$rulePath) {
            return $this->error("Rule file not found: {$rule}");
        }

        // Backup main config
        $this->backupFile($this->configPath, 'disableRule', $actor);

        // Remove include line
        $content = file_get_contents($this->configPath);
        $pattern = '/^Include\s+' . preg_quote($rulePath, '/') . '\s*$/m';
        $newContent = preg_replace($pattern, '', $content);
        $newContent = preg_replace('/\n{3,}/', "\n\n", $newContent);
        
        file_put_contents($this->configPath, $newContent);

        // Reload
        $this->execCommand('/usr/local/lsws/bin/lswsctrl', ['reload']);

        return $this->success([
            'rule' => $rule,
        ], "Rule {$rule} disabled");
    }

    /**
     * Get audit log entries
     */
    protected function actionAuditLog(array $params, string $actor): array
    {
        $config = $this->parseConfig();
        $logPath = $config['SecAuditLog'] ?? '/var/log/modsec_audit.log';

        if (!file_exists($logPath)) {
            return $this->success(['entries' => [], 'path' => $logPath]);
        }

        $limit = $params['limit'] ?? 100;
        $entries = [];

        // Read last N entries
        $file = new \SplFileObject($logPath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $start = max(0, $totalLines - ($limit * 20)); // Estimate ~20 lines per entry
        $file->seek($start);

        $currentEntry = null;
        
        while (!$file->eof()) {
            $line = $file->fgets();
            
            if (preg_match('/^--[a-f0-9]+-([A-Z])--$/', $line, $m)) {
                $section = $m[1];
                
                if ($section === 'A') {
                    // New entry
                    if ($currentEntry) {
                        $entries[] = $currentEntry;
                    }
                    $currentEntry = [
                        'sections' => [],
                        'timestamp' => null,
                        'client_ip' => null,
                        'request_uri' => null,
                        'rule_id' => null,
                    ];
                }
                
                if ($currentEntry) {
                    $currentEntry['sections'][$section] = '';
                }
            } elseif ($currentEntry && !empty($currentEntry['sections'])) {
                $lastSection = array_key_last($currentEntry['sections']);
                $currentEntry['sections'][$lastSection] .= $line;
            }
        }

        if ($currentEntry) {
            $entries[] = $currentEntry;
        }

        // Parse entries
        foreach ($entries as &$entry) {
            if (isset($entry['sections']['A'])) {
                // Parse audit log header
                if (preg_match('/\[(\d{2}\/\w+\/\d{4}:\d{2}:\d{2}:\d{2})/', $entry['sections']['A'], $m)) {
                    $entry['timestamp'] = $m[1];
                }
                if (preg_match('/(\d+\.\d+\.\d+\.\d+)/', $entry['sections']['A'], $m)) {
                    $entry['client_ip'] = $m[1];
                }
            }
            if (isset($entry['sections']['B'])) {
                // Parse request line
                if (preg_match('/^\S+\s+(\S+)/', $entry['sections']['B'], $m)) {
                    $entry['request_uri'] = $m[1];
                }
            }
            if (isset($entry['sections']['H'])) {
                // Parse matched rules
                if (preg_match('/id "(\d+)"/', $entry['sections']['H'], $m)) {
                    $entry['rule_id'] = $m[1];
                }
            }

            // Remove raw sections for cleaner output
            unset($entry['sections']);
        }

        // Return last $limit entries
        $entries = array_slice($entries, -$limit);

        return $this->success([
            'entries' => array_reverse($entries),
            'path' => $logPath,
        ]);
    }

    /**
     * Parse ModSecurity config
     */
    private function parseConfig(): array
    {
        $config = [];

        if (!file_exists($this->configPath)) {
            return $config;
        }

        $content = file_get_contents($this->configPath);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line && !str_starts_with($line, '#')) {
                if (preg_match('/^(\S+)\s+(.+)$/', $line, $m)) {
                    $config[$m[1]] = trim($m[2], '"');
                }
            }
        }

        return $config;
    }

    /**
     * Count loaded rules
     */
    private function countLoadedRules(): int
    {
        $count = 0;

        if (!file_exists($this->configPath)) {
            return $count;
        }

        $content = file_get_contents($this->configPath);
        preg_match_all('/^Include\s+.+\.conf/m', $content, $matches);
        
        return count($matches[0]);
    }

    /**
     * Check if a rule file is enabled
     */
    private function isRuleEnabled(string $rulePath): bool
    {
        // Check multiple config files for include directives
        $configFiles = [
            $this->configPath,
            $this->rulesPath . '/rules.conf',
            $this->rulesPath . '/owasp-crs/crs-setup.conf',
        ];
        
        $ruleBasename = basename($rulePath);
        $ruleDir = dirname($rulePath);
        
        foreach ($configFiles as $configFile) {
            if (!file_exists($configFile)) {
                continue;
            }
            
            $content = file_get_contents($configFile);
            
            // Check for direct include
            if (strpos($content, "Include {$rulePath}") !== false) {
                return true;
            }
            
            // Check for relative include
            if (strpos($content, "Include {$ruleBasename}") !== false) {
                return true;
            }
            
            // Check for wildcard include of directory (e.g., "Include rules/*.conf")
            $relDir = basename($ruleDir);
            if (preg_match('/Include\s+' . preg_quote($relDir, '/') . '\/\*\.conf/i', $content)) {
                return true;
            }
            
            // Check for include all in owasp-crs/rules
            if (strpos($rulePath, 'owasp-crs/rules') !== false) {
                if (preg_match('/Include.*owasp-crs\/rules\/\*\.conf/i', $content)) {
                    return true;
                }
            }
        }
        
        // Also check if the main modsec.conf includes the rules.conf
        // If so, and rules.conf includes the CRS, consider them enabled
        if (file_exists($this->configPath)) {
            $mainContent = file_get_contents($this->configPath);
            if (strpos($mainContent, 'Include') !== false && strpos($mainContent, 'rules.conf') !== false) {
                // Rules are loaded via rules.conf - check that file
                $rulesConfPath = $this->rulesPath . '/rules.conf';
                if (file_exists($rulesConfPath)) {
                    $rulesContent = file_get_contents($rulesConfPath);
                    if (strpos($rulesContent, 'owasp-crs') !== false || 
                        strpos($rulesContent, basename($rulePath)) !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Find rule file path
     */
    private function findRuleFile(string $rule): ?string
    {
        // Check multiple CRS locations
        $searchPaths = [
            $this->rulesPath . '/owasp-crs/rules/' . $rule,
            $this->rulesPath . '/owasp-crs/' . $rule,
            $this->rulesPath . '/crs/rules/' . $rule,
            $this->rulesPath . '/crs/' . $rule,
            $this->rulesPath . '/' . $rule,
        ];

        foreach ($searchPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}

