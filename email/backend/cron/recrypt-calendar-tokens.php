<?php
/**
 * Recrypt Calendar Connection Tokens (Phase 2.4 one-shot migration)
 *
 * Walks every row in `calendar_connections` and rewrites the
 * access_token_encrypted / refresh_token_encrypted columns from the legacy
 * AES-256-CBC envelope (key = sha256(jwt_secret)) into the new AES-256-GCM
 * envelope handled by OAuthCryptor.
 *
 * Idempotent: a row whose ciphertext already starts with the `v{N}:` prefix
 * is skipped. Safe to re-run. Designed to be invoked once after deploying the
 * Phase 2.4 code change (CalendarConnectionService.php). Existing GCM-aware
 * code on read paths will continue to handle remaining CBC rows lazily, but
 * running this script puts the whole table on GCM in one pass.
 *
 * Server run command:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/recrypt-calendar-tokens.php
 *
 * Flags:
 *   --dry-run   Report what would change, do not write.
 *   --verbose   Print each row inspected.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Webmail\Services\OAuthCryptor;

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true);

$config = require __DIR__ . '/../src/config.php';

try {
    $cryptor = new OAuthCryptor($config);
} catch (\Throwable $e) {
    fwrite(STDERR, "[recrypt] OAuthCryptor init failed: " . $e->getMessage() . "\n");
    exit(1);
}

$legacyKey = hash('sha256', $config['jwt']['secret'] ?? 'default_key', true);

$pdo = \Webmail\Core\Database::getConnection($config);

$rows = $pdo->query("SELECT id, primary_email, access_token_encrypted, refresh_token_encrypted FROM calendar_connections")
    ->fetchAll(\PDO::FETCH_ASSOC);

$total = count($rows);
$migrated = 0;
$skipped = 0;
$failed = 0;

$decryptLegacy = function (string $payload) use ($legacyKey): ?string {
    if ($payload === '') {
        return '';
    }
    if (preg_match('/^v\d+:/', $payload)) {
        return null; // Already GCM
    }
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 17) {
        return null;
    }
    $iv = substr($raw, 0, 16);
    $ct = substr($raw, 16);
    $plain = openssl_decrypt($ct, 'AES-256-CBC', $legacyKey, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? null : $plain;
};

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $needsUpdate = false;
    $updates = [];

    foreach (['access_token_encrypted', 'refresh_token_encrypted'] as $col) {
        $val = (string)($row[$col] ?? '');
        if ($val === '' || preg_match('/^v\d+:/', $val)) {
            continue; // Empty or already GCM
        }
        $plain = $decryptLegacy($val);
        if ($plain === null) {
            $failed++;
            fwrite(STDERR, "[recrypt] id={$id} {$col}: legacy decrypt failed\n");
            continue 2; // Skip this row entirely
        }
        if ($plain === '') {
            continue;
        }
        $updates[$col] = $cryptor->encrypt($plain);
        $needsUpdate = true;
    }

    if (!$needsUpdate) {
        $skipped++;
        if ($verbose) {
            echo "[recrypt] id={$id}: already GCM, skipped\n";
        }
        continue;
    }

    if ($dryRun) {
        echo "[recrypt] id={$id}: would re-encrypt " . implode(',', array_keys($updates)) . "\n";
        $migrated++;
        continue;
    }

    $setSql = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($updates)));
    $stmt = $pdo->prepare("UPDATE calendar_connections SET {$setSql}, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $params = array_values($updates);
    $params[] = $id;
    try {
        $stmt->execute($params);
        $migrated++;
        if ($verbose) {
            echo "[recrypt] id={$id}: migrated\n";
        }
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "[recrypt] id={$id}: UPDATE failed: " . $e->getMessage() . "\n");
    }
}

echo "[recrypt] total={$total} migrated={$migrated} skipped={$skipped} failed={$failed}"
    . ($dryRun ? ' (dry-run)' : '') . "\n";

exit($failed > 0 ? 1 : 0);
