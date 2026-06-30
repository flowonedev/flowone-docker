<?php

namespace FleetManager\Agent\Lib;

/**
 * Simple file logger for the agent
 */
class Logger
{
    private string $logFile;
    private string $level;
    private array $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    
    public function __construct(array $config)
    {
        $this->logFile = $config['paths']['log_file'] ?? '/var/log/fleet-manager/agent.log';
        $this->level = $config['logging']['level'] ?? 'info';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
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
    
    private function log(string $level, string $message, array $context = []): void
    {
        // Check if we should log this level
        if (($this->levels[$level] ?? 0) < ($this->levels[$this->level] ?? 0)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        
        $line = "[{$timestamp}] [{$levelUpper}] {$message}{$contextStr}\n";
        
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

