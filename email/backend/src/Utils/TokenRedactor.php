<?php

namespace Webmail\Utils;

/**
 * Redact meeting/guest tokens from strings before logging.
 */
final class TokenRedactor
{
    public static function redactUrl(string $message): string
    {
        $m = preg_replace('#(/guest/call/)([a-f0-9]{32,128})#i', '$1[REDACTED]', $message);
        $m = preg_replace('#(/meet/)([a-f0-9]{32,128})#i', '$1[REDACTED]', (string) $m);
        return (string) $m;
    }

    public static function redactException(\Throwable $e): string
    {
        return self::redactUrl($e->getMessage());
    }
}
