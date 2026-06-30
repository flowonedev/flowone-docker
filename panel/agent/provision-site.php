#!/usr/bin/env php
<?php
/**
 * provision-site.php
 *
 * One-shot CLI that enqueues a CREATE provisioning job for a single
 * domain. It is a thin wrapper over the exact same
 * `provisioning.enqueueCreate` action the panel UI's "Provision site"
 * button calls, so the site is created through the canonical saga
 * (sftp user, home dir, vhost, database + user, SSL) and reconciler
 * parity is preserved. The worker daemon (vpsadmin-worker) drives the
 * saga to completion once the job is queued.
 *
 * The Fleet Manager calls this during a fresh deploy to register the
 * server's own base domain (e.g. weddingcards.hu) as a real site in
 * the panel's Sites V2 table, so the operator can manage DNS / create
 * email accounts from it immediately.
 *
 * Usage:
 *   provision-site.php --domain=example.com [--php=lsphp83] [--no-dns]
 *                      [--actor=fleet]
 *
 *   --no-dns   The caller already seeded the DNS zone + records, so the
 *              saga's DnsZoneCreateStep is told to skip (dns_enabled=false)
 *              to avoid double-creating the zone.
 *
 * Exit codes:
 *   0  enqueue succeeded (job queued or already in flight)
 *   1  bad usage / not root / enqueue failed
 *
 * Must run as root (writes to the panel DB + SecretVault).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "provision-site must run from CLI\n");
    exit(1);
}

// Autoload — VpsAdmin\Agent\* (mirrors agent.php).
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

// Autoload shared FlowOne\Storage\* (mirrors agent.php's resolution for
// both the production /var/www/shared layout and local dev panel/shared).
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

use VpsAdmin\Agent\Actions\ProvisioningAction;
use VpsAdmin\Agent\Lib\BackupManager;
use VpsAdmin\Agent\Lib\DiffGenerator;
use VpsAdmin\Agent\Lib\Logger;

$opts = getopt('', ['domain:', 'php:', 'no-dns', 'actor:', 'help']);

if (isset($opts['help']) || empty($opts['domain'])) {
    fwrite(STDOUT, "Usage: provision-site.php --domain=example.com [--php=lsphp83] [--no-dns] [--actor=fleet]\n");
    exit(isset($opts['help']) ? 0 : 1);
}

if (function_exists('posix_getuid') && posix_getuid() !== 0) {
    fwrite(STDERR, "ERROR: provision-site must run as root\n");
    exit(1);
}

$domain = strtolower(trim((string) $opts['domain']));
$php    = isset($opts['php']) && $opts['php'] !== '' ? (string) $opts['php'] : 'lsphp83';
$actor  = isset($opts['actor']) && $opts['actor'] !== '' ? (string) $opts['actor'] : 'fleet';
$noDns  = isset($opts['no-dns']);

// Basic FQDN sanity (the saga validates again downstream).
if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
    fwrite(STDERR, "ERROR: '{$domain}' is not a valid FQDN\n");
    exit(1);
}

$config = require __DIR__ . '/config.php';
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    $config = array_replace_recursive($config, (array) require $localConfig);
}

$logger = new Logger($config);
$backup = new BackupManager($config);
$diff   = new DiffGenerator();

$action = new ProvisioningAction($config, $backup, $diff, $logger);

$payload = ['php_version' => $php];
if ($noDns) {
    $payload['dns_enabled'] = false;
}

$result = $action->execute('enqueueCreate', [
    'domain'  => $domain,
    'payload' => $payload,
    'actor'   => $actor,
], $actor);

fwrite(STDOUT, json_encode($result) . "\n");

exit(empty($result['success']) ? 1 : 0);
