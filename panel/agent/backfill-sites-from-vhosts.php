<?php
/**
 * vpsadmin-backfill-sites-from-vhosts
 *
 * One-shot CLI that walks /usr/local/lsws/conf/vhosts and inserts a
 * row into the `sites` table for every existing OLS vhost. After
 * this runs once, the legacy filesystem-driven sites and the v2
 * SitesV2View share the same data set.
 *
 * The script is intentionally idempotent: rows that already exist
 * for a domain are skipped (unless --overwrite is passed). Re-runs
 * pick up new vhosts created since the last pass.
 *
 * Usage:
 *   backfill-sites-from-vhosts.php                run with default vhosts path
 *   backfill-sites-from-vhosts.php --vhosts=PATH  override the scan root
 *   backfill-sites-from-vhosts.php --only=A,B     only adopt the listed domains
 *   backfill-sites-from-vhosts.php --dry-run      parse + report but DO NOT insert
 *   backfill-sites-from-vhosts.php --overwrite    update existing rows in place
 *   backfill-sites-from-vhosts.php --json         machine-readable summary
 *   backfill-sites-from-vhosts.php --verbose      per-domain trace
 *   backfill-sites-from-vhosts.php --smoke        bootstrap + DB ping only
 *   backfill-sites-from-vhosts.php --help         print this and exit
 *
 * Exit codes:
 *   0  - all vhosts processed (skipped or inserted), no fatal errors
 *   1  - bootstrap failed (DB unreachable, vhosts path missing, etc.)
 *   2  - at least one domain failed to adopt (others may still have succeeded)
 *
 * What gets adopted into the `sites` row:
 *   - php_version           parsed from extprocessor / path directives
 *   - sftp_user             parsed from extUser
 *   - home_dir              derived from $VH_ROOT or document_root parent
 *   - document_root         parsed from docRoot, $VH_ROOT expanded
 *   - ssl_enabled           true if /etc/letsencrypt/live/<domain> cert exists
 *   - ssl_expires_at        x509 notAfter from that cert
 *   - ssl_issuer            cert issuer CN
 *   - db_name / db_user     resolved by probing MariaDB for a database
 *                           whose name matches fo_<slug>, <slug>_db, <slug>
 *                           (best-effort; NULL if nothing matches)
 *   - dns_enabled = 0       (no DNS saga step yet; reconciler can flip
 *                           later when a DNS prober lands)
 *   - mail_enabled = 0      same shape
 *
 * Run command (on server):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/agent/backfill-sites-from-vhosts.php --verbose --dry-run
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/agent/backfill-sites-from-vhosts.php --verbose
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "backfill-sites must run from CLI\n");
    exit(1);
}

spl_autoload_register(function (string $class): void {
    $prefix = 'VpsAdmin\\Agent\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use VpsAdmin\Agent\Provisioner\Adapters\MysqlAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

$opts = getopt('', [
    'vhosts:', 'only:', 'dry-run', 'overwrite',
    'json', 'verbose', 'smoke', 'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2800));
    exit(0);
}

$vhostsRoot = isset($opts['vhosts']) ? (string) $opts['vhosts'] : '/usr/local/lsws/conf/vhosts';
$onlyList = isset($opts['only'])
    ? array_filter(array_map('trim', explode(',', (string) $opts['only'])))
    : [];
$dryRun = isset($opts['dry-run']);
$overwrite = isset($opts['overwrite']);
$json = isset($opts['json']);
$verbose = isset($opts['verbose']);
$smoke = isset($opts['smoke']);

// ─────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────

try {
    $db = PanelDatabase::fromDefaultConfigFiles();
    $db->pdo()->query('SELECT 1');

    $masker = new SecretMasker();
    $audit = new AuditLogger($db, $masker);
    $stateMachine = new SiteStateMachine($db, $audit);

    $runner = new ProcessCommandRunner();
    // Use the admin resolver so the backfill probe sees the same
    // databases the saga can write to. Falling through to the panel
    // user would still let us run information_schema queries, but
    // matching the saga's privilege scope keeps "what we adopt" in
    // sync with "what we can manage". See Support/MysqlAdminCredentials.
    $mysql = new MysqlAdapter($runner, MysqlAdminCredentials::providerFromDefaultConfigFiles());
} catch (\Throwable $e) {
    fwrite(STDERR, '[backfill] bootstrap failed: '
        . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($smoke) {
    fwrite(STDOUT, "[backfill] smoke OK (db reachable, vhosts root=" . $vhostsRoot . ")\n");
    exit(0);
}

if (!is_dir($vhostsRoot)) {
    fwrite(STDERR, "[backfill] vhosts root does not exist: {$vhostsRoot}\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────
// Scan + adopt
// ─────────────────────────────────────────────────────────

$actor = ActorContext::cli('backfill-sites-from-vhosts');

$dirs = glob(rtrim($vhostsRoot, '/') . '/*', GLOB_ONLYDIR);
sort($dirs);

$results = [
    'scanned' => 0,
    'inserted' => 0,
    'skipped_existing' => 0,
    'skipped_no_config' => 0,
    'failed' => 0,
    'dry_run' => $dryRun,
    'rows' => [],
];

foreach ($dirs as $dir) {
    $domain = basename($dir);

    if ($onlyList !== [] && !in_array($domain, $onlyList, true)) {
        continue;
    }

    $results['scanned']++;

    $configFile = pickVhostConfigFile($dir);
    if ($configFile === null) {
        $results['skipped_no_config']++;
        $results['rows'][] = ['domain' => $domain, 'status' => 'skipped_no_config'];
        if ($verbose) {
            fwrite(STDOUT, "[skip] {$domain}: no vhost.conf or vhconf.conf in {$dir}\n");
        }
        continue;
    }

    try {
        $parsed = parseVhostConfig($domain, $configFile);
        $ssl = probeSslCert($domain);
        [$dbName, $dbUser] = probeDatabase($mysql, $domain);

        $config = [
            'php_version' => $parsed['php_version'],
            'php_handler' => $parsed['php_handler'],
            'sftp_user' => $parsed['ext_user'],
            'sftp_group' => $parsed['ext_user'],
            'home_dir' => $parsed['home_dir'],
            'document_root' => $parsed['document_root'],
            'ssl_enabled' => $ssl['enabled'],
            'ssl_expires_at' => $ssl['expires_at'],
            'ssl_issuer' => $ssl['issuer'],
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'dns_enabled' => false,
            'mail_enabled' => false,
            'imported_from' => 'legacy-vhost',
            'imported_config_file' => $configFile,
        ];

        if ($dryRun) {
            $results['rows'][] = [
                'domain' => $domain,
                'status' => 'dry_run',
                'config' => $config,
            ];
            if ($verbose) {
                fwrite(STDOUT, "[dry] {$domain}: would adopt with "
                    . jsonShort($config) . "\n");
            }
            continue;
        }

        $r = $stateMachine->adoptExisting($domain, $config, $actor, $overwrite);

        if ($r['inserted'] === 1) {
            $results['inserted']++;
            $results['rows'][] = [
                'domain' => $domain,
                'status' => 'inserted',
                'site_id' => $r['site_id'],
            ];
            if ($verbose) {
                fwrite(STDOUT, "[ok] {$domain}: adopted as site #{$r['site_id']}\n");
            }
        } elseif ($r['already_existed']) {
            $results['skipped_existing']++;
            $results['rows'][] = [
                'domain' => $domain,
                'status' => 'skipped_existing',
                'site_id' => $r['site_id'],
            ];
            if ($verbose) {
                fwrite(STDOUT, "[skip] {$domain}: already in sites table (site_id={$r['site_id']})\n");
            }
        }
    } catch (\Throwable $e) {
        $results['failed']++;
        $results['rows'][] = [
            'domain' => $domain,
            'status' => 'failed',
            'error' => $e->getMessage(),
        ];
        fwrite(STDERR, "[fail] {$domain}: " . $e->getMessage() . "\n");
        if ($verbose) {
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }
    }
}

// ─────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────

if ($json) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    fwrite(STDOUT, sprintf(
        "\n[backfill] scanned=%d inserted=%d skipped_existing=%d skipped_no_config=%d failed=%d%s\n",
        $results['scanned'],
        $results['inserted'],
        $results['skipped_existing'],
        $results['skipped_no_config'],
        $results['failed'],
        $dryRun ? '  (DRY-RUN)' : ''
    ));
}

exit($results['failed'] > 0 ? 2 : 0);

// ─────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────

function pickVhostConfigFile(string $dir): ?string
{
    foreach (['vhost.conf', 'vhconf.conf'] as $candidate) {
        $path = $dir . '/' . $candidate;
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Slim parser - we only need fields the sites table actually stores.
 * Mirrors the regexes from VhostAction::parseVhostConfig but without
 * pulling in the 6k-line legacy file.
 *
 * @return array{
 *   document_root: ?string,
 *   home_dir: ?string,
 *   php_handler: ?string,
 *   php_version: ?string,
 *   ext_user: ?string
 * }
 */
function parseVhostConfig(string $domain, string $configFile): array
{
    $content = (string) @file_get_contents($configFile);

    $out = [
        'document_root' => null,
        'home_dir' => null,
        'php_handler' => null,
        'php_version' => null,
        'ext_user' => null,
    ];

    // docRoot - $VH_ROOT expands to /home/<domain>
    if (preg_match('/docRoot\s+(.+)$/m', $content, $m)) {
        $docRoot = trim($m[1]);
        if (strpos($docRoot, '$VH_ROOT') !== false) {
            $docRoot = str_replace('$VH_ROOT', '/home/' . $domain, $docRoot);
        }
        $out['document_root'] = $docRoot;
    }

    // home_dir: parent of document_root if recognisable, else /home/<domain>
    if ($out['document_root'] !== null) {
        $parent = dirname($out['document_root']);
        if ($parent !== '/' && $parent !== '.') {
            $out['home_dir'] = $parent;
        }
    }
    if ($out['home_dir'] === null) {
        $out['home_dir'] = '/home/' . $domain;
    }

    // PHP handler: CyberPanel style first (path /usr/local/lsws/lsphp83/bin/lsphp)
    if (preg_match('/path\s+\/usr\/local\/lsws\/lsphp(\d)(\d)\/bin\/lsphp/m', $content, $m)) {
        $out['php_handler'] = 'lsphp' . $m[1] . $m[2];
        $out['php_version'] = $m[1] . '.' . $m[2];
    } elseif (preg_match('/extprocessor\s+(lsphp(\d)(\d))/m', $content, $m)) {
        $out['php_handler'] = $m[1];
        $out['php_version'] = $m[2] . '.' . $m[3];
    }

    if (preg_match('/extUser\s+(\S+)/m', $content, $m)) {
        $out['ext_user'] = $m[1];
    }

    return $out;
}

/**
 * Read /etc/letsencrypt/live/<domain>/fullchain.pem and pull out the
 * notAfter + issuer. We deliberately do NOT consult vhost.conf's
 * vhssl block: if the operator wrote a cert path that doesn't
 * resolve, the legacy parseVhostConfig still marked SSL true. We're
 * stricter - only certs we can actually read count.
 *
 * @return array{enabled: bool, expires_at: ?string, issuer: ?string}
 */
function probeSslCert(string $domain): array
{
    $certPath = '/etc/letsencrypt/live/' . $domain . '/fullchain.pem';
    if (!is_file($certPath) || !is_readable($certPath)) {
        return ['enabled' => false, 'expires_at' => null, 'issuer' => null];
    }
    $pem = (string) @file_get_contents($certPath);
    if ($pem === '') {
        return ['enabled' => false, 'expires_at' => null, 'issuer' => null];
    }
    $parsed = @openssl_x509_parse($pem);
    if (!is_array($parsed)) {
        return ['enabled' => false, 'expires_at' => null, 'issuer' => null];
    }
    $expiresAt = isset($parsed['validTo_time_t'])
        ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t'])
        : null;
    $issuer = null;
    if (isset($parsed['issuer']) && is_array($parsed['issuer'])) {
        $issuer = (string) ($parsed['issuer']['CN'] ?? $parsed['issuer']['O'] ?? '');
        $issuer = $issuer === '' ? null : substr($issuer, 0, 64);
    }
    return [
        'enabled' => true,
        'expires_at' => $expiresAt,
        'issuer' => $issuer,
    ];
}

/**
 * Best-effort DB detection. We try the three naming conventions FlowOne
 * has used (fo_<slug>, <slug>_db, <slug>) and pick the first that
 * actually exists in MariaDB.
 *
 * Returns [db_name, db_user] or [null, null] if nothing matches.
 *
 * @return array{0: ?string, 1: ?string}
 */
function probeDatabase(MysqlAdapter $mysql, string $domain): array
{
    $slug = strtolower($domain);
    $slug = preg_replace('/[^a-z0-9_]/', '_', $slug) ?? '';
    $slug = trim($slug, '_');
    if ($slug === '') {
        return [null, null];
    }

    $candidates = [
        'fo_' . $slug,
        $slug . '_db',
        $slug,
    ];
    // MariaDB max identifier is 64 chars; trim aggressively.
    $candidates = array_map(static fn(string $c) => substr($c, 0, 64), $candidates);

    foreach ($candidates as $dbName) {
        // Pre-validate against the MysqlAdapter safe-name shape so a
        // malformed candidate (e.g. truncated to end in something
        // weird) only skips ITSELF, not the whole probe.
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName) !== 1) {
            continue;
        }
        try {
            if (!$mysql->databaseExists($dbName)) {
                continue;
            }
        } catch (\Throwable) {
            // Real query/connection failure - we cannot tell what's
            // out there. Bail entirely so the caller logs db_name=null
            // and the reconciler can recheck on its next pass once
            // credentials are fixed.
            return [null, null];
        }
        // Found a DB. Probe a matching user under the same name.
        $dbUser = null;
        try {
            if ($mysql->userExists($dbName)) {
                $dbUser = $dbName;
            }
        } catch (\Throwable) {
            // ignore; we'll just leave user null.
        }
        return [$dbName, $dbUser];
    }

    return [null, null];
}

function jsonShort(array $config): string
{
    return json_encode([
        'php' => $config['php_version'],
        'user' => $config['sftp_user'],
        'home' => $config['home_dir'],
        'ssl' => $config['ssl_enabled'],
        'db' => $config['db_name'],
    ], JSON_UNESCAPED_SLASHES);
}
