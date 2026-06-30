<?php
/**
 * Debug logging helper – only writes to error_log when app.debug is true.
 * Set the flag early in index.php via debug_log_init().
 */

/** @var bool */
$_GLOBALS['__vps_debug'] = false;

/**
 * Call once at boot with the resolved config value.
 */
function debug_log_init(bool $enabled): void
{
    $GLOBALS['__vps_debug'] = $enabled;
}

/**
 * Log a message only when debug mode is enabled.
 * Safe to call from anywhere after boot.
 */
function debug_log(string $message): void
{
    if (!empty($GLOBALS['__vps_debug'])) {
        error_log($message);
    }
}

