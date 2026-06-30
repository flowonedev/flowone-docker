<?php

namespace FleetManager\Agent\Lib;

/**
 * Base class for all agent actions
 */
abstract class BaseAction implements ActionInterface
{
    protected array $config;
    protected Logger $logger;
    
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Execute a method by name
     */
    public function execute(string $method, array $params, string $actor): array
    {
        $methodName = 'action' . ucfirst($method);
        
        if (!method_exists($this, $methodName)) {
            return [
                'success' => false,
                'error' => "Unknown method: {$method}",
            ];
        }
        
        return $this->$methodName($params, $actor);
    }
    
    /**
     * Execute a shell command and return result
     */
    protected function exec(string $command, int $timeout = 30): array
    {
        $output = [];
        $exitCode = 0;
        
        // Add timeout wrapper
        $fullCommand = "timeout {$timeout} {$command} 2>&1";
        
        exec($fullCommand, $output, $exitCode);
        
        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
        ];
    }
    
    /**
     * Check if a file exists
     */
    protected function fileExists(string $path): bool
    {
        return file_exists($path) && is_file($path);
    }
    
    /**
     * Check if a directory exists
     */
    protected function dirExists(string $path): bool
    {
        return file_exists($path) && is_dir($path);
    }
    
    /**
     * Read file content with size limit
     */
    protected function readFile(string $path, int $maxSize = 5242880): ?string
    {
        if (!$this->fileExists($path)) {
            return null;
        }
        
        $size = filesize($path);
        if ($size > $maxSize) {
            $this->logger->warning("File too large, skipping", ['path' => $path, 'size' => $size]);
            return null;
        }
        
        return file_get_contents($path);
    }
    
    /**
     * Get file info (permissions, owner, etc)
     */
    protected function getFileInfo(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        
        $stat = stat($path);
        $owner = posix_getpwuid($stat['uid']);
        $group = posix_getgrgid($stat['gid']);
        
        return [
            'size' => $stat['size'],
            'permissions' => substr(sprintf('%o', $stat['mode']), -4),
            'owner' => $owner['name'] ?? $stat['uid'],
            'group' => $group['name'] ?? $stat['gid'],
            'modified' => date('Y-m-d H:i:s', $stat['mtime']),
        ];
    }
}

