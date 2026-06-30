<?php

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

/**
 * CPGuard WAF management actions
 * 
 * Data sources (cPanel/WHM CPGuard):
 * - License: /opt/cpguard/app/data/app.json (JSON)
 * - Config: /opt/cpguard/app/data/config.db (SQLite)
 * - Reports: /opt/cpguard/app/data/reports.db (SQLite)
 * - WAF Logs: /opt/cpguard/app/data/waf.db (SQLite)
 * - Incidents: /opt/cpguard/app/data/incidents.db (SQLite)
 * - Firewall: /opt/cpguard/app/data/firewall.db (SQLite)
 */
class CpguardAction extends BaseAction
{
    // CPGuard installation paths
    private const CPGUARD_PATHS = [
        '/etc/cpguard',
        '/opt/cpguard',
    ];
    
    // SQLite database paths (PRIMARY DATA SOURCE)
    private const CONFIG_DB = '/opt/cpguard/app/data/config.db';
    private const REPORTS_DB = '/opt/cpguard/app/data/reports.db';
    private const WAF_DB = '/opt/cpguard/app/data/waf.db';
    private const INCIDENTS_DB = '/opt/cpguard/app/data/incidents.db';
    private const FIREWALL_DB = '/opt/cpguard/app/data/firewall.db';
    private const SCANNER_DB = '/opt/cpguard/app/data/scanner.db';
    
    // JSON data files
    private const APP_JSON = '/opt/cpguard/app/data/app.json';
    
    // Legacy config files (for backwards compatibility)
    private const MAIN_CONFIG = '/etc/cpguard/conf/main.conf';
    private const MODSEC_CONFIG = '/etc/cpguard/cpguard_modsec100.conf';
    
    // Feature-specific files
    private const BADBOTS_FILE = '/etc/cpguard/badbots.txt';
    private const BFURLS_FILE = '/etc/cpguard/bfurls.txt';
    private const WAFURLS_FILE = '/etc/cpguard/wafurls.txt';
    private const RULES_FILE = '/etc/cpguard/rules.txt';
    
    // Whitelist files
    private const WHITELIST_IPS_FILE = '/opt/cpguard/whitelistips.txt';
    private const WHITELIST_DOMAINS_FILE = '/opt/cpguard/whitelistdomains.txt';
    private const WHITELIST_FILES_FILE = '/etc/cpguard/whitelistfiles.txt';
    private const WHITELIST_URLS_FILE = '/etc/cpguard/whitelist.conf';
    
    // Blacklist files  
    private const BLACKLIST_IPS_FILE = '/opt/cpguard/blacklistips.txt';
    private const BLACKLIST_FILES_FILE = '/etc/cpguard/blacklistfiles.txt';
    
    // License and logs
    private const LICENSE_FILE = '/etc/cpguard/LICENSE_cPGuard';
    private const LOGS_DIR = '/opt/cpguard/logs';
    
    public function getNamespace(): string
    {
        return 'cpguard';
    }

    public function getMethods(): array
    {
        return [
            'status', 
            'wafStatus', 
            'stats',
            'install',
            'uninstall',
            'getLicense',
            'updateLicense',
            'getLists',
            'addToWhitelist',
            'removeFromWhitelist',
            'addToBlacklist',
            'removeFromBlacklist',
            'getConfig',
            'updateConfig',
            'toggleModule',
            'restartService',
            'triggerScan',
        ];
    }

    public function requiresBackup(string $method): bool
    {
        // Methods that modify config should backup
        return in_array($method, ['updateConfig', 'toggleModule', 'uninstall']);
    }

    // ============================================
    // Action Handlers
    // ============================================

    protected function actionStatus(array $params, string $actor): array
    {
        return $this->status($params);
    }

    protected function actionWafStatus(array $params, string $actor): array
    {
        return $this->wafStatus($params);
    }

    protected function actionStats(array $params, string $actor): array
    {
        return $this->stats($params);
    }

    protected function actionInstall(array $params, string $actor): array
    {
        return $this->install($params);
    }

    protected function actionUninstall(array $params, string $actor): array
    {
        return $this->uninstall($params);
    }

    protected function actionGetLicense(array $params, string $actor): array
    {
        return $this->getLicense($params);
    }

    protected function actionUpdateLicense(array $params, string $actor): array
    {
        return $this->updateLicense($params);
    }

    protected function actionGetLists(array $params, string $actor): array
    {
        return $this->getLists($params);
    }

    protected function actionAddToWhitelist(array $params, string $actor): array
    {
        return $this->addToWhitelist($params);
    }

    protected function actionRemoveFromWhitelist(array $params, string $actor): array
    {
        return $this->removeFromWhitelist($params);
    }

    protected function actionAddToBlacklist(array $params, string $actor): array
    {
        return $this->addToBlacklist($params);
    }

    protected function actionRemoveFromBlacklist(array $params, string $actor): array
    {
        return $this->removeFromBlacklist($params);
    }

    protected function actionGetConfig(array $params, string $actor): array
    {
        return $this->getConfig($params);
    }

    protected function actionUpdateConfig(array $params, string $actor): array
    {
        return $this->updateConfig($params);
    }

    protected function actionToggleModule(array $params, string $actor): array
    {
        return $this->toggleModule($params);
    }

    protected function actionRestartService(array $params, string $actor): array
    {
        return $this->restartService($params);
    }

    protected function actionTriggerScan(array $params, string $actor): array
    {
        return $this->triggerScan($params);
    }

    // ============================================
    // Installation & License Management
    // ============================================

    /**
     * Install CPGuard with license key
     */
    private function install(array $params): array
    {
        $licenseKey = $params['license_key'] ?? '';
        
        if (empty($licenseKey)) {
            return [
                'success' => false,
                'error' => 'License key is required',
            ];
        }
        
        // Check if already installed
        if ($this->isInstalled()) {
            return [
                'success' => false,
                'error' => 'CPGuard is already installed',
            ];
        }
        
        // Download and run CPGuard installer
        // CPGuard uses: curl -sL https://download.configserver.com/cpguard/install.sh | bash -s -- <license_key>
        $installCmd = sprintf(
            'curl -sL https://download.configserver.com/cpguard/install.sh 2>/dev/null | bash -s -- %s 2>&1',
            escapeshellarg($licenseKey)
        );
        
        $output = [];
        $exitCode = 0;
        exec($installCmd, $output, $exitCode);
        
        if ($exitCode !== 0) {
            return [
                'success' => false,
                'error' => 'Installation failed',
                'output' => implode("\n", $output),
            ];
        }
        
        // Verify installation
        sleep(2); // Wait for install to complete
        if (!$this->isInstalled()) {
            return [
                'success' => false,
                'error' => 'Installation completed but CPGuard not detected',
                'output' => implode("\n", $output),
            ];
        }
        
        return [
            'success' => true,
            'message' => 'CPGuard installed successfully',
            'output' => implode("\n", $output),
        ];
    }

    /**
     * Uninstall CPGuard
     */
    private function uninstall(array $params): array
    {
        if (!$this->isInstalled()) {
            return [
                'success' => false,
                'error' => 'CPGuard is not installed',
            ];
        }
        
        // Stop service first
        exec('systemctl stop cpguard 2>/dev/null');
        
        // Run uninstaller if available
        $uninstallPaths = [
            '/opt/cpguard/uninstall.sh',
            '/usr/local/cpguard/uninstall.sh',
        ];
        
        foreach ($uninstallPaths as $uninstaller) {
            if (file_exists($uninstaller)) {
                exec("bash " . escapeshellarg($uninstaller) . " -y 2>&1", $output, $exitCode);
                return [
                    'success' => $exitCode === 0,
                    'message' => $exitCode === 0 ? 'CPGuard uninstalled successfully' : 'Uninstall may have failed',
                    'output' => implode("\n", $output),
                ];
            }
        }
        
        // Manual removal if no uninstaller
        exec('rm -rf /opt/cpguard /etc/cpguard 2>&1', $output, $exitCode);
        exec('systemctl disable cpguard 2>/dev/null');
        
        return [
            'success' => true,
            'message' => 'CPGuard removed manually',
        ];
    }

    /**
     * Get license information from /opt/cpguard/app/data/app.json
     */
    private function getLicense(array $params): array
    {
        if (!$this->isInstalled()) {
            return [
                'success' => true,
                'data' => [
                    'installed' => false,
                ],
            ];
        }
        
        // Read from app.json (PRIMARY SOURCE)
        $appData = $this->readAppJson();
        
        $licenseKey = $appData['license_key'] ?? null;
        $serverId = $appData['server_id'] ?? null;
        $serverIp = $appData['public_ip'] ?? null;
        $package = $appData['package'] ?? [];
        
        // Extract package info
        $packageName = $package['name'] ?? null;
        $expiryDate = $package['date_renews'] ?? null;
        $term = $package['term'] ?? null;
        $period = $package['period'] ?? null;
        
        // Determine license status
        $licenseStatus = 'unknown';
        if ($licenseKey && $expiryDate) {
            $expiryTimestamp = strtotime($expiryDate);
            if ($expiryTimestamp && $expiryTimestamp > time()) {
                $licenseStatus = 'active';
            } elseif ($expiryTimestamp) {
                $licenseStatus = 'expired';
            }
        } elseif ($licenseKey) {
            // Check logs for license status
            $logFile = self::LOGS_DIR . '/application_log';
            if (file_exists($logFile)) {
                $recentLog = shell_exec("tail -20 " . escapeshellarg($logFile) . " 2>/dev/null");
                if ($recentLog && stripos($recentLog, 'License renewed successfully') !== false) {
                    $licenseStatus = 'active';
                }
            }
        }
        
        // Mask the license key for security (show first 4 and last 4 chars)
        $maskedKey = null;
        if ($licenseKey && strlen($licenseKey) > 8) {
            $maskedKey = substr($licenseKey, 0, 4) . str_repeat('*', strlen($licenseKey) - 8) . substr($licenseKey, -4);
        } elseif ($licenseKey) {
            $maskedKey = str_repeat('*', strlen($licenseKey));
        }
        
        return [
            'success' => true,
            'data' => [
                'installed' => true,
                'license_key' => $maskedKey,
                'license_key_full' => $licenseKey,
                'server_id' => $serverId,
                'server_ip' => $serverIp,
                'status' => $licenseStatus,
                'expiry_date' => $expiryDate,
                'package_name' => $packageName,
                'term' => $term,
                'period' => $period,
            ],
        ];
    }
    
    /**
     * Read app.json license file
     */
    private function readAppJson(): array
    {
        if (!file_exists(self::APP_JSON)) {
            return [];
        }
        $content = @file_get_contents(self::APP_JSON);
        if (!$content) {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Query SQLite database and return results using PDO (more widely available than SQLite3)
     */
    private function querySqlite(string $dbFile, string $sql): array
    {
        if (!file_exists($dbFile)) {
            return [];
        }
        
        try {
            // Use PDO which is more commonly available than SQLite3 extension
            $pdo = new \PDO("sqlite:{$dbFile}", null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            
            $stmt = $pdo->query($sql);
            if (!$stmt) {
                return [];
            }
            
            $rows = $stmt->fetchAll();
            return $rows ?: [];
        } catch (\PDOException $e) {
            // If PDO SQLite fails, try sqlite3 CLI as fallback
            return $this->querySqliteCli($dbFile, $sql);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Fallback: Query SQLite using command-line sqlite3
     */
    private function querySqliteCli(string $dbFile, string $sql): array
    {
        if (!file_exists($dbFile)) {
            return [];
        }
        
        // Use sqlite3 CLI with JSON output
        $cmd = sprintf(
            'sqlite3 -json %s %s 2>/dev/null',
            escapeshellarg($dbFile),
            escapeshellarg($sql)
        );
        
        $output = shell_exec($cmd);
        if (!$output) {
            // Try CSV mode as fallback
            $cmd = sprintf(
                'sqlite3 -header -csv %s %s 2>/dev/null',
                escapeshellarg($dbFile),
                escapeshellarg($sql)
            );
            $output = shell_exec($cmd);
            if (!$output) {
                return [];
            }
            
            // Parse CSV output
            $lines = explode("\n", trim($output));
            if (count($lines) < 2) {
                return [];
            }
            
            $headers = str_getcsv(array_shift($lines));
            $rows = [];
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $values = str_getcsv($line);
                $rows[] = array_combine($headers, $values);
            }
            return $rows;
        }
        
        $data = json_decode($output, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Get single config value from config.db
     */
    private function getConfigValue(string $key, $default = null)
    {
        $rows = $this->querySqlite(self::CONFIG_DB, "SELECT data_value FROM config WHERE data_key = '{$key}' LIMIT 1");
        if (empty($rows)) {
            return $default;
        }
        
        $value = $rows[0]['data_value'] ?? $default;
        
        // Try to decode JSON values (stored as strings like '"email"' or 'true')
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            // Handle string booleans
            if ($value === 'true') return true;
            if ($value === 'false') return false;
        }
        
        return $value;
    }
    
    /**
     * Get all config values from config.db
     */
    private function getAllConfigValues(): array
    {
        $rows = $this->querySqlite(self::CONFIG_DB, "SELECT data_key, data_value FROM config");
        $config = [];
        
        foreach ($rows as $row) {
            $key = $row['data_key'];
            $value = $row['data_value'];
            
            // Decode JSON values
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $config[$key] = $decoded;
                } elseif ($value === 'true') {
                    $config[$key] = true;
                } elseif ($value === 'false') {
                    $config[$key] = false;
                } else {
                    $config[$key] = $value;
                }
            } else {
                $config[$key] = $value;
            }
        }
        
        return $config;
    }

    /**
     * Update/renew license key
     */
    private function updateLicense(array $params): array
    {
        $newLicenseKey = $params['license_key'] ?? '';
        
        if (empty($newLicenseKey)) {
            return [
                'success' => false,
                'error' => 'New license key is required',
            ];
        }
        
        if (!$this->isInstalled()) {
            return [
                'success' => false,
                'error' => 'CPGuard is not installed',
            ];
        }
        
        // Use license file location
        $licenseFile = '/etc/cpguard/license.key';
        @mkdir(dirname($licenseFile), 0755, true);
        
        // Backup old license
        if (file_exists($licenseFile)) {
            copy($licenseFile, $licenseFile . '.bak');
        }
        
        // Write new license
        $result = file_put_contents($licenseFile, $newLicenseKey);
        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Failed to write license file',
            ];
        }
        
        // Try to activate/validate the new license
        exec('cpguard --activate 2>&1', $output, $exitCode);
        
        // Restart service to apply new license
        exec('systemctl restart cpguard 2>/dev/null');
        
        return [
            'success' => true,
            'message' => 'License updated successfully',
            'activation_output' => implode("\n", $output),
        ];
    }

    // ============================================
    // Whitelist / Blacklist Management
    // ============================================

    /**
     * Get all whitelists and blacklists (from files AND SQLite databases)
     */
    private function getLists(array $params): array
    {
        // Get config from SQLite for country lists and other settings
        $config = $this->getAllConfigValues();
        
        // Get temp bans from firewall.db (actively blocked IPs)
        $tempBans = $this->getTempBans();
        
        $data = [
            // From text files
            'whitelist_ips' => $this->readListFile(self::WHITELIST_IPS_FILE),
            'whitelist_domains' => $this->readListFile(self::WHITELIST_DOMAINS_FILE),
            'whitelist_files' => $this->readListFile(self::WHITELIST_FILES_FILE),
            'whitelist_urls' => $this->readListFile(self::WHITELIST_URLS_FILE),
            'blacklist_ips' => $this->readListFile(self::BLACKLIST_IPS_FILE),
            'blacklist_files' => $this->readListFile(self::BLACKLIST_FILES_FILE),
            'bad_bots' => $this->readListFile(self::BADBOTS_FILE),
            'bf_urls' => $this->readListFile(self::BFURLS_FILE),
            'waf_urls' => $this->readListFile(self::WAFURLS_FILE),
            'rules' => $this->readListFile(self::RULES_FILE),
            
            // From SQLite config.db - Country lists
            'whitelist_countries' => $config['fw_whitelist_country'] ?? [],
            'blacklist_countries' => $config['fw_blacklist_country'] ?? [],
            
            // From SQLite config.db - Firewall lists
            'fw_whitelist_ips' => $config['fw_whitelisted_ips'] ?? [],
            'fw_blacklist_ips' => $config['fw_blacklisted_ips'] ?? [],
            
            // From SQLite firewall.db - Active temp bans
            'temp_bans' => $tempBans,
            
            // From SQLite config.db - Other lists
            'suspend_domain_whitelist' => $config['suspend_domain_whitelist'] ?? [],
            'suspend_user_whitelist' => $config['suspend_user_whitelist'] ?? [],
            'waf_rule_whitelist' => $config['waf_rule_whitelist'] ?? [],
            'wp_user_whitelist' => $config['wp_user_whitelist'] ?? [],
            'wp_plugin_blacklist' => $config['wp_plugin_blacklist'] ?? [],
            'db_scan_whitelisted_signatures' => $config['db_scan_whitelisted_signatures'] ?? [],
        ];
        
        return [
            'success' => true,
            'data' => $data,
        ];
    }
    
    /**
     * Get temp bans from firewall.db
     */
    private function getTempBans(): array
    {
        $rows = $this->querySqlite(
            self::FIREWALL_DB, 
            "SELECT ip, reason, country, ban_time, expire_time FROM temp_ban ORDER BY ban_time DESC LIMIT 100"
        );
        
        return $rows;
    }

    /**
     * Add entry to whitelist
     */
    private function addToWhitelist(array $params): array
    {
        $type = $params['type'] ?? ''; // 'ip' or 'domain'
        $value = $params['value'] ?? '';
        
        if (empty($type) || empty($value)) {
            return [
                'success' => false,
                'error' => 'Type and value are required',
            ];
        }
        
        $file = $type === 'domain' ? self::WHITELIST_DOMAINS_FILE : self::WHITELIST_IPS_FILE;
        
        // Validate input
        if ($type === 'ip' && !$this->isValidIp($value)) {
            return [
                'success' => false,
                'error' => 'Invalid IP address format',
            ];
        }
        
        if ($type === 'domain' && !$this->isValidDomain($value)) {
            return [
                'success' => false,
                'error' => 'Invalid domain format',
            ];
        }
        
        return $this->addToListFile($file, $value);
    }

    /**
     * Remove entry from whitelist
     */
    private function removeFromWhitelist(array $params): array
    {
        $type = $params['type'] ?? '';
        $value = $params['value'] ?? '';
        
        if (empty($type) || empty($value)) {
            return [
                'success' => false,
                'error' => 'Type and value are required',
            ];
        }
        
        $file = $type === 'domain' ? self::WHITELIST_DOMAINS_FILE : self::WHITELIST_IPS_FILE;
        
        return $this->removeFromListFile($file, $value);
    }

    /**
     * Add entry to blacklist
     */
    private function addToBlacklist(array $params): array
    {
        $type = $params['type'] ?? ''; // 'ip' or 'file'
        $value = $params['value'] ?? '';
        
        if (empty($type) || empty($value)) {
            return [
                'success' => false,
                'error' => 'Type and value are required',
            ];
        }
        
        $file = $type === 'file' ? self::BLACKLIST_FILES_FILE : self::BLACKLIST_IPS_FILE;
        
        // Validate IP if type is ip
        if ($type === 'ip' && !$this->isValidIp($value)) {
            return [
                'success' => false,
                'error' => 'Invalid IP address format',
            ];
        }
        
        return $this->addToListFile($file, $value);
    }

    /**
     * Remove entry from blacklist
     */
    private function removeFromBlacklist(array $params): array
    {
        $type = $params['type'] ?? '';
        $value = $params['value'] ?? '';
        
        if (empty($type) || empty($value)) {
            return [
                'success' => false,
                'error' => 'Type and value are required',
            ];
        }
        
        $file = $type === 'file' ? self::BLACKLIST_FILES_FILE : self::BLACKLIST_IPS_FILE;
        
        return $this->removeFromListFile($file, $value);
    }

    // ============================================
    // Configuration Management
    // ============================================

    /**
     * Get full CPGuard configuration from SQLite config.db
     */
    private function getConfig(array $params): array
    {
        if (!$this->isInstalled()) {
            return [
                'success' => true,
                'data' => [
                    'installed' => false,
                ],
            ];
        }
        
        // Get all config values from SQLite
        $config = $this->getAllConfigValues();
        
        // Get module-specific settings (organized)
        $modules = [
            'waf' => $this->getWafConfig(),
            'scanner' => $this->getScannerConfig(),
            'brute_force' => $this->getBruteForceConfig(),
            'captcha' => $this->getCaptchaConfig(),
            'bot_control' => $this->getBotControlConfig(),
            'country_blocking' => $this->getCountryBlockingConfig(),
            'ipdb' => $this->getIpdbConfig(),
            'firewall' => $this->getFirewallConfig(),
            'notifications' => $this->getNotificationConfig(),
            'wordpress' => $this->getWordPressConfig(),
        ];
        
        return [
            'success' => true,
            'data' => [
                'installed' => true,
                'config_source' => 'sqlite',
                'config_db' => self::CONFIG_DB,
                'settings' => $config,
                'modules' => $modules,
            ],
        ];
    }

    /**
     * Update CPGuard configuration
     */
    private function updateConfig(array $params): array
    {
        $settings = $params['settings'] ?? [];
        
        if (empty($settings)) {
            return [
                'success' => false,
                'error' => 'Settings are required',
            ];
        }
        
        $configFile = $this->findConfigFile();
        if (!$configFile) {
            return [
                'success' => false,
                'error' => 'Config file not found',
            ];
        }
        
        // Read current config
        $content = @file_get_contents($configFile);
        if ($content === false) {
            return [
                'success' => false,
                'error' => 'Failed to read config file',
            ];
        }
        
        // Backup config
        copy($configFile, $configFile . '.bak');
        
        // Update settings
        foreach ($settings as $key => $value) {
            // Try to replace existing setting
            $pattern = '/^' . preg_quote($key, '/') . '\s*[=:]\s*.*/m';
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "{$key}={$value}", $content);
            } else {
                // Add new setting
                $content .= "\n{$key}={$value}";
            }
        }
        
        // Write config
        $result = file_put_contents($configFile, $content);
        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Failed to write config file',
            ];
        }
        
        // Restart service to apply changes
        exec('systemctl restart cpguard 2>/dev/null');
        
        return [
            'success' => true,
            'message' => 'Configuration updated successfully',
        ];
    }

    /**
     * Toggle a CPGuard module on/off
     */
    private function toggleModule(array $params): array
    {
        $module = $params['module'] ?? '';
        $enabled = $params['enabled'] ?? true;
        
        if (empty($module)) {
            return [
                'success' => false,
                'error' => 'Module name is required',
            ];
        }
        
        // Map module names to config keys
        $moduleKeys = [
            'waf' => 'waf_enabled',
            'scanner' => 'malware_scanner',
            'malware_scanner' => 'malware_scanner',
            'brute_force' => 'brute_force_protection',
            'captcha' => 'captcha_enabled',
            'bot_control' => 'bot_control',
            'country_blocking' => 'country_blocking',
            'ipdb' => 'ipdb_firewall',
        ];
        
        $configKey = $moduleKeys[$module] ?? $module;
        $value = $enabled ? '1' : '0';
        
        return $this->updateConfig(['settings' => [$configKey => $value]]);
    }

    /**
     * Restart CPGuard service
     */
    private function restartService(array $params): array
    {
        $action = $params['action'] ?? 'restart'; // start, stop, restart
        
        $validActions = ['start', 'stop', 'restart', 'reload'];
        if (!in_array($action, $validActions)) {
            return [
                'success' => false,
                'error' => 'Invalid action. Use: start, stop, restart, reload',
            ];
        }
        
        exec("systemctl " . escapeshellarg($action) . " cpguard 2>&1", $output, $exitCode);
        
        return [
            'success' => $exitCode === 0,
            'message' => $exitCode === 0 ? "Service {$action}ed successfully" : "Failed to {$action} service",
            'output' => implode("\n", $output),
        ];
    }

    /**
     * Trigger a manual malware scan
     */
    private function triggerScan(array $params): array
    {
        $path = $params['path'] ?? '/home';
        $background = $params['background'] ?? true;
        
        // Validate path exists
        if (!is_dir($path)) {
            return [
                'success' => false,
                'error' => 'Path does not exist',
            ];
        }
        
        // Run scan
        $cmd = sprintf('cpguard --scan %s', escapeshellarg($path));
        if ($background) {
            $cmd .= ' > /var/log/cpguard/scan.log 2>&1 &';
        }
        
        exec($cmd, $output, $exitCode);
        
        return [
            'success' => true,
            'message' => $background ? 'Scan started in background' : 'Scan completed',
            'output' => implode("\n", $output),
        ];
    }

    // ============================================
    // Status Methods
    // ============================================

    /**
     * Get CPGuard installation and status info from SQLite databases
     */
    private function status(array $params): array
    {
        $installed = $this->isInstalled();
        
        if (!$installed) {
            return [
                'success' => true,
                'data' => [
                    'installed' => false,
                ],
            ];
        }

        // Get service status
        $serviceStatus = $this->getServiceStatus();
        
        // Get version
        $version = $this->getVersion();
        
        // Get module status from SQLite config.db
        $wafStatus = $this->getWafStatusData();
        
        // Get block stats from SQLite reports.db
        $stats = $this->getBlockStats();
        
        // Get last scan info
        $lastScan = $this->getLastScanInfo();
        
        // Get license info from app.json
        $licenseInfo = $this->getLicense([]);

        $data = [
            'installed' => true,
            'version' => $version,
            'service_status' => $serviceStatus,
            'process_running' => $serviceStatus === 'running',
            'data_source' => 'sqlite',
            
            // Module states from SQLite config.db
            'waf_enabled' => $wafStatus['waf_enabled'],
            'malware_scanner' => $wafStatus['malware_scanner'],
            'brute_force' => $wafStatus['brute_force'],
            'captcha' => $wafStatus['captcha'],
            'bot_control' => $wafStatus['bot_control'],
            'ipdb_firewall' => $wafStatus['ipdb_firewall'],
            'country_blocking' => $wafStatus['country_blocking'],
            'auto_clean' => $wafStatus['auto_clean'],
            'firewall' => $wafStatus['firewall'] ?? false,
            'dos_protection' => $wafStatus['dos_protection'] ?? false,
            'fail2ban' => $wafStatus['fail2ban'] ?? false,
            
            // Statistics from SQLite reports.db
            'blocked_today' => $stats['blocked_today'] ?? 0,
            'blocked_week' => $stats['blocked_week'] ?? 0,
            'blocked_month' => $stats['blocked_month'] ?? 0,
            'waf_blocks_today' => $stats['waf_blocks_today'] ?? 0,
            'brute_force_today' => $stats['brute_force_today'] ?? 0,
            'ipdb_blocks_today' => $stats['ipdb_blocks_today'] ?? 0,
            'virus_today' => $stats['virus_today'] ?? 0,
            'temp_bans' => $stats['temp_bans'] ?? 0,
            'waf_logs_total' => $stats['waf_logs_total'] ?? 0,
            'incidents_total' => $stats['incidents_total'] ?? 0,
            'active_rules' => $stats['active_rules'] ?? 0,
            'threats_detected' => $stats['threats_detected'] ?? 0,
            'last_scan' => $lastScan,
            
            // License from app.json
            'license_status' => $licenseInfo['data']['status'] ?? 'unknown',
            'license_expiry' => $licenseInfo['data']['expiry_date'] ?? null,
            'license_package' => $licenseInfo['data']['package_name'] ?? null,
            'server_id' => $licenseInfo['data']['server_id'] ?? null,
        ];

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * Get detailed WAF status
     */
    private function wafStatus(array $params): array
    {
        return $this->status($params);
    }

    /**
     * Get detailed statistics
     */
    private function stats(array $params): array
    {
        $stats = $this->getBlockStats();
        
        return [
            'success' => true,
            'data' => $stats ?? [],
        ];
    }

    // ============================================
    // Helper Methods
    // ============================================

    /**
     * Check if CPGuard is installed
     */
    private function isInstalled(): bool
    {
        foreach (self::CPGUARD_PATHS as $path) {
            if (is_dir($path)) {
                return true;
            }
        }
        
        // Also check if process is running
        exec('pgrep -f cpguard 2>/dev/null', $output, $exitCode);
        return $exitCode === 0 && !empty($output);
    }

    /**
     * Get CPGuard version - avoid CLI calls as they hang
     */
    private function getVersion(): ?string
    {
        // Try reading from version file first (faster, no CLI)
        $versionFiles = [
            '/opt/cpguard/version',
            '/opt/cpguard/VERSION',
            '/etc/cpguard/version',
        ];
        
        foreach ($versionFiles as $file) {
            if (file_exists($file)) {
                return trim(@file_get_contents($file));
            }
        }
        
        // Try to get version from API response (cached from app.json calls)
        // The API returns version in its response
        $apiVersionFile = '/opt/cpguard/app/data/cache.db';
        if (file_exists($apiVersionFile)) {
            $rows = $this->querySqlite($apiVersionFile, "SELECT data_value FROM cache WHERE data_key = 'version' LIMIT 1");
            if (!empty($rows)) {
                return $rows[0]['data_value'] ?? null;
            }
        }
        
        // Check nginx API response for version
        // The 403 responses included version: "5.81.01"
        // We can get this from the logs or just return a default
        $logFile = self::LOGS_DIR . '/application_log';
        if (file_exists($logFile)) {
            $recent = shell_exec("tail -100 " . escapeshellarg($logFile) . " 2>/dev/null | grep -oP 'version[\":]\\s*[\"]*\\K[0-9.]+' | tail -1");
            if ($recent && trim($recent)) {
                return trim($recent);
            }
        }
        
        return null;
    }

    /**
     * Get service status
     */
    private function getServiceStatus(): string
    {
        exec('systemctl is-active cpguard 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0) {
            return trim($output[0] ?? 'unknown');
        }
        
        // Check if process is running
        exec('pgrep -f cpguard 2>/dev/null', $output2, $exitCode2);
        if ($exitCode2 === 0 && !empty($output2)) {
            return 'running';
        }
        
        return 'stopped';
    }

    /**
     * Find config file path - uses MAIN_CONFIG (JSON)
     */
    private function findConfigFile(): ?string
    {
        if (file_exists(self::MAIN_CONFIG)) {
            return self::MAIN_CONFIG;
        }
        return null;
    }

    /**
     * Parse config file into array (handles JSON format)
     */
    private function parseConfigFile(): array
    {
        $configFile = $this->findConfigFile();
        if (!$configFile) {
            return [];
        }

        $content = @file_get_contents($configFile);
        if (!$content) {
            return [];
        }

        // Try JSON first (main.conf is JSON)
        $json = json_decode($content, true);
        if (is_array($json)) {
            return $json;
        }

        // Fallback to key=value parsing
        $config = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (preg_match('/^([^=:]+)[=:](.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2], " \t\n\r\0\x0B\"'");
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * Get WAF status data - reads from SQLite config.db
     */
    private function getWafStatusData(): array
    {
        // Read all config from SQLite database (PRIMARY SOURCE)
        $config = $this->getAllConfigValues();
        
        $result = [
            // WAF - from waf_switch in config.db
            'waf_enabled' => $config['waf_switch'] ?? false,
            // Malware Scanner - from scanner_switch
            'malware_scanner' => $config['scanner_switch'] ?? false,
            // Brute Force - from brute_force_switch
            'brute_force' => $config['brute_force_switch'] ?? false,
            // CAPTCHA - from waf_captcha_switch
            'captcha' => $config['waf_captcha_switch'] ?? false,
            // Bot Control - from waf_badbot_switch
            'bot_control' => $config['waf_badbot_switch'] ?? false,
            // IPDB Firewall - from fw_ipdb_switch
            'ipdb_firewall' => $config['fw_ipdb_switch'] ?? false,
            // Country Blocking - determined by having whitelist or blacklist countries
            'country_blocking' => !empty($config['fw_whitelist_country']) || !empty($config['fw_blacklist_country']),
            // Auto Clean - from auto_clean_switch
            'auto_clean' => $config['auto_clean_switch'] ?? false,
            // Firewall - from fw_switch
            'firewall' => $config['fw_switch'] ?? false,
            // DOS Protection - from fw_dos_switch
            'dos_protection' => $config['fw_dos_switch'] ?? false,
            // AI Bots - from fw_ai_bots_switch
            'ai_bots' => $config['fw_ai_bots_switch'] ?? false,
            // Fail2Ban - from fw_fail2ban_switch
            'fail2ban' => $config['fw_fail2ban_switch'] ?? false,
        ];

        return $result;
    }

    /**
     * Get block statistics from SQLite reports.db
     */
    private function getBlockStats(): array
    {
        $stats = [
            'blocked_today' => 0,
            'blocked_week' => 0,
            'blocked_month' => 0,
            'active_rules' => 0,
            'threats_detected' => 0,
            'waf_blocks_today' => 0,
            'brute_force_today' => 0,
            'ipdb_blocks_today' => 0,
            'virus_today' => 0,
            'temp_bans' => 0,
            'waf_logs_total' => 0,
            'incidents_total' => 0,
        ];

        // Get today's timestamp (start of day)
        $todayStart = strtotime('today midnight');
        $weekAgoStart = strtotime('-7 days midnight');
        $monthAgoStart = strtotime('-30 days midnight');
        
        // Query reports.db for hourly stats
        // Today's stats
        $todayRows = $this->querySqlite(
            self::REPORTS_DB,
            "SELECT SUM(virus) as virus, SUM(brute_force) as bf, SUM(waf) as waf, SUM(ipdb) as ipdb 
             FROM hourly WHERE time >= {$todayStart}"
        );
        
        if (!empty($todayRows)) {
            $row = $todayRows[0];
            $stats['virus_today'] = (int)($row['virus'] ?? 0);
            $stats['brute_force_today'] = (int)($row['bf'] ?? 0);
            $stats['waf_blocks_today'] = (int)($row['waf'] ?? 0);
            $stats['ipdb_blocks_today'] = (int)($row['ipdb'] ?? 0);
            $stats['blocked_today'] = $stats['waf_blocks_today'] + $stats['brute_force_today'] + $stats['ipdb_blocks_today'];
        }
        
        // Week stats
        $weekRows = $this->querySqlite(
            self::REPORTS_DB,
            "SELECT SUM(virus) as virus, SUM(brute_force) as bf, SUM(waf) as waf, SUM(ipdb) as ipdb 
             FROM hourly WHERE time >= {$weekAgoStart}"
        );
        
        if (!empty($weekRows)) {
            $row = $weekRows[0];
            $stats['blocked_week'] = (int)($row['waf'] ?? 0) + (int)($row['bf'] ?? 0) + (int)($row['ipdb'] ?? 0);
            $stats['threats_detected'] = (int)($row['virus'] ?? 0);
        }
        
        // Month stats
        $monthRows = $this->querySqlite(
            self::REPORTS_DB,
            "SELECT SUM(virus) as virus, SUM(brute_force) as bf, SUM(waf) as waf, SUM(ipdb) as ipdb 
             FROM hourly WHERE time >= {$monthAgoStart}"
        );
        
        if (!empty($monthRows)) {
            $row = $monthRows[0];
            $stats['blocked_month'] = (int)($row['waf'] ?? 0) + (int)($row['bf'] ?? 0) + (int)($row['ipdb'] ?? 0);
        }
        
        // Get temp ban count from firewall.db
        $banRows = $this->querySqlite(self::FIREWALL_DB, "SELECT COUNT(*) as cnt FROM temp_ban");
        if (!empty($banRows)) {
            $stats['temp_bans'] = (int)($banRows[0]['cnt'] ?? 0);
        }
        
        // Get WAF logs total from waf.db
        $wafRows = $this->querySqlite(self::WAF_DB, "SELECT COUNT(*) as cnt FROM waf_logs");
        if (!empty($wafRows)) {
            $stats['waf_logs_total'] = (int)($wafRows[0]['cnt'] ?? 0);
        }
        
        // Get incidents total
        $incRows = $this->querySqlite(self::INCIDENTS_DB, "SELECT COUNT(*) as cnt FROM incidents");
        if (!empty($incRows)) {
            $stats['incidents_total'] = (int)($incRows[0]['cnt'] ?? 0);
        }

        // Count active rules from rules file or directory
        $rulesDir = '/opt/cpguard/app/rules';
        if (is_dir($rulesDir)) {
            $ruleFiles = glob("{$rulesDir}/*.rules") ?: [];
            $stats['active_rules'] = count($ruleFiles);
        }
        
        // Also count rules from rules.txt
        if (file_exists(self::RULES_FILE)) {
            $rules = $this->readListFile(self::RULES_FILE);
            $stats['active_rules'] = max($stats['active_rules'], count($rules));
        }

        return $stats;
    }

    /**
     * Get last malware scan info
     */
    private function getLastScanInfo(): ?string
    {
        $scanLogs = [
            '/opt/cpguard/logs/scan.log',
            '/var/log/cpguard/scan.log',
        ];
        
        foreach ($scanLogs as $scanLog) {
            if (file_exists($scanLog)) {
                $mtime = filemtime($scanLog);
                if ($mtime) {
                    return date('M j, Y H:i', $mtime);
                }
            }
        }

        // Check rambo file
        $ramboFile = '/opt/cpguard/rambo' . date('dmY');
        if (file_exists($ramboFile)) {
            return date('M j, Y H:i', filemtime($ramboFile));
        }
        
        $ramboFiles = glob('/opt/cpguard/rambo*');
        if (!empty($ramboFiles)) {
            $latest = end($ramboFiles);
            return date('M j, Y H:i', filemtime($latest));
        }

        return 'Never';
    }

    /**
     * Read list file into array
     */
    private function readListFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        
        $content = @file_get_contents($file);
        if (!$content) {
            return [];
        }
        
        $lines = array_filter(array_map('trim', explode("\n", $content)));
        // Remove comments and empty lines
        return array_values(array_filter($lines, function($line) {
            return !empty($line) && $line[0] !== '#';
        }));
    }

    /**
     * Add entry to list file
     */
    private function addToListFile(string $file, string $value): array
    {
        // Create directory if needed
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        // Read current entries
        $entries = $this->readListFile($file);
        
        // Check if already exists
        if (in_array($value, $entries)) {
            return [
                'success' => false,
                'error' => 'Entry already exists',
            ];
        }
        
        // Add new entry
        $entries[] = $value;
        
        // Write back
        $content = implode("\n", $entries) . "\n";
        $result = file_put_contents($file, $content);
        
        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Failed to write file',
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Entry added successfully',
        ];
    }

    /**
     * Remove entry from list file
     */
    private function removeFromListFile(string $file, string $value): array
    {
        if (!file_exists($file)) {
            return [
                'success' => false,
                'error' => 'List file does not exist',
            ];
        }
        
        $entries = $this->readListFile($file);
        
        // Find and remove
        $key = array_search($value, $entries);
        if ($key === false) {
            return [
                'success' => false,
                'error' => 'Entry not found',
            ];
        }
        
        unset($entries[$key]);
        
        // Write back
        $content = implode("\n", array_values($entries)) . "\n";
        $result = file_put_contents($file, $content);
        
        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Failed to write file',
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Entry removed successfully',
        ];
    }

    /**
     * Validate IP address
     */
    private function isValidIp(string $ip): bool
    {
        // Allow CIDR notation
        if (strpos($ip, '/') !== false) {
            list($addr, $prefix) = explode('/', $ip, 2);
            if (!filter_var($addr, FILTER_VALIDATE_IP)) {
                return false;
            }
            $prefix = intval($prefix);
            return $prefix >= 0 && $prefix <= 128;
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate domain name
     */
    private function isValidDomain(string $domain): bool
    {
        // Allow wildcards like *.example.com
        $domain = ltrim($domain, '*.');
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*(\.[a-zA-Z0-9][a-zA-Z0-9-]*)*\.[a-zA-Z]{2,}$/', $domain) === 1;
    }

    // ============================================
    // Module-Specific Config Getters (reads from SQLite config.db)
    // ============================================

    private function getWafConfig(): array
    {
        $config = $this->getAllConfigValues();
        
        $enabled = $config['waf_switch'] ?? false;
        $mode = $enabled ? 'blocking' : 'off';
        
        // Check ModSec config for actual mode
        if (file_exists(self::MODSEC_CONFIG)) {
            $content = @file_get_contents(self::MODSEC_CONFIG);
            if ($content) {
                if (preg_match('/SecRuleEngine\s+DetectionOnly/i', $content)) {
                    $mode = 'detection';
                }
            }
        }
        
        // Count rules
        $ruleCount = 0;
        if (file_exists(self::RULES_FILE)) {
            $rules = $this->readListFile(self::RULES_FILE);
            $ruleCount = count($rules);
        }
        
        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'rule_count' => $ruleCount,
            'webshell_protection' => $config['waf_webshell_switch'] ?? false,
            'scanner_integration' => $config['waf_scanner_switch'] ?? false,
            'crawler_protection' => $config['waf_crawler_switch'] ?? false,
            'cdn_proxy_mode' => $config['waf_cdn_proxy_switch'] ?? false,
            'temp_ban_enabled' => $config['fw_waf_temp_ban_switch'] ?? false,
        ];
    }

    private function getScannerConfig(): array
    {
        $config = $this->getAllConfigValues();
        
        return [
            'enabled' => $config['scanner_switch'] ?? false,
            'suspicious_action' => $config['scanner_suspicious_action'] ?? 'email',
            'virus_action' => $config['scanner_virus_action'] ?? 'quarantine',
            'binary_action' => $config['scanner_binary_action'] ?? 'email',
            'auto_clean' => $config['auto_clean_switch'] ?? false,
            'symbolic_action' => $config['scanner_symbolic_action'] ?? false,
            'weekly_scan' => $config['weekly_scan_switch'] ?? false,
            'daily_scan' => $config['daily_scan_switch'] ?? false,
            'ai_scan' => $config['ai_scan_switch'] ?? false,
            'db_scan' => $config['db_scan_switch'] ?? false,
            'user_mscan' => $config['user_mscan_switch'] ?? false,
            'rootkit_scan' => $config['rootkit_switch'] ?? false,
            'process_monitor' => $config['process_monitor_switch'] ?? false,
        ];
    }

    private function getBruteForceConfig(): array
    {
        $config = $this->getAllConfigValues();
        $enabled = $config['brute_force_switch'] ?? false;
        
        // Get protected URLs from file
        $urls = [];
        if (file_exists(self::BFURLS_FILE)) {
            $urls = $this->readListFile(self::BFURLS_FILE);
        }
        
        return [
            'enabled' => $enabled,
            'protected_urls' => $urls,
            'url_count' => count($urls),
        ];
    }

    private function getCaptchaConfig(): array
    {
        $config = $this->getAllConfigValues();
        
        return [
            'enabled' => $config['waf_captcha_switch'] ?? false,
            'firewall_captcha' => $config['fw_captcha_switch'] ?? false,
            'type' => 'recaptcha',
        ];
    }

    private function getBotControlConfig(): array
    {
        $config = $this->getAllConfigValues();
        $enabled = $config['waf_badbot_switch'] ?? false;
        
        // Get bad bots from file
        $botCount = 0;
        if (file_exists(self::BADBOTS_FILE)) {
            $bots = $this->readListFile(self::BADBOTS_FILE);
            $botCount = count($bots);
        }
        
        return [
            'enabled' => $enabled,
            'bad_bot_count' => $botCount,
            'ai_bots_protection' => $config['fw_ai_bots_switch'] ?? false,
        ];
    }

    private function getCountryBlockingConfig(): array
    {
        $config = $this->getAllConfigValues();
        
        $whitelistCountries = $config['fw_whitelist_country'] ?? [];
        $blacklistCountries = $config['fw_blacklist_country'] ?? [];
        
        // Determine mode based on which list has entries
        $mode = null;
        if (!empty($blacklistCountries)) {
            $mode = 'blacklist';
        } elseif (!empty($whitelistCountries)) {
            $mode = 'whitelist';
        }
        
        $enabled = !empty($whitelistCountries) || !empty($blacklistCountries);
        
        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'blocked_countries' => $blacklistCountries,
            'allowed_countries' => $whitelistCountries,
        ];
    }

    private function getIpdbConfig(): array
    {
        $config = $this->getAllConfigValues();
        
        return [
            'enabled' => $config['fw_ipdb_switch'] ?? false,
            'logging' => $config['fw_ipdb_log_switch'] ?? false,
        ];
    }
    
    private function getFirewallConfig(): array
    {
        $config = $this->getAllConfigValues();
        
        return [
            'enabled' => $config['fw_switch'] ?? false,
            'dos_protection' => $config['fw_dos_switch'] ?? false,
            'dos_threshold' => $config['fw_dos_threshold'] ?? 150,
            'port_filter' => $config['fw_port_filter'] ?? false,
            'fail2ban' => $config['fw_fail2ban_switch'] ?? false,
            'logging' => $config['fw_log_switch'] ?? false,
            'tcp_in_ports' => $config['fw_ports_tcp_in'] ?? [],
            'tcp_out_ports' => $config['fw_ports_tcp_out'] ?? [],
            'udp_in_ports' => $config['fw_ports_udp_in'] ?? [],
            'udp_out_ports' => $config['fw_ports_udp_out'] ?? [],
            'whitelisted_ips' => $config['fw_whitelisted_ips'] ?? [],
            'blacklisted_ips' => $config['fw_blacklisted_ips'] ?? [],
        ];
    }
    
    private function getNotificationConfig(): array
    {
        $config = $this->getAllConfigValues();
        
        return [
            'primary_email' => $config['notify_primary_email'] ?? null,
            'secondary_email' => $config['notify_secondary_email'] ?? null,
            'scanner_notifications' => $config['notify_scanner'] ?? false,
            'suspicious_notifications' => $config['notify_suspicious'] ?? false,
            'binary_notifications' => $config['notify_binary'] ?? false,
            'iprep_notifications' => $config['notify_iprep'] ?? false,
            'daily_report' => $config['notify_daily_report'] ?? false,
            'method' => $config['notify_method'] ?? 'local',
            'slack_enabled' => $config['slack_switch'] ?? false,
        ];
    }
    
    private function getWordPressConfig(): array
    {
        $config = $this->getAllConfigValues();
        
        return [
            'checksum_verification' => $config['wpchecksum_switch'] ?? false,
            'cron_optimization' => $config['wpcron_switch'] ?? false,
            'auto_update' => $config['wp_auto_update'] ?? false,
            'auto_update_score' => $config['wp_auto_update_score'] ?? 6,
            'auto_update_release_time' => $config['wp_auto_update_release_time'] ?? 7,
        ];
    }
}
