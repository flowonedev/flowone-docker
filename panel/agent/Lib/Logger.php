<?php
/**
 * Agent Logger
 * 
 * Handles all logging for the agent daemon.
 * Logs to file and optionally to the database.
 */

namespace VpsAdmin\Agent\Lib;

class Logger
{
    private string $logFile;
    private string $level;
    private array $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(array $config)
    {
        $this->logFile = $config['logging']['file'] ?? '/var/www/vps-admin/logs/agent.log';
        $this->level = $config['logging']['level'] ?? 'info';
        
        $this->ensureLogFile();
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function action(string $action, string $actor, string $target, string $outcome, array $details = []): void
    {
        $this->info("ACTION: {$action}", [
            'actor' => $actor,
            'target' => $target,
            'outcome' => $outcome,
            'details' => $details,
        ]);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->levels[$level] < $this->levels[$this->level]) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        
        $logLine = "[{$timestamp}] [{$levelUpper}] {$message}{$contextStr}\n";
        
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function ensureLogFile(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        
        if (!file_exists($this->logFile)) {
            touch($this->logFile);
            chmod($this->logFile, 0640);
        }
    }

    /**
     * Get recent log entries
     */
    public function getRecent(int $lines = 100): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $output = [];
        $file = new \SplFileObject($this->logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $start = max(0, $totalLines - $lines);
        $file->seek($start);

        while (!$file->eof()) {
            $line = $file->fgets();
            if (trim($line) !== '') {
                $output[] = $this->parseLine($line);
            }
        }

        return $output;
    }

    private function parseLine(string $line): array
    {
        $pattern = '/^\[([^\]]+)\] \[([^\]]+)\] (.+)$/';
        if (preg_match($pattern, trim($line), $matches)) {
            $message = $matches[3];
            $context = [];
            
            // Try to extract JSON context
            if (preg_match('/^(.+?) (\{.+\})$/', $message, $msgMatches)) {
                $message = $msgMatches[1];
                $context = json_decode($msgMatches[2], true) ?? [];
            }

            return [
                'timestamp' => $matches[1],
                'level' => strtolower($matches[2]),
                'message' => $message,
                'context' => $context,
            ];
        }

        return [
            'timestamp' => null,
            'level' => 'unknown',
            'message' => trim($line),
            'context' => [],
        ];
    }
}

