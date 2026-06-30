<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;
use PDO;

/**
 * ServerReportService - Generate server info files and issue logs
 */
class ServerReportService
{
    private PDO $db;
    private Container $container;
    private EncryptionService $encryption;
    private string $reportsPath;
    private string $issueLogsPath;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->db = $container->getDatabase();
        $this->encryption = $container->get(EncryptionService::class);
        
        // Use storage path from config or default
        $storagePath = $container->getConfig('paths.storage') ?? '/var/www/vps-admin/storage';
        $this->reportsPath = $storagePath . '/reports';
        $this->issueLogsPath = $storagePath . '/issue_logs';
        
        // Ensure directories exist
        $this->ensureDirectories();
    }

    /**
     * Ensure required directories exist
     */
    private function ensureDirectories(): void
    {
        if (!is_dir($this->reportsPath)) {
            mkdir($this->reportsPath, 0755, true);
        }
        if (!is_dir($this->issueLogsPath)) {
            mkdir($this->issueLogsPath, 0755, true);
        }
    }

    /**
     * Generate server info report after provisioning
     */
    public function generateServerReport(int $serverId, array $variables = []): string
    {
        $server = $this->getServerDetails($serverId);
        if (!$server) {
            throw new \Exception("Server not found: {$serverId}");
        }

        $report = $this->buildReport($server, $variables);
        $filename = $this->saveReport($serverId, $report);
        
        // Update server record with report path
        $stmt = $this->db->prepare("UPDATE servers SET info_report_path = ? WHERE id = ?");
        $stmt->execute([$filename, $serverId]);

        return $filename;
    }

    /**
     * Get server details including packages and configs
     */
    private function getServerDetails(int $serverId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$server) {
            return null;
        }

        // Get installed packages
        $stmt = $this->db->prepare("
            SELECT package_name, installed_version, installed_at
            FROM server_packages
            WHERE server_id = ?
            ORDER BY installed_at
        ");
        $stmt->execute([$serverId]);
        $server['packages'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get deployed configs
        $stmt = $this->db->prepare("
            SELECT target_path, content_hash, applied_at
            FROM server_configs
            WHERE server_id = ?
            ORDER BY applied_at
        ");
        $stmt->execute([$serverId]);
        $server['configs'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get blueprint info
        if ($server['blueprint_id']) {
            $stmt = $this->db->prepare("SELECT name, description FROM blueprints WHERE id = ?");
            $stmt->execute([$server['blueprint_id']]);
            $server['blueprint'] = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $server;
    }

    /**
     * Build the report content
     */
    private function buildReport(array $server, array $variables = []): string
    {
        $generatedAt = date('Y-m-d H:i:s');
        $separator = str_repeat('=', 70);
        $subSeparator = str_repeat('-', 70);

        $report = <<<REPORT
{$separator}
                    FLEET MANAGER - SERVER INFORMATION REPORT
{$separator}

Generated: {$generatedAt}
Server Name: {$server['name']}

{$separator}
                              CONNECTION DETAILS
{$separator}

IP Address:     {$server['ip_address']}
SSH Port:       {$server['ssh_port']}
SSH User:       {$server['ssh_user']}
SSH Key:        {$this->yesNo($server['ssh_key_installed'])}

{$separator}
                                  DOMAINS
{$separator}

Panel Domain:   {$server['panel_domain']}
Email Domain:   {$server['email_domain']}
Mail Domain:    {$server['mail_domain']}

{$separator}
                                CREDENTIALS
{$separator}

REPORT;

        // Add credentials if we have them (decrypted for report)
        if (!empty($server['db_root_password_encrypted'])) {
            $dbRootPass = $this->safeDecrypt($server['db_root_password_encrypted']);
            $report .= "MariaDB Root Password:   {$dbRootPass}\n";
        }

        if (!empty($server['panel_db_password_encrypted'])) {
            $panelDbPass = $this->safeDecrypt($server['panel_db_password_encrypted']);
            $report .= "Panel DB Password:       {$panelDbPass}\n";
        }

        if (!empty($server['email_db_password_encrypted'])) {
            $emailDbPass = $this->safeDecrypt($server['email_db_password_encrypted']);
            $report .= "Email DB Password:       {$emailDbPass}\n";
        }

        if (!empty($server['mail_db_password_encrypted'])) {
            $mailDbPass = $this->safeDecrypt($server['mail_db_password_encrypted']);
            $report .= "Mail DB Password:        {$mailDbPass}\n";
        }

        if (!empty($server['panel_admin_email'])) {
            $report .= "\nPanel Admin Email:       {$server['panel_admin_email']}\n";
        }

        if (!empty($server['panel_admin_password_encrypted'])) {
            $panelAdminPass = $this->safeDecrypt($server['panel_admin_password_encrypted']);
            $report .= "Panel Admin Password:    {$panelAdminPass}\n";
        }

        if (!empty($server['agent_token'])) {
            $report .= "\nAgent Token:             {$server['agent_token']}\n";
        }

        // Add variables if provided
        if (!empty($variables)) {
            $report .= "\n{$separator}\n";
            $report .= "                           DEPLOYMENT VARIABLES\n";
            $report .= "{$separator}\n\n";
            
            foreach ($variables as $key => $value) {
                // Skip sensitive variables already shown above
                if (strpos($key, 'PASS') !== false || strpos($key, 'TOKEN') !== false) {
                    continue;
                }
                $report .= sprintf("%-25s %s\n", $key . ':', $value);
            }
        }

        $report .= "\n{$separator}\n";
        $report .= "                            INSTALLED PACKAGES\n";
        $report .= "{$separator}\n\n";

        if (!empty($server['packages'])) {
            foreach ($server['packages'] as $pkg) {
                $installedAt = !empty($pkg['installed_at']) ? date('Y-m-d H:i', strtotime($pkg['installed_at'])) : 'Unknown';
                $report .= sprintf("%-30s v%-15s (%s)\n", $pkg['package_name'] ?? 'unknown', $pkg['installed_version'] ?? 'N/A', $installedAt);
            }
        } else {
            $report .= "No packages recorded.\n";
        }

        $report .= "\n{$separator}\n";
        $report .= "                            DEPLOYED CONFIGS\n";
        $report .= "{$separator}\n\n";

        if (!empty($server['configs'])) {
            foreach ($server['configs'] as $cfg) {
                $appliedAt = !empty($cfg['applied_at']) ? date('Y-m-d H:i', strtotime($cfg['applied_at'])) : 'Unknown';
                $report .= sprintf("%-50s (%s)\n", $cfg['target_path'] ?? 'unknown', $appliedAt);
            }
        } else {
            $report .= "No configs recorded.\n";
        }

        if (!empty($server['blueprint'])) {
            $report .= "\n{$separator}\n";
            $report .= "                               BLUEPRINT\n";
            $report .= "{$separator}\n\n";
            $report .= "Name:        {$server['blueprint']['name']}\n";
            $report .= "Description: {$server['blueprint']['description']}\n";
        }

        $report .= "\n{$separator}\n";
        $report .= "                            STAGING FOLDERS\n";
        $report .= "{$separator}\n\n";

        $report .= "Panel Staging:  /home/{$server['panel_domain']}/\n";
        $report .= "  SSH User:     panel_staging\n";
        $report .= "  Public HTML:  /home/{$server['panel_domain']}/public_html/\n\n";

        $report .= "Email Staging:  /home/{$server['email_domain']}/\n";
        $report .= "  SSH User:     email_staging\n";
        $report .= "  Public HTML:  /home/{$server['email_domain']}/public_html/\n";

        $report .= "\n{$separator}\n";
        $report .= "                           APPLICATION PATHS\n";
        $report .= "{$separator}\n\n";

        $report .= "Panel Application:   /var/www/vps-admin/\n";
        $report .= "Email Application:   /var/www/email-admin/\n";
        $report .= "Agent Path:          /opt/vps-admin/agent/\n";
        $report .= "OpenLiteSpeed:       /usr/local/lsws/\n";
        $report .= "VHosts Config:       /usr/local/lsws/conf/vhosts/\n";
        $report .= "Logs:                /var/log/lsws/\n";

        $report .= "\n{$separator}\n";
        $report .= "                              USEFUL COMMANDS\n";
        $report .= "{$separator}\n\n";

        $report .= "# SSH Access\n";
        $report .= "ssh {$server['ssh_user']}@{$server['ip_address']} -p {$server['ssh_port']}\n\n";

        $report .= "# Restart Services\n";
        $report .= "systemctl restart lshttpd          # OpenLiteSpeed\n";
        $report .= "systemctl restart mariadb          # MariaDB\n";
        $report .= "systemctl restart postfix          # Postfix\n";
        $report .= "systemctl restart dovecot          # Dovecot\n";
        $report .= "systemctl restart fleet-agent      # Fleet Agent\n\n";

        $report .= "# View Logs\n";
        $report .= "tail -f /var/log/lsws/error.log    # OLS Error Log\n";
        $report .= "tail -f /var/log/mail.log          # Mail Log\n";
        $report .= "journalctl -u fleet-agent -f       # Agent Log\n\n";

        $report .= "# Database Access\n";
        $dbRootPass = $this->safeDecrypt($server['db_root_password_encrypted'] ?? '');
        $report .= "mysql -u root -p'{$dbRootPass}'\n";

        $report .= "\n{$separator}\n";
        $report .= "                              STATUS INFO\n";
        $report .= "{$separator}\n\n";

        $report .= "Server Status:    {$server['status']}\n";
        $report .= "Panel Version:    " . ($server['panel_version'] ?? 'Not set') . "\n";
        $report .= "Email Version:    " . ($server['email_app_version'] ?? 'Not set') . "\n";
        $report .= "Agent Version:    " . ($server['agent_version'] ?? 'Not set') . "\n";
        $report .= "Last Heartbeat:   " . ($server['last_heartbeat'] ?? 'Never') . "\n";
        $report .= "Provisioned At:   " . ($server['provisioned_at'] ?? 'Not recorded') . "\n";

        $report .= "\n{$separator}\n";
        $report .= "                          END OF REPORT\n";
        $report .= "{$separator}\n";

        return $report;
    }

    /**
     * Save report to file
     */
    private function saveReport(int $serverId, string $content): string
    {
        $timestamp = date('Ymd_His');
        $filename = "server_{$serverId}_{$timestamp}.txt";
        $filepath = $this->reportsPath . '/' . $filename;
        
        file_put_contents($filepath, $content);
        chmod($filepath, 0644);
        
        return $filename;
    }

    /**
     * Get report content for download
     */
    public function getReport(int $serverId, ?string $filename = null): ?array
    {
        if ($filename) {
            $filepath = $this->reportsPath . '/' . basename($filename);
        } else {
            // Get latest report for this server
            $stmt = $this->db->prepare("SELECT info_report_path FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result || !$result['info_report_path']) {
                return null;
            }
            
            $filepath = $this->reportsPath . '/' . $result['info_report_path'];
        }

        if (!file_exists($filepath)) {
            return null;
        }

        return [
            'filename' => basename($filepath),
            'content' => file_get_contents($filepath),
            'size' => filesize($filepath),
            'modified' => filemtime($filepath),
        ];
    }

    /**
     * List all reports for a server
     */
    public function listReports(int $serverId): array
    {
        $pattern = $this->reportsPath . "/server_{$serverId}_*.txt";
        $files = glob($pattern);
        
        $reports = [];
        foreach ($files as $file) {
            $reports[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }
        
        // Sort by modification time (newest first)
        usort($reports, fn($a, $b) => strtotime($b['modified']) - strtotime($a['modified']));
        
        return $reports;
    }

    /**
     * Log issue from heartbeat
     */
    public function logIssue(int $serverId, string $issueType, string $message, array $details = []): void
    {
        // Log to database
        $stmt = $this->db->prepare("
            INSERT INTO server_issues (server_id, issue_type, message, details, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $serverId,
            $issueType,
            $message,
            json_encode($details),
        ]);

        // Also append to daily log file
        $date = date('Y-m-d');
        $logFile = $this->issueLogsPath . "/server_{$serverId}_{$date}.log";
        
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] [{$issueType}] {$message}";
        if (!empty($details)) {
            $logLine .= " | " . json_encode($details);
        }
        $logLine .= "\n";
        
        file_put_contents($logFile, $logLine, FILE_APPEND);
    }

    /**
     * Get issues for a server
     */
    public function getIssues(int $serverId, int $limit = 100, ?string $since = null): array
    {
        $sql = "SELECT * FROM server_issues WHERE server_id = ?";
        $params = [$serverId];
        
        if ($since) {
            $sql .= " AND created_at >= ?";
            $params[] = $since;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get issue log file content
     */
    public function getIssueLog(int $serverId, string $date): ?string
    {
        $logFile = $this->issueLogsPath . "/server_{$serverId}_{$date}.log";
        
        if (!file_exists($logFile)) {
            return null;
        }
        
        return file_get_contents($logFile);
    }

    /**
     * List available issue log files for a server
     */
    public function listIssueLogs(int $serverId): array
    {
        $pattern = $this->issueLogsPath . "/server_{$serverId}_*.log";
        $files = glob($pattern);
        
        $logs = [];
        foreach ($files as $file) {
            $basename = basename($file);
            preg_match('/server_\d+_(\d{4}-\d{2}-\d{2})\.log/', $basename, $matches);
            
            $logs[] = [
                'filename' => $basename,
                'date' => $matches[1] ?? 'unknown',
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }
        
        // Sort by date (newest first)
        usort($logs, fn($a, $b) => strcmp($b['date'], $a['date']));
        
        return $logs;
    }

    /**
     * Safely decrypt a value
     */
    private function safeDecrypt(?string $encrypted): string
    {
        if (empty($encrypted)) {
            return '[Not set]';
        }
        
        try {
            return $this->encryption->decrypt($encrypted);
        } catch (\Exception $e) {
            return '[Encrypted]';
        }
    }

    /**
     * Format yes/no value
     */
    private function yesNo($value): string
    {
        return $value ? 'Yes' : 'No';
    }
}

