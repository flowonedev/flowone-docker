#!/usr/bin/env php
<?php
/**
 * mailsec-learn-setup.php
 *
 * One-shot CLI that installs (or removes) the IMAPSieve reactive-learning loop
 * through the EXACT same agent action the panel's Learning tab calls
 * (MailSecurityAction::setupLearning). Use it to bring the loop up from the
 * shell without the panel, and WITHOUT the side effects of mailsec-provision
 * (which re-runs the full engine install and would reset spam thresholds).
 *
 * It deploys the learn wrapper, spool dir, opt-out file, the IMAPSieve sieve
 * scripts and the Dovecot conf - validated with doveconf and rolled back on
 * failure so a bad edit can never break IMAP delivery. Idempotent: safe to
 * re-run.
 *
 * Usage:
 *   mailsec-learn-setup.php [--disable] [--actor=cli]
 *
 *   (default)   install the loop
 *   --disable   remove the loop (backs up + deletes the managed conf/scripts)
 *
 * Exit codes:
 *   0  success
 *   1  bad usage / not root / setup failed
 *
 * Must run as root (writes Dovecot config + reloads dovecot).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "mailsec-learn-setup must run from CLI\n");
    exit(1);
}

// Autoload — VpsAdmin\Agent\* (mirrors agent.php / mailsec-provision.php).
spl_autoload_register(function ($class) {
    $prefix = 'VpsAdmin\\Agent\\';
    $baseDir = __DIR__ . '/';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Autoload shared FlowOne\Storage\* (mirrors mailsec-provision.php).
spl_autoload_register(function ($class) {
    $prefix = 'FlowOne\\Storage\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    static $sharedRoot = null;
    if ($sharedRoot === null) {
        $sharedRoot = false;
        foreach ([__DIR__ . '/../../shared', __DIR__ . '/../shared'] as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved . '/src/Storage')) {
                $sharedRoot = $resolved;
                break;
            }
        }
    }
    if ($sharedRoot === false) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $f = $sharedRoot . '/src/Storage/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($f)) {
        require $f;
    }
});

use VpsAdmin\Agent\Actions\MailSecurityAction;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;

$opts = getopt('', ['disable', 'actor:', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: mailsec-learn-setup.php [--disable] [--actor=cli]\n");
    exit(0);
}

if (function_exists('posix_getuid') && posix_getuid() !== 0) {
    fwrite(STDERR, "ERROR: mailsec-learn-setup must run as root\n");
    exit(1);
}

$enabled = !isset($opts['disable']);
$actor   = isset($opts['actor']) && $opts['actor'] !== '' ? (string) $opts['actor'] : 'cli';

$config = require __DIR__ . '/config.php';
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    $config = array_replace_recursive($config, (array) require $localConfig);
}

$logger = new Logger($config);
$backup = new BackupManager($config);
$diff   = new DiffGenerator();

$action = new MailSecurityAction($config, $backup, $diff, $logger);

$result = $action->execute('setupLearning', ['enabled' => $enabled], $actor);

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exit(!empty($result['success']) ? 0 : 1);
