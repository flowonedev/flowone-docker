<?php

namespace Webmail\Services;

/**
 * Centralized Audit Logger
 * 
 * Sends security events to the Panel's audit log ingest endpoint.
 * Events are buffered in-memory and flushed at the end of the request
 * to avoid blocking the response for non-critical audit events.
 * 
 * Falls back to local error_log if Panel is unreachable.
 */
class AuditLogger
{
    private static ?AuditLogger $instance = null;
    private array $config;
    private array $buffer = [];
    private bool $registered = false;
    
    // Source app identifier
    private const SOURCE_APP = 'email';
    
    // Max buffer size before auto-flush
    private const MAX_BUFFER = 50;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Initialize singleton with config
     */
    public static function init(array $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Log a security event
     */
    public static function log(
        string $action,
        string $severity = 'info',
        string $outcome = 'success',
        array $details = [],
        ?string $target = null,
        ?string $actor = null,
        ?string $userEmail = null
    ): void {
        $instance = self::$instance;
        if (!$instance) {
            // Fallback: not initialized, log locally
            error_log("[AUDIT] {$severity} | {$action} | {$outcome} | " . json_encode($details));
            return;
        }

        $event = [
            'action' => $action,
            'severity' => $severity,
            'outcome' => $outcome,
            'details' => $details,
            'target' => $target ?? '',
            'actor' => $actor ?? 'system',
            'user_email' => $userEmail,
            'ip_address' => self::getClientIp(),
        ];

        $instance->buffer[] = $event;
        
        // Register shutdown flush only once
        if (!$instance->registered) {
            register_shutdown_function([$instance, 'flush']);
            $instance->registered = true;
        }

        // Auto-flush if buffer is full
        if (count($instance->buffer) >= self::MAX_BUFFER) {
            $instance->flush();
        }
    }

    /**
     * Log a critical security event (sends immediately, not buffered)
     */
    public static function critical(
        string $action,
        array $details = [],
        ?string $target = null,
        ?string $actor = null,
        ?string $userEmail = null
    ): void {
        self::log($action, 'critical', 'failed', $details, $target, $actor, $userEmail);
        
        // Flush immediately for critical events
        if (self::$instance) {
            self::$instance->flush();
        }
    }

    /**
     * Convenience: log authentication event
     */
    public static function auth(string $action, string $outcome, ?string $userEmail = null, array $details = []): void
    {
        $severity = $outcome === 'failed' ? 'medium' : 'info';
        self::log("auth.{$action}", $severity, $outcome, $details, 'auth', 'user', $userEmail);
    }

    /**
     * Convenience: log data access event
     */
    public static function access(string $action, ?string $target = null, ?string $userEmail = null, array $details = []): void
    {
        self::log("access.{$action}", 'info', 'success', $details, $target, 'user', $userEmail);
    }

    /**
     * Convenience: log admin/config change
     */
    public static function config(string $action, ?string $target = null, ?string $actor = null, array $details = []): void
    {
        self::log("config.{$action}", 'medium', 'success', $details, $target, $actor ?? 'admin');
    }

    /**
     * Flush buffered events to Panel
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];

        $panelUrl = $this->config['panel']['api_url'] ?? '';
        $apiKey = $this->config['panel']['api_key'] ?? '';

        if (empty($panelUrl) || empty($apiKey)) {
            // No panel configured — log locally
            foreach ($events as $event) {
                error_log("[AUDIT] {$event['severity']} | {$event['action']} | {$event['outcome']} | " . json_encode($event['details']));
            }
            return;
        }

        $url = rtrim($panelUrl, '/') . '/audit/ingest';
        $payload = json_encode([
            'source_app' => self::SOURCE_APP,
            'events' => $events,
        ]);

        // Non-blocking HTTP POST using cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,           // Max 5 seconds
            CURLOPT_CONNECTTIMEOUT => 2,    // Max 2 seconds to connect
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || $error) {
            // Fallback: log locally if Panel is unreachable
            error_log("[AuditLogger] Failed to send to panel ({$httpCode}): {$error}");
            foreach ($events as $event) {
                error_log("[AUDIT-LOCAL] {$event['severity']} | {$event['action']} | {$event['outcome']}");
            }
        }
    }

    /**
     * Get client IP address
     */
    private static function getClientIp(): string
    {
        // Check forwarded headers (behind reverse proxy)
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // X-Forwarded-For can contain comma-separated list
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}

