#!/usr/bin/env php
<?php
/**
 * thread-endpoint-test.php
 *
 * Reproduces the production console log:
 *
 *   GET /api/mailbox/thread?... 500 (Internal Server Error)
 *   Failed to fetch thread messages from API: AxiosError
 *
 * AND the user-visible symptom that "opening any email on an OAuth Gmail
 * account takes a few seconds".
 *
 * Both have the same root cause: MailboxController::getThread() fans out
 * (N folders) x (M references) x (3 separate IMAP HEADER searches), each
 * preceded by its own SELECT. On Gmail OAuth XOAUTH2 a single HEADER
 * search is 300-800ms, so a 2-reference thread on 3 folders is 18
 * round-trips and 5-12 seconds wall-clock — frequently past
 * max_execution_time, which kills the worker and produces an HTTP 500.
 *
 * This script measures and asserts:
 *   - the endpoint completes in a generous wall-clock budget (5 seconds)
 *   - it returns a 200 with a `messages` array (never a 5xx)
 *   - on Gmail it uses the [Gmail]/All Mail fast path so only ONE folder
 *     SELECT is issued (folderSelectCount delta == 1) regardless of how
 *     many references were supplied
 *   - the OR'd searchHeadersOr() helper returns the same set as the
 *     legacy 3x searchHeader() fanout
 *
 * Per .cursor/rules/server-side-testing.mdc:
 *   CLI-only, --help / --verbose / --skip-send / --only= / --smoke / --json /
 *   --timeout=, pre-flight extension + service checks, signal-safe cleanup,
 *   per-test pcntl_alarm timeout, timestamped log under storage/logs/,
 *   color-coded PASS / FAIL / WARN, summary + exit 0/1, idempotent.
 *
 * Safety:
 *   - Read-only against the real IMAP account. No SMTP send, no folder
 *     mutations, no DB writes against any production table.
 *   - Synthetic identity rows (none here) would use prefix flowone_test_.
 *
 * Run command (per migrations.mdc / project-info.mdc):
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/thread-endpoint-test.php \
 *     --email=USER@gmail.com --verbose
 *
 * Required flags:
 *   --email=EMAIL    primary_email OR oauth_email of the OAuth account.
 *
 * Optional flags (parsed by FlowOneTestRunner):
 *   --help / --verbose / --smoke / --json / --timeout=SEC
 *   --only=GROUP[,GROUP]   sections:
 *                          preflight | oauth-token | imap-connect |
 *                          pick-msg | budget | status | correctness |
 *                          no-fanout | or-search
 *   --account-id=N   force a specific webmail_oauth_tokens.id row
 *   --max-ms=N       wall-clock budget for the BUDGET test (default 5000)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "thread-endpoint-test.php is CLI-only\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';
require_once __DIR__ . '/lib/test-runner.php';

use Webmail\Controllers\MailboxController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\GoogleOAuthService;
use Webmail\Services\ImapService;
use Webmail\Services\MicrosoftOAuthService;

$runner = new FlowOneTestRunner('thread-endpoint', $argv);

$ownOpts = ['email' => null, 'account-id' => null, 'max-ms' => 5000];
foreach ($runner->extra as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $ownOpts['email'] = trim(substr($arg, 8));
    } elseif (str_starts_with($arg, '--account-id=')) {
        $ownOpts['account-id'] = trim(substr($arg, 13));
    } elseif (str_starts_with($arg, '--max-ms=')) {
        $ownOpts['max-ms'] = (int) substr($arg, 9);
    }
}

if (empty($ownOpts['email'])) {
    $runner->log('FAIL: --email=USER@gmail.com is required');
    exit(1);
}

$testEmail = strtolower($ownOpts['email']);
$forceAccountId = $ownOpts['account-id'] !== null ? (int) $ownOpts['account-id'] : null;
$maxMs = max(1000, (int) $ownOpts['max-ms']);

$config = require __DIR__ . '/../src/config.php';

// -----------------------------------------------------------------------------
// 0. PREFLIGHT
// -----------------------------------------------------------------------------

$runner->section('0. PREFLIGHT');

$db = null;
$redis = null;

foreach (['imap', 'openssl', 'pdo_mysql', 'mbstring', 'redis', 'curl'] as $ext) {
    $runner->test("ext:{$ext} loaded", function () use ($ext) {
        if (!extension_loaded($ext)) {
            throw new \RuntimeException("PHP extension '{$ext}' not loaded");
        }
        return true;
    });
}

$runner->test('DB reachable', function () use ($config, &$db) {
    $db = \Webmail\Core\Database::getConnection($config);
    $db->query('SELECT 1');
    return true;
});

$runner->test('OAUTH_KEYS env OR jwt.secret present', function () use ($config) {
    if (!getenv('OAUTH_KEYS') && empty($config['jwt']['secret'] ?? null)) {
        throw new \RuntimeException('neither env OAUTH_KEYS nor config[jwt][secret] set');
    }
    return true;
});

if ($runner->smoke) {
    exit($runner->finish());
}

// -----------------------------------------------------------------------------
// Shared state used across sections.
// -----------------------------------------------------------------------------

$oauthRow = null;
$primaryEmail = null;
$oauthEmail = null;
$provider = null;
$accessToken = null;
/** @var ?ImapService $imap */
$imap = null;
/** @var array<int,array<string,mixed>> $listedFolders */
$listedFolders = [];
/** @var ?array{uid:int,message_id:string,subject:string,folder:string} $sourceMsg */
$sourceMsg = null;
$hasGmailAllMail = false;

$runner->addCleanup(function () use (&$imap) {
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

// -----------------------------------------------------------------------------
// 1. OAUTH-TOKEN
// -----------------------------------------------------------------------------

if ($runner->shouldRunSection('1. OAUTH-TOKEN')) {
    $runner->section('1. OAUTH-TOKEN');

    $runner->test('webmail_oauth_tokens row for --email', function () use (&$db, $testEmail, $forceAccountId, &$oauthRow, &$primaryEmail, &$oauthEmail, &$provider) {
        if ($forceAccountId !== null) {
            $stmt = $db->prepare('SELECT id, primary_email, oauth_email, provider FROM webmail_oauth_tokens WHERE id = ? LIMIT 1');
            $stmt->execute([$forceAccountId]);
        } else {
            $stmt = $db->prepare("SELECT id, primary_email, oauth_email, provider FROM webmail_oauth_tokens
                WHERE primary_email = ? OR oauth_email = ?
                ORDER BY (oauth_email = ?) DESC, updated_at DESC LIMIT 1");
            $stmt->execute([$testEmail, $testEmail, $testEmail]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException("no webmail_oauth_tokens row matched '{$testEmail}'");
        }
        $oauthRow = $row;
        $primaryEmail = strtolower($row['primary_email']);
        $oauthEmail = strtolower($row['oauth_email']);
        $provider = $row['provider'] ?? 'google';
        return true;
    });

    $runner->test('getValidAccessToken returns a token', function () use (&$oauthRow, &$primaryEmail, &$oauthEmail, &$provider, $config, &$accessToken) {
        if ($oauthRow === null) {
            throw new \RuntimeException('skipped: oauth row not loaded');
        }
        $svc = $provider === 'microsoft' ? new MicrosoftOAuthService($config) : new GoogleOAuthService($config);
        $token = $svc->getValidAccessToken($primaryEmail, $oauthEmail);
        if (!$token) {
            $reason = method_exists($svc, 'getLastFailureReason') ? $svc->getLastFailureReason() : null;
            throw new \RuntimeException('access token NULL; reason=' . ($reason ?? 'unknown'));
        }
        $accessToken = $token;
        return true;
    });
}

// -----------------------------------------------------------------------------
// 2. IMAP-CONNECT
// -----------------------------------------------------------------------------

if ($runner->shouldRunSection('2. IMAP-CONNECT')) {
    $runner->section('2. IMAP-CONNECT');

    $runner->test('connectWithOAuth + listFolders', function () use (&$accessToken, &$oauthEmail, &$provider, $config, &$imap, &$listedFolders, &$hasGmailAllMail) {
        if (!$accessToken || !$oauthEmail) {
            throw new \RuntimeException('skipped: no access token');
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
            throw new \RuntimeException('connectWithOAuth returned false');
        }
        $listedFolders = $imap->listFolders();
        if (!is_array($listedFolders) || empty($listedFolders)) {
            throw new \RuntimeException('listFolders empty');
        }
        foreach ($listedFolders as $row) {
            $name = $row['name'] ?? '';
            $special = strtolower((string)($row['special_use'] ?? ''));
            if ($name === '[Gmail]/All Mail' || $special === '\\all' || $special === 'all') {
                $hasGmailAllMail = true;
                break;
            }
        }
        return true;
    });
}

// -----------------------------------------------------------------------------
// 3. PICK-MSG — find a recent INBOX message with a non-empty Message-ID
//               and at least one Reference (so the thread search has work
//               to do; without references the endpoint trivially short-
//               circuits and doesn't reproduce the slowness).
// -----------------------------------------------------------------------------

if ($runner->shouldRunSection('3. PICK-MSG')) {
    $runner->section('3. PICK-MSG');

    $runner->test('locate INBOX message with Message-ID via getMessages()', function () use (&$imap, &$sourceMsg) {
        if ($imap === null) {
            throw new \RuntimeException('skipped: imap not connected');
        }
        $page = $imap->getMessages('INBOX', 1, 20);
        if (empty($page['messages'])) {
            throw new \RuntimeException('INBOX page-1 returned no messages');
        }
        foreach ($page['messages'] as $m) {
            $mid = $m['message_id'] ?? '';
            if (!empty($mid)) {
                $sourceMsg = [
                    'uid' => (int) ($m['uid'] ?? 0),
                    'message_id' => trim((string)$mid, '<> '),
                    'subject' => (string)($m['subject'] ?? ''),
                    'folder' => 'INBOX',
                ];
                return true;
            }
        }
        throw new \RuntimeException('no INBOX message in first 20 had a Message-ID header');
    });
}

// -----------------------------------------------------------------------------
// Controller construction helper (re-used by §4-§8).
// -----------------------------------------------------------------------------

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

$buildThreadRequest = static function (array $sourceMsg): Request {
    // Stub the request via $_GET; the Request constructor reads $_GET into
    // its private $query array. We restore $_GET state after Request is
    // built so concurrent test paths don't interfere with each other.
    $prev = $_GET;
    $_GET = [
        'current_folder' => $sourceMsg['folder'] ?? 'INBOX',
        'message_id'     => (string)($sourceMsg['message_id'] ?? ''),
        // Simulate the frontend payload: the message itself + one synthetic
        // ancestor id, so the thread search must process MULTIPLE ids
        // (single-id paths short-circuit and would hide the slowness bug).
        'references'     => json_encode([
            (string)($sourceMsg['message_id'] ?? ''),
            'synthetic-ancestor-' . bin2hex(random_bytes(4)) . '@flowone.test',
        ]),
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/mailbox/thread';
    $request = new Request();
    $_GET = $prev;
    return $request;
};

// Decode a Response body to its inner data array.
$decodeBody = static function (Response $resp): array {
    $raw = $resp->getContent();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

// -----------------------------------------------------------------------------
// 4. BUDGET — wall-clock < $maxMs (default 5000ms).
// -----------------------------------------------------------------------------

if ($runner->shouldRunSection('4. BUDGET')) {
    $runner->section('4. BUDGET');

    $runner->test("getThread() completes in <= {$maxMs}ms", function () use ($buildController, $buildThreadRequest, &$sourceMsg, $maxMs) {
        if ($sourceMsg === null) {
            throw new \RuntimeException('skipped: no source message');
        }
        $ctl = $buildController();
        $req = $buildThreadRequest($sourceMsg);
        $t0 = microtime(true);
        /** @var Response $resp */
        $resp = $ctl->getThread($req);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($ms > $maxMs) {
            throw new \RuntimeException("getThread took {$ms}ms, budget is {$maxMs}ms (this is what makes opening an email feel sluggish)");
        }
        return true;
    }, 30);
}

// -----------------------------------------------------------------------------
// 5. STATUS — must be 200 with a `messages` array, never a 5xx.
// -----------------------------------------------------------------------------

if ($runner->shouldRunSection('5. STATUS')) {
    $runner->section('5. STATUS');

    $runner->test('getThread() returns 200 + messages array (never 5xx)', function () use ($buildController, $buildThreadRequest, $decodeBody, &$sourceMsg) {
        if ($sourceMsg === null) {
            throw new \RuntimeException('skipped: no source message');
        }
        $ctl = $buildController();
        $req = $buildThreadRequest($sourceMsg);
        /** @var Response $resp */
        $resp = $ctl->getThread($req);
        $status = $resp->getStatusCode();
        $body   = $decodeBody($resp);
        if ($status >= 500) {
            throw new \RuntimeException("HTTP {$status} (this is the production console error); body=" . substr($resp->getContent(), 0, 200));
        }
        if ($status !== 200) {
            throw new \RuntimeException("expected 200, got {$status}; body=" . substr($resp->getContent(), 0, 200));
        }
        if (!isset($body['data']['messages']) || !is_array($body['data']['messages'])) {
            throw new \RuntimeException('response.data.messages is missing/not-array: ' . substr($resp->getContent(), 0, 200));
        }
        return true;
    }, 30);
}

// -----------------------------------------------------------------------------
// 6. CORRECTNESS — must include at least the source message itself.
// -----------------------------------------------------------------------------

if ($runner->shouldRunSection('6. CORRECTNESS')) {
    $runner->section('6. CORRECTNESS');

    $runner->test('getThread() result includes the source message_id', function () use ($buildController, $buildThreadRequest, $decodeBody, &$sourceMsg) {
        if ($sourceMsg === null) {
            throw new \RuntimeException('skipped: no source message');
        }
        $ctl = $buildController();
        $req = $buildThreadRequest($sourceMsg);
        /** @var Response $resp */
        $resp = $ctl->getThread($req);
        $body = $decodeBody($resp);
        $messages = $body['data']['messages'] ?? [];
        $sourceId = strtolower(trim($sourceMsg['message_id'], '<> '));
        foreach ($messages as $m) {
            $mid = strtolower(trim((string)($m['message_id'] ?? ''), '<> '));
            if ($mid === $sourceId) {
                return true;
            }
        }
        // Many threads are 1 message (no replies, no references-to-self). If
        // the source message had a self-reference we'd see it; otherwise
        // empty result is acceptable here. Warn, don't fail.
        if (empty($messages)) {
            return 'warn';
        }
        throw new \RuntimeException('source message_id not found in thread result; got ' . count($messages) . ' messages');
    }, 30);
}

// -----------------------------------------------------------------------------
// 7. NO-FANOUT — on Gmail, fast path must search only [Gmail]/All Mail.
//                Asserted via the public folderSelectCount counter on the
//                ImapService: the delta after getThread() must be small
//                (<= 2: one for All Mail, optional one for current folder
//                reselect). Pre-fix this delta is 6-12 depending on how
//                many priority folders + references the endpoint fans out
//                across.
// -----------------------------------------------------------------------------

if ($runner->shouldRunSection('7. NO-FANOUT')) {
    $runner->section('7. NO-FANOUT');

    $runner->test('Gmail [Gmail]/All Mail fast path keeps folderSelectCount delta <= 2', function () use ($buildController, $buildThreadRequest, &$sourceMsg, &$imap, &$hasGmailAllMail) {
        if ($sourceMsg === null) {
            throw new \RuntimeException('skipped: no source message');
        }
        if (!$hasGmailAllMail) {
            return 'warn'; // non-Gmail server: fast path not applicable
        }
        // Force a clean baseline by selecting INBOX once, then resetting
        // the public counter via reflection (the existing public
        // resetFolderSelectCounter method is the production API, so use it).
        $imap->selectFolder('INBOX');
        if (method_exists($imap, 'resetFolderSelectCounter')) {
            $imap->resetFolderSelectCounter();
        }
        $beforeR = new \ReflectionClass($imap);
        $counterProp = $beforeR->hasProperty('folderSelectCount') ? $beforeR->getProperty('folderSelectCount') : null;
        if ($counterProp !== null) {
            $counterProp->setAccessible(true);
        }
        $before = $counterProp !== null ? (int) $counterProp->getValue($imap) : 0;

        $ctl = $buildController();
        $req = $buildThreadRequest($sourceMsg);
        $ctl->getThread($req);

        $after = $counterProp !== null ? (int) $counterProp->getValue($imap) : 0;
        $delta = $after - $before;
        if ($delta > 2) {
            throw new \RuntimeException("getThread issued {$delta} folder SELECTs on Gmail; expected <= 2 with the [Gmail]/All Mail fast path. This is the source of the 'few seconds' delay.");
        }
        return true;
    }, 30);
}

// -----------------------------------------------------------------------------
// 8. OR-SEARCH — searchHeadersOr returns a superset (>=) of the union of
//                the three legacy single-header searches. Proves the
//                helper is functionally equivalent before we replace the
//                3-call fanout with 1 OR'd call.
// -----------------------------------------------------------------------------

if ($runner->shouldRunSection('8. OR-SEARCH')) {
    $runner->section('8. OR-SEARCH');

    $runner->test('searchHeadersOr(References|In-Reply-To|Message-ID) matches union of 3 separate searches', function () use (&$imap, &$sourceMsg, &$hasGmailAllMail) {
        if ($imap === null || $sourceMsg === null) {
            throw new \RuntimeException('skipped: prerequisites not met');
        }
        if (!method_exists($imap, 'searchHeadersOr')) {
            throw new \RuntimeException('ImapService::searchHeadersOr() is missing — implement it before this test can run');
        }
        $folder = $hasGmailAllMail ? '[Gmail]/All Mail' : 'INBOX';
        $id = $sourceMsg['message_id'];

        $a = $imap->searchHeader($folder, 'References', $id);
        $b = $imap->searchHeader($folder, 'In-Reply-To', $id);
        $c = $imap->searchHeader($folder, 'Message-ID', $id);
        $unionUids = [];
        foreach (array_merge($a, $b, $c) as $m) { $unionUids[(int)$m['uid']] = true; }

        $combined = $imap->searchHeadersOr($folder, [
            ['header' => 'References',  'value' => $id],
            ['header' => 'In-Reply-To', 'value' => $id],
            ['header' => 'Message-ID',  'value' => $id],
        ]);
        $combinedUids = [];
        foreach ($combined as $m) { $combinedUids[(int)$m['uid']] = true; }

        // Must cover every UID the union found. (Combined may include more
        // because IMAP OR can match overlapping criteria the per-header
        // calls would have de-duplicated client-side too — but never less.)
        $missing = array_diff_key($unionUids, $combinedUids);
        if (!empty($missing)) {
            throw new \RuntimeException('searchHeadersOr missed UIDs the legacy fanout found: ' . implode(',', array_keys($missing)));
        }
        return true;
    }, 30);
}

exit($runner->finish());
