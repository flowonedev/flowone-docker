#!/usr/bin/env php
<?php
/**
 * mailsec-provision.php
 *
 * One-shot CLI that brings the Mail Security Gateway up on a server through the
 * EXACT same agent action code (`MailSecurityAction`) the panel UI uses, so a
 * Fleet-provisioned server ends up identical to a server set up by hand from the
 * panel. There is a single source of truth - this script just drives it.
 *
 * Fleet Manager calls this during a fresh deploy (the `install_mailsecurity`
 * step) so new servers automatically get:
 *   - Rspamd + ClamAV installed (monitor-only foundation)
 *   - a local unbound recursor on 127.0.0.1:5335 for reliable DNSBLs
 *     (coexists with PowerDNS on :53)
 *   - the quarantine spool + Postfix pipe transport
 *   - the DB-seeded policy exported into Rspamd (default attachment policy,
 *     lists, anti-spoofing, rules, geo-ip) - same payload the panel UI syncs
 *   - and, with --wire, the live milter + quarantine routing (fail-open)
 *
 * Every step is idempotent and fail-safe, so this is safe to re-run on resume.
 *
 * Usage:
 *   mailsec-provision.php [--wire] [--spam=6] [--reject=15] [--actor=fleet]
 *                         [--panel-domain=panel.example.com]
 *
 *   --wire   Also point Postfix at Rspamd (go live). Without it the engine is
 *            installed monitor-only and can be wired later from the panel.
 *
 *   --panel-domain  Seed mail_security_settings.quarantine_link_base with
 *            https://<panel-domain> (only if currently empty) so self-service
 *            quarantine digest links work out of the box.
 *
 * Exit codes:
 *   0  the engine installed successfully (wiring failures are non-fatal: mail
 *      still flows fail-open and delivery can be wired from the panel)
 *   1  bad usage / not root / engine install failed
 *
 * Must run as root (manages packages + system config).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "mailsec-provision must run from CLI\n");
    exit(1);
}

// Autoload — VpsAdmin\Agent\* (mirrors agent.php / provision-site.php).
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

// Autoload shared FlowOne\Storage\* (mirrors agent.php's dual-root resolution).
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

$opts = getopt('', ['wire', 'spam:', 'reject:', 'actor:', 'panel-domain:', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: mailsec-provision.php [--wire] [--spam=6] [--reject=15] [--actor=fleet] [--panel-domain=panel.example.com]\n");
    exit(0);
}

if (function_exists('posix_getuid') && posix_getuid() !== 0) {
    fwrite(STDERR, "ERROR: mailsec-provision must run as root\n");
    exit(1);
}

$spam   = isset($opts['spam']) && $opts['spam'] !== '' ? (float) $opts['spam'] : 6.0;
$reject = isset($opts['reject']) && $opts['reject'] !== '' ? (float) $opts['reject'] : 15.0;
$actor  = isset($opts['actor']) && $opts['actor'] !== '' ? (string) $opts['actor'] : 'fleet';
$wire   = isset($opts['wire']);
$panelDomain = isset($opts['panel-domain']) ? strtolower(trim((string) $opts['panel-domain'])) : '';

/**
 * Connect to the panel DB the same way the deployed cron scripts do (see
 * quarantine-maintenance.php): read the panel API config from its canonical
 * install path. Returns null when the panel isn't deployed (yet) - every
 * caller treats that as "skip, non-fatal".
 */
function mailsecProvisionPanelDb(): ?PDO
{
    $configFile = '/var/www/vps-admin/api/config.php';
    $localConfigFile = '/var/www/vps-admin/api/config.local.php';
    if (!file_exists($configFile)) {
        return null;
    }
    $config = require $configFile;
    if (file_exists($localConfigFile)) {
        $local = require $localConfigFile;
        $config = array_replace_recursive($config, (array) $local);
    }
    $db = $config['database'] ?? [];
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            (int) ($db['port'] ?? 3306),
            $db['name'] ?? 'devc_vps_dash'
        );
        return new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Build the exact `exportMaps` payload MailSecurityController::pushEngineConfig()
 * sends, from the freshly seeded panel DB. This makes the DB defaults (e.g. the
 * blocked-executable attachment policy) actually enforced in Rspamd at provision
 * time instead of waiting for the first list edit in the panel UI.
 */
function mailsecProvisionBuildExportPayload(PDO $db): array
{
    $settings = [];
    try {
        $settings = $db->query('SELECT k, v FROM mail_security_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        // Defaults below cover a missing/empty settings table.
    }
    $flag = static function (string $key, bool $default) use ($settings): bool {
        if (!array_key_exists($key, $settings)) {
            return $default;
        }
        return in_array(strtolower((string) $settings[$key]), ['1', 'true', 'yes', 'on'], true);
    };
    $mode = (strtolower((string) ($settings['mode'] ?? 'monitor')) === 'active') ? 'active' : 'monitor';
    $countries = static function (string $csv): array {
        $out = [];
        foreach (explode(',', strtoupper($csv)) as $c) {
            $c = trim($c);
            if ($c !== '') {
                $out[] = $c;
            }
        }
        return array_values(array_unique($out));
    };

    // Global allow/block lists -> map buckets (mirrors buildMaps()).
    $maps = array_fill_keys([
        'mailsec_whitelist_email', 'mailsec_whitelist_domain', 'mailsec_whitelist_ip',
        'mailsec_blacklist_email', 'mailsec_blacklist_domain', 'mailsec_blacklist_ip',
    ], []);
    foreach (['whitelist' => 'mail_security_global_whitelist', 'blacklist' => 'mail_security_global_blacklist'] as $kind => $table) {
        try {
            foreach ($db->query("SELECT type, value FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bucket = ($r['type'] === 'ip' || $r['type'] === 'cidr') ? 'ip' : $r['type'];
                $key = "mailsec_{$kind}_{$bucket}";
                if (isset($maps[$key])) {
                    $maps[$key][] = $r['value'];
                }
            }
        } catch (Throwable $e) {
            // Empty bucket -> agent writes an empty map.
        }
    }

    // Blocked attachment extensions (mirrors buildBadExtensions()); on a fresh
    // server these are the schema-seeded exe/bat/cmd/... rows.
    $badExtensions = ['reject' => [], 'quarantine' => []];
    try {
        $rows = $db->query("SELECT extension, action FROM mail_security_attachment_policy WHERE list_type = 'block'")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $ext = (string) ($row['extension'] ?? '');
            if ($ext === '') {
                continue;
            }
            $bucket = (($row['action'] ?? 'quarantine') === 'reject') ? 'reject' : 'quarantine';
            $badExtensions[$bucket][] = $ext;
        }
    } catch (Throwable $e) {
        // Empty groups simply clear the engine maps.
    }

    // Anti-spoofing (mirrors buildImpersonation(): manual entries + hosted domains).
    $impersonation = [
        'vip_names' => [],
        'protected_domains' => [],
        'exempt_senders' => [],
        'lookalike' => [
            'enabled' => $flag('lookalike_enabled', true),
            'sensitivity' => in_array(strtolower((string) ($settings['lookalike_sensitivity'] ?? 'medium')), ['low', 'medium', 'high'], true)
                ? strtolower((string) ($settings['lookalike_sensitivity'] ?? 'medium')) : 'medium',
        ],
    ];
    try {
        foreach ($db->query('SELECT kind, value FROM mail_security_impersonation')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            switch ($r['kind']) {
                case 'vip_name':
                    $impersonation['vip_names'][] = $r['value'];
                    break;
                case 'protected_domain':
                    $impersonation['protected_domains'][] = $r['value'];
                    break;
                case 'exempt_sender':
                    $impersonation['exempt_senders'][] = $r['value'];
                    break;
            }
        }
    } catch (Throwable $e) {
        // No manual entries.
    }
    try {
        foreach ($db->query('SELECT domain FROM mail_domains')->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $impersonation['protected_domains'][] = $d;
        }
    } catch (Throwable $e) {
        // No hosted-domains table yet.
    }
    $impersonation['protected_domains'] = array_values(array_unique($impersonation['protected_domains']));

    // Mail flow rules (mirrors buildRules()).
    $rules = ['mode' => $mode, 'rules' => []];
    try {
        $rows = $db->query(
            'SELECT id, name, priority, conditions_json, action, action_arg
             FROM mail_security_rules WHERE enabled = 1 ORDER BY priority ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $conds = [];
            if (!empty($r['conditions_json'])) {
                $decoded = json_decode((string) $r['conditions_json'], true);
                if (is_array($decoded)) {
                    $conds = $decoded;
                }
            }
            $rules['rules'][] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'priority' => (int) $r['priority'],
                'action' => (string) $r['action'],
                'arg' => $r['action_arg'] !== null ? (string) $r['action_arg'] : '',
                'conditions' => $conds,
            ];
        }
    } catch (Throwable $e) {
        // Empty ruleset disables the engine rules.
    }

    // Geo-IP policy (mirrors buildGeoip()).
    $geoip = [
        'mode' => $mode,
        'enabled' => $flag('geoip_enabled', false),
        'default' => [
            'mode' => in_array(($settings['geoip_mode'] ?? 'deny'), ['allow', 'deny'], true) ? $settings['geoip_mode'] : 'deny',
            'countries' => $countries((string) ($settings['geoip_countries'] ?? '')),
            'action' => in_array(($settings['geoip_action'] ?? 'reject'), ['reject', 'quarantine', 'tag'], true) ? $settings['geoip_action'] : 'reject',
        ],
        'domains' => [],
    ];
    try {
        foreach ($db->query('SELECT domain, mode, countries, action FROM mail_security_geoip')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dom = strtolower((string) $r['domain']);
            if ($dom === '') {
                continue;
            }
            $geoip['domains'][$dom] = [
                'mode' => in_array($r['mode'], ['allow', 'deny'], true) ? $r['mode'] : 'deny',
                'countries' => $countries((string) $r['countries']),
                'action' => in_array($r['action'], ['reject', 'quarantine', 'tag'], true) ? $r['action'] : 'reject',
            ];
        }
    } catch (Throwable $e) {
        // No per-domain overrides.
    }

    return [
        'maps' => $maps,
        'bad_extensions' => $badExtensions,
        'impersonation' => $impersonation,
        'rules' => $rules,
        'geoip' => $geoip,
    ];
}

$config = require __DIR__ . '/config.php';
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    $config = array_replace_recursive($config, (array) require $localConfig);
}

$logger = new Logger($config);
$backup = new BackupManager($config);
$diff   = new DiffGenerator();

$action = new MailSecurityAction($config, $backup, $diff, $logger);

$out = ['success' => false, 'steps' => []];

// 1. Install the engine foundation (Rspamd + ClamAV + unbound resolver +
//    quarantine transport). This is the same call as the panel "Install" button.
$install = $action->execute('install', ['spam_score' => $spam, 'reject_score' => $reject], $actor);
$out['steps']['install'] = $install;

if (empty($install['success'])) {
    $out['error'] = $install['error'] ?? 'engine install failed';
    fwrite(STDOUT, json_encode($out) . "\n");
    exit(1);
}

// 2. Export the DB-seeded policy into Rspamd (same payload the panel UI sends
//    via mailsec.exportMaps). Without this the schema-seeded default attachment
//    policy (exe/bat/cmd/... -> quarantine) sits in the DB but is not enforced
//    until an admin first touches a list in the UI. Best-effort: requires the
//    panel to be deployed (install_mailsecurity runs after deploy_panel).
$panelDb = mailsecProvisionPanelDb();
if ($panelDb !== null) {
    try {
        $payload = mailsecProvisionBuildExportPayload($panelDb);
        $export = $action->execute('exportMaps', $payload, $actor);
        $out['steps']['export_maps'] = $export;
        if (empty($export['success'])) {
            $logger->warning('mailsec-provision: exportMaps failed (policy will sync on first panel edit)', [
                'error' => $export['error'] ?? 'unknown',
            ]);
        }
    } catch (Throwable $e) {
        $out['steps']['export_maps'] = ['success' => false, 'error' => $e->getMessage()];
        $logger->warning('mailsec-provision: exportMaps threw', ['error' => $e->getMessage()]);
    }

    // 2b. Seed quarantine_link_base (only when empty) so the self-service
    //     quarantine digest links resolve to this server's panel from day one.
    if ($panelDomain !== '' && preg_match('/^[a-z0-9.-]+$/', $panelDomain)) {
        try {
            $cur = $panelDb->prepare('SELECT v FROM mail_security_settings WHERE k = ? LIMIT 1');
            $cur->execute(['quarantine_link_base']);
            $existing = trim((string) ($cur->fetchColumn() ?: ''));
            if ($existing === '') {
                $ins = $panelDb->prepare(
                    'INSERT INTO mail_security_settings (k, v) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE v = VALUES(v)'
                );
                $ins->execute(['quarantine_link_base', 'https://' . $panelDomain]);
                $out['steps']['quarantine_link_base'] = 'https://' . $panelDomain;
            } else {
                $out['steps']['quarantine_link_base'] = $existing . ' (kept)';
            }
        } catch (Throwable $e) {
            $logger->warning('mailsec-provision: could not seed quarantine_link_base', ['error' => $e->getMessage()]);
        }
    }
} else {
    $out['steps']['export_maps'] = ['success' => false, 'error' => 'panel DB not reachable (panel not deployed yet?)'];
    $logger->warning('mailsec-provision: panel DB not reachable - skipping policy export');
}

// 3. Optionally wire the live milter + quarantine routing (fail-open, with
//    rollback inside the action). Non-fatal: a wiring hiccup must not fail the
//    whole server build, and mail keeps flowing regardless.
if ($wire) {
    $wireResult = $action->execute('wireMilter', [], $actor);
    $out['steps']['wire'] = $wireResult;
    if (empty($wireResult['success'])) {
        $logger->warning('mailsec-provision: milter wiring failed (engine left monitor-only)', [
            'error' => $wireResult['error'] ?? 'unknown',
        ]);
    }
}

$out['success'] = true;
fwrite(STDOUT, json_encode($out) . "\n");
exit(0);
