#!/usr/bin/env php
<?php
/**
 * docker-stack-smoke-test.php — Layer 1 stack smoke (Phase C).
 *
 * Run INSIDE the `web` container of the production compose stack. It exercises
 * the exact effective config the app loads (src/config.php) and proves the
 * bridge-network plumbing is sound BEFORE any functional/e2e work:
 *
 *   - web -> mariadb   (PDO connect, core schema present, migrations applied)
 *   - web -> redis     (PING)
 *   - web -> meili     (GET /health == available)
 *   - web -> collab    (TCP COLLAB_ADDR, default collab:1234)
 *   - web -> mailsync  (TCP MAILSYNC_ADDR, default mailsync:1235)
 *   - web -> imap      (TCP IMAP_HOST:993; skipped with --skip-send)
 *   - config sanity    (JWT keys/secret, IMAP_ENCRYPTION_KEY, OAuth canary)
 *
 * This is the harness referenced by PLAN.md "Layer 1 (image/stack smoke)".
 * Per .cursor/rules/server-side-testing.mdc — CLI only, non-destructive,
 * read-only (no writes, nothing to clean up), per-test timeouts, timestamped
 * log, exit 0/1.
 *
 * Run (against the live local/staging stack):
 *   docker exec flowone-web-1 /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/docker-stack-smoke-test.php --verbose
 *
 *   # quick connectivity-only health check:
 *   docker exec flowone-web-1 /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/docker-stack-smoke-test.php --smoke --json
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Core\Database;

$runner = new FlowOneTestRunner('docker-stack-smoke', $argv);

$config = require __DIR__ . '/../src/config.php';

/** Open a TCP connection to host:port within $timeout seconds; throw on failure. */
$tcpProbe = function (string $host, int $port, float $timeout = 5.0): void {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        throw new \RuntimeException("cannot reach {$host}:{$port} ({$errno} {$errstr})");
    }
    fclose($fp);
};

// --- 0. PREFLIGHT ------------------------------------------------------------

$runner->section('0. PREFLIGHT');

foreach (['pdo_mysql', 'curl', 'openssl'] as $ext) {
    $runner->test("php extension loaded: {$ext}", function () use ($ext) {
        if (!extension_loaded($ext)) throw new \RuntimeException("missing extension {$ext}");
        return true;
    });
}

// --- 1. CONNECTIVITY (web -> peers) -----------------------------------------

$runner->section('1. CONNECTIVITY');

if ($runner->shouldRunSection('1. CONNECTIVITY')) {

    $runner->test('web -> mariadb: PDO connect + SELECT 1', function () use ($config) {
        $db = Database::getConnection($config);
        $v = (string) $db->query('SELECT 1')->fetchColumn();
        if ($v !== '1') throw new \RuntimeException('unexpected SELECT 1 result');
        return true;
    });

    $runner->test('web -> redis: PING', function () use ($config) {
        $r = $config['redis'];
        if (class_exists('\Redis')) {
            $redis = new \Redis();
            if (!@$redis->connect($r['host'], (int) $r['port'], (float) ($r['timeout'] ?? 2.0))) {
                throw new \RuntimeException("redis connect failed to {$r['host']}:{$r['port']}");
            }
            if (!empty($r['password'])) {
                $redis->auth($r['password']);
            }
            $pong = $redis->ping();
            $redis->close();
            // phpredis returns true or '+PONG' depending on version
            if ($pong !== true && $pong !== '+PONG' && strtoupper((string) $pong) !== 'PONG') {
                throw new \RuntimeException('redis PING did not pong: ' . var_export($pong, true));
            }
            return true;
        }
        // Fallback: raw RESP PING over a socket.
        $fp = @fsockopen($r['host'], (int) $r['port'], $e, $s, (float) ($r['timeout'] ?? 2.0));
        if (!$fp) throw new \RuntimeException("redis socket failed: {$e} {$s}");
        if (!empty($r['password'])) {
            fwrite($fp, "AUTH {$r['password']}\r\n");
            fgets($fp);
        }
        fwrite($fp, "PING\r\n");
        $line = (string) fgets($fp);
        fclose($fp);
        if (stripos($line, 'PONG') === false) throw new \RuntimeException('no PONG: ' . trim($line));
        return true;
    });

    $runner->test('web -> meilisearch: GET /health == available', function () use ($config) {
        $host = rtrim((string) ($config['meilisearch']['host'] ?? ''), '/');
        if ($host === '') throw new \RuntimeException('MEILI_HOST not configured');
        $ch = curl_init($host . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new \RuntimeException("meili unreachable at {$host}: {$err}");
        if ($code !== 200) throw new \RuntimeException("meili /health HTTP {$code}");
        $json = json_decode((string) $body, true);
        if (($json['status'] ?? '') !== 'available') {
            throw new \RuntimeException('meili status != available: ' . trim((string) $body));
        }
        return true;
    });

    $runner->test('web -> collab: TCP reachable', function () use ($config, $tcpProbe) {
        $addr = getenv('COLLAB_ADDR') ?: ((($config['collab']['ws_host'] ?? 'collab')) . ':' . ($config['collab']['ws_port'] ?? 1234));
        [$host, $port] = array_pad(explode(':', $addr, 2), 2, '1234');
        $tcpProbe($host, (int) $port);
        return true;
    });

    $runner->test('web -> mailsync: TCP reachable', function () use ($tcpProbe) {
        $addr = getenv('MAILSYNC_ADDR') ?: 'mailsync:1235';
        [$host, $port] = array_pad(explode(':', $addr, 2), 2, '1235');
        $tcpProbe($host, (int) $port);
        return true;
    });

    $runner->test('web -> imap: TCP reachable (993)', function () use ($runner, $tcpProbe) {
        if ($runner->skipSend) return 'skip';
        $host = getenv('IMAP_HOST') ?: '';
        if ($host === '' || $host === 'localhost') return 'skip';
        $tcpProbe($host, 993, 6.0);
        return true;
    });
}

if ($runner->smoke) {
    exit($runner->finish());
}

// --- 2. SCHEMA / MIGRATIONS --------------------------------------------------

$runner->section('2. SCHEMA');

if ($runner->shouldRunSection('2. SCHEMA')) {

    $runner->test('core tables present', function () use ($config) {
        $db = Database::getConnection($config);
        $required = ['migrations', 'webmail_conversations', 'webmail_accounts', 'schema_guards'];
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $missing = [];
        foreach ($required as $t) {
            $stmt->execute([$t]);
            if ((int) $stmt->fetchColumn() === 0) $missing[] = $t;
        }
        if ($missing) throw new \RuntimeException('missing tables: ' . implode(', ', $missing));
        return true;
    });

    $runner->test('migrations have been applied', function () use ($config) {
        $db = Database::getConnection($config);
        $n = (int) $db->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        if ($n <= 0) throw new \RuntimeException('migrations table is empty (none applied)');
        return true;
    });

    $runner->test('schema_guards is recording markers (DDL gating live)', function () use ($config) {
        $db = Database::getConnection($config);
        $n = (int) $db->query('SELECT COUNT(*) FROM schema_guards')->fetchColumn();
        if ($n <= 0) return 'warn'; // not yet warmed; not a hard failure on a cold boot
        return true;
    });
}

// --- 3. CONFIG SANITY (non-regenerable secrets + canary) --------------------

$runner->section('3. CONFIG');

if ($runner->shouldRunSection('3. CONFIG')) {

    $runner->test('JWT is usable (RS256 keys readable, or HS256 secret set)', function () use ($config) {
        $jwt = $config['jwt'];
        $alg = strtoupper((string) ($jwt['algorithm'] ?? ''));
        if ($alg === 'HS256') {
            if (empty($jwt['secret'])) throw new \RuntimeException('HS256 selected but JWT_SECRET empty');
            return true;
        }
        // RS256 (production default): both PEMs must exist and be readable.
        foreach (['private_key_path' => $jwt['private_key_path'] ?? '', 'public_key_path' => $jwt['public_key_path'] ?? ''] as $label => $path) {
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                throw new \RuntimeException("RS256 {$label} missing/unreadable: {$path}");
            }
        }
        return true;
    });

    $runner->test('IMAP_ENCRYPTION_KEY present', function () use ($config) {
        if (empty($config['imap_encryption_key'])) {
            throw new \RuntimeException('imap_encryption_key empty — OAuth canary would fail and logins break');
        }
        return true;
    });

    $runner->test('OAuth encryption canary passes', function () use ($config) {
        // Same fail-fast the app runs in public/index.php. Throws on misconfig.
        \Webmail\Services\OAuthCryptor::canaryCheck($config);
        return true;
    });

    $runner->test('DB host points at a real host (not the localhost fallback)', function () use ($config) {
        $host = (string) ($config['db']['host'] ?? '');
        if ($host === '' ) throw new \RuntimeException('DB_HOST empty');
        // In compose this is the `mariadb` service name. A 127.0.0.1 here on a
        // containerized web tier means .env wasn't injected — flag it.
        if (in_array($host, ['127.0.0.1', 'localhost'], true)) return 'warn';
        return true;
    });
}

exit($runner->finish());
