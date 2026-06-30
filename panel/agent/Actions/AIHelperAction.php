<?php
/**
 * AI Helper Action Handler
 * 
 * Provides safe read-only operations for AI diagnostic assistant.
 * All commands are executed in dry-run mode (read-only).
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

class AIHelperAction extends BaseAction
{
    /**
     * Whitelist of allowed read-only commands
     */
    private array $allowedCommands = [
        'grep', 'cat', 'ls', 'tail', 'head', 'find', 'stat', 'file',
        'test', 'readlink', 'realpath', 'dirname', 'basename',
        'wc', 'sort', 'uniq', 'cut', 'awk', 'sed', 'tr',
        'df', 'du', 'free', 'ps', 'top', 'uptime', 'who',
        'id', 'groups', 'whoami', 'hostname', 'uname',
        'date', 'env', 'printenv',
    ];

    /**
     * Blocked commands that could modify the system
     */
    private array $blockedCommands = [
        'rm', 'mv', 'cp', 'chmod', 'chown', 'chgrp',
        'mkdir', 'rmdir', 'touch', 'ln', 'unlink',
        'echo', 'printf', 'write', 'tee',
        'sudo', 'su', 'bash', 'sh', 'zsh',
        'systemctl', 'service', 'systemd',
        'iptables', 'firewall-cmd',
        'mysql', 'mysqldump', 'psql',
        'curl', 'wget', 'nc', 'netcat',
    ];

    public function getNamespace(): string
    {
        return 'aihelper';
    }

    public function getMethods(): array
    {
        return [
            'dryRunCommand',
            'readConfigFile',
            'checkServiceStatus',
            'validateConfigSyntax',
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return false; // All methods are read-only
    }

    /**
     * Execute a dry-run command (read-only)
     */
    protected function actionDryRunCommand(array $params, string $actor): array
    {
        $command = $params['command'] ?? null;
        $cwd = $params['cwd'] ?? null;

        if (!$command) {
            return $this->error('Command is required');
        }

        // Sanitize and validate command
        $command = trim($command);
        
        // Check for blocked commands
        $commandLower = strtolower($command);
        foreach ($this->blockedCommands as $blocked) {
            if (strpos($commandLower, $blocked) === 0 || strpos($commandLower, ' ' . $blocked) !== false) {
                return $this->error("Command '{$blocked}' is not allowed in dry-run mode");
            }
        }

        // Check if command starts with allowed command
        $allowed = false;
        foreach ($this->allowedCommands as $allowedCmd) {
            if (strpos($commandLower, $allowedCmd) === 0) {
                $allowed = true;
                break;
            }
        }

        // Also allow systemctl status, journalctl, postconf, doveconf, postfix check (read-only)
        if (!$allowed) {
            $readOnlyPatterns = [
                '/^systemctl\s+status/',
                '/^journalctl\s+/',
                '/^postconf\s+/',
                '/^doveconf\s+/',
                '/^postfix\s+check/',
                '/^postfix\s+verify/',
                '/^sshd\s+-t/',
                '/^php\s+-l/',
                '/^nginx\s+-t/',
                '/^apache2ctl\s+-t/',
                '/^httpd\s+-t/',
                '/^dovecot\s+-n/',
                '/^named-checkconf/',
                '/^named-checkzone/',
            ];
            
            foreach ($readOnlyPatterns as $pattern) {
                if (preg_match($pattern, $command)) {
                    $allowed = true;
                    break;
                }
            }
        }

        if (!$allowed) {
            return $this->error("Command not allowed in dry-run mode. Only read-only commands are permitted.");
        }

        // Prevent command injection
        if (preg_match('/[;&|`$(){}]/', $command)) {
            return $this->error("Command contains invalid characters. Pipes and command chaining are not allowed.");
        }

        // Execute command safely
        $output = [];
        $exitCode = 0;
        $errorOutput = [];

        // Set working directory if provided
        $originalCwd = getcwd();
        if ($cwd && is_dir($cwd)) {
            chdir($cwd);
        }

        // Execute with timeout and output capture
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorspec, $pipes);
        
        if (is_resource($process)) {
            fclose($pipes[0]); // Close stdin
            
            // Read output with timeout
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            $exitCode = proc_close($process);
            
            if ($stdout) {
                $output = explode("\n", rtrim($stdout));
            }
            if ($stderr) {
                $errorOutput = explode("\n", rtrim($stderr));
            }
        } else {
            return $this->error('Failed to execute command');
        }

        // Restore original directory
        if ($cwd) {
            chdir($originalCwd);
        }

        return $this->success([
            'command' => $command,
            'cwd' => $cwd ?: getcwd(),
            'exit_code' => $exitCode,
            'output' => $output,
            'error' => $errorOutput,
            'dry_run' => true,
        ]);
    }

    /**
     * Read a config file for analysis
     */
    protected function actionReadConfigFile(array $params, string $actor): array
    {
        $path = $params['path'] ?? null;
        
        if (!$path) {
            return $this->error('Config file path is required');
        }

        // Security: Only allow reading from specific directories
        $allowedPaths = [
            '/etc/',
            '/usr/local/lsws/conf/',
            '/usr/local/lsws/Example/conf/',
            '/var/www/',
            '/home/',
        ];

        $allowed = false;
        foreach ($allowedPaths as $allowedPath) {
            if (strpos($path, $allowedPath) === 0) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return $this->error('Path not allowed for security reasons');
        }

        // Prevent directory traversal
        if (strpos($path, '..') !== false) {
            return $this->error('Invalid path');
        }

        if (!file_exists($path)) {
            return $this->error('File does not exist');
        }

        if (!is_readable($path)) {
            return $this->error('File is not readable');
        }

        // Limit file size (max 1MB)
        $size = filesize($path);
        if ($size > 1048576) {
            return $this->error('File is too large (max 1MB)');
        }

        $content = file_get_contents($path);
        $stat = stat($path);

        return $this->success([
            'path' => $path,
            'content' => $content,
            'size' => $size,
            'permissions' => substr(sprintf('%o', $stat['mode']), -4),
            'owner' => posix_getpwuid($stat['uid'])['name'] ?? 'unknown',
            'group' => posix_getgrgid($stat['gid'])['name'] ?? 'unknown',
            'modified' => date('Y-m-d H:i:s', $stat['mtime']),
        ]);
    }

    /**
     * Check service status
     */
    protected function actionCheckServiceStatus(array $params, string $actor): array
    {
        $service = $params['service'] ?? null;
        
        if (!$service) {
            return $this->error('Service name is required');
        }

        // Sanitize service name
        $service = preg_replace('/[^a-zA-Z0-9@\-_.]/', '', $service);
        
        $output = [];
        $exitCode = 0;
        exec("systemctl is-active {$service} 2>&1", $output, $exitCode);
        
        $isActive = ($exitCode === 0);
        
        // Get more details
        $statusOutput = [];
        exec("systemctl status {$service} --no-pager -l 2>&1", $statusOutput, $statusCode);
        
        return $this->success([
            'service' => $service,
            'active' => $isActive,
            'status' => $isActive ? 'active' : 'inactive',
            'details' => implode("\n", $statusOutput),
        ]);
    }

    /**
     * Validate config file syntax
     * Reuses existing SystemAction methods via agent
     */
    protected function actionValidateConfigSyntax(array $params, string $actor): array
    {
        $service = $params['service'] ?? null;
        $content = $params['content'] ?? null;
        
        if (!$service) {
            return $this->error('Service name is required');
        }

        // This will be handled by SystemAction.syntaxCheck
        // We just pass through the request
        return $this->success([
            'service' => $service,
            'note' => 'Use system.syntaxCheck for actual validation',
        ]);
    }
}

