<?php
/**
 * Debug logging helper — only writes to error_log when APP_DEBUG=true.
 * Use this for verbose/diagnostic logs. Keep error_log() for genuine errors.
 */

namespace Webmail\Helpers;

function debug_log(string $message): void
{
    if (getenv('APP_DEBUG') === 'true') {
        error_log($message);
    }
}

