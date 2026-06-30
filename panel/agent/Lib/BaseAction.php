<?php
/**
 * Base Action Class
 * 
 * Provides common functionality for all action handlers.
 * Handles backup, logging, and validation integration.
 */

namespace VpsAdmin\Agent\Lib;

abstract class BaseAction implements ActionInterface
{
    protected array $config;
    protected BackupManager $backup;
    protected DiffGenerator $diff;
    protected Logger $logger;

    public function __construct(array $config, BackupManager $backup, DiffGenerator $diff, Logger $logger)
    {
        $this->config = $config;
        $this->backup = $backup;
        $this->diff = $diff;
        $this->logger = $logger;
    }

    /**
     * Execute an action with automatic backup and logging
     */
    public function execute(string $method, array $params, string $actor): array
    {
        $fullAction = $this->getNamespace() . '.' . $method;

        // Check if method exists
        if (!in_array($method, $this->getMethods())) {
            $this->logger->warning("Unknown method requested: {$fullAction}", ['actor' => $actor]);
            return $this->error("Unknown method: {$method}");
        }

        $this->logger->info("Executing action: {$fullAction}", [
            'actor' => $actor,
            'params' => $this->sanitizeParams($params),
        ]);

        try {
            // Execute the method
            $methodName = 'action' . ucfirst($method);
            if (!method_exists($this, $methodName)) {
                return $this->error("Method not implemented: {$method}");
            }

            $result = $this->$methodName($params, $actor);

            $this->logger->action(
                $fullAction,
                $actor,
                $params['target'] ?? $params['name'] ?? $params['domain'] ?? '-',
                $result['success'] ? 'success' : 'failed',
                $result
            );

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Action failed: {$fullAction}", [
                'actor' => $actor,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error($e->getMessage());
        }
    }

    /**
     * Create a successful response
     */
    protected function success(array $data = [], string $message = 'Success'): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * Create an error response
     */
    protected function error(string $message, array $data = []): array
    {
        return [
            'success' => false,
            'error' => $message,
            'data' => $data,
        ];
    }

    /**
     * Create a backup before modifying a file
     */
    protected function backupFile(string $path, string $action, string $actor): ?string
    {
        return $this->backup->backup($path, $this->getNamespace() . '.' . $action, $actor);
    }

    /**
     * Execute a system command safely
     * Only allowed commands, no shell interpolation
     *
     * @param int $timeout Max seconds to wait (0 = no limit). Kills the process on expiry.
     */
    protected function execCommand(string $command, array $args = [], int $timeout = 0): array
    {
        $escapedArgs = array_map('escapeshellarg', $args);
        $fullCommand = $command . ' ' . implode(' ', $escapedArgs);

        if ($timeout <= 0) {
            $output = [];
            $returnCode = 0;
            exec($fullCommand . ' 2>&1', $output, $returnCode);
            return [
                'success' => $returnCode === 0,
                'output' => implode("\n", $output),
                'code' => $returnCode,
            ];
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($fullCommand, $descriptors, $pipes);

        if (!is_resource($process)) {
            return [
                'success' => false,
                'output' => 'Failed to start process',
                'code' => -1,
            ];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = time() + $timeout;
        $timedOut = false;
        $exitCode = null;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                // The first proc_get_status() call after the process exits carries
                // the real exit code AND reaps the child. proc_close() would then
                // return -1 (child already reaped), so capture the code here.
                $exitCode = $status['exitcode'];
                break;
            }
            if (time() >= $deadline) {
                $timedOut = true;
                $pid = $status['pid'];
                @posix_kill($pid, SIGTERM);
                usleep(200000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    @posix_kill($pid, SIGKILL);
                }
                break;
            }
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            usleep(50000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $closeCode = proc_close($process);
        // Prefer the exit code captured from proc_get_status; proc_close() often
        // returns -1 once proc_get_status() has already reaped the child.
        $returnCode = ($exitCode !== null && $exitCode !== -1) ? $exitCode : $closeCode;
        $combinedOutput = trim($stdout . ($stderr ? "\n" . $stderr : ''));

        if ($timedOut) {
            return [
                'success' => false,
                'output' => "Command timed out after {$timeout}s" . ($combinedOutput ? ": {$combinedOutput}" : ''),
                'code' => -1,
                'timed_out' => true,
            ];
        }

        return [
            'success' => $returnCode === 0,
            'output' => $combinedOutput,
            'code' => $returnCode,
        ];
    }

    /**
     * Sanitize params for logging (hide sensitive data)
     */
    private function sanitizeParams(array $params): array
    {
        $sensitive = ['password', 'secret', 'token', 'key'];
        $sanitized = [];

        foreach ($params as $key => $value) {
            if (in_array(strtolower($key), $sensitive)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_string($value) && strlen($value) > 256) {
                // Don't bloat the log with large payloads (e.g. migration VCF/ICS
                // data) and avoid spilling their contents into log files.
                $sanitized[$key] = '[' . strlen($value) . ' chars omitted]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}

