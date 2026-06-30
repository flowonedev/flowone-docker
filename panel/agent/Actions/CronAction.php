<?php
/**
 * Cron Action Handler
 * 
 * Manages server-level cron jobs.
 */

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;
use VpsAdmin\Agent\Lib\Validator;

class CronAction extends BaseAction
{
    private string $cronDir = '/etc/cron.d';
    private string $cronTabPath = '/var/spool/cron/crontabs/root';

    public function getNamespace(): string
    {
        return 'cron';
    }

    public function getMethods(): array
    {
        return ['list', 'get', 'create', 'update', 'delete', 'toggle', 'logs'];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['create', 'update', 'delete', 'toggle']);
    }

    /**
     * List all cron jobs
     */
    protected function actionList(array $params, string $actor): array
    {
        $jobs = [];

        // Get jobs from /etc/cron.d/
        if (is_dir($this->cronDir)) {
            $files = scandir($this->cronDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                if (str_ends_with($file, '.dpkg-dist') || str_ends_with($file, '.dpkg-old')) continue;
                
                $filePath = $this->cronDir . '/' . $file;
                if (is_file($filePath)) {
                    $content = file_get_contents($filePath);
                    $parsed = $this->parseCronFile($content, $file);
                    $jobs = array_merge($jobs, $parsed);
                }
            }
        }

        // Get jobs from root's crontab
        $result = $this->execCommand('crontab', ['-l', '-u', 'root']);
        if ($result['success'] && !empty($result['output'])) {
            $parsed = $this->parseCrontab($result['output']);
            $jobs = array_merge($jobs, $parsed);
        }

        // Get system scheduled jobs (anacron, etc.)
        $systemJobs = $this->getSystemScheduledJobs();
        $jobs = array_merge($jobs, $systemJobs);

        return $this->success([
            'jobs' => $jobs,
            'count' => count($jobs),
        ]);
    }

    /**
     * Get a specific cron job
     */
    protected function actionGet(array $params, string $actor): array
    {
        if (!isset($params['id'])) {
            return $this->error('Job ID is required');
        }

        $id = $params['id'];
        
        // Parse the ID to find the job
        // Format: source:filename:line or crontab:line
        $parts = explode(':', $id);
        
        if ($parts[0] === 'crontab') {
            $result = $this->execCommand('crontab', ['-l', '-u', 'root']);
            if (!$result['success']) {
                return $this->error('Failed to read crontab');
            }
            
            $lines = explode("\n", $result['output']);
            $lineNum = (int)$parts[1];
            
            if (!isset($lines[$lineNum])) {
                return $this->error('Job not found');
            }
            
            $job = $this->parseCronLine($lines[$lineNum], 'crontab', '', $lineNum);
            return $this->success(['job' => $job]);
        } elseif ($parts[0] === 'crond') {
            $filename = $parts[1];
            $lineNum = (int)$parts[2];
            
            $filePath = $this->cronDir . '/' . $filename;
            if (!file_exists($filePath)) {
                return $this->error('Cron file not found');
            }
            
            $content = file_get_contents($filePath);
            $lines = explode("\n", $content);
            
            if (!isset($lines[$lineNum])) {
                return $this->error('Job not found');
            }
            
            $job = $this->parseCronLine($lines[$lineNum], 'crond', $filename, $lineNum);
            return $this->success(['job' => $job]);
        }

        return $this->error('Invalid job ID format');
    }

    /**
     * Create a new cron job
     */
    protected function actionCreate(array $params, string $actor): array
    {
        $required = ['schedule', 'command'];
        foreach ($required as $field) {
            if (!isset($params[$field]) || empty($params[$field])) {
                return $this->error("{$field} is required");
            }
        }

        $schedule = $params['schedule'];
        $command = $params['command'];
        $description = $params['description'] ?? '';
        $user = $params['user'] ?? 'root';
        $name = $params['name'] ?? 'vpsadmin-' . time();

        // Validate schedule format
        if (!$this->isValidSchedule($schedule)) {
            return $this->error('Invalid cron schedule format');
        }

        // Sanitize name for filename
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if (empty($name)) {
            $name = 'vpsadmin-' . time();
        }

        // Create cron file in /etc/cron.d/
        $cronFile = $this->cronDir . '/' . $name;
        
        if (file_exists($cronFile)) {
            return $this->error("Cron job '{$name}' already exists");
        }

        $content = "# Managed by VPS Admin Panel\n";
        if ($description) {
            $content .= "# {$description}\n";
        }
        $content .= "SHELL=/bin/bash\n";
        $content .= "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n";
        $content .= "\n";
        $content .= "{$schedule} {$user} {$command}\n";

        // Backup before creation (for audit)
        $this->logger->info("Creating cron job: {$name}", [
            'schedule' => $schedule,
            'command' => $command,
            'user' => $user,
            'actor' => $actor,
        ]);

        file_put_contents($cronFile, $content);
        chmod($cronFile, 0644);

        return $this->success([
            'name' => $name,
            'file' => $cronFile,
            'schedule' => $schedule,
            'command' => $command,
        ], "Cron job '{$name}' created");
    }

    /**
     * Update an existing cron job
     */
    protected function actionUpdate(array $params, string $actor): array
    {
        if (!isset($params['id'])) {
            return $this->error('Job ID is required');
        }

        $id = $params['id'];
        $parts = explode(':', $id);
        
        if ($parts[0] !== 'crond') {
            return $this->error('Only /etc/cron.d jobs can be updated through this interface');
        }

        $filename = $parts[1];
        $lineNum = isset($parts[2]) ? (int)$parts[2] : null;
        
        $filePath = $this->cronDir . '/' . $filename;
        if (!file_exists($filePath)) {
            return $this->error('Cron file not found');
        }

        // Backup the file
        $this->backupFile($filePath, 'update', $actor);

        $schedule = $params['schedule'] ?? null;
        $command = $params['command'] ?? null;
        $description = $params['description'] ?? null;
        $user = $params['user'] ?? null;

        if ($schedule && !$this->isValidSchedule($schedule)) {
            return $this->error('Invalid cron schedule format');
        }

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        // If line number specified, update that specific line
        if ($lineNum !== null && isset($lines[$lineNum])) {
            $currentLine = $lines[$lineNum];
            $parsed = $this->parseCronLine($currentLine, 'crond', $filename, $lineNum);
            
            if ($parsed['type'] === 'job') {
                $newSchedule = $schedule ?? $parsed['schedule'];
                $newUser = $user ?? $parsed['user'];
                $newCommand = $command ?? $parsed['command'];
                
                $lines[$lineNum] = "{$newSchedule} {$newUser} {$newCommand}";
            }
        } else {
            // Update the entire file
            $newLines = [];
            $hasUpdated = false;
            
            foreach ($lines as $i => $line) {
                $parsed = $this->parseCronLine($line, 'crond', $filename, $i);
                
                if ($parsed['type'] === 'job' && !$hasUpdated) {
                    $newSchedule = $schedule ?? $parsed['schedule'];
                    $newUser = $user ?? $parsed['user'];
                    $newCommand = $command ?? $parsed['command'];
                    $newLines[] = "{$newSchedule} {$newUser} {$newCommand}";
                    $hasUpdated = true;
                } else {
                    $newLines[] = $line;
                }
            }
            
            $lines = $newLines;
        }

        file_put_contents($filePath, implode("\n", $lines));

        return $this->success([
            'id' => $id,
            'file' => $filePath,
        ], "Cron job updated");
    }

    /**
     * Delete a cron job
     */
    protected function actionDelete(array $params, string $actor): array
    {
        if (!isset($params['id'])) {
            return $this->error('Job ID is required');
        }

        $id = $params['id'];
        $parts = explode(':', $id);
        
        if ($parts[0] !== 'crond') {
            return $this->error('Only /etc/cron.d jobs can be deleted through this interface');
        }

        $filename = $parts[1];
        $filePath = $this->cronDir . '/' . $filename;
        
        if (!file_exists($filePath)) {
            return $this->error('Cron file not found');
        }

        // Check if it's a VPS Admin managed job
        $content = file_get_contents($filePath);
        if (!str_contains($content, 'VPS Admin') && !str_starts_with($filename, 'vpsadmin')) {
            // For safety, don't delete system cron files
            if (!($params['force'] ?? false)) {
                return $this->error("This is not a VPS Admin managed cron job. Use force=true to delete anyway.");
            }
        }

        // Backup before deletion
        $this->backupFile($filePath, 'delete', $actor);

        unlink($filePath);

        return $this->success([
            'id' => $id,
            'file' => $filePath,
        ], "Cron job deleted");
    }

    /**
     * Toggle (enable/disable) a cron job
     */
    protected function actionToggle(array $params, string $actor): array
    {
        if (!isset($params['id'])) {
            return $this->error('Job ID is required');
        }

        $id = $params['id'];
        $parts = explode(':', $id);
        
        if ($parts[0] !== 'crond') {
            return $this->error('Only /etc/cron.d jobs can be toggled');
        }

        $filename = $parts[1];
        $lineNum = isset($parts[2]) ? (int)$parts[2] : null;
        
        $filePath = $this->cronDir . '/' . $filename;
        if (!file_exists($filePath)) {
            return $this->error('Cron file not found');
        }

        // Backup the file
        $this->backupFile($filePath, 'toggle', $actor);

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        $enabled = null;
        
        if ($lineNum !== null && isset($lines[$lineNum])) {
            $line = $lines[$lineNum];
            
            // Toggle comment
            if (preg_match('/^#\s*(.+)$/', $line, $matches)) {
                // Uncomment (enable)
                $lines[$lineNum] = $matches[1];
                $enabled = true;
            } elseif (!str_starts_with(trim($line), '#') && !empty(trim($line))) {
                // Comment out (disable)
                $lines[$lineNum] = '# ' . $line;
                $enabled = false;
            }
        }

        file_put_contents($filePath, implode("\n", $lines));

        return $this->success([
            'id' => $id,
            'enabled' => $enabled,
        ], $enabled ? "Cron job enabled" : "Cron job disabled");
    }

    /**
     * Get cron execution logs
     */
    protected function actionLogs(array $params, string $actor): array
    {
        $lines = $params['lines'] ?? 100;
        $filter = $params['filter'] ?? null;
        
        // Read from syslog/cron log
        $logPaths = [
            '/var/log/cron.log',
            '/var/log/cron',
            '/var/log/syslog',
        ];
        
        $logPath = null;
        foreach ($logPaths as $path) {
            if (file_exists($path)) {
                $logPath = $path;
                break;
            }
        }

        if (!$logPath) {
            return $this->error('Cron log not found');
        }

        // Use tail and grep to get relevant lines
        if ($filter) {
            $result = $this->execCommand('grep', ['-i', 'CRON', $logPath, '|', 'grep', '-i', $filter, '|', 'tail', '-n', (string)$lines]);
        } else {
            $result = $this->execCommand('sh', ['-c', "grep -i 'CRON' {$logPath} | tail -n {$lines}"]);
        }

        $logs = [];
        if ($result['success']) {
            $lines = explode("\n", $result['output']);
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                
                $logs[] = [
                    'raw' => $line,
                    'parsed' => $this->parseCronLogLine($line),
                ];
            }
        }

        return $this->success([
            'logs' => $logs,
            'log_file' => $logPath,
        ]);
    }

    /**
     * Parse a cron.d file
     */
    private function parseCronFile(string $content, string $filename): array
    {
        $jobs = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            $parsed = $this->parseCronLine($line, 'crond', $filename, $lineNum);
            if ($parsed['type'] === 'job') {
                $jobs[] = $parsed;
            }
        }
        
        return $jobs;
    }

    /**
     * Parse root's crontab
     */
    private function parseCrontab(string $content): array
    {
        $jobs = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            $parsed = $this->parseCronLine($line, 'crontab', '', $lineNum);
            if ($parsed['type'] === 'job') {
                $jobs[] = $parsed;
            }
        }
        
        return $jobs;
    }

    /**
     * Parse a single cron line
     */
    private function parseCronLine(string $line, string $source, string $filename, int $lineNum): array
    {
        $line = trim($line);
        
        // Skip empty lines and comments
        if (empty($line)) {
            return ['type' => 'empty'];
        }
        
        // Check if disabled (commented out)
        $enabled = true;
        if (str_starts_with($line, '#')) {
            $enabled = false;
            $line = trim(substr($line, 1));
            
            // If it's just a comment (not a disabled job), skip
            if (!$this->looksLikeCronJob($line)) {
                return ['type' => 'comment'];
            }
        }
        
        // Skip variable assignments
        if (preg_match('/^[A-Z_]+=/', $line)) {
            return ['type' => 'variable'];
        }

        // Parse the cron expression
        // Format for cron.d: minute hour day month dow user command
        // Format for crontab: minute hour day month dow command
        $pattern = '/^(@\w+|[\d\*\/\-,]+\s+[\d\*\/\-,]+\s+[\d\*\/\-,]+\s+[\d\*\/\-,]+\s+[\d\*\/\-,]+)\s+(\S+)(?:\s+(.+))?$/';
        
        if (preg_match($pattern, $line, $matches)) {
            $schedule = $matches[1];
            
            if ($source === 'crond') {
                // cron.d files have user field
                $user = $matches[2];
                $command = $matches[3] ?? '';
            } else {
                // crontab doesn't have user field
                $user = 'root';
                $command = $matches[2] . (isset($matches[3]) ? ' ' . $matches[3] : '');
            }
            
            $id = $source === 'crontab' 
                ? "crontab:{$lineNum}"
                : "crond:{$filename}:{$lineNum}";
            
            return [
                'type' => 'job',
                'id' => $id,
                'source' => $source,
                'filename' => $filename,
                'line' => $lineNum,
                'schedule' => $schedule,
                'schedule_human' => $this->scheduleToHuman($schedule),
                'user' => $user,
                'command' => $command,
                'enabled' => $enabled,
                'editable' => $source === 'crond',
            ];
        }
        
        return ['type' => 'unknown'];
    }

    /**
     * Check if a line looks like a cron job
     */
    private function looksLikeCronJob(string $line): bool
    {
        // Check for @ shortcuts
        if (preg_match('/^@(reboot|yearly|annually|monthly|weekly|daily|hourly|midnight)\s/', $line)) {
            return true;
        }
        
        // Check for standard cron format
        if (preg_match('/^[\d\*\/\-,]+\s+[\d\*\/\-,]+\s+[\d\*\/\-,]+\s+[\d\*\/\-,]+\s+[\d\*\/\-,]+\s/', $line)) {
            return true;
        }
        
        return false;
    }

    /**
     * Validate a cron schedule
     */
    private function isValidSchedule(string $schedule): bool
    {
        // Check @ shortcuts
        if (preg_match('/^@(reboot|yearly|annually|monthly|weekly|daily|hourly|midnight)$/', $schedule)) {
            return true;
        }
        
        // Check standard format
        $parts = preg_split('/\s+/', $schedule);
        if (count($parts) !== 5) {
            return false;
        }
        
        // Basic validation for each part
        foreach ($parts as $part) {
            if (!preg_match('/^[\d\*\/\-,]+$/', $part)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Convert cron schedule to human-readable format
     */
    private function scheduleToHuman(string $schedule): string
    {
        // Handle @ shortcuts
        $shortcuts = [
            '@reboot' => 'At system startup',
            '@yearly' => 'Once a year',
            '@annually' => 'Once a year',
            '@monthly' => 'Once a month',
            '@weekly' => 'Once a week',
            '@daily' => 'Once a day',
            '@hourly' => 'Every hour',
            '@midnight' => 'At midnight',
        ];
        
        if (isset($shortcuts[$schedule])) {
            return $shortcuts[$schedule];
        }
        
        // Parse standard format
        $parts = preg_split('/\s+/', $schedule);
        if (count($parts) !== 5) {
            return $schedule;
        }
        
        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;
        
        $description = [];
        
        // Every minute
        if ($minute === '*' && $hour === '*' && $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*') {
            return 'Every minute';
        }
        
        // Specific time patterns
        if ($minute !== '*' && $hour !== '*') {
            $description[] = "At {$hour}:{$minute}";
        } elseif ($minute === '0' && $hour !== '*') {
            $description[] = "At {$hour}:00";
        } elseif ($minute !== '*') {
            $description[] = "At minute {$minute}";
        } elseif ($hour !== '*') {
            $description[] = "Every minute past hour {$hour}";
        }
        
        // Day of month
        if ($dayOfMonth !== '*') {
            $description[] = "on day {$dayOfMonth}";
        }
        
        // Month
        $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        if ($month !== '*') {
            if (is_numeric($month) && isset($months[(int)$month])) {
                $description[] = "in {$months[(int)$month]}";
            } else {
                $description[] = "in month {$month}";
            }
        }
        
        // Day of week
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        if ($dayOfWeek !== '*') {
            if (is_numeric($dayOfWeek) && isset($days[(int)$dayOfWeek])) {
                $description[] = "on {$days[(int)$dayOfWeek]}";
            } else {
                $description[] = "on day {$dayOfWeek}";
            }
        }
        
        // Interval patterns
        if (preg_match('/^\*\/(\d+)$/', $minute, $matches)) {
            $description = ["Every {$matches[1]} minutes"];
        }
        if (preg_match('/^\*\/(\d+)$/', $hour, $matches)) {
            $description = ["Every {$matches[1]} hours"];
        }
        
        return !empty($description) ? implode(' ', $description) : $schedule;
    }

    /**
     * Get system scheduled jobs (anacron, etc.)
     */
    private function getSystemScheduledJobs(): array
    {
        $jobs = [];
        
        // Check /etc/cron.daily, /etc/cron.hourly, etc.
        $schedDirs = [
            '/etc/cron.hourly' => 'Every hour',
            '/etc/cron.daily' => 'Daily',
            '/etc/cron.weekly' => 'Weekly',
            '/etc/cron.monthly' => 'Monthly',
        ];
        
        foreach ($schedDirs as $dir => $schedule) {
            if (!is_dir($dir)) continue;
            
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === '.placeholder') continue;
                
                $filePath = $dir . '/' . $file;
                if (!is_file($filePath)) continue;
                
                $jobs[] = [
                    'type' => 'job',
                    'id' => 'system:' . basename($dir) . ':' . $file,
                    'source' => 'system',
                    'filename' => $filePath,
                    'schedule' => $schedule,
                    'schedule_human' => $schedule,
                    'user' => 'root',
                    'command' => $filePath,
                    'enabled' => is_executable($filePath),
                    'editable' => false,
                ];
            }
        }
        
        return $jobs;
    }

    /**
     * Parse a cron log line
     */
    private function parseCronLogLine(string $line): array
    {
        // Example: Jan  1 12:00:01 hostname CRON[12345]: (root) CMD (/path/to/command)
        $parsed = [
            'time' => null,
            'host' => null,
            'user' => null,
            'command' => null,
        ];
        
        if (preg_match('/^(\w+\s+\d+\s+[\d:]+)\s+(\S+)\s+CRON\[\d+\]:\s+\((\w+)\)\s+CMD\s+\((.+)\)$/', $line, $matches)) {
            $parsed['time'] = $matches[1];
            $parsed['host'] = $matches[2];
            $parsed['user'] = $matches[3];
            $parsed['command'] = $matches[4];
        }
        
        return $parsed;
    }
}

