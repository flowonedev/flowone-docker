#!/usr/bin/env php
<?php
/**
 * remove-linked-account.php
 *
 * Safe, idempotent CLI to FULLY remove a linked/secondary email account from
 * the server and stop the notifications it keeps generating. This is the
 * supported way to do a manual cleanup (e.g. an account removed long ago whose
 * residue still drives the sync cron) and the disaster-recovery twin of the
 * AccountTeardownService that now runs automatically on account delete.
 *
 * What it touches, by address:
 *   - webmail_oauth_tokens      (oauth_email = address  -> credential rows)
 *   - webmail_accounts          (account_email = address -> credential rows)
 *   - webmail_folder_sync_state (account_email = address -> cron work queue)
 *   - webmail_folder_identity   (account_id = address    -> folder UUIDs)
 *   - Redis OAuth token cache + revoked flag (per primary,account)
 *
 * Dry-run is the DEFAULT. Nothing is deleted unless you pass --apply.
 *
 * Run on server:
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tools/remove-linked-account.php \
 *       --oauth-email=pixelrangerstudio@gmail.com --verbose            # dry-run
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/tools/remove-linked-account.php \
 *       --oauth-email=pixelrangerstudio@gmail.com --apply --verbose    # delete
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Core\Database;
use Webmail\Services\AccountTeardownService;
use Webmail\Services\OAuthCryptor;

// --------------------------------------------------------------------------
// Args
// --------------------------------------------------------------------------

$opts = [
    'oauth_email' => '',
    'primary'     => '',   // optional filter
    'provider'    => null, // optional ('google'|'microsoft')
    'apply'       => false,
    'dry_run'     => true,
    'verbose'     => false,
    'json'        => false,
    'revoke'      => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        printHelp();
        exit(0);
    } elseif ($arg === '--apply') {
        $opts['apply'] = true;
        $opts['dry_run'] = false;
    } elseif ($arg === '--dry-run') {
        $opts['dry_run'] = true;
        $opts['apply'] = false;
    } elseif ($arg === '--verbose') {
        $opts['verbose'] = true;
    } elseif ($arg === '--json') {
        $opts['json'] = true;
    } elseif ($arg === '--revoke') {
        $opts['revoke'] = true;
    } elseif (str_starts_with($arg, '--oauth-email=')) {
        $opts['oauth_email'] = strtolower(trim(substr($arg, 14)));
    } elseif (str_starts_with($arg, '--primary=')) {
        $opts['primary'] = strtolower(trim(substr($arg, 10)));
    } elseif (str_starts_with($arg, '--provider=')) {
        $opts['provider'] = strtolower(trim(substr($arg, 11))) ?: null;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        printHelp();
        exit(1);
    }
}

if ($opts['oauth_email'] === '' || !str_contains($opts['oauth_email'], '@')) {
    fwrite(STDERR, "ERROR: --oauth-email=<address> is required.\n\n");
    printHelp();
    exit(1);
}

function printHelp(): void
{
    echo <<<TXT
remove-linked-account.php - fully purge a linked/secondary email account

  --oauth-email=ADDR   REQUIRED. The mailbox address to purge (e.g. foo@gmail.com)
  --primary=ADDR       Optional. Limit to one owner login (default: all owners)
  --provider=NAME      Optional. 'google' | 'microsoft' (default: clear both caches)
  --dry-run            Show what would be removed; change nothing (DEFAULT)
  --apply              Actually delete the rows and clear the caches
  --revoke             Also revoke the refresh token at Google (default OFF)
  --verbose            Per-row detail
  --json               Emit the result summary as JSON
  --help               This banner

TXT;
}

// --------------------------------------------------------------------------
// Bootstrap services
// --------------------------------------------------------------------------

$config = require __DIR__ . '/../src/config.php';

try {
    $db = Database::getConnection($config);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$addr = $opts['oauth_email'];
$mode = $opts['apply'] ? 'APPLY' : 'DRY-RUN';

line("=== remove-linked-account [{$mode}] address={$addr} ===");

// --------------------------------------------------------------------------
// Phase 1: discover every (primary, account) pair + row counts
// --------------------------------------------------------------------------

$scan = scanTraces($db, $addr, $opts['primary']);

line('Found:');
line(sprintf('  webmail_oauth_tokens      : %d row(s)', count($scan['oauth'])));
line(sprintf('  webmail_accounts          : %d row(s)', count($scan['accounts'])));
line(sprintf('  webmail_folder_sync_state : %d row(s)', $scan['sync_state_count']));
line(sprintf('  webmail_folder_identity   : %d row(s)', $scan['identity_count']));

if ($opts['verbose']) {
    foreach ($scan['oauth'] as $r) {
        line(sprintf('    oauth   id=%s primary=%s provider=%s health=%s',
            $r['id'], $r['primary_email'], $r['provider'] ?? '', $r['health'] ?? ''));
    }
    foreach ($scan['accounts'] as $r) {
        line(sprintf('    account id=%s primary=%s', $r['id'], $r['primary_email']));
    }
}

$pairs = collectPairs($scan, $addr);

if (empty($pairs)) {
    line('Nothing references this address. Already clean.');
    finishOutput($opts, ['address' => $addr, 'mode' => $mode, 'pairs' => [], 'removed' => []]);
    exit(0);
}

line('Owner login(s) affected: ' . implode(', ', array_keys($pairs)));

// --------------------------------------------------------------------------
// Phase 2: dry-run stops here
// --------------------------------------------------------------------------

if (!$opts['apply']) {
    line('');
    line('DRY-RUN: no changes made. Re-run with --apply to remove the above.');
    finishOutput($opts, [
        'address' => $addr,
        'mode'    => $mode,
        'pairs'   => array_keys($pairs),
        'scan'    => [
            'oauth'      => count($scan['oauth']),
            'accounts'   => count($scan['accounts']),
            'sync_state' => $scan['sync_state_count'],
            'identity'   => $scan['identity_count'],
        ],
    ]);
    exit(0);
}

// --------------------------------------------------------------------------
// Phase 3: APPLY - capture revoke tokens, delete credential rows, teardown
// --------------------------------------------------------------------------

$teardown = new AccountTeardownService($config, $db);
$cryptor  = $opts['revoke'] ? new OAuthCryptor($config) : null;

// Capture refresh tokens BEFORE deleting the rows, if revoke requested.
$refreshByPrimary = [];
if ($opts['revoke'] && $cryptor) {
    foreach ($scan['oauth'] as $r) {
        $enc = (string)($r['refresh_token_encrypted'] ?? '');
        if ($enc !== '') {
            $dec = $cryptor->decrypt($enc);
            if ($dec) {
                $refreshByPrimary[strtolower((string)$r['primary_email'])] = $dec;
            }
        }
    }
}

// Delete the credential rows (these belong to the table-owning services in the
// app path; here, in the manual-recovery path, we remove them directly).
$delOauth = execDelete($db, 'DELETE FROM webmail_oauth_tokens WHERE LOWER(oauth_email) = ?', [$addr]);
$delAcct  = execDelete($db, 'DELETE FROM webmail_accounts WHERE LOWER(account_email) = ?', [$addr]);
line(sprintf('Deleted credential rows: oauth=%d accounts=%d', $delOauth, $delAcct));

// Teardown residual state for each (primary, account) pair.
$removed = [];
foreach ($pairs as $primary => $_) {
    $purgeOpts = [];
    if (isset($refreshByPrimary[$primary])) {
        $purgeOpts['refresh_token'] = $refreshByPrimary[$primary];
    }
    $r = $teardown->purge($primary, $addr, $opts['provider'], $purgeOpts);
    $removed[] = $r;
    line(sprintf(
        '  teardown primary=%s -> sync_state=%d identity=%d token_cache=%s unread=%s revoked=%s',
        $primary,
        $r['sync_state_rows'],
        $r['identity_rows'],
        $r['token_cache'] ? 'yes' : 'no',
        $r['unread_cache'] ? 'yes' : 'no',
        $r['provider_revoked'] ? 'yes' : 'no'
    ));
}

// --------------------------------------------------------------------------
// Phase 4: verify zero residue
// --------------------------------------------------------------------------

$after = scanTraces($db, $addr, $opts['primary']);
$residue = count($after['oauth']) + count($after['accounts'])
         + $after['sync_state_count'] + $after['identity_count'];

line('');
if ($residue === 0) {
    line('VERIFIED: zero rows remain for ' . $addr . '.');
} else {
    line('WARNING: ' . $residue . ' row(s) still reference ' . $addr . ' (see --verbose).');
}

finishOutput($opts, [
    'address'  => $addr,
    'mode'     => $mode,
    'pairs'    => array_keys($pairs),
    'deleted'  => ['oauth' => $delOauth, 'accounts' => $delAcct],
    'teardown' => $removed,
    'residue'  => $residue,
]);

exit($residue === 0 ? 0 : 1);

// ==========================================================================
// Helpers
// ==========================================================================

/** Locate every trace of $addr across the four tables. */
function scanTraces(\PDO $db, string $addr, string $primaryFilter): array
{
    $oauth = [];
    $accounts = [];
    $syncCount = 0;
    $identityCount = 0;

    $primaryClause = $primaryFilter !== '' ? ' AND LOWER(primary_email) = ?' : '';

    try {
        $sql = 'SELECT id, primary_email, oauth_email, provider, health, refresh_token_encrypted
                  FROM webmail_oauth_tokens
                 WHERE LOWER(oauth_email) = ?' . $primaryClause;
        $stmt = $db->prepare($sql);
        $stmt->execute($primaryFilter !== '' ? [$addr, $primaryFilter] : [$addr]);
        $oauth = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        fwrite(STDERR, 'scan oauth: ' . $e->getMessage() . "\n");
    }

    try {
        $sql = 'SELECT id, primary_email, account_email
                  FROM webmail_accounts
                 WHERE LOWER(account_email) = ?' . $primaryClause;
        $stmt = $db->prepare($sql);
        $stmt->execute($primaryFilter !== '' ? [$addr, $primaryFilter] : [$addr]);
        $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        fwrite(STDERR, 'scan accounts: ' . $e->getMessage() . "\n");
    }

    $syncOwners = [];
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM webmail_folder_sync_state WHERE LOWER(account_email) = ?');
        $stmt->execute([$addr]);
        $syncCount = (int)$stmt->fetchColumn();

        // Distinct owners - so a pure orphan (sync-state row with no credential
        // row) still gets its queue purged by the teardown below.
        $stmt = $db->prepare('SELECT DISTINCT LOWER(user_email) AS u FROM webmail_folder_sync_state WHERE LOWER(account_email) = ?');
        $stmt->execute([$addr]);
        $syncOwners = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'u');
    } catch (\Throwable $e) {
        fwrite(STDERR, 'scan sync_state: ' . $e->getMessage() . "\n");
    }

    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM webmail_folder_identity WHERE LOWER(account_id) = ?');
        $stmt->execute([$addr]);
        $identityCount = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        fwrite(STDERR, 'scan identity: ' . $e->getMessage() . "\n");
    }

    return [
        'oauth'            => $oauth,
        'accounts'         => $accounts,
        'sync_state_count' => $syncCount,
        'sync_owners'      => $syncOwners,
        'identity_count'   => $identityCount,
    ];
}

/**
 * Build the set of (primary -> true) owner logins that reference the address.
 * Includes a self-OAuth address as its own owner so the teardown also clears
 * its caches.
 */
function collectPairs(array $scan, string $addr): array
{
    $pairs = [];
    foreach ($scan['oauth'] as $r) {
        $pairs[strtolower((string)$r['primary_email'])] = true;
    }
    foreach ($scan['accounts'] as $r) {
        $pairs[strtolower((string)$r['primary_email'])] = true;
    }
    // sync_state rows may exist with no credential row (the orphan case). We
    // still need their owner login so the teardown can purge the queue.
    foreach (($scan['sync_owners'] ?? []) as $owner) {
        if ($owner !== '') {
            $pairs[strtolower((string)$owner)] = true;
        }
    }
    return $pairs;
}

function execDelete(\PDO $db, string $sql, array $params): int
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (\Throwable $e) {
        fwrite(STDERR, 'delete error: ' . $e->getMessage() . "\n");
        return 0;
    }
}

function line(string $msg): void
{
    echo $msg . "\n";
}

function finishOutput(array $opts, array $summary): void
{
    if (!empty($opts['json'])) {
        echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
    }
}
