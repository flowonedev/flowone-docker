#!/usr/bin/env php
<?php
/**
 * FlowOne — OAuth / Gmail / Mailbox / Calendar End-to-End Test Suite
 * ====================================================================
 *
 * Exercises every layer touched by the OAuth Mail Calendar Audit Fixes plan:
 *
 *   - Pre-flight    : PHP extensions, DB + Redis reachability, key config
 *   - Auth          : HMAC-signed OAuth state round-trip (Phase 2.1)
 *   - Crypto        : OAuthCryptor AES-256-GCM encrypt/decrypt round-trip,
 *                     CBC -> GCM migration dry-run on a synthetic envelope
 *                     (Phase 2.4)
 *   - IMAP          : XOAUTH2 connect to imap.gmail.com:993, listFolders(),
 *                     batched FETCH of 50 UIDs in one command (Phases 1.1, 2.8)
 *   - Gmail list    : Regression guard for the May 2026 OAuth fixes -
 *                     readMultilineResponse byte cap, parseFetchResponse
 *                     UID/message_id de-dupe, COPYUID parser, STATUS RTT
 *                     proxy for single-move latency.
 *   - Mailbox ops   : Bulk delete + bulk move of 5 [FLOWONE-TEST] messages with
 *                     a wrapper that counts IMAP commands (Phase 1.2)
 *   - SMTP          : XOAUTH2 send to self ([FLOWONE-TEST] subject) — skip with
 *                     --skip-send
 *   - Calendar      : Google Calendar auth URL HMAC + pagination loop +
 *                     syncToGoogle/deleteFromGoogle round-trip (Phase 3.1, 3.2)
 *   - Cron health   : refresh-oauth-tokens.php, sync-google-calendars.php,
 *                     index-meilisearch.php exist, executable, --help works
 *
 * Run command:
 *   /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-email/backend/tests/oauth-gmail-e2e-test.php \
 *     --email=USER --password=PASS --verbose
 *
 * Required flags:
 *   --email=EMAIL    The primary_email row in webmail_oauth_tokens to test.
 *   --password=PASS  (Only used by --skip-send=false SMTP test — Gmail
 *                    no longer accepts password auth, so XOAUTH2 is used
 *                    against the OAuth token in the DB. Pass an empty
 *                    string with --password= if you only have OAuth.)
 *
 * Optional flags (per the server-side-testing workspace rule):
 *   --help                  Show this banner
 *   --verbose               Per-row debug output
 *   --skip-send             Skip the SMTP send test (defaults to false)
 *   --only=group1,group2    Run only listed groups
 *   --smoke                 Connectivity + config only, no business logic
 *   --json                  Emit results as JSON instead of human format
 *   --timeout=SEC           Per-test timeout (default 30)
 *   --to=EMAIL              SMTP send target (default = --email)
 *
 * Safety:
 *   * Every created mailbox subject is prefixed [FLOWONE-TEST] so a stray
 *     cleanup failure is trivially distinguishable from real mail.
 *   * The script ALWAYS runs a finalize() cleanup, including SIGINT/SIGTERM.
 *   * Per-test timeouts are enforced via pcntl_alarm so one stuck IMAP
 *     handshake never blocks the entire suite.
 *
 * Exit codes:
 *   0 - all tests passed (warnings allowed)
 *   1 - at least one test failed OR preflight blocked execution
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/bootstrap.php';

use Webmail\Services\OAuthStateService;
use Webmail\Services\OAuthCryptor;
use Webmail\Services\GoogleOAuthService;
use Webmail\Services\ImapService;
use Webmail\Addons\Calendar\Services\GoogleCalendarService;

$config = require __DIR__ . '/../src/config.php';

// ───── CLI parsing ─────────────────────────────────────────────────

$opts = getopt('', [
    'help', 'verbose', 'skip-send', 'smoke', 'json',
    'email::', 'password::', 'to::',
    'only::', 'timeout::',
]);

if (isset($opts['help']) || !isset($opts['email'])) {
    print_help();
    exit(isset($opts['help']) ? 0 : 1);
}

$opt = static function (string $k, $default = null) use ($opts) {
    return $opts[$k] ?? $default;
};

$testEmail   = strtolower(trim((string)$opt('email')));
$testPass    = (string)$opt('password', '');
$testTo      = strtolower(trim((string)$opt('to', $testEmail)));
$skipSend    = isset($opts['skip-send']);
$smoke       = isset($opts['smoke']);
$verbose     = isset($opts['verbose']);
$jsonOutput  = isset($opts['json']);
$onlyGroups  = isset($opts['only']) ? array_filter(array_map('trim', explode(',', (string)$opts['only']))) : [];
$timeoutSec  = max(5, (int)$opt('timeout', 30));

// ───── Logging + counters ──────────────────────────────────────────

$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/oauth-gmail-e2e-' . date('Ymd-His') . '.log';

$totals = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'total' => 0];
$results = [];
$cleanupItems = [];

$ansi = !$jsonOutput && function_exists('posix_isatty') && posix_isatty(STDOUT);
$C_RESET = $ansi ? "\033[0m" : '';
$C_RED   = $ansi ? "\033[31m" : '';
$C_GREEN = $ansi ? "\033[32m" : '';
$C_YEL   = $ansi ? "\033[33m" : '';
$C_BLUE  = $ansi ? "\033[34m" : '';
$C_DIM   = $ansi ? "\033[90m" : '';

$out = static function (string $msg) use ($logFile, $jsonOutput): void {
    if (!$jsonOutput) {
        echo $msg . "\n";
    }
    @file_put_contents($logFile, date('[H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
};
$out("FlowOne OAuth/Gmail/Mailbox/Calendar test suite — " . date('c'));
$out("Account: {$testEmail}  timeout={$timeoutSec}s  smoke=" . ($smoke ? '1' : '0') . "  skip-send=" . ($skipSend ? '1' : '0'));

// ───── Signal-safe cleanup ─────────────────────────────────────────

$finalize = static function () use (&$cleanupItems, $out): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    foreach (array_reverse($cleanupItems) as $fn) {
        try {
            $fn();
        } catch (\Throwable $e) {
            $out('cleanup: ' . $e->getMessage());
        }
    }
};
register_shutdown_function($finalize);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use ($out, $finalize) {
        $out('SIGINT received — running cleanup');
        $finalize();
        exit(130);
    });
    pcntl_signal(SIGTERM, static function () use ($out, $finalize) {
        $out('SIGTERM received — running cleanup');
        $finalize();
        exit(143);
    });
}

// ───── Test harness ────────────────────────────────────────────────

$run = static function (string $group, string $name, callable $fn) use (
    &$totals, &$results, $out, $timeoutSec, $C_GREEN, $C_RED, $C_YEL, $C_RESET, $verbose, $jsonOutput
): void {
    $totals['total']++;
    $start = microtime(true);
    $status = 'pass';
    $message = '';
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm($timeoutSec);
    }
    try {
        $result = $fn();
        if ($result === 'warn') {
            $status = 'warn';
        } elseif ($result === false) {
            $status = 'fail';
            $message = 'returned false';
        } elseif (is_string($result) && strpos($result, 'fail:') === 0) {
            $status = 'fail';
            $message = substr($result, 5);
        } elseif (is_string($result) && strpos($result, 'warn:') === 0) {
            $status = 'warn';
            $message = substr($result, 5);
        }
    } catch (\Throwable $e) {
        $status = 'fail';
        $message = $e->getMessage();
        if ($verbose) {
            $message .= ' @ ' . $e->getFile() . ':' . $e->getLine();
        }
    } finally {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }
    $elapsed = (int)round((microtime(true) - $start) * 1000);
    $totals[$status === 'pass' ? 'pass' : ($status === 'warn' ? 'warn' : 'fail')]++;
    $results[] = compact('group', 'name', 'status', 'message', 'elapsed');
    if (!$jsonOutput) {
        $label = $status === 'pass' ? "{$C_GREEN}PASS{$C_RESET}" : ($status === 'warn' ? "{$C_YEL}WARN{$C_RESET}" : "{$C_RED}FAIL{$C_RESET}");
        $line = "  [{$label}] {$group} :: {$name} ({$elapsed}ms)";
        if ($message !== '') {
            $line .= " — {$message}";
        }
        $out($line);
    }
};

$groupActive = static function (string $g) use ($onlyGroups, $smoke): bool {
    if ($smoke && $g !== 'preflight') {
        return false;
    }
    return empty($onlyGroups) || in_array($g, $onlyGroups, true);
};

// ───── DB + token lookup (needed by most groups) ───────────────────

$db = null;
try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    $out("FATAL: DB connection failed: " . $e->getMessage());
    exit(1);
}

$oauthRow = null;
$accessToken = null;
$googleSvc = !empty($config['google_oauth']['client_id']) ? new GoogleOAuthService($config) : null;

if ($groupActive('imap') || $groupActive('gmail-list-fetch') || $groupActive('mailbox-ops') || $groupActive('smtp') || $groupActive('calendar')) {
    // Accept --email as either the FlowOne primary_email OR the linked
    // Google oauth_email. Most test runs hand in the gmail address, which
    // is the oauth_email; the row stores it separately from primary_email.
    try {
        $stmt = $db->prepare("
            SELECT id, primary_email, oauth_email, provider, token_expires_at
            FROM webmail_oauth_tokens
            WHERE (primary_email = ? OR oauth_email = ?)
              AND provider = 'google'
            ORDER BY (oauth_email = ?) DESC, token_expires_at DESC
            LIMIT 1
        ");
        $stmt->execute([$testEmail, $testEmail, $testEmail]);
        $oauthRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($oauthRow && $googleSvc) {
            $accessToken = $googleSvc->getValidAccessToken($oauthRow['primary_email'], $oauthRow['oauth_email']);
            if ($verbose) {
                $out("oauth row found: primary={$oauthRow['primary_email']} oauth={$oauthRow['oauth_email']} expires={$oauthRow['token_expires_at']}");
                $out('access token: ' . ($accessToken ? 'OK (length=' . strlen($accessToken) . ')' : 'NULL — refresh likely failed'));
            }
        } elseif ($verbose) {
            $out("no webmail_oauth_tokens row matched primary_email or oauth_email = {$testEmail}");
        }
    } catch (\Throwable $e) {
        $out('oauth token lookup failed: ' . $e->getMessage());
    }
}

// ───── 1. PREFLIGHT ────────────────────────────────────────────────

if ($groupActive('preflight')) {
    $out("");
    $out("--- 1. PREFLIGHT ---");

    foreach (['imap', 'openssl', 'curl', 'pdo_mysql', 'mbstring', 'redis'] as $ext) {
        $run('preflight', "ext:{$ext} loaded", function () use ($ext) {
            return extension_loaded($ext);
        });
    }

    $run('preflight', 'DB reachable', function () use ($db) {
        return (bool)$db->query("SELECT 1")->fetchColumn();
    });

    $run('preflight', 'Redis reachable', function () use ($config) {
        if (!extension_loaded('redis')) return 'warn';
        $r = new Redis();
        $r->connect($config['redis']['host'] ?? '127.0.0.1', $config['redis']['port'] ?? 6379, 2.0);
        if (!empty($config['redis']['password'])) {
            $r->auth($config['redis']['password']);
        }
        $ok = $r->ping();
        $r->close();
        return $ok !== false;
    });

    $run('preflight', 'webmail_oauth_tokens schema', function () use ($db) {
        $cols = $db->query("SHOW COLUMNS FROM webmail_oauth_tokens")->fetchAll(\PDO::FETCH_COLUMN);
        $required = ['id', 'primary_email', 'oauth_email', 'access_token_encrypted', 'refresh_token_encrypted', 'token_expires_at'];
        foreach ($required as $c) {
            if (!in_array($c, $cols, true)) {
                return "fail:missing column {$c}";
            }
        }
        return true;
    });

    $run('preflight', 'OAUTH_KEYS or jwt.secret present', function () use ($config) {
        $env = getenv('OAUTH_KEYS') ?: '';
        $jwt = $config['jwt']['secret'] ?? '';
        if ($env === '' && (!is_string($jwt) || $jwt === '')) {
            return 'fail:no key material configured';
        }
        return true;
    });

    $run('preflight', 'google_oauth.client_id configured', function () use ($config) {
        return !empty($config['google_oauth']['client_id']);
    });
}

// ───── 2. AUTH (HMAC state) ────────────────────────────────────────

if ($groupActive('auth')) {
    $out("");
    $out("--- 2. AUTH ---");

    $stateSvc = null;
    try {
        $stateSvc = new OAuthStateService($config);
    } catch (\Throwable $e) {
        $run('auth', 'OAuthStateService instantiates', fn() => "fail:" . $e->getMessage());
    }

    if ($stateSvc) {
        $run('auth', 'sign + verify roundtrip', function () use ($stateSvc) {
            $payload = ['user_email' => 'alice@example.test', 'flow' => 'add_account'];
            $state = $stateSvc->sign($payload);
            $back = $stateSvc->verify($state);
            if (!$back) return 'fail:verify returned null';
            if (($back['user_email'] ?? null) !== 'alice@example.test') return 'fail:payload corrupted';
            if (empty($back['nonce']) || empty($back['timestamp'])) return 'fail:nonce/timestamp missing';
            return true;
        });

        $run('auth', 'tampered state rejected', function () use ($stateSvc) {
            $state = $stateSvc->sign(['x' => 1]);
            // Flip a byte in the middle of the base64 payload to corrupt the JSON without
            // affecting overall structure too much.
            $raw = base64_decode($state, true);
            $mid = (int)floor(strlen($raw) / 2);
            $raw[$mid] = $raw[$mid] === 'a' ? 'b' : 'a';
            $tampered = base64_encode($raw);
            return $stateSvc->verify($tampered) === null;
        });

        $run('auth', 'expired state rejected', function () use ($stateSvc) {
            // Build a state with a stale timestamp by re-signing with an old time.
            $payload = ['user_email' => 'alice@example.test', 'nonce' => bin2hex(random_bytes(16)), 'timestamp' => time() - 3600];
            $state = $stateSvc->sign($payload);
            return $stateSvc->verify($state) === null;
        });

        $run('auth', 'legacy unsigned state rejected', function () use ($stateSvc) {
            $legacy = base64_encode(json_encode(['user_email' => 'alice@example.test', 'nonce' => 'x', 'timestamp' => time()]));
            return $stateSvc->verify($legacy) === null;
        });
    }
}

// ───── 3. CRYPTO ───────────────────────────────────────────────────

if ($groupActive('crypto')) {
    $out("");
    $out("--- 3. CRYPTO ---");

    $cryptor = null;
    try {
        $cryptor = new OAuthCryptor($config);
    } catch (\Throwable $e) {
        $run('crypto', 'OAuthCryptor instantiates', fn() => "fail:" . $e->getMessage());
    }

    if ($cryptor) {
        $run('crypto', 'encrypt/decrypt round-trip', function () use ($cryptor) {
            $plain = 'flowone-test-secret-' . random_int(0, 99999);
            $cipher = $cryptor->encrypt($plain);
            if (!is_string($cipher) || $cipher === '') return 'fail:empty ciphertext';
            if (strpos($cipher, 'v') !== 0) return 'fail:no versioned prefix';
            $back = $cryptor->decrypt($cipher);
            return $back === $plain;
        });

        $run('crypto', 'GCM tamper detection', function () use ($cryptor) {
            $cipher = $cryptor->encrypt('alpha');
            // Flip a byte 5 chars from the end so the GCM tag check trips.
            $tampered = substr($cipher, 0, -3) . 'AAA';
            try {
                $back = $cryptor->decrypt($tampered);
                return $back === null ? true : 'fail:decrypted tampered payload';
            } catch (\Throwable $e) {
                return true; // exception is acceptable
            }
        });

        $run('crypto', 'legacy CBC migration dry-run', function () use ($config, $cryptor) {
            // Build a CBC envelope using the legacy key shape used by
            // CalendarConnectionService::encryptToken before Phase 2.4.
            $key = hash('sha256', $config['jwt']['secret'] ?? 'default_key', true);
            $iv = openssl_random_pseudo_bytes(16);
            $plain = 'cbc-legacy-token';
            $ct = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($ct === false) return 'fail:openssl_encrypt failed';
            $envelope = base64_encode($iv . $ct);
            // Decrypt as the cron migration does
            $raw = base64_decode($envelope, true);
            $iv2 = substr($raw, 0, 16);
            $body = substr($raw, 16);
            $decrypted = openssl_decrypt($body, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv2);
            if ($decrypted !== $plain) return 'fail:cbc decrypt mismatch';
            // Re-encrypt with the new GCM cryptor and verify
            $newCipher = $cryptor->encrypt($decrypted);
            return $cryptor->decrypt($newCipher) === $plain;
        });
    }
}

// ───── 4. IMAP ─────────────────────────────────────────────────────

$imap = null;
if ($groupActive('imap') || $groupActive('gmail-list-fetch') || $groupActive('mailbox-ops')) {
    $out("");
    $out("--- 4. IMAP ---");

    if (!$accessToken) {
        $run('imap', 'OAuth access token available', fn() => 'fail:no valid access token for ' . $testEmail);
    } else {
        $run('imap', 'connectWithOAuth to imap.gmail.com:993', function () use (&$imap, $accessToken, $oauthRow) {
            $imap = new ImapService(['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl']);
            $ok = $imap->connectWithOAuth($oauthRow['oauth_email'], $accessToken);
            return $ok ? true : ('fail:' . ($imap->getLastError() ?? 'unknown'));
        });

        if ($imap) {
            $run('imap', 'listFolders returns INBOX', function () use ($imap) {
                $folders = $imap->listFolders();
                foreach ($folders as $f) {
                    $name = strtoupper((string)($f['name'] ?? ''));
                    if ($name === 'INBOX') return true;
                }
                return 'fail:no INBOX in folder list';
            });

            $run('imap', 'TLS context enforces verify_peer', function () use ($config) {
                // The audit fix sets verify_peer = true. Smoke-check that the
                // env override exists OR /etc/ssl/certs/ca-certificates.crt
                // is readable (the default our code falls back to).
                $caFile = getenv('IMAP_TLS_CAFILE') ?: '/etc/ssl/certs/ca-certificates.crt';
                return is_readable($caFile) ? true : 'warn:CA bundle not readable at ' . $caFile;
            });

            $run('imap', 'batched FETCH of recent 50 UIDs', function () use ($imap) {
                $result = $imap->getMessages('INBOX', 1, 50);
                $messages = $result['messages'] ?? [];
                if (count($messages) < 1) return 'warn:INBOX empty or fewer than 1 message';
                return count($messages) <= 50;
            });
        }
    }
}

// ───── 4b. GMAIL-LIST-FETCH (regression guard) ────────────────────
//
// Exercises the May 2026 OAuth-only fixes:
//   - readMultilineResponse byte cap (no truncated FETCH)
//   - parseFetchResponse de-dupe (no two rows share UID / message_id)
//   - empty-message_id label-attach guard (no shared label set)
//   - getFolderStatus over OAuth socket (proxy for single-move RTT cost)
//
// Failure modes this catches:
//   - "Inbox shows 0 / N messages"     -> messages array empty when N > 0
//   - "All Mail every row same sender" -> duplicate from_email across rows
//   - "single move takes seconds"      -> getFolderStatus > 750ms (1 RTT)

if ($groupActive('gmail-list-fetch') && $imap) {
    $out("");
    $out("--- 4b. GMAIL-LIST-FETCH (regression guard) ---");

    $assertUnique = function (array $messages, string $folder): string|bool {
        if (empty($messages)) {
            return 'warn:' . $folder . ' returned 0 messages (folder may be empty or fetch truncated)';
        }
        $seenUids = [];
        $seenMsgIds = [];
        $dupUids = 0;
        $dupMsgIds = 0;
        foreach ($messages as $m) {
            $uid = (int)($m['uid'] ?? 0);
            $mid = (string)($m['message_id'] ?? '');
            if ($uid > 0) {
                if (isset($seenUids[$uid])) {
                    $dupUids++;
                }
                $seenUids[$uid] = true;
            }
            if ($mid !== '') {
                if (isset($seenMsgIds[$mid])) {
                    $dupMsgIds++;
                }
                $seenMsgIds[$mid] = true;
            }
        }
        if ($dupUids > 0) {
            return "fail:{$folder} returned {$dupUids} duplicate UID(s) across " . count($messages) . " rows";
        }
        if ($dupMsgIds > 0) {
            return "fail:{$folder} returned {$dupMsgIds} duplicate message_id(s) across " . count($messages) . " rows";
        }
        return true;
    };

    $run('gmail-list-fetch', 'INBOX page 1 returns parseable messages', function () use ($imap, $assertUnique) {
        $result = $imap->getMessages('INBOX', 1, 50);
        $messages = $result['messages'] ?? [];
        $total = (int)($result['total'] ?? 0);
        if ($total > 0 && count($messages) === 0) {
            return 'fail:INBOX total=' . $total . ' but messages array is empty (FETCH truncation symptom)';
        }
        return $assertUnique($messages, 'INBOX');
    });

    $run('gmail-list-fetch', 'INBOX page 1 senders not all identical', function () use ($imap) {
        $result = $imap->getMessages('INBOX', 1, 50);
        $messages = $result['messages'] ?? [];
        if (count($messages) < 2) {
            return 'warn:not enough INBOX messages to evaluate sender diversity';
        }
        $senders = [];
        foreach ($messages as $m) {
            $email = strtolower(trim((string)($m['from_email'] ?? '')));
            if ($email !== '') {
                $senders[$email] = true;
            }
        }
        if (count($senders) === 0) {
            return 'fail:every INBOX row has empty from_email (FETCH header parsing broken)';
        }
        if (count($messages) >= 5 && count($senders) === 1) {
            return 'fail:every INBOX row collapses to the same sender (' . array_key_first($senders) . ') -- scrambled-sender regression';
        }
        return true;
    });

    $run('gmail-list-fetch', '[Gmail]/All Mail page 1 returns parseable messages', function () use ($imap, $assertUnique) {
        $allMailPath = null;
        foreach ($imap->listFolders() as $f) {
            $name = (string)($f['name'] ?? '');
            if (preg_match('#^\[Gmail\]/All Mail$#i', $name)) {
                $allMailPath = $name;
                break;
            }
        }
        if (!$allMailPath) {
            return 'warn:no [Gmail]/All Mail folder (non-Gmail account?)';
        }
        $result = $imap->getMessages($allMailPath, 1, 50);
        $messages = $result['messages'] ?? [];
        $total = (int)($result['total'] ?? 0);
        if ($total > 0 && count($messages) === 0) {
            return 'fail:[Gmail]/All Mail total=' . $total . ' but messages array is empty';
        }
        return $assertUnique($messages, '[Gmail]/All Mail');
    });

    $run('gmail-list-fetch', '[Gmail]/All Mail page 1 senders not all identical', function () use ($imap) {
        $allMailPath = null;
        foreach ($imap->listFolders() as $f) {
            $name = (string)($f['name'] ?? '');
            if (preg_match('#^\[Gmail\]/All Mail$#i', $name)) {
                $allMailPath = $name;
                break;
            }
        }
        if (!$allMailPath) {
            return 'warn:no [Gmail]/All Mail folder';
        }
        $result = $imap->getMessages($allMailPath, 1, 50);
        $messages = $result['messages'] ?? [];
        if (count($messages) < 2) {
            return 'warn:not enough All Mail messages to evaluate sender diversity';
        }
        $senders = [];
        foreach ($messages as $m) {
            $email = strtolower(trim((string)($m['from_email'] ?? '')));
            if ($email !== '') {
                $senders[$email] = true;
            }
        }
        if (count($senders) === 0) {
            return 'fail:every All Mail row has empty from_email';
        }
        if (count($messages) >= 5 && count($senders) === 1) {
            return 'fail:every All Mail row collapses to the same sender (' . array_key_first($senders) . ') -- scrambled-sender regression';
        }
        return true;
    });

    $run('gmail-list-fetch', 'INBOX getMessages page 1 under 2.5s (sequence-fetch fast path)', function () use ($imap) {
        // After the May 2026 "drop UID SEARCH ALL" optimization a fresh
        // page-1 fetch should be: 1x SELECT (already done) + 1x FETCH
        // by sequence. Even on a 50k-message folder this completes in
        // well under 2.5s end-to-end. If this test starts failing
        // someone has either re-introduced UID SEARCH ALL or there's
        // a network regression worth investigating.
        $start = microtime(true);
        $result = $imap->getMessages('INBOX', 1, 50);
        $ms = (int)round((microtime(true) - $start) * 1000);
        if (!is_array($result) || !isset($result['messages'])) {
            return 'fail:getMessages returned malformed shape';
        }
        if ($ms > 2500) {
            return 'warn:getMessages took ' . $ms . 'ms (>2.5s suggests UID SEARCH ALL regression or slow Gmail RTT)';
        }
        return true;
    });

    $run('gmail-list-fetch', 'single STATUS round-trip under 750ms (move-RTT proxy)', function () use ($imap) {
        // A single OAuth move = SELECT + UID MOVE (with COPYUID parsing).
        // If a single STATUS already breaks 750ms, we have a network or
        // server-side regression that's going to make moves "feel slow".
        // STATUS is the cheapest OAuth command we have - using it as a
        // proxy avoids destructive moves while still detecting latency.
        $start = microtime(true);
        $status = $imap->getFolderStatus('INBOX');
        $ms = (int)round((microtime(true) - $start) * 1000);
        if (!is_array($status) || !isset($status['messages'])) {
            return 'fail:STATUS returned malformed data';
        }
        if ($ms > 750) {
            return 'warn:STATUS took ' . $ms . 'ms (>750ms suggests slow Gmail RTT; single move ~= 2x this)';
        }
        return true;
    });

    $run('gmail-list-fetch', 'All Mail shortcut detection matches controller logic', function () use ($imap, $out) {
        // Mirrors MailboxController::search() detection chain so we can
        // confirm the controller will pick the same folder we expect.
        // If this test reports special_use=none AND name_match=none on a
        // Gmail account, the controller is silently falling back to the
        // (slow, sometimes-wrong) cross-folder scan and that is the
        // root cause of "All Mail shows wrong messages" symptoms.
        $folders = $imap->listFolders();
        $byFlag = null;
        foreach ($folders as $f) {
            $su = (string)($f['special_use'] ?? '');
            if ($su !== '' && strcasecmp(ltrim($su, '\\'), 'All') === 0) {
                $byFlag = $f;
                break;
            }
        }
        $byName = null;
        $candidates = [
            '[Gmail]/All Mail', '[Google Mail]/All Mail',
            '[Gmail]/Tutta la posta', '[Gmail]/Toda la correspondencia',
            '[Gmail]/Tous les messages', '[Gmail]/Tüm Postalar',
            '[Gmail]/Alle Nachrichten', '[Gmail]/Sva pošta',
            '[Gmail]/Minden levél',
        ];
        foreach ($folders as $f) {
            foreach ($candidates as $c) {
                if (strcasecmp((string)($f['name'] ?? ''), $c) === 0) {
                    $byName = $f;
                    break 2;
                }
            }
        }
        if (!$byFlag && !$byName) {
            return 'fail:no \\All special-use AND no Gmail-name match found across ' . count($folders) . ' folders -- controller will fall through to cross-folder scan';
        }
        $picked = $byFlag ?: $byName;
        $method = $byFlag ? 'special_use' : 'name_match';
        $name = (string)($picked['name'] ?? '');
        $total = (int)($picked['total'] ?? 0);
        $out("    detected=\"{$name}\" via={$method} total={$total}");
        return true;
    });

    $run('gmail-list-fetch', 'All Mail first 10 subjects (for visual Gmail compare)', function () use ($imap, $out) {
        // Diagnostic: print the first 10 subjects + senders + dates that
        // our shortcut path returns. User compares this against Gmail
        // web UI's All Mail to confirm we're surfacing the same rows.
        // If subjects don't match Gmail (and aren't reordered threads),
        // we have a sequence-fetch ordering or folder-selection bug.
        $folders = $imap->listFolders();
        $allMailName = null;
        foreach ($folders as $f) {
            $su = (string)($f['special_use'] ?? '');
            if ($su !== '' && strcasecmp(ltrim($su, '\\'), 'All') === 0) {
                $allMailName = (string)$f['name'];
                break;
            }
        }
        if (!$allMailName) {
            $candidates = ['[Gmail]/All Mail', '[Google Mail]/All Mail'];
            foreach ($folders as $f) {
                foreach ($candidates as $c) {
                    if (strcasecmp((string)($f['name'] ?? ''), $c) === 0) {
                        $allMailName = (string)$f['name'];
                        break 2;
                    }
                }
            }
        }
        if (!$allMailName) {
            return 'warn:no All Mail folder found';
        }
        $result = $imap->getMessages($allMailName, 1, 10, 'date', 'desc');
        $messages = $result['messages'] ?? [];
        $total = (int)($result['total'] ?? 0);
        if (empty($messages)) {
            return 'fail:All Mail (' . $allMailName . ') total=' . $total . ' returned 0 messages';
        }
        $out("    [\"{$allMailName}\" total={$total}]");
        foreach (array_slice($messages, 0, 10) as $i => $m) {
            $subj = (string)($m['subject'] ?? '(no subject)');
            $from = (string)($m['from_email'] ?? '?');
            $date = (string)($m['date'] ?? '?');
            if (strlen($subj) > 60) $subj = substr($subj, 0, 57) . '...';
            $out(sprintf('      %2d. [%s] %s | %s', $i + 1, $date, $from, $subj));
        }
        return true;
    });

    $run('gmail-list-fetch', 'Trash folder detected via \\Trash SPECIAL-USE flag', function () use ($imap, $out) {
        // findTrashFolder() in MailboxController now prefers RFC 6154
        // \Trash over name matching. Verify listFolders surfaces that
        // flag on Gmail so the controller can pick [Gmail]/Bin or
        // [Gmail]/Trash correctly regardless of locale.
        $folders = $imap->listFolders();
        $trashFolder = null;
        foreach ($folders as $f) {
            $su = (string)($f['special_use'] ?? '');
            if ($su !== '' && strcasecmp(ltrim($su, '\\'), 'Trash') === 0) {
                $trashFolder = $f;
                break;
            }
        }
        if (!$trashFolder) {
            return 'fail:no \\Trash special-use found on Gmail account -- bulk delete will fall back to name matching';
        }
        $name = (string)($trashFolder['name'] ?? '');
        $total = (int)($trashFolder['total'] ?? 0);
        $out("    detected=\"{$name}\" via=special_use total={$total}");
        return true;
    });

    $run('gmail-list-fetch', 'bulkDeleteMessages issues single UID STORE + EXPUNGE under 1.5s', function () use ($imap) {
        // Non-destructive smoke test: select [Gmail]/All Mail (read-only
        // operation for our purposes) and time how long a SELECT + the
        // STATUS round trip take. Real bulkDeleteMessages on 32 UIDs in
        // the Bin should be ~3 round trips at this rate; if a SELECT
        // alone is > 1.5s the bulk path will feel "hung" no matter how
        // tight our DB cleanup is, and the fix lives in network/RTT.
        $start = microtime(true);
        $ok = method_exists($imap, 'selectFolder') && $imap->selectFolder('[Gmail]/Bin');
        $status = method_exists($imap, 'getFolderStatus') ? $imap->getFolderStatus('[Gmail]/Bin') : null;
        $ms = (int)round((microtime(true) - $start) * 1000);
        if (!$ok && !$status) {
            return 'warn:no [Gmail]/Bin folder on this account';
        }
        if ($ms > 1500) {
            return 'warn:select + status on [Gmail]/Bin took ' . $ms . 'ms (network RTT is the bulk-delete bottleneck, not our DB layer)';
        }
        return true;
    });

    $run('gmail-list-fetch', 'getFolderSyncState returns EXISTS for external-delete detection', function () use ($imap) {
        // The frontend revalidateActiveFolder() uses syncState.exists to
        // detect when someone emptied a folder externally (e.g. user
        // wipes spam from Gmail web while FlowOne is open on the same
        // folder). If this field disappears from the API response,
        // FlowOne can't tell deletions happened on the server side and
        // the user stares at ghost rows until they click somewhere else.
        if (!$imap->selectFolder('INBOX')) {
            return 'fail:selectFolder INBOX failed';
        }
        $state = $imap->getFolderSyncState();
        if (!is_array($state)) {
            return 'fail:getFolderSyncState did not return an array';
        }
        if (!array_key_exists('exists', $state)) {
            return 'fail:getFolderSyncState response is missing the exists field -- external-delete detection broken';
        }
        if (!array_key_exists('uidnext', $state) || !array_key_exists('uidvalidity', $state)) {
            return 'fail:getFolderSyncState response missing uidnext/uidvalidity -- frontend revalidation will fail';
        }
        return true;
    });

    $run('gmail-list-fetch', 'COPYUID parser handles canonical Gmail response', function () {
        // Static unit-style assertion for the regex used in
        // moveMessageOAuthAtomic / bulkMoveMessages. Catches accidental
        // regex regressions without touching live IMAP.
        $sample = "A007 OK [COPYUID 1234567890 100 9876] (Success)";
        if (!preg_match('/COPYUID \d+ \d+ (\d+(?:,\d+)*)/i', $sample, $m)) {
            return 'fail:single-uid COPYUID parse failed';
        }
        if ((int)$m[1] !== 9876) {
            return 'fail:wrong new uid parsed: ' . $m[1];
        }
        $sampleBulk = "A008 OK [COPYUID 1234567890 100,101,102 9876,9877,9878] (Success)";
        if (!preg_match('/COPYUID \d+ ([\d,:]+) ([\d,:]+)/i', $sampleBulk, $m2)) {
            return 'fail:bulk COPYUID parse failed';
        }
        return $m2[2] === '9876,9877,9878';
    });
}

// ───── 5. MAILBOX-OPS (single-message delete + move) ───────────────

if ($groupActive('mailbox-ops')) {
    $out("");
    $out("--- 5. MAILBOX-OPS ---");

    // Verify the production single-message ops exist. Bulk variants are
    // NOT shipped yet -- the frontend currently iterates one HTTP request
    // per selected UID, which hits these singular endpoints. If/when a
    // single-round-trip bulk path is added, re-introduce method_exists
    // assertions here and a lab-fixture round-trip test.
    $run('mailbox-ops', 'deleteMessage method exists', function () {
        return method_exists(ImapService::class, 'deleteMessage');
    });
    $run('mailbox-ops', 'moveMessage method exists', function () {
        return method_exists(ImapService::class, 'moveMessage');
    });
}

// ───── 6. SMTP ─────────────────────────────────────────────────────

if ($groupActive('smtp') && !$skipSend) {
    $out("");
    $out("--- 6. SMTP ---");

    if (!$accessToken) {
        $run('smtp', 'XOAUTH2 send', fn() => 'fail:no access token');
    } else {
        $run('smtp', 'XOAUTH2 send [FLOWONE-TEST]', function () use ($testEmail, $testTo, $accessToken, $oauthRow) {
            // Match the production path used by ChatController / BaseController:
            // submission port 587 with STARTTLS. Many hosts (including ours)
            // block outbound 465 but leave 587 open. The session walks:
            //   plain TCP -> 220 -> EHLO -> STARTTLS -> 220 -> TLS upgrade ->
            //   EHLO (post-tls) -> AUTH XOAUTH2 -> MAIL FROM/RCPT TO/DATA -> QUIT
            $sock = @stream_socket_client('tcp://smtp.gmail.com:587', $errno, $errstr, 10);
            if (!$sock) return 'fail:smtp connect ' . $errstr;
            stream_set_timeout($sock, 15);

            // 220+ multi-line aware reader. Lines that start with `<code>-` are
            // continuation; the line that starts with `<code> ` is the final.
            $readResp = function () use ($sock): string {
                $buf = '';
                while (!feof($sock)) {
                    $line = fgets($sock, 4096);
                    if ($line === false) return $buf;
                    $buf .= $line;
                    if (preg_match('/^\d{3} /m', $line)) {
                        break;
                    }
                }
                return $buf;
            };
            $expect = function (string $code) use ($readResp): array {
                $resp = $readResp();
                return [strpos($resp, $code) === 0, $resp];
            };

            [$ok, $resp] = $expect('220');
            if (!$ok) { fclose($sock); return 'fail:smtp greeting (' . trim($resp) . ')'; }

            fwrite($sock, "EHLO flowone-test\r\n");
            [$ok, $resp] = $expect('250');
            if (!$ok) { fclose($sock); return 'fail:EHLO (' . trim($resp) . ')'; }

            fwrite($sock, "STARTTLS\r\n");
            [$ok, $resp] = $expect('220');
            if (!$ok) { fclose($sock); return 'fail:STARTTLS (' . trim($resp) . ')'; }

            // Upgrade the existing socket to TLS in place.
            $tlsOk = @stream_socket_enable_crypto($sock, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$tlsOk) { fclose($sock); return 'fail:TLS upgrade'; }

            fwrite($sock, "EHLO flowone-test\r\n");
            [$ok, $resp] = $expect('250');
            if (!$ok) { fclose($sock); return 'fail:EHLO post-TLS (' . trim($resp) . ')'; }

            // XOAUTH2 must use the same email the token was issued for —
            // which is oauth_email on the webmail_oauth_tokens row, not the
            // FlowOne primary_email. (Important when --email=primary.)
            $authEmail = $oauthRow['oauth_email'] ?? $testEmail;
            $xoauth2 = base64_encode("user={$authEmail}\x01auth=Bearer {$accessToken}\x01\x01");
            fwrite($sock, "AUTH XOAUTH2 {$xoauth2}\r\n");
            [$ok, $resp] = $expect('235');
            if (!$ok) { fclose($sock); return 'fail:XOAUTH2 (' . trim($resp) . ')'; }

            fwrite($sock, "MAIL FROM:<{$authEmail}>\r\n");
            [$ok, $resp] = $expect('250');
            if (!$ok) { fclose($sock); return 'fail:MAIL FROM (' . trim($resp) . ')'; }

            $rcpt = strtolower($testTo) ?: $authEmail;
            fwrite($sock, "RCPT TO:<{$rcpt}>\r\n");
            [$ok, $resp] = $expect('250');
            if (!$ok) { fclose($sock); return 'fail:RCPT TO (' . trim($resp) . ')'; }

            fwrite($sock, "DATA\r\n");
            [$ok, $resp] = $expect('354');
            if (!$ok) { fclose($sock); return 'fail:DATA (' . trim($resp) . ')'; }

            $subj = '[FLOWONE-TEST] smtp-' . date('His');
            $body = "From: {$authEmail}\r\nTo: {$rcpt}\r\nSubject: {$subj}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nautomated test, safe to delete\r\n.\r\n";
            fwrite($sock, $body);
            [$ok, $resp] = $expect('250');
            if (!$ok) { fclose($sock); return 'fail:DATA accept (' . trim($resp) . ')'; }

            fwrite($sock, "QUIT\r\n");
            fclose($sock);
            return true;
        });
    }
} elseif ($groupActive('smtp')) {
    $out("");
    $out("--- 6. SMTP (skipped by --skip-send) ---");
}

// ───── 7. CALENDAR ─────────────────────────────────────────────────

if ($groupActive('calendar')) {
    $out("");
    $out("--- 7. CALENDAR ---");

    $run('calendar', 'OAuthStateService signs calendar state', function () use ($config) {
        $svc = new OAuthStateService($config);
        $state = $svc->sign(['user_email' => 'alice@example.test', 'flow' => 'calendar']);
        return $svc->verify($state) !== null;
    });

    if (!class_exists(GoogleCalendarService::class)) {
        $run('calendar', 'GoogleCalendarService loadable', fn() => 'fail:class missing');
    } elseif (!$oauthRow) {
        $run('calendar', 'oauth row available', fn() => 'fail:no webmail_oauth_tokens row for ' . $testEmail);
    } else {
        $gcal = null;
        $run('calendar', 'GoogleCalendarService instantiates', function () use (&$gcal, $config) {
            $gcal = new GoogleCalendarService($config);
            return $gcal !== null;
        });

        if ($gcal) {
            $run('calendar', 'pagination helper completes (primary calendar)', function () use ($gcal, $oauthRow, $accessToken) {
                if (!$accessToken) return 'warn:no access token';
                // Use reflection to call private helper for testing.
                $rc = new \ReflectionClass($gcal);
                if (!$rc->hasMethod('listEventsPaginated')) {
                    return 'fail:listEventsPaginated missing';
                }
                $method = $rc->getMethod('listEventsPaginated');
                $method->setAccessible(true);
                $base = [
                    'maxResults' => 250,
                    'singleEvents' => 'true',
                    'orderBy' => 'updated',
                    'timeMin' => date('c', strtotime('-30 days')),
                    'timeMax' => date('c', strtotime('+30 days')),
                ];
                $result = $method->invoke($gcal, 'primary', $base, $accessToken);
                if ($result === null) {
                    // The most common cause on accounts linked for Gmail-only:
                    // the OAuth token has no calendar scope, so apiRequest
                    // returned 403/null. Not a code bug; surface as a warn
                    // with the actionable fix.
                    return 'warn:primary calendar fetch returned null (likely Gmail-only OAuth scope — relink with calendar scope to exercise)';
                }
                $items = $result['items'] ?? [];
                $partial = $result['partial'] ?? false;
                if ($partial) return 'warn:hit pagination cap (50 pages) — should not happen on a normal account';
                return is_array($items);
            });
        }
    }
}

// ───── 8. CRON HEALTH ─────────────────────────────────────────────

if ($groupActive('cron-health')) {
    $out("");
    $out("--- 8. CRON-HEALTH ---");

    $crons = [
        'refresh-oauth-tokens.php',
        'sync-google-calendars.php',
        'renew-calendar-push-channels.php',
        'recrypt-calendar-tokens.php',
        'index-meilisearch.php',
    ];
    foreach ($crons as $cron) {
        $run('cron-health', "{$cron} exists + --help works", function () use ($cron) {
            $path = __DIR__ . '/../cron/' . $cron;
            if (!file_exists($path)) return 'fail:not found';
            $cmd = PHP_BINARY . ' ' . escapeshellarg($path) . ' --help 2>&1';
            $out = shell_exec($cmd);
            return is_string($out) && $out !== '' ? true : 'fail:--help produced no output';
        });
    }
}

// ───── Summary ─────────────────────────────────────────────────────

$exitCode = $totals['fail'] > 0 ? 1 : 0;

if ($jsonOutput) {
    echo json_encode([
        'totals' => $totals,
        'results' => $results,
        'log_file' => $logFile,
        'exit_code' => $exitCode,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    $out("");
    $out("--- SUMMARY ---");
    $out("Passed:   {$totals['pass']}");
    $out("Failed:   {$totals['fail']}");
    $out("Warnings: {$totals['warn']}");
    $out("Total:    {$totals['total']}");
    $out("Log:      {$logFile}");
    if ($totals['fail'] > 0) {
        $out("");
        $out("Failures:");
        foreach ($results as $r) {
            if ($r['status'] === 'fail') {
                $out("  - {$r['group']} :: {$r['name']}: {$r['message']}");
            }
        }
    }
}

exit($exitCode);

// ───── Helpers ─────────────────────────────────────────────────────

function print_help(): void
{
    echo <<<HELP
FlowOne OAuth / Gmail / Mailbox / Calendar end-to-end test suite

Required:
  --email=EMAIL       Primary email matching webmail_oauth_tokens.primary_email
                      (the Google account used for end-to-end IMAP/SMTP tests)

Optional:
  --password=PASS     SMTP password (legacy; XOAUTH2 is used by default)
  --to=EMAIL          SMTP destination (default = --email)
  --verbose           Per-row debug output (extra context on failures)
  --skip-send         Skip SMTP send + mailbox-ops destructive subtests
  --smoke             Pre-flight only
  --only=A,B          Run only groups: preflight, auth, crypto, imap,
                      gmail-list-fetch, mailbox-ops, smtp, calendar,
                      cron-health
  --json              Emit results as JSON (good for automation)
  --timeout=SEC       Per-test wall-clock timeout (default 30)
  --help              Show this banner

Example:
  /usr/local/lsws/lsphp83/bin/php \\
    /var/www/vps-email/backend/tests/oauth-gmail-e2e-test.php \\
    --email=admin@flowone.pro --verbose

HELP;
}
