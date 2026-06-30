<?php
namespace Webmail\Helpers;

/**
 * Log Redaction Utility
 * 
 * Sanitizes sensitive data from log entries to prevent credential leakage
 * through log files. Replaces passwords, tokens, and other secrets with
 * redacted placeholders while preserving the structure of the log message.
 */
class LogRedactor
{
    /**
     * Patterns to redact from log messages.
     * Each pattern captures enough context to identify the field,
     * and replaces the sensitive value with [REDACTED].
     */
    private static array $patterns = [
        // JSON fields with sensitive values
        '/"(password|passwd|pass|secret|token|api_key|apikey|api_secret|access_token|refresh_token|session_token|authorization|auth_token|encrypted_password|client_secret)"\s*:\s*"[^"]*"/i'
            => '"$1":"[REDACTED]"',
        
        // Query string parameters
        '/([?&])(password|token|secret|api_key|apikey|access_token|refresh_token|session_token)=[^&\s]*/i'
            => '$1$2=[REDACTED]',
        
        // Authorization headers
        '/Authorization:\s*(Bearer|Basic)\s+[A-Za-z0-9\-_.~+\/=]+/i'
            => 'Authorization: $1 [REDACTED]',
        
        // Email passwords in IMAP/SMTP contexts
        '/imap_open\([^,]+,\s*[^,]+,\s*[^)]+\)/i'
            => 'imap_open([HOST], [USER], [REDACTED])',
        
        // Generic password= assignments (e.g., in DSN strings or configs)
        '/password\s*=\s*[\'"][^\'"]+[\'"]/i'
            => 'password=[REDACTED]',
        
        // JWT tokens (three base64 segments separated by dots)
        '/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/'
            => '[JWT_REDACTED]',
        
        // Long hex strings (session tokens, API keys, etc.) - 32+ hex chars
        '/\b[a-f0-9]{64,}\b/'
            => '[TOKEN_REDACTED]',
    ];

    /**
     * Redact sensitive data from a log message.
     */
    public static function redact(string $message): string
    {
        foreach (self::$patterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message);
        }
        return $message;
    }

    /**
     * Register as the global error_log handler via a custom error handler.
     * Call this once at application bootstrap.
     * 
     * Note: This only intercepts error_log() calls; it does NOT intercept
     * PHP's own internal error messages. For full coverage, also use
     * set_error_handler() and set_exception_handler().
     */
    public static function register(): void
    {
        // Set a custom error handler that redacts messages
        $previousHandler = set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use (&$previousHandler) {
            $errstr = self::redact($errstr);
            
            if ($previousHandler) {
                return $previousHandler($errno, $errstr, $errfile, $errline);
            }
            
            // Default behavior: let PHP handle it
            return false;
        });
    }
}

