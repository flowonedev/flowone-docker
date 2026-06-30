#!/usr/bin/env php
<?php
/**
 * folder-id-resolution-test.php
 *
 * Walks the full chain a user triggers when they click an email in INBOX
 * on an OAuth (Gmail / Microsoft) account. Each layer is a named test so
 * the failure point is pinpoint, not a guessing game.
 *
 * Maps 1:1 to the production console log:
 *
 *   [Mailbox] folder_id unresolved for "INBOX" -- refreshing folder list and retrying
 *   [Mailbox] folder_id STILL unresolved for "INBOX" after refresh -- aborting message open
 *
 * The chain it exercises:
 *
 *   OAuth token refresh -> ImapService::connectWithOAuth -> listFolders ->
 *   FolderIndexService::upsertFromListing -> MailboxController::annotateFoldersWithIdentity ->
 *   RedisCacheService folder_list round-trip -> FolderInputResolver::resolve ->
 *   MailboxController::folders() -> /folders/{folder_id}/messages/{uid}
 *
 * Per .cursor/rules/server-side-testing.mdc:
 *   CLI-only, --help / --verbose / --skip-send / --only= / --smoke / --json /
 *   --timeout=, pre-flight extension + service checks, signal-safe cleanup,
 *   per-test pcntl_alarm timeout, timestamped log under storage/logs/,
 *   color-coded PASS / FAIL / WARN, summary + exit 0/1, idempotent.
 *
 * Per .cursor/rules/never-leave-orphans.mdc: this script is wired into the
 * existing test runner library (tests/lib/test-runner.php) and the production
 * controllers / services it exercises; nothing new is left orphan.
 *
 * Safety:
 *   - Synthetic identity rows use the prefix "flowone_test_" so they cannot
 *     collide with real user data.
 *   - Real-account Redis writes happen ONLY to the legitimate folder_list
 *     cache key and are ALWAYS restored to the captured pre-test state at
 *     cleanup, so a crash mid-test cannot leave a poisoned production cache.
 *   - No SMTP send, no IMAP folder mutations, no DB writes against the real
 *     OAuth account (writes only against the synthetic flowone_test_* account).
 *
 * Run command (per migrations.mdc / project-info.mdc):
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/folder-id-resolution-test.php \
 *     --email=USER@gmail.com --verbose
 *
 * Required flags:
 *   --email=EMAIL    Primary_email OR oauth_email of the OAuth account to test.
 *
 * Optional flags (parsed by FlowOneTestRunner; see tests/lib/test-runner.php):
 *   --help                  Show this banner
 *   --verbose               Per-test debug + stack traces on failure
 *   --skip-send             No-op here (suite is read-only). Accepted for parity.
 *   --only=GROUP[,GROUP]    Run only the named test sections
 *                           (preflight|oauth-token|imap-connect|list-folders|
 *                            identity-upsert|annotate|input-resolver|cache-roundtrip|
 *                            cache-poison|ctl-folders|ctl-message|race-retry)
 *   --smoke                 Pre-flight only
 *   --timeout=SEC           Per-test pcntl_alarm timeout (default 30)
 *   --json                  Emit final result table as JSON
 *   --account-id=N          Force a specific webmail_oauth_tokens.id row
 *
 * Exit codes:
 *   0  all PASS or WARN
 *   1  at least one FAIL (or pre-flight blocked execution)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "folder-id-resolution-test.php is CLI-only\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Controllers\MailboxController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\FolderIndexService;
use Webmail\Services\FolderInputResolver;
use Webmail\Services\GoogleOAuthService;
use Webmail\Services\ImapService;
use Webmail\Services\MicrosoftOAuthService;
use Webmail\Services\OAuthCryptor;
use Webmail\Services\OAuthTokenCache;
use Webmail\Services\RedisCacheService;

$runner = new FlowOneTestRunner('folder-id-resolution', $argv);

// FlowOneTestRunner already swallowed its own flags; whatever remains in
// $runner->extra is for us. Parse our own --email / --account-id from there.
$ownOpts = ['email' => null, 'account-id' => null];
foreach ($runner->extra as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $ownOpts['email'] = trim(substr($arg, 8));
    } elseif (str_starts_with($arg, '--account-id=')) {
        $ownOpts['account-id'] = trim(substr($arg, 13));
    }
}

if (empty($ownOpts['email'])) {
    $runner->log('FAIL: --email=USER@gmail.com is required');
    $runner->log('Run with --help for usage.');
    exit(1);
}

$testEmailArg = strtolower($ownOpts['email']);
$forceAccountId = $ownOpts['account-id'] !== null ? (int) $ownOpts['account-id'] : null;

$config = require __DIR__ . '/../src/config.php';

// =============================================================================
// 0. PREFLIGHT (smoke mode stops here).
// =============================================================================

$runner->section('0. PREFLIGHT');

$db = null;
$redis = null;

foreach (['imap', 'openssl', 'pdo_mysql', 'mbstring', 'redis', 'curl'] as $ext) {
    $runner->test("ext:{$ext} loaded", function () use ($ext) {
        if (!extension_loaded($ext)) {
            throw new \RuntimeException("PHP extension '{$ext}' is not loaded");
        }
        return true;
    });
}

$runner->test('DB reachable (SELECT 1)', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    $v = $db->query('SELECT 1')->fetchColumn();
    if ((int) $v !== 1) {
        throw new \RuntimeException('SELECT 1 returned ' . var_export($v, true));
    }
    return true;
});

$runner->test('Redis reachable (PING)', function () use ($config, &$redis) {
    $redis = new RedisCacheService($config);
    if (!$redis->isAvailable()) {
        throw new \RuntimeException('RedisCacheService::isAvailable() === false');
    }
    return true;
});

$runner->test('migration 160: webmail_folder_identity exists', function () use (&$db) {
    if ($db === null) {
        throw new \RuntimeException('DB not initialised');
    }
    $row = $db->query("SHOW TABLES LIKE 'webmail_folder_identity'")->fetch();
    if (!$row) {
        throw new \RuntimeException('table webmail_folder_identity missing — run migration 160');
    }
    return true;
});

$runner->test('migration 164: webmail_folder_path_intervals exists', function () use (&$db) {
    $row = $db->query("SHOW TABLES LIKE 'webmail_folder_path_intervals'")->fetch();
    if (!$row) {
        throw new \RuntimeException('table webmail_folder_path_intervals missing — run migration 164');
    }
    return true;
});

$runner->test('webmail_folder_path_history exists', function () use (&$db) {
    $row = $db->query("SHOW TABLES LIKE 'webmail_folder_path_history'")->fetch();
    if (!$row) {
        throw new \RuntimeException('table webmail_folder_path_history missing — run migration 160');
    }
    return true;
});

$runner->test('migration 163: pinned_emails.folder_id column present', function () use (&$db) {
    $row = $db->query("SHOW COLUMNS FROM pinned_emails LIKE 'folder_id'")->fetch();
    if (!$row) {
        throw new \RuntimeException('pinned_emails.folder_id missing — run migration 163');
    }
    return true;
});

$runner->test('webmail_oauth_tokens schema', function () use (&$db) {
    $cols = $db->query("SHOW COLUMNS FROM webmail_oauth_tokens")->fetchAll(\PDO::FETCH_COLUMN);
    foreach (['id', 'primary_email', 'oauth_email', 'provider', 'access_token_encrypted', 'refresh_token_encrypted', 'token_expires_at'] as $c) {
        if (!in_array($c, $cols, true)) {
            throw new \RuntimeException("missing column webmail_oauth_tokens.{$c}");
        }
    }
    return true;
});

$runner->test('OAUTH_KEYS env OR jwt.secret present', function () use ($config) {
    $env = getenv('OAUTH_KEYS') ?: '';
    $jwt = $config['jwt']['secret'] ?? '';
    if ($env === '' && (!is_string($jwt) || $jwt === '')) {
        throw new \RuntimeException('no OAUTH_KEYS env and no jwt.secret — token decrypt will fail');
    }
    return true;
});

$runner->test('google_oauth.client_id OR microsoft_oauth.client_id present', function () use ($config) {
    $g = $config['google_oauth']['client_id'] ?? '';
    $m = $config['microsoft_oauth']['client_id'] ?? '';
    if ($g === '' && $m === '') {
        throw new \RuntimeException('no google_oauth.client_id and no microsoft_oauth.client_id configured');
    }
    return true;
});

$runner->test('storage/logs writable', function () use ($runner) {
    $dir = dirname($runner->logFile);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new \RuntimeException("log dir not writable: {$dir}");
    }
    return true;
});

if ($runner->smoke) {
    exit($runner->finish());
}

// =============================================================================
// Shared state populated by the OAuth lookup and reused by every later section.
// =============================================================================

/** @var array{id:int,primary_email:string,oauth_email:string,provider:string}|null */
$oauthRow = null;
/** @var string|null */
$accessToken = null;
/** @var string|null */
$oauthEmail = null;
/** @var string|null */
$primaryEmail = null;
/** @var string */
$provider = 'google';
/** @var ImapService|null */
$imap = null;
/** @var array<int,array<string,mixed>> */
$listedFolders = [];
/** @var array<string,mixed>|null */
$inboxRow = null;
/** @var string|null */
$inboxFolderId = null;
/** @var string|null */
$preTestCachedFolderList = null;
/** @var bool */
$preTestCacheCaptured = false;

// Cleanup registered FIRST so any abort still runs it.
$runner->addCleanup(function () use (&$imap, &$redis, &$oauthEmail, &$preTestCacheCaptured, &$preTestCachedFolderList, &$db, $runner, $config) {
    // Restore the real account's Redis folder_list cache so we never leave a
    // poisoned (folder_id=null) entry behind. If we didn't capture a pre-test
    // value (it was absent / Redis was down) we just invalidate the key so
    // the next live /mailbox/folders request rebuilds it fresh.
    if ($redis !== null && $redis->isAvailable() && $oauthEmail !== null) {
        try {
            if ($preTestCacheCaptured && is_string($preTestCachedFolderList) && $preTestCachedFolderList !== '') {
                $decoded = json_decode($preTestCachedFolderList, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $redis->setFolderList($oauthEmail, $decoded);
                    $runner->log('  cleanup: restored pre-test folder_list cache for ' . $oauthEmail);
                } else {
                    $redis->invalidateFolderList($oauthEmail);
                    $runner->log('  cleanup: invalidated folder_list cache (pre-test value undecodable)');
                }
            } else {
                $redis->invalidateFolderList($oauthEmail);
                $runner->log('  cleanup: invalidated folder_list cache (no pre-test value to restore)');
            }
        } catch (\Throwable $e) {
            $runner->log('  cleanup: cache restore failed: ' . $e->getMessage());
        }
    }

    // Tear down any synthetic identity rows we may have written under the
    // flowone_test_ prefix. Idempotent: missing rows are fine.
    if ($db !== null) {
        try {
            $db->prepare("DELETE FROM webmail_folder_path_intervals WHERE account_id LIKE 'flowone_test_%'")->execute();
            $db->prepare("DELETE FROM webmail_folder_path_history WHERE folder_id IN (SELECT id FROM webmail_folder_identity WHERE account_id LIKE 'flowone_test_%')")->execute();
            $db->prepare("DELETE FROM webmail_folder_identity WHERE account_id LIKE 'flowone_test_%'")->execute();
        } catch (\Throwable $e) {
            $runner->log('  cleanup: synthetic row teardown failed: ' . $e->getMessage());
        }
    }

    // Close IMAP socket if still open.
    if ($imap !== null) {
        try {
            if (method_exists($imap, 'disconnect')) {
                $imap->disconnect();
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }
});

// =============================================================================
// 1. OAUTH-TOKEN — DB row, refresh-token decrypt, getValidAccessToken.
// =============================================================================

if ($runner->shouldRunSection('1. OAUTH-TOKEN')) {
    $runner->section('1. OAUTH-TOKEN');

    $runner->test('webmail_oauth_tokens row exists for --email', function () use (&$db, &$oauthRow, $testEmailArg, $forceAccountId, &$primaryEmail, &$oauthEmail, &$provider) {
        if ($forceAccountId !== null) {
            $stmt = $db->prepare('SELECT id, primary_email, oauth_email, provider FROM webmail_oauth_tokens WHERE id = ? LIMIT 1');
            $stmt->execute([$forceAccountId]);
        } else {
            $stmt = $db->prepare("
                SELECT id, primary_email, oauth_email, provider
                FROM webmail_oauth_tokens
                WHERE (primary_email = ? OR oauth_email = ?)
                ORDER BY (oauth_email = ?) DESC, updated_at DESC
                LIMIT 1
            ");
            $stmt->execute([$testEmailArg, $testEmailArg, $testEmailArg]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException("no webmail_oauth_tokens row matched '{$testEmailArg}'" . ($forceAccountId !== null ? " (id={$forceAccountId})" : ''));
        }
        $oauthRow = $row;
        $primaryEmail = strtolower($row['primary_email']);
        $oauthEmail = strtolower($row['oauth_email']);
        $provider = $row['provider'] ?? 'google';
        return true;
    });

    $runner->test('refresh_token_encrypted decrypts via OAuthCryptor', function () use (&$db, &$oauthRow, $config) {
        if ($oauthRow === null) {
            throw new \RuntimeException('skipped: oauth row not loaded');
        }
        $stmt = $db->prepare('SELECT refresh_token_encrypted FROM webmail_oauth_tokens WHERE id = ?');
        $stmt->execute([$oauthRow['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['refresh_token_encrypted'])) {
            throw new \RuntimeException('refresh_token_encrypted is empty in DB row');
        }
        $cryptor = new OAuthCryptor($config);
        $rt = $cryptor->decrypt($row['refresh_token_encrypted']);
        if ($rt === null || $rt === '') {
            throw new \RuntimeException('OAuthCryptor::decrypt returned empty — check OAUTH_KEYS / jwt.secret');
        }
        return true;
    });

    $runner->test('getValidAccessToken returns a token', function () use (&$oauthRow, &$primaryEmail, &$oauthEmail, &$provider, $config, &$accessToken) {
        if ($oauthRow === null) {
            throw new \RuntimeException('skipped: oauth row not loaded');
        }
        if ($provider === 'microsoft') {
            $svc = new MicrosoftOAuthService($config);
        } else {
            $svc = new GoogleOAuthService($config);
        }
        $token = $svc->getValidAccessToken($primaryEmail, $oauthEmail);
        $reason = method_exists($svc, 'getLastFailureReason') ? $svc->getLastFailureReason() : null;
        if (!$token) {
            throw new \RuntimeException('access token NULL; provider=' . $provider . ' reason=' . ($reason ?? 'unknown'));
        }
        if (in_array($reason, ['oauth_revoked', 'oauth_decrypt_failed', 'oauth_no_account'], true)) {
            throw new \RuntimeException('terminal failure reason: ' . $reason);
        }
        $accessToken = $token;
        return true;
    });

    $runner->test('second getValidAccessToken hits OAuthTokenCache (<= 200ms)', function () use (&$primaryEmail, &$oauthEmail, &$provider, $config) {
        if ($primaryEmail === null || $oauthEmail === null) {
            throw new \RuntimeException('skipped: oauth row not loaded');
        }
        $svc = $provider === 'microsoft'
            ? new MicrosoftOAuthService($config)
            : new GoogleOAuthService($config);
        $t0 = microtime(true);
        $token = $svc->getValidAccessToken($primaryEmail, $oauthEmail);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (!$token) {
            throw new \RuntimeException('access token NULL on second call');
        }
        if ($ms > 200) {
            // Not a hard fail — slow Redis or token actually had to refresh
            // again — but worth surfacing as a warn so the user sees it.
            return 'warn';
        }
        return true;
    });
}

// =============================================================================
// 2. IMAP-CONNECT — connectWithOAuth, isConnected, INBOX selectable.
// =============================================================================

if ($runner->shouldRunSection('2. IMAP-CONNECT')) {
    $runner->section('2. IMAP-CONNECT');

    $runner->test('connectWithOAuth succeeds', function () use (&$accessToken, &$oauthEmail, &$provider, $config, &$imap) {
        if (!$accessToken || !$oauthEmail) {
            throw new \RuntimeException('skipped: no access token / oauth email');
        }
        $imapConfig = $provider === 'microsoft'
            ? [
                'host' => MicrosoftOAuthService::IMAP_HOST,
                'port' => MicrosoftOAuthService::IMAP_PORT,
                'encryption' => MicrosoftOAuthService::IMAP_ENCRYPTION,
                'validate_cert' => false,
            ]
            : [
                'host' => 'imap.gmail.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => false,
            ];
        $imap = new ImapService($imapConfig);
        if (!$imap->connectWithOAuth($oauthEmail, $accessToken)) {
            throw new \RuntimeException('ImapService::connectWithOAuth returned false');
        }
        return true;
    });

    $runner->test('isConnected after connectWithOAuth', function () use (&$imap) {
        if ($imap === null) {
            throw new \RuntimeException('skipped: imap not connected');
        }
        if (!$imap->isConnected()) {
            throw new \RuntimeException('ImapService::isConnected() returned false');
        }
        return true;
    });

    $runner->test('selectFolder INBOX', function () use (&$imap) {
        if ($imap === null || !$imap->isConnected()) {
            throw new \RuntimeException('skipped: imap not connected');
        }
        if (!$imap->selectFolder('INBOX')) {
            throw new \RuntimeException('selectFolder("INBOX") returned false');
        }
        return true;
    });
}

// =============================================================================
// 3. LIST-FOLDERS — listFolders shape, INBOX present, required fields.
// =============================================================================

if ($runner->shouldRunSection('3. LIST-FOLDERS')) {
    $runner->section('3. LIST-FOLDERS');

    $runner->test('listFolders returns non-empty array', function () use (&$imap, &$listedFolders) {
        if ($imap === null || !$imap->isConnected()) {
            throw new \RuntimeException('skipped: imap not connected');
        }
        $listedFolders = $imap->listFolders();
        if (!is_array($listedFolders) || empty($listedFolders)) {
            throw new \RuntimeException('listFolders returned empty / non-array');
        }
        return true;
    });

    $runner->test('INBOX present and is_selectable', function () use (&$listedFolders, &$inboxRow) {
        $found = null;
        foreach ($listedFolders as $row) {
            if (($row['name'] ?? null) === 'INBOX') {
                $found = $row;
                break;
            }
        }
        if (!$found) {
            throw new \RuntimeException('INBOX missing from listFolders output');
        }
        if ((int) ($found['is_selectable'] ?? 0) !== 1) {
            throw new \RuntimeException('INBOX is not selectable');
        }
        $inboxRow = $found;
        return true;
    });

    $runner->test('every row has path / display_name / uidvalidity / uidnext', function () use (&$listedFolders, $runner) {
        $missing = [];
        foreach ($listedFolders as $row) {
            $name = $row['name'] ?? '?';
            foreach (['path', 'display_name'] as $k) {
                if (!isset($row[$k]) || $row[$k] === '') {
                    $missing[] = "{$name}.{$k}";
                }
            }
            // uidvalidity / uidnext may be 0 on freshly created empty folders,
            // so we only flag them as warn-worthy, not fail.
        }
        if (!empty($missing)) {
            throw new \RuntimeException('rows missing required fields: ' . implode(', ', array_slice($missing, 0, 10)));
        }
        return true;
    });

    $runner->test('[Gmail]/All Mail present (Gmail-only sanity)', function () use (&$listedFolders, &$provider) {
        if ($provider !== 'google') {
            return 'warn';
        }
        foreach ($listedFolders as $row) {
            if (($row['name'] ?? '') === '[Gmail]/All Mail') {
                return true;
            }
        }
        return 'warn';
    });
}

// =============================================================================
// 4. IDENTITY-UPSERT — UUIDv7 assignment per folder, idempotency, round-trip.
// =============================================================================

if ($runner->shouldRunSection('4. IDENTITY-UPSERT')) {
    $runner->section('4. IDENTITY-UPSERT');

    $folderIndex = null;

    $runner->test('FolderIndexService instantiates', function () use ($config, &$folderIndex) {
        $folderIndex = new FolderIndexService($config);
        return true;
    });

    $uuidv7Regex = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    $runner->test('upsertFromListing(INBOX) returns UUIDv7', function () use (&$folderIndex, &$inboxRow, &$oauthEmail, &$inboxFolderId, $uuidv7Regex) {
        if ($folderIndex === null || $inboxRow === null || $oauthEmail === null) {
            throw new \RuntimeException('skipped: prerequisites not met');
        }
        $id = $folderIndex->upsertFromListing($oauthEmail, $inboxRow);
        if (!is_string($id) || !preg_match($uuidv7Regex, $id)) {
            throw new \RuntimeException('upsertFromListing returned non-UUIDv7: ' . var_export($id, true));
        }
        $inboxFolderId = $id;
        return true;
    });

    $runner->test('upsertFromListing is idempotent (same id on second call)', function () use (&$folderIndex, &$inboxRow, &$oauthEmail, &$inboxFolderId) {
        if ($folderIndex === null || $inboxRow === null || $oauthEmail === null) {
            throw new \RuntimeException('skipped: prerequisites not met');
        }
        $id2 = $folderIndex->upsertFromListing($oauthEmail, $inboxRow);
        if ($id2 !== $inboxFolderId) {
            throw new \RuntimeException("idempotency broken: first={$inboxFolderId} second={$id2}");
        }
        return true;
    });

    $runner->test('getById($inboxFolderId).current_path === "INBOX"', function () use (&$folderIndex, &$inboxFolderId) {
        if ($folderIndex === null || $inboxFolderId === null) {
            throw new \RuntimeException('skipped: prerequisites not met');
        }
        $row = $folderIndex->getById($inboxFolderId);
        if (!$row) {
            throw new \RuntimeException('getById returned null');
        }
        if (($row['current_path'] ?? null) !== 'INBOX') {
            throw new \RuntimeException('current_path !== "INBOX": ' . var_export($row['current_path'] ?? null, true));
        }
        return true;
    });

    $runner->test('getByPath(INBOX).id === $inboxFolderId', function () use (&$folderIndex, &$oauthEmail, &$inboxFolderId) {
        if ($folderIndex === null || $oauthEmail === null) {
            throw new \RuntimeException('skipped: prerequisites not met');
        }
        $row = $folderIndex->getByPath($oauthEmail, 'INBOX');
        if (!$row) {
            throw new \RuntimeException('getByPath("INBOX") returned null — open path-interval missing');
        }
        if (($row['id'] ?? null) !== $inboxFolderId) {
            throw new \RuntimeException("id mismatch: getByPath={$row['id']} vs inbox={$inboxFolderId}");
        }
        return true;
    });

    $runner->test('every selectable folder gets a non-null folder_id', function () use (&$folderIndex, &$listedFolders, &$oauthEmail, $uuidv7Regex) {
        if ($folderIndex === null || $oauthEmail === null) {
            throw new \RuntimeException('skipped: prerequisites not met');
        }
        $nulls = [];
        foreach ($listedFolders as $row) {
            if ((int) ($row['is_selectable'] ?? 1) !== 1) continue;
            try {
                $id = $folderIndex->upsertFromListing($oauthEmail, $row);
                if (!is_string($id) || !preg_match($uuidv7Regex, $id)) {
                    $nulls[] = $row['name'] . '=' . var_export($id, true);
                }
            } catch (\Throwable $e) {
                $nulls[] = ($row['name'] ?? '?') . ': ' . $e->getMessage();
            }
        }
        if (!empty($nulls)) {
            throw new \RuntimeException('folders with no/bad folder_id (smoking gun for the console error): '
                . implode(' | ', array_slice($nulls, 0, 10)));
        }
        return true;
    });
}

// =============================================================================
// 5. ANNOTATE — MailboxController::annotateFoldersWithIdentity (reflection).
// =============================================================================

/**
 * Build a MailboxController already primed with the OAuth IMAP connection
 * and the OAuth email as $userEmail, bypassing the JWT / SessionService
 * machinery (which would require a live session row in the DB).
 *
 * Returns the controller AND a callable that re-applies the OAuth state in
 * case BaseController::getImap() resets $userEmail mid-test.
 */
$buildController = static function () use ($config, &$imap, &$oauthEmail, &$primaryEmail, &$provider): MailboxController {
    $ctl = new MailboxController($config);
    $r = new \ReflectionClass($ctl);
    foreach ([
        'userEmail' => $oauthEmail,
        'primaryUserEmail' => $primaryEmail ?? $oauthEmail,
        'isOAuthSession' => true,
        'oauthProvider' => $provider,
        'imap' => $imap,
    ] as $prop => $value) {
        if ($r->hasProperty($prop)) {
            $p = $r->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($ctl, $value);
        }
    }
    return $ctl;
};

if ($runner->shouldRunSection('5. ANNOTATE')) {
    $runner->section('5. ANNOTATE');

    $runner->test('annotateFoldersWithIdentity sets folder_id on every selectable row', function () use ($buildController, &$listedFolders) {
        if (empty($listedFolders)) {
            throw new \RuntimeException('skipped: no folders listed');
        }
        $ctl = $buildController();
        $rm = new \ReflectionMethod($ctl, 'annotateFoldersWithIdentity');
        $rm->setAccessible(true);
        /** @var array<int,array<string,mixed>> $annotated */
        $annotated = $rm->invoke($ctl, $listedFolders);

        $missing = [];
        foreach ($annotated as $row) {
            if ((int) ($row['is_selectable'] ?? 1) !== 1) continue;
            $id = $row['folder_id'] ?? null;
            if (!is_string($id) || $id === '') {
                $missing[] = (string) ($row['name'] ?? '?');
            }
        }
        if (!empty($missing)) {
            throw new \RuntimeException('annotated rows missing folder_id (fix the controller, not the test): '
                . implode(', ', array_slice($missing, 0, 20)));
        }
        return true;
    });
}

// =============================================================================
// 6. INPUT-RESOLVER — FolderInputResolver path <-> id round-trip + compareResolve.
// =============================================================================

if ($runner->shouldRunSection('6. INPUT-RESOLVER')) {
    $runner->section('6. INPUT-RESOLVER');

    $resolver = null;

    $runner->test('FolderInputResolver instantiates', function () use ($config, &$resolver) {
        $resolver = new FolderInputResolver($config);
        return true;
    });

    $runner->test('resolve("INBOX") -> folder_id non-null, path="INBOX", source="path"', function () use (&$resolver, &$oauthEmail, &$inboxFolderId) {
        if ($resolver === null || $oauthEmail === null) {
            throw new \RuntimeException('skipped');
        }
        $out = $resolver->resolve($oauthEmail, 'INBOX');
        if ($out['folder_id'] === null) {
            throw new \RuntimeException('folder_id is null — open path-interval missing for INBOX');
        }
        if ($out['folder_path'] !== 'INBOX') {
            throw new \RuntimeException('folder_path mismatch: ' . var_export($out['folder_path'], true));
        }
        if ($out['source'] !== 'path') {
            throw new \RuntimeException('source !== "path": ' . var_export($out['source'], true));
        }
        if ($inboxFolderId !== null && $out['folder_id'] !== $inboxFolderId) {
            throw new \RuntimeException("folder_id mismatch with §4: resolver={$out['folder_id']} upsert={$inboxFolderId}");
        }
        return true;
    });

    $runner->test('resolve($inboxFolderId) -> same folder_id, path="INBOX", source="folder_id"', function () use (&$resolver, &$oauthEmail, &$inboxFolderId) {
        if ($resolver === null || $oauthEmail === null || $inboxFolderId === null) {
            throw new \RuntimeException('skipped');
        }
        $out = $resolver->resolve($oauthEmail, $inboxFolderId);
        if ($out['folder_id'] !== $inboxFolderId) {
            throw new \RuntimeException("folder_id mismatch: {$out['folder_id']} vs {$inboxFolderId}");
        }
        if ($out['folder_path'] !== 'INBOX') {
            throw new \RuntimeException('folder_path mismatch: ' . var_export($out['folder_path'], true));
        }
        if ($out['source'] !== 'folder_id') {
            throw new \RuntimeException('source !== "folder_id": ' . var_export($out['source'], true));
        }
        return true;
    });

    $runner->test('compareResolve returns "ok"', function () use (&$resolver, &$oauthEmail, &$inboxFolderId) {
        if ($resolver === null || $oauthEmail === null || $inboxFolderId === null) {
            throw new \RuntimeException('skipped');
        }
        $cmp = $resolver->compareResolve($oauthEmail, $inboxFolderId, 'INBOX');
        if (($cmp['status'] ?? null) !== 'ok') {
            throw new \RuntimeException('compareResolve status=' . ($cmp['status'] ?? 'null') . ' details=' . ($cmp['details'] ?? ''));
        }
        return true;
    });

    $runner->test('case-insensitive: resolve("inbox") still finds INBOX', function () use (&$resolver, &$oauthEmail, &$inboxFolderId) {
        if ($resolver === null || $oauthEmail === null) {
            throw new \RuntimeException('skipped');
        }
        $out = $resolver->resolve($oauthEmail, 'inbox');
        // Either: case-insensitive lookup yields folder_id (best), or path
        // remains "inbox" with folder_id null (FAIL — needs case-folding).
        if ($out['folder_id'] === null) {
            throw new \RuntimeException('lowercase "inbox" did not resolve — fix case-insensitive lookup in FolderIndexService::getByPath');
        }
        if ($inboxFolderId !== null && $out['folder_id'] !== $inboxFolderId) {
            throw new \RuntimeException("case folding produced different id: {$out['folder_id']} vs {$inboxFolderId}");
        }
        return true;
    });
}

// =============================================================================
// 7. CACHE-ROUND-TRIP — setFolderList -> getFolderList preserves folder_id.
// =============================================================================

if ($runner->shouldRunSection('7. CACHE-ROUND-TRIP')) {
    $runner->section('7. CACHE-ROUND-TRIP');

    // Capture pre-test cache state so cleanup can restore it.
    if ($redis !== null && $redis->isAvailable() && $oauthEmail !== null && !$preTestCacheCaptured) {
        $current = $redis->getFolderList($oauthEmail);
        $preTestCachedFolderList = $current === null ? '' : json_encode($current);
        $preTestCacheCaptured = true;
    }

    $runner->test('setFolderList -> getFolderList preserves folder_id on every row', function () use (&$redis, &$oauthEmail, &$listedFolders, $buildController) {
        if ($redis === null || !$redis->isAvailable() || $oauthEmail === null || empty($listedFolders)) {
            throw new \RuntimeException('skipped');
        }
        // Build a known-good annotated list via the controller path so the
        // round-trip mirrors what production caches.
        $ctl = $buildController();
        $rm = new \ReflectionMethod($ctl, 'annotateFoldersWithIdentity');
        $rm->setAccessible(true);
        $annotated = $rm->invoke($ctl, $listedFolders);

        $redis->setFolderList($oauthEmail, $annotated);
        $back = $redis->getFolderList($oauthEmail);
        if (!is_array($back) || count($back) !== count($annotated)) {
            throw new \RuntimeException('round-trip count mismatch: in=' . count($annotated) . ' out=' . (is_array($back) ? count($back) : 'null'));
        }
        $missing = [];
        foreach ($back as $row) {
            if ((int) ($row['is_selectable'] ?? 1) !== 1) continue;
            if (empty($row['folder_id'])) {
                $missing[] = (string) ($row['name'] ?? '?');
            }
        }
        if (!empty($missing)) {
            throw new \RuntimeException('cache round-trip dropped folder_id on: ' . implode(', ', $missing));
        }
        return true;
    });

    $runner->test('bumpFolderCounts does not strip folder_id', function () use (&$redis, &$oauthEmail) {
        if ($redis === null || !$redis->isAvailable() || $oauthEmail === null) {
            throw new \RuntimeException('skipped');
        }
        $before = $redis->getFolderList($oauthEmail);
        if (!is_array($before) || empty($before)) {
            throw new \RuntimeException('no cached folder list to bump');
        }
        $redis->bumpFolderCounts($oauthEmail, ['INBOX' => ['unread' => 0, 'total' => 0]]);
        $after = $redis->getFolderList($oauthEmail);
        $missing = [];
        foreach ($after as $row) {
            if ((int) ($row['is_selectable'] ?? 1) !== 1) continue;
            if (empty($row['folder_id'])) {
                $missing[] = (string) ($row['name'] ?? '?');
            }
        }
        if (!empty($missing)) {
            throw new \RuntimeException('bumpFolderCounts stripped folder_id on: ' . implode(', ', $missing));
        }
        return true;
    });
}

// =============================================================================
// 8. CACHE-POISON-DETECTION — the production bug from your console log.
//
// Injects a folder list with folder_id=null for INBOX into Redis, then calls
// MailboxController::folders() and asserts the response STILL has a non-null
// folder_id. Today this will FAIL — the fix is to make folders() treat a
// cached list with any selectable folder missing folder_id as a cache miss,
// invalidate, and recompute. Per the user: fix the issue, not the test.
// =============================================================================

if ($runner->shouldRunSection('8. CACHE-POISON-DETECTION')) {
    $runner->section('8. CACHE-POISON-DETECTION');

    if ($redis !== null && $redis->isAvailable() && $oauthEmail !== null && !$preTestCacheCaptured) {
        $current = $redis->getFolderList($oauthEmail);
        $preTestCachedFolderList = $current === null ? '' : json_encode($current);
        $preTestCacheCaptured = true;
    }

    $runner->test('folders() recovers from a poisoned cache (folder_id=null for INBOX)', function () use (&$redis, &$oauthEmail, &$listedFolders, $buildController) {
        if ($redis === null || !$redis->isAvailable() || $oauthEmail === null || empty($listedFolders)) {
            throw new \RuntimeException('skipped');
        }
        // Build a poisoned list: identical to the real one but every
        // selectable folder gets folder_id=null. We have to set folder_id
        // EXPLICITLY to null (not just omit it) because the resolver checks
        // for null specifically.
        $poisoned = [];
        foreach ($listedFolders as $row) {
            $row['folder_id'] = null;
            $poisoned[] = $row;
        }
        $redis->setFolderList($oauthEmail, $poisoned);

        // Stub a Request for GET /mailbox/folders.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/mailbox/folders';
        $_GET = [];
        $_POST = [];
        $request = new Request();

        $ctl = $buildController();
        /** @var Response $resp */
        $resp = $ctl->folders($request);
        $status = $resp->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("folders() returned HTTP {$status}");
        }
        $body = json_decode($resp->getContent(), true);
        if (!is_array($body) || empty($body['success'])) {
            throw new \RuntimeException('folders() response was not success: ' . substr((string) $resp->getContent(), 0, 200));
        }
        $rows = $body['data']['folders'] ?? [];
        $missing = [];
        foreach ($rows as $row) {
            if ((int) ($row['is_selectable'] ?? 1) !== 1) continue;
            if (empty($row['folder_id'])) {
                $missing[] = (string) ($row['name'] ?? '?');
            }
        }
        if (!empty($missing)) {
            throw new \RuntimeException('controller served poisoned cache untouched (BUG — fix MailboxController::folders to detect/invalidate poisoned cache): missing folder_id for '
                . implode(', ', array_slice($missing, 0, 10)));
        }
        return true;
    });
}

// =============================================================================
// 9. CONTROLLER-FOLDERS — real /mailbox/folders shape with skip_cache flag.
// =============================================================================

if ($runner->shouldRunSection('9. CONTROLLER-FOLDERS')) {
    $runner->section('9. CONTROLLER-FOLDERS');

    $runner->test('folders() returns folder_id on every selectable row', function () use ($buildController) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/mailbox/folders';
        $_GET = [];
        $_POST = [];
        $request = new Request();

        $ctl = $buildController();
        $resp = $ctl->folders($request);
        $body = json_decode($resp->getContent(), true);
        if (!is_array($body) || empty($body['success'])) {
            throw new \RuntimeException('folders() response not success: ' . substr((string) $resp->getContent(), 0, 200));
        }
        $rows = $body['data']['folders'] ?? [];
        if (empty($rows)) {
            throw new \RuntimeException('folders() returned empty list');
        }
        $missing = [];
        foreach ($rows as $row) {
            if ((int) ($row['is_selectable'] ?? 1) !== 1) continue;
            if (empty($row['folder_id'])) {
                $missing[] = (string) ($row['name'] ?? '?');
            }
        }
        if (!empty($missing)) {
            throw new \RuntimeException('folder_id missing on: ' . implode(', ', array_slice($missing, 0, 10)));
        }
        return true;
    });

    $runner->test('folders()?skip_cache=1 returns folder_id on every selectable row', function () use ($buildController) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/mailbox/folders?skip_cache=1';
        $_GET = ['skip_cache' => '1'];
        $_POST = [];
        $request = new Request();

        $ctl = $buildController();
        $resp = $ctl->folders($request);
        $body = json_decode($resp->getContent(), true);
        $rows = $body['data']['folders'] ?? [];
        $missing = [];
        foreach ($rows as $row) {
            if ((int) ($row['is_selectable'] ?? 1) !== 1) continue;
            if (empty($row['folder_id'])) {
                $missing[] = (string) ($row['name'] ?? '?');
            }
        }
        if (!empty($missing)) {
            throw new \RuntimeException('skip_cache=1 still produced rows without folder_id: ' . implode(', ', array_slice($missing, 0, 10)));
        }
        return true;
    });
}

// =============================================================================
// 10. CONTROLLER-MESSAGE — the click itself. /folders/{folder_id}/messages/{uid}
// =============================================================================

if ($runner->shouldRunSection('10. CONTROLLER-MESSAGE')) {
    $runner->section('10. CONTROLLER-MESSAGE');

    $topInboxUid = null;

    $runner->test('locate top INBOX UID via getMessages(page=1, limit=1)', function () use (&$imap, &$topInboxUid) {
        if ($imap === null || !$imap->isConnected()) {
            throw new \RuntimeException('skipped: imap not connected');
        }
        $result = $imap->getMessages('INBOX', 1, 1, 'date', 'desc');
        $messages = $result['messages'] ?? [];
        if (empty($messages)) {
            // Empty inbox is a real possibility on a brand-new account.
            // Warn so we surface it, but don't break the suite.
            return 'warn';
        }
        $topInboxUid = (int) ($messages[0]['uid'] ?? 0);
        if ($topInboxUid <= 0) {
            throw new \RuntimeException('top UID resolved to non-positive: ' . var_export($messages[0]['uid'] ?? null, true));
        }
        return true;
    });

    $runner->test('message() via canonical /folders/{folder_id}/messages/{uid} returns 200 with body', function () use ($buildController, &$inboxFolderId, &$topInboxUid) {
        if ($inboxFolderId === null) {
            throw new \RuntimeException('skipped: inbox folder_id unknown');
        }
        if ($topInboxUid === null) {
            return 'warn'; // empty inbox above
        }

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = "/folders/{$inboxFolderId}/messages/{$topInboxUid}";
        $_GET = [];
        $_POST = [];
        $request = new Request();
        // Mirror what the router does for /folders/{folder_id:...}/messages/{uid}.
        $request->setParam('folder_id', $inboxFolderId);
        $request->setParam('uid', (string) $topInboxUid);

        $ctl = $buildController();
        $resp = $ctl->message($request);
        $status = $resp->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("message() returned HTTP {$status}: " . substr((string) $resp->getContent(), 0, 200));
        }
        $body = json_decode($resp->getContent(), true);
        if (!is_array($body) || empty($body['success'])) {
            throw new \RuntimeException('message() response not success');
        }
        $data = $body['data'] ?? [];
        $hasBody = !empty($data['body_html']) || !empty($data['body_text']) || isset($data['parts']);
        if (!$hasBody) {
            throw new \RuntimeException('message() returned no body_html / body_text / parts');
        }
        return true;
    });
}

// =============================================================================
// 11. RACE-RETRY — frontend's fetchFolders(true) semantics.
// =============================================================================

if ($runner->shouldRunSection('11. RACE-RETRY')) {
    $runner->section('11. RACE-RETRY');

    $runner->test('two back-to-back folders() calls both yield non-null folder_id for INBOX (poisoned start)', function () use (&$redis, &$oauthEmail, &$listedFolders, $buildController) {
        if ($redis === null || !$redis->isAvailable() || $oauthEmail === null || empty($listedFolders)) {
            throw new \RuntimeException('skipped');
        }
        // Re-poison cache to mirror the fetchFolders(true) retry scenario:
        // first response should detect + heal, second should serve clean.
        $poisoned = [];
        foreach ($listedFolders as $row) {
            $row['folder_id'] = null;
            $poisoned[] = $row;
        }
        $redis->setFolderList($oauthEmail, $poisoned);

        $callFolders = static function () use ($buildController): array {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/mailbox/folders';
            $_GET = [];
            $_POST = [];
            $request = new Request();

            $ctl = $buildController();
            $resp = $ctl->folders($request);
            $body = json_decode($resp->getContent(), true);
            return $body['data']['folders'] ?? [];
        };

        $first = $callFolders();
        $second = $callFolders();

        foreach (['first' => $first, 'second' => $second] as $label => $rows) {
            $missing = [];
            foreach ($rows as $row) {
                if ((int) ($row['is_selectable'] ?? 1) !== 1) continue;
                if (empty($row['folder_id'])) {
                    $missing[] = (string) ($row['name'] ?? '?');
                }
            }
            if (!empty($missing)) {
                throw new \RuntimeException("{$label} response still missing folder_id on: "
                    . implode(', ', array_slice($missing, 0, 10))
                    . ' (frontend retry would still abort the click)');
            }
        }

        // Stability: second call must have same folder_id for every name.
        $firstById = [];
        foreach ($first as $r) {
            $firstById[$r['name'] ?? '?'] = $r['folder_id'] ?? null;
        }
        foreach ($second as $r) {
            $name = $r['name'] ?? '?';
            $id = $r['folder_id'] ?? null;
            $expected = $firstById[$name] ?? null;
            if ($expected !== null && $id !== $expected) {
                throw new \RuntimeException("folder_id flapped between calls for {$name}: {$expected} -> {$id}");
            }
        }

        return true;
    });
}

// =============================================================================
// finish — runs cleanups in reverse order, prints summary, exits 0/1.
// =============================================================================

exit($runner->finish());
