<?php

namespace Webmail\Services;

use Webmail\Addons\Reactions\Services\ReactionDetectorService;

class ImapService
{
    private $connection = null;
    private $streamConnection = null; // For OAuth stream-based connection
    private array $config;
    private string $email;
    private string $password;
    private ?string $oauthToken = null;
    private string $currentFolder = 'INBOX';
    private bool $isOAuthConnection = false;
    private int $tagCounter = 0;
    private array $lastSearchLabelFilters = [];
    private ?string $lastError = null;
    private ?int $lastMoveNewUid = null;

    // Server capabilities advertised by the IMAP server.
    // Populated from the [CAPABILITY ...] response code on the AUTHENTICATE OK
    // (most modern IMAP servers including Gmail), or via an explicit CAPABILITY
    // command as a fallback. Keys are uppercased capability names.
    private array $capabilities = [];

    // CONDSTORE state from last SELECT
    private int $currentFolderModseq = 0;
    private int $currentFolderUidnext = 0;
    private int $currentFolderUidvalidity = 0;
    private int $currentFolderExists = 0;

    private int $folderSelectCount = 0;
    private const RECONNECT_EVERY_N_FOLDERS = 20;
    private const ALLMAIL_SCAN_LIMIT = 500;

    // ===== Tiered fetch fallback (Wave 1) =====
    // The c-client backing PHP's imap_fetch_overview returns false for the
    // entire range if any single header is unparseable. To survive a single
    // corrupt header in a large folder we descend through:
    //   full-range  -> binary split  -> 50-msg chunks  -> per-UID FT_UID
    //
    // MIN_SPLIT_SIZE governs when binary splitting bottoms out into chunked
    // mode. MAX_SPLIT_DEPTH protects against pathological infinite recursion
    // if a server returns false for everything.
    private const SCAN_MIN_SPLIT_SIZE = 200;
    private const SCAN_MAX_SPLIT_DEPTH = 12;
    private const SCAN_CHUNK_SIZE = 50;

    // Memory-pressure bounds with truncation telemetry.
    private const SCAN_MAX_UID_TRACK = 100000;
    private const SCAN_MAX_BAD_UIDS_REPORTED = 500;
    private const SCAN_MAX_SEGMENTS_PENDING = 32;

    // Stages, in order of last-applied-on-success. Mirrors the structured-log
    // fallback_stage values referenced in email-life.md section 7.
    public const SCAN_STAGE_FULL_RANGE = 'full_range';
    public const SCAN_STAGE_BINARY_SPLIT = 'binary_split';
    public const SCAN_STAGE_CHUNK_50 = 'chunk_50';
    public const SCAN_STAGE_PER_UID = 'per_uid';

    /**
     * Metadata from the most recent getUidsWithTimestamps() call. Read with
     * getLastScanMeta(). Reset at the top of every scan.
     *
     * Shape:
     *   state             - healthy|degraded
     *   fallback_stage    - full_range|binary_split|chunk_50|per_uid
     *   total             - imap_num_msg result
     *   retrieved         - count of parseable UIDs returned
     *   bad_uids          - first N unparseable UIDs (capped at MAX_BAD_UIDS_REPORTED)
     *   bad_uids_truncated_count - elements dropped from bad_uids due to cap
     *   truncated         - reasons for truncation telemetry
     *   failure_reason    - human string when state != healthy
     *   segments_attempted- number of binary/chunk segments processed
     */
    private array $lastScanMeta = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastMoveNewUid(): ?int
    {
        return $this->lastMoveNewUid;
    }

    /**
     * Connect to IMAP server with credentials
     */
    public function connect(string $email, string $password): bool
    {
        $this->email = $email;
        $this->password = $password;
        $this->lastError = null;

        $server = $this->buildConnectionString('INBOX');
        
        // Suppress warnings, handle errors manually
        $this->connection = @imap_open($server, $email, $password, 0, 1);
        
        if ($this->connection === false) {
            // imap_errors() drains the error queue; capture once and expose via
            // getLastError() so callers/tests can surface the real reason
            // (e.g. AUTHENTICATIONFAILED, Connection refused) instead of just bool.
            $errors = imap_errors() ?: [];
            $alerts = function_exists('imap_alerts') ? (imap_alerts() ?: []) : [];
            $detail = trim(implode('; ', array_merge($errors, $alerts)));
            if ($detail === '') {
                $detail = 'Unknown error';
            }
            $this->lastError = $detail;
            error_log("IMAP connection failed for {$email}: {$detail}");
            return false;
        }
        
        return true;
    }
    
    /**
     * Connect to IMAP server with OAuth2 (XOAUTH2)
     * Uses raw socket connection for XOAUTH2 authentication
     */
    public function connectWithOAuth(string $email, string $accessToken): bool
    {
        $this->email = $email;
        $this->oauthToken = $accessToken;
        $host = $this->config['host'] ?? 'imap.gmail.com';
        $port = $this->config['port'] ?? 993;
        $encryption = $this->config['encryption'] ?? 'ssl';
        
        error_log("ImapService::connectWithOAuth - Connecting to {$host}:{$port} for {$email}");
        
        try {
            // Build the XOAUTH2 string
            $xoauth2 = base64_encode("user={$email}\x01auth=Bearer {$accessToken}\x01\x01");
            
            // Create SSL context
            $contextOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ];
            $context = stream_context_create($contextOptions);
            
            // Connect to IMAP server
            $protocol = $encryption === 'ssl' ? 'ssl' : 'tcp';
            $address = "{$protocol}://{$host}:{$port}";
            
            $this->streamConnection = @stream_socket_client(
                $address,
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$this->streamConnection) {
                error_log("ImapService::connectWithOAuth - Socket connection failed: $errstr ($errno)");
                return false;
            }
            
            // Set stream timeout
            stream_set_timeout($this->streamConnection, 30);
            
            // Read server greeting
            $greeting = $this->readLine();
            error_log("ImapService::connectWithOAuth - Server greeting: $greeting");
            
            if (!$greeting || strpos($greeting, '* OK') !== 0) {
                error_log("ImapService::connectWithOAuth - Invalid server greeting");
                return false;
            }
            
            // Authenticate with XOAUTH2
            $tag = $this->getNextTag();
            $this->writeLine("{$tag} AUTHENTICATE XOAUTH2 {$xoauth2}");
            
            $response = $this->readResponse($tag);
            
            if (strpos($response, "{$tag} OK") === false) {
                error_log("ImapService::connectWithOAuth - XOAUTH2 auth failed: $response");
                
                // Check for continuation request (+ challenge)
                if (strpos($response, '+ ') === 0) {
                    // Send empty response to cancel
                    $this->writeLine("");
                    $response = $this->readResponse($tag);
                }
                
                return false;
            }
            
            error_log("ImapService::connectWithOAuth - XOAUTH2 authentication successful");
            $this->isOAuthConnection = true;

            // Most modern IMAP servers (Gmail included) embed CAPABILITY in the
            // tagged OK after AUTHENTICATE. Parse it here so we can advertise
            // RFC 6851 UID MOVE / CONDSTORE etc without an extra round trip.
            $this->parseCapabilitiesFromResponse($response);
            if (empty($this->capabilities)) {
                $this->queryCapabilities();
            }

            // Select INBOX
            if (!$this->selectFolderOAuth('INBOX')) {
                error_log("ImapService::connectWithOAuth - Failed to select INBOX");
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            error_log("ImapService::connectWithOAuth - Exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get next IMAP tag
     */
    private function getNextTag(): string
    {
        $this->tagCounter++;
        return 'A' . str_pad($this->tagCounter, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Parse [CAPABILITY ...] response code from an IMAP response and cache it.
     * Safe to call repeatedly; later calls add to the existing capability set.
     */
    private function parseCapabilitiesFromResponse(string $response): void
    {
        if (preg_match('/\[CAPABILITY\s+([^\]]+)\]/i', $response, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $cap) {
                if ($cap !== '') {
                    $this->capabilities[strtoupper($cap)] = true;
                }
            }
            return;
        }
        // Also handle "* CAPABILITY ..." untagged responses (from explicit CAPABILITY command)
        if (preg_match('/\*\s+CAPABILITY\s+([^\r\n]+)/i', $response, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $cap) {
                if ($cap !== '') {
                    $this->capabilities[strtoupper($cap)] = true;
                }
            }
        }
    }

    /**
     * Send an explicit CAPABILITY command. Only used for OAuth connections when
     * the AUTHENTICATE OK did not embed the [CAPABILITY ...] response code.
     */
    private function queryCapabilities(): void
    {
        if (!$this->streamConnection) {
            return;
        }
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} CAPABILITY");
        $response = $this->readResponse($tag);
        $this->parseCapabilitiesFromResponse($response);
    }

    /**
     * Check if the IMAP server advertises a capability (e.g. 'MOVE', 'CONDSTORE').
     * Returns false for non-OAuth connections because the c-client extension does
     * not expose capabilities and callers should use the imap_* fallback paths.
     */
    public function hasCapability(string $capability): bool
    {
        return isset($this->capabilities[strtoupper($capability)]);
    }
    
    /**
     * Write line to stream
     */
    private function writeLine(string $line): void
    {
        if ($this->streamConnection) {
            fwrite($this->streamConnection, $line . "\r\n");
        }
    }
    
    /**
     * Read single line from stream
     */
    private function readLine(): ?string
    {
        if (!$this->streamConnection) {
            return null;
        }
        
        $line = fgets($this->streamConnection);
        return $line !== false ? rtrim($line, "\r\n") : null;
    }
    
    /**
     * Read full response until tag is found
     */
    private function readResponse(string $tag): string
    {
        $response = '';
        $maxLines = 1000;
        $lineCount = 0;
        
        while ($lineCount < $maxLines) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }
            
            $response .= $line . "\n";
            $lineCount++;
            
            // Check if this is the tagged response
            if (strpos($line, $tag . ' ') === 0) {
                break;
            }
            
            // Check for continuation request
            if (strpos($line, '+ ') === 0) {
                break;
            }
        }
        
        return $response;
    }
    
    /**
     * Select folder for OAuth connection with CONDSTORE extension.
     * Parses HIGHESTMODSEQ, UIDNEXT, UIDVALIDITY, EXISTS from the SELECT response.
     */
    private function selectFolderOAuth(string $folder): bool
    {
        $tag = $this->getNextTag();
        $encodedFolder = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine("{$tag} SELECT \"{$encodedFolder}\" (CONDSTORE)");
        
        $response = $this->readResponse($tag);
        
        if (strpos($response, "{$tag} OK") !== false) {
            $this->currentFolder = $folder;
            
            // Parse CONDSTORE metadata from SELECT response
            if (preg_match('/HIGHESTMODSEQ\s+(\d+)/i', $response, $m)) {
                $this->currentFolderModseq = (int)$m[1];
            } else {
                $this->currentFolderModseq = 0;
            }
            
            if (preg_match('/UIDNEXT\s+(\d+)/i', $response, $m)) {
                $this->currentFolderUidnext = (int)$m[1];
            }
            
            if (preg_match('/UIDVALIDITY\s+(\d+)/i', $response, $m)) {
                $this->currentFolderUidvalidity = (int)$m[1];
            }
            
            if (preg_match('/\*\s+(\d+)\s+EXISTS/i', $response, $m)) {
                $this->currentFolderExists = (int)$m[1];
            }
            
            return true;
        }
        
        error_log("ImapService::selectFolderOAuth - Failed to select $folder: $response");
        return false;
    }
    
    /**
     * Get the current folder's sync state from the last SELECT (CONDSTORE).
     */
    public function getFolderSyncState(): array
    {
        return [
            'uidvalidity' => $this->currentFolderUidvalidity,
            'uidnext' => $this->currentFolderUidnext,
            'highest_modseq' => $this->currentFolderModseq,
            'exists' => $this->currentFolderExists,
        ];
    }
    
    /**
     * Fetch flag changes since a given MODSEQ value (CONDSTORE / RFC 4551).
     * Returns only messages whose flags changed after the given modseq.
     * Scoped to 1:$maxUid to avoid scanning new messages fetched separately.
     *
     * Works for BOTH OAuth (XOAUTH2 raw-socket) and password (c-client) accounts.
     * For OAuth, reuses the persistent stream. For password, opens a transient
     * raw socket, runs LOGIN, executes the CONDSTORE fetch, then logs out.
     * This lets cron/poller jobs detect external (phone, Thunderbird) flag
     * changes for password accounts at the same fidelity as OAuth accounts.
     *
     * @return array{changes: array, highest_modseq: int}
     */
    public function fetchFlagChangesSince(string $folder, int $modseq, int $maxUid = 0): array
    {
        if ($modseq <= 0) {
            return ['changes' => [], 'highest_modseq' => $modseq];
        }

        if ($this->isOAuthConnection) {
            if (!$this->selectFolder($folder)) {
                return ['changes' => [], 'highest_modseq' => $modseq];
            }
            return $this->runFlagChangesQuery($folder, $modseq, $maxUid);
        }

        // Password auth: need a raw socket because c-client doesn't expose
        // CONDSTORE / CHANGEDSINCE through imap_*. Open a transient stream.
        if (empty($this->email) || empty($this->password)) {
            return ['changes' => [], 'highest_modseq' => $modseq];
        }

        $savedStream = $this->streamConnection;
        $savedTag = $this->tagCounter;
        try {
            $transient = $this->openTransientPasswordStream();
            if (!$transient) {
                return ['changes' => [], 'highest_modseq' => $modseq];
            }
            $this->streamConnection = $transient;
            $this->tagCounter = 0;

            $selectModseq = $this->selectFolderForCondstore($folder);
            if ($selectModseq === null) {
                return ['changes' => [], 'highest_modseq' => $modseq];
            }
            return $this->runFlagChangesQuery($folder, $modseq, $maxUid);
        } finally {
            if ($this->streamConnection && $this->streamConnection !== $savedStream) {
                $this->closeTransientStream($this->streamConnection);
            }
            $this->streamConnection = $savedStream;
            $this->tagCounter = $savedTag;
        }
    }

    /**
     * Run the actual UID FETCH ... CHANGEDSINCE on whatever stream is currently
     * mounted at $this->streamConnection. Assumes folder is already SELECTed.
     */
    private function runFlagChangesQuery(string $folder, int $modseq, int $maxUid): array
    {
        $uidRange = $maxUid > 0 ? "1:{$maxUid}" : '1:*';
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uidRange} (UID FLAGS MODSEQ) (CHANGEDSINCE {$modseq})");

        $response = $this->readMultilineResponse($tag);

        $changes = [];
        $newHighest = $modseq;

        if (preg_match_all('/\* \d+ FETCH \(([^)]*UID[^)]*)\)/i', $response, $blocks)) {
            foreach ($blocks[1] as $block) {
                $uid = null;
                $flags = '';
                $msgModseq = 0;

                if (preg_match('/UID\s+(\d+)/i', $block, $m)) {
                    $uid = (int)$m[1];
                }
                if (preg_match('/FLAGS\s+\(([^)]*)\)/i', $block, $m)) {
                    $flags = $m[1];
                }
                if (preg_match('/MODSEQ\s+\((\d+)\)/i', $block, $m)) {
                    $msgModseq = (int)$m[1];
                }

                if ($uid !== null) {
                    $changes[] = [
                        'uid' => $uid,
                        'seen' => stripos($flags, '\\Seen') !== false,
                        'flagged' => stripos($flags, '\\Flagged') !== false,
                        'answered' => stripos($flags, '\\Answered') !== false,
                        'deleted' => stripos($flags, '\\Deleted') !== false,
                        'modseq' => $msgModseq,
                    ];

                    if ($msgModseq > $newHighest) {
                        $newHighest = $msgModseq;
                    }
                }
            }
        }

        return [
            'changes' => $changes,
            'highest_modseq' => $newHighest,
        ];
    }

    /**
     * Open a transient raw IMAP socket authenticated via LOGIN.
     * Used by password-auth accounts that need CONDSTORE access.
     * Returns the stream resource or null on failure.
     *
     * @return resource|null
     */
    private function openTransientPasswordStream()
    {
        $host = $this->config['host'] ?? 'localhost';
        $port = (int)($this->config['port'] ?? 993);
        $encryption = $this->config['encryption'] ?? 'ssl';

        $contextOptions = [
            'ssl' => [
                'verify_peer' => (bool)($this->config['validate_cert'] ?? false),
                'verify_peer_name' => (bool)($this->config['validate_cert'] ?? false),
                'allow_self_signed' => true,
            ],
        ];
        $context = stream_context_create($contextOptions);

        $protocol = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $address = "{$protocol}://{$host}:{$port}";

        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$stream) {
            error_log("[ImapService::openTransientPasswordStream] socket connect failed: {$errstr} ({$errno})");
            return null;
        }
        stream_set_timeout($stream, 15);

        // Read greeting
        $greeting = fgets($stream);
        if ($greeting === false || strpos($greeting, '* OK') !== 0) {
            error_log("[ImapService::openTransientPasswordStream] invalid greeting: " . trim((string)$greeting));
            @fclose($stream);
            return null;
        }

        // STARTTLS upgrade if requested
        if ($encryption === 'tls') {
            $this->tagCounter = max($this->tagCounter, 0);
            $tag = 'A' . str_pad((string)(++$this->tagCounter), 4, '0', STR_PAD_LEFT);
            fwrite($stream, "{$tag} STARTTLS\r\n");
            $resp = '';
            while (($line = fgets($stream)) !== false) {
                $resp .= $line;
                if (strpos($line, $tag . ' ') === 0) break;
            }
            if (strpos($resp, "{$tag} OK") === false) {
                error_log("[ImapService::openTransientPasswordStream] STARTTLS failed");
                @fclose($stream);
                return null;
            }
            if (!@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log("[ImapService::openTransientPasswordStream] TLS upgrade failed");
                @fclose($stream);
                return null;
            }
        }

        // LOGIN with credentials. Quote literal-safely.
        $userQ = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->email) . '"';
        $passQ = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->password) . '"';
        $tag = 'A' . str_pad((string)(++$this->tagCounter), 4, '0', STR_PAD_LEFT);
        fwrite($stream, "{$tag} LOGIN {$userQ} {$passQ}\r\n");

        $resp = '';
        while (($line = fgets($stream)) !== false) {
            $resp .= $line;
            if (strpos($line, $tag . ' ') === 0) break;
        }
        if (strpos($resp, "{$tag} OK") === false) {
            error_log("[ImapService::openTransientPasswordStream] LOGIN failed: " . trim($resp));
            @fclose($stream);
            return null;
        }

        // Capture any CAPABILITY embedded in the OK so downstream code knows
        // CONDSTORE / MOVE / QRESYNC support.
        $this->parseCapabilitiesFromResponse($resp);

        return $stream;
    }

    /**
     * Cleanly LOGOUT and close a transient stream opened by
     * openTransientPasswordStream().
     *
     * @param resource $stream
     */
    private function closeTransientStream($stream): void
    {
        if (!is_resource($stream)) {
            return;
        }
        try {
            $tag = 'A' . str_pad((string)(++$this->tagCounter), 4, '0', STR_PAD_LEFT);
            @fwrite($stream, "{$tag} LOGOUT\r\n");
            // Drain to avoid RST on close.
            $deadline = microtime(true) + 1.0;
            while (microtime(true) < $deadline) {
                $line = fgets($stream);
                if ($line === false) break;
                if (strpos($line, $tag . ' ') === 0) break;
            }
        } catch (\Throwable $e) {
            // Best-effort logout; ignore.
        }
        @fclose($stream);
    }

    /**
     * SELECT a folder with CONDSTORE on the currently-mounted stream
     * ($this->streamConnection). Returns the HIGHESTMODSEQ from the SELECT
     * response, or null on failure.
     */
    private function selectFolderForCondstore(string $folder): ?int
    {
        $encodedFolder = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} SELECT \"{$encodedFolder}\" (CONDSTORE)");

        $response = $this->readMultilineResponse($tag);

        if (strpos($response, "{$tag} OK") === false) {
            return null;
        }

        if (preg_match('/HIGHESTMODSEQ\s+(\d+)/i', $response, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Build IMAP connection string
     */
    private function buildConnectionString(string $folder = 'INBOX'): string
    {
        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 143;
        $encryption = $this->config['encryption'] ?? '';
        $validateCert = $this->config['validate_cert'] ?? false;
        
        $flags = '/imap';
        
        if ($encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        
        if (!$validateCert) {
            $flags .= '/novalidate-cert';
        }
        
        return "{{$host}:{$port}{$flags}}{$folder}";
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        if ($this->isOAuthConnection) {
            return $this->streamConnection !== null && is_resource($this->streamConnection);
        }
        return $this->connection !== null && $this->connection !== false;
    }

    /**
     * Return the underlying \IMAP\Connection (or stream resource for OAuth)
     * so service-layer helpers like FolderIndexService::fingerprintProvider
     * can issue raw IMAP calls without piercing other ImapService internals.
     *
     * @return resource|\IMAP\Connection|null
     */
    public function getRawConnection()
    {
        if ($this->isOAuthConnection) {
            return $this->streamConnection;
        }
        return $this->connection;
    }

    /**
     * Close connection
     */
    public function disconnect(): void
    {
        if ($this->isOAuthConnection && $this->streamConnection) {
            $tag = $this->getNextTag();
            $this->writeLine("{$tag} LOGOUT");
            fclose($this->streamConnection);
            $this->streamConnection = null;
        }
        if ($this->connection) {
            @imap_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Reconnect to IMAP using stored credentials.
     * Returns true if reconnection succeeded.
     */
    public function reconnect(): bool
    {
        error_log("[IMAP] Reconnecting for {$this->email}");
        $this->disconnect();
        $this->folderSelectCount = 0;

        if ($this->isOAuthConnection && $this->oauthToken) {
            return $this->connectWithOAuth($this->email, $this->oauthToken);
        }

        return $this->connect($this->email, $this->password);
    }

    /**
     * Reconnect proactively to prevent stale IMAP connections.
     * Call this during bulk operations that SELECT many folders.
     * IMAP servers often drop responses after ~50 folder operations.
     */
    public function reconnectIfStale(): void
    {
        if ($this->folderSelectCount >= self::RECONNECT_EVERY_N_FOLDERS) {
            error_log("[IMAP] Proactive reconnect after {$this->folderSelectCount} folder selects");
            $this->reconnect();
        }
    }

    /**
     * Reset the folder select counter (call at start of bulk operations).
     */
    public function resetFolderSelectCounter(): void
    {
        $this->folderSelectCount = 0;
    }

    /**
     * Get list of folders with unread counts
     */
    public function listFolders(): array
    {
        if (!$this->isConnected()) {
            return [];
        }
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->listFoldersOAuth();
        }
        
        $server = $this->buildConnectionString('');

        // Wave 2: prefer imap_getmailboxes over imap_list because it returns
        // the IMAP LIST attributes (SPECIAL-USE flags, \Noselect, \HasChildren,
        // etc.) alongside the name. Fall back to imap_list when the call
        // fails so we don't break old/stripped servers.
        $boxes = @imap_getmailboxes($this->connection, $server, '*');
        if (!is_array($boxes) || empty($boxes)) {
            $folders = @imap_list($this->connection, $server, '*');
            if (!is_array($folders)) {
                return [];
            }
            $boxes = array_map(fn($f) => (object) ['name' => (string) $f, 'attributes' => 0], $folders);
        }

        $result = [];
        $serverPrefix = $server;

        foreach ($boxes as $box) {
            $rawName = (string) ($box->name ?? '');
            if ($rawName === '') {
                continue;
            }
            $folderName = str_replace($serverPrefix, '', $rawName);
            $folderName = mb_convert_encoding($folderName, 'UTF-8', 'UTF7-IMAP');

            // Preserve historical behavior: \Noselect containers don't
            // appear in the message-fetch list. The is_selectable flag still
            // emits on every row so Wave 3 tree views can show containers.
            $rawAttrs = $box->attributes ?? 0;
            if (is_int($rawAttrs) && defined('LATT_NOSELECT') && ($rawAttrs & LATT_NOSELECT)) {
                continue;
            }
            $isSelectable = 1;

            $status = @imap_status($this->connection, $rawName, SA_ALL);
            $specialUse = $this->specialUseFromAttributes($rawAttrs);

            $result[] = [
                'name' => $folderName,
                'path' => $folderName,
                'display_name' => $folderName,
                'total' => $status ? $status->messages : 0,
                'unread' => $status ? $status->unseen : 0,
                'recent' => $status ? $status->recent : 0,
                'uidnext' => $status ? $status->uidnext : 0,
                'uidvalidity' => $status ? $status->uidvalidity : 0,
                'special_use' => $specialUse,
                'attributes' => $rawAttrs,
                'is_selectable' => $isSelectable,
                'type' => $this->getFolderType($folderName, $specialUse),
                'system' => $this->isSystemFolder($folderName),
                // folder_id is populated by callers via
                // FolderIndexService::upsertFromListing(). The IMAP layer
                // emits the canonical IMAP attributes only; identity is
                // assigned at the controller boundary so a single upsert
                // pass per request handles both classic and OAuth lists.
            ];
        }

        usort($result, function($a, $b) {
            if ($a['name'] === 'INBOX') return -1;
            if ($b['name'] === 'INBOX') return 1;
            return strcasecmp($a['name'], $b['name']);
        });

        return $result;
    }
    
    /**
     * List folders for OAuth connection
     */
    private function listFoldersOAuth(): array
    {
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} LIST \"\" \"*\"");
        
        $response = $this->readResponse($tag);
        $result = [];
        
        // Parse LIST responses
        // Format: * LIST (\HasNoChildren) "/" "INBOX"
        preg_match_all('/\* LIST \(([^)]*)\) "[^"]*" "?([^"\r\n]+)"?/i', $response, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $flags = $match[1] ?? '';
            $folderName = $match[2] ?? '';
            $folderName = mb_convert_encoding($folderName, 'UTF-8', 'UTF7-IMAP');

            // Preserve historical behavior: \Noselect containers are not
            // surfaced in the message-fetch list. The new is_selectable
            // field still appears in every emitted row for forward
            // compatibility with Wave 3 tree views.
            if (stripos($flags, '\\Noselect') !== false) {
                continue;
            }
            $isSelectable = 1;

            // Wave 2: pull SPECIAL-USE tokens out of the LIST flags directly.
            $specialUse = null;
            $tokens = preg_split('/\s+/', trim($flags));
            $useFlags = ['\\Sent', '\\Drafts', '\\Trash', '\\Junk', '\\Archive', '\\All', '\\Important', '\\Flagged'];
            foreach ($tokens as $tok) {
                $tokTrim = trim($tok);
                foreach ($useFlags as $u) {
                    if (strcasecmp($tokTrim, $u) === 0) {
                        $specialUse = $u;
                        break 2;
                    }
                }
            }

            $status = $this->getFolderStatusOAuth($folderName);

            $result[] = [
                'name' => $folderName,
                'path' => $folderName,
                'display_name' => $folderName,
                'total' => $status['messages'] ?? 0,
                'unread' => $status['unseen'] ?? 0,
                'recent' => $status['recent'] ?? 0,
                'uidnext' => $status['uidnext'] ?? 0,
                'uidvalidity' => $status['uidvalidity'] ?? 0,
                'special_use' => $specialUse,
                'attributes' => $tokens,
                'is_selectable' => $isSelectable,
                'type' => $this->getFolderType($folderName, $specialUse),
                'system' => $this->isSystemFolder($folderName),
                // folder_id is populated by callers via
                // FolderIndexService::upsertFromListing().
            ];
        }
        
        // Sort: INBOX first, then alphabetically
        usort($result, function($a, $b) {
            if ($a['name'] === 'INBOX') return -1;
            if ($b['name'] === 'INBOX') return 1;
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $result;
    }
    
    /**
     * Get folder status for OAuth connection
     */
    private function getFolderStatusOAuth(string $folder): array
    {
        $encodedFolder = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} STATUS \"{$encodedFolder}\" (MESSAGES UNSEEN RECENT UIDNEXT UIDVALIDITY)");
        
        $response = $this->readResponse($tag);
        
        $status = ['messages' => 0, 'unseen' => 0, 'recent' => 0, 'uidnext' => 0, 'uidvalidity' => 0];
        
        // Parse STATUS response
        // Format: * STATUS "INBOX" (MESSAGES 1533 UNSEEN 10 RECENT 0 UIDNEXT 1534 UIDVALIDITY 12345)
        if (preg_match('/MESSAGES\s+(\d+)/i', $response, $m)) {
            $status['messages'] = (int)$m[1];
        }
        if (preg_match('/UNSEEN\s+(\d+)/i', $response, $m)) {
            $status['unseen'] = (int)$m[1];
        }
        if (preg_match('/UIDNEXT\s+(\d+)/i', $response, $m)) {
            $status['uidnext'] = (int)$m[1];
        }
        if (preg_match('/UIDVALIDITY\s+(\d+)/i', $response, $m)) {
            $status['uidvalidity'] = (int)$m[1];
        }
        if (preg_match('/RECENT\s+(\d+)/i', $response, $m)) {
            $status['recent'] = (int)$m[1];
        }
        
        return $status;
    }
    
    /**
     * Get folder status (public wrapper for both IMAP and OAuth)
     * Returns: messages, unseen, recent, uidnext, uidvalidity
     */
    public function getFolderStatus(string $folder): array
    {
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->getFolderStatusOAuth($folder);
        }
        
        // Regular IMAP connection uses PHP IMAP extension
        if (!$this->connection) {
            return ['messages' => 0, 'unseen' => 0, 'recent' => 0, 'uidnext' => 0, 'uidvalidity' => 0];
        }
        
        $server = $this->buildConnectionString($folder);
        $statusObj = @imap_status($this->connection, $server, SA_ALL);

        // imap_status() is known to flap intermittently right after a STORE
        // command (Dovecot returns the STATUS response before fully applying
        // the flag change). Retry once after a tiny pause; if it still fails,
        // return an EMPTY array so callers can distinguish "I don't know"
        // from "I confirmed this folder has zero messages". A zero-filled
        // array previously got published as "unread=0" over the WebSocket
        // and produced the "INBOX shows 2 then disappears" badge flicker.
        if (!$statusObj) {
            usleep(50000); // 50ms
            $statusObj = @imap_status($this->connection, $server, SA_ALL);
            if (!$statusObj) {
                error_log("[ImapService::getFolderStatus] imap_status() failed twice for {$folder} - returning empty array");
                return [];
            }
        }

        return [
            'messages' => $statusObj->messages ?? 0,
            'unseen' => $statusObj->unseen ?? 0,
            'recent' => $statusObj->recent ?? 0,
            'uidnext' => $statusObj->uidnext ?? 0,
            'uidvalidity' => $statusObj->uidvalidity ?? 0,
        ];
    }
    
    /**
     * Alias for getFolderStatus (for backwards compatibility)
     * Returns: messages, unseen, recent, uidnext, uidvalidity
     */
    public function getStatus(string $folder): array
    {
        return $this->getFolderStatus($folder);
    }

    /**
     * Determine folder type for icons.
     *
     * Wave 2: prefer RFC 6154 SPECIAL-USE flags advertised by the server
     * over substring matching. SPECIAL-USE is authoritative; substring is
     * a last-resort fallback that fires only when no flag is advertised.
     *
     * The original substring-based path stays correct for legacy callers
     * that don't have flag info; the bug it caused (custom folders named
     * "Customer_consent" being misclassified as "sent") is fixed by the
     * SPECIAL-USE first path.
     *
     * @param string|null $specialUse e.g. "\\Sent" / "\\Drafts" / "\\Trash" /
     *                                "\\Junk" / "\\Archive" / "\\All" /
     *                                "\\Important" / "\\Flagged"
     */
    private function getFolderType(string $name, ?string $specialUse = null): string
    {
        if ($specialUse !== null && $specialUse !== '') {
            $byFlag = $this->folderTypeFromSpecialUse($specialUse);
            if ($byFlag !== null) {
                return $byFlag;
            }
        }

        $lower = strtolower($name);

        if ($lower === 'inbox') return 'inbox';
        if (str_contains($lower, 'sent')) return 'sent';
        if (str_contains($lower, 'draft')) return 'drafts';
        if (str_contains($lower, 'trash') || str_contains($lower, 'deleted') || str_contains($lower, 'bin')) return 'trash';
        if (str_contains($lower, 'junk') || str_contains($lower, 'spam')) return 'spam';
        if (str_contains($lower, 'archive') || str_contains($lower, 'all mail')) return 'archive';
        if (str_contains($lower, 'important')) return 'important';
        if (str_contains($lower, 'starred')) return 'starred';

        return 'folder';
    }

    /**
     * Map an RFC 6154 SPECIAL-USE flag to our internal folder-type vocabulary.
     * Returns null if the flag is unrecognized so the caller can fall back
     * to substring matching.
     */
    private function folderTypeFromSpecialUse(string $flag): ?string
    {
        $normalized = strtolower(trim($flag, " \\\t\n"));
        return match ($normalized) {
            'sent' => 'sent',
            'drafts' => 'drafts',
            'trash' => 'trash',
            'junk' => 'spam',
            'archive', 'all' => 'archive',
            'important' => 'important',
            'flagged' => 'starred',
            default => null,
        };
    }

    /**
     * Extract a SPECIAL-USE flag from an IMAP attributes integer (LATT_*) or
     * a flag-string array. Returns the canonical flag (e.g. "\\Sent") or
     * null when no SPECIAL-USE attribute is set.
     *
     * imap_getmailboxes returns ->attributes as a bitmask. Provider-specific
     * extensions sometimes deliver flag tokens as strings instead; we accept
     * both shapes.
     *
     * @param int|array|null $attributes
     */
    private function specialUseFromAttributes($attributes): ?string
    {
        if (is_array($attributes)) {
            foreach ($attributes as $token) {
                if (!is_string($token)) {
                    continue;
                }
                $normalized = strtolower(ltrim($token, '\\'));
                if (in_array($normalized, ['sent', 'drafts', 'trash', 'junk', 'archive', 'all', 'important', 'flagged'], true)) {
                    return '\\' . ucfirst($normalized);
                }
            }
            return null;
        }
        if (!is_int($attributes)) {
            return null;
        }
        // PHP IMAP exposes a small set of LATT_* constants; SPECIAL-USE tokens
        // beyond \Noselect / \HasChildren / \HasNoChildren / \Marked /
        // \Unmarked are not represented by LATT_*. We check the well-known
        // ones for completeness.
        if (defined('LATT_NOSELECT') && ($attributes & LATT_NOSELECT)) {
            return null; // Container; no SPECIAL-USE meaning.
        }
        return null;
    }
    
    /**
     * Check if a folder is a system folder (cannot be deleted)
     */
    private function isSystemFolder(string $name): bool
    {
        $lower = strtolower($name);
        
        // INBOX is always a system folder
        if ($lower === 'inbox') return true;
        
        // Gmail system folders
        if (str_starts_with($lower, '[gmail]')) return true;
        
        // Common system folder names (top-level only)
        $systemFolders = ['sent', 'drafts', 'trash', 'junk', 'spam', 'archive', 'deleted items', 'sent items'];
        foreach ($systemFolders as $sys) {
            if ($lower === $sys || $lower === 'inbox.' . $sys) return true;
        }
        
        return false;
    }

    /**
     * Switch to a folder. Retries once with a fresh connection on failure.
     */
    public function selectFolder(string $folder): bool
    {
        $result = $this->selectFolderInternal($folder);
        if ($result) {
            return true;
        }

        error_log("[IMAP] selectFolder FAILED for '$folder', retrying with reconnect");
        if (!$this->reconnect()) {
            error_log("[IMAP] selectFolder reconnect FAILED for '$folder'");
            return false;
        }

        $result = $this->selectFolderInternal($folder);
        if ($result) {
            error_log("[IMAP] selectFolder retry SUCCESS for '$folder'");
            return true;
        }

        error_log("[IMAP] selectFolder PERMANENTLY FAILED for '$folder'");
        return false;
    }

    /**
     * Internal folder select (no retry). Used by selectFolder().
     */
    private function selectFolderInternal(string $folder): bool
    {
        if (!$this->isConnected()) {
            error_log("[IMAP] selectFolderInternal - not connected for '$folder'");
            return false;
        }

        $this->folderSelectCount++;

        if ($this->isOAuthConnection) {
            return $this->selectFolderOAuth($folder);
        }

        $encodedFolder = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        $server = $this->buildConnectionString($encodedFolder);

        if (@imap_reopen($this->connection, $server)) {
            $this->currentFolder = $encodedFolder;

            // Populate sync state from imap_status (native ext doesn't expose
            // CONDSTORE, but uidvalidity/uidnext/exists are available here).
            $statusObj = @imap_status($this->connection, $server, SA_ALL);
            if ($statusObj) {
                $this->currentFolderUidvalidity = $statusObj->uidvalidity ?? 0;
                $this->currentFolderUidnext = $statusObj->uidnext ?? 0;
                $this->currentFolderExists = $statusObj->messages ?? 0;
            }
            // Drain any benign IMAP errors/alerts (e.g. namespace probe
            // noise) so they don't surface as PHP shutdown notices.
            @imap_errors();
            @imap_alerts();

            return true;
        }

        $lastError = imap_last_error();
        // Drain queued imap errors/alerts BEFORE returning false — otherwise
        // they surface at request shutdown as "PHP Notice: Mailbox doesn't
        // exist: ..." and flood php_errors.log with thousands of lines per
        // cron run when the DB contains stale folder names.
        @imap_errors();
        @imap_alerts();
        error_log("[IMAP] selectFolderInternal FAILED for '$folder': " . $lastError);
        return false;
    }

    /**
     * Get unread message count for a folder
     */
    public function getUnreadCount(string $folder = 'INBOX'): int
    {
        if (!$this->selectFolder($folder)) {
            return 0;
        }
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->getUnreadCountOAuth($folder);
        }
        
        try {
            $status = imap_status($this->connection, $this->buildConnectionString($folder), SA_UNSEEN);
            if ($status) {
                return $status->unseen ?? 0;
            }
        } catch (\Exception $e) {
            error_log("ImapService::getUnreadCount error: " . $e->getMessage());
        }
        
        return 0;
    }
    
    /**
     * Get unread count for OAuth connections
     */
    private function getUnreadCountOAuth(string $folder): int
    {
        if (!$this->streamConnection) {
            return 0;
        }
        
        try {
            // Use SEARCH UNSEEN to count unread messages
            $tag = 'A' . (++$this->tagCounter);
            $command = "$tag SEARCH UNSEEN\r\n";
            fwrite($this->streamConnection, $command);
            
            $unreadCount = 0;
            while (($line = fgets($this->streamConnection)) !== false) {
                $line = trim($line);
                
                // Parse SEARCH response - it contains list of message numbers
                if (preg_match('/^\* SEARCH\s*(.*)$/i', $line, $matches)) {
                    $uids = trim($matches[1]);
                    if (!empty($uids)) {
                        $unreadCount = count(preg_split('/\s+/', $uids));
                    }
                }
                
                // Check for command completion
                if (strpos($line, "$tag OK") === 0) {
                    break;
                }
                if (strpos($line, "$tag NO") === 0 || strpos($line, "$tag BAD") === 0) {
                    break;
                }
            }
            
            return $unreadCount;
        } catch (\Exception $e) {
            error_log("ImapService::getUnreadCountOAuth error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get paginated message list
     */
    public function getMessages(string $folder, int $page = 1, int $limit = 50, string $sortBy = 'date', string $sortOrder = 'desc'): array
    {
        // Verbose per-page telemetry kept gated; the list endpoint is hit
        // on every folder open and was flooding logs.
        $verbose = (bool)($_ENV['FLOWONE_IMAP_VERBOSE'] ?? getenv('FLOWONE_IMAP_VERBOSE') ?: false);

        if (!$this->selectFolder($folder)) {
            error_log("ImapService::getMessages - selectFolder FAILED for: $folder");
            return ['messages' => [], 'total' => 0, 'page' => $page, 'pages' => 0];
        }

        if ($this->isOAuthConnection) {
            return $this->getMessagesOAuth($folder, $page, $limit, $sortBy, $sortOrder);
        }

        $total = imap_num_msg($this->connection);
        if ($verbose) {
            error_log("ImapService::getMessages - folder=$folder page=$page limit=$limit total=$total");
        }
        
        if ($total === 0) {
            return ['messages' => [], 'total' => 0, 'page' => 1, 'pages' => 0];
        }
        
        // Calculate pagination
        $pages = ceil($total / $limit);
        $page = max(1, min($page, $pages));
        
        // For newest first (desc), we count from the end
        if ($sortOrder === 'desc') {
            $start = max(1, $total - ($page * $limit) + 1);
            $end = min($total, $total - (($page - 1) * $limit));
        } else {
            $start = (($page - 1) * $limit) + 1;
            $end = min($total, $page * $limit);
        }
        
        // Fetch message numbers
        $sequence = "$start:$end";
        $overview = imap_fetch_overview($this->connection, $sequence, 0);
        
        if ($overview === false) {
            return ['messages' => [], 'total' => $total, 'page' => $page, 'pages' => $pages];
        }
        
        $messages = [];
        foreach ($overview as $msg) {
            // Fetch raw headers once so formatMessageOverview can parse a full
            // Cc list (and full To list as a safety net) - the IMAP overview
            // only carries To, so without this Cc is always empty.
            $rawHeaders = '';
            if (isset($msg->uid) && $msg->uid > 0) {
                $rawHeaders = @imap_fetchheader($this->connection, $msg->uid, FT_UID) ?: '';
            }

            $formatted = $this->formatMessageOverview($msg, $rawHeaders);

            // Check for attachments by fetching structure
            if (isset($msg->msgno) && $msg->msgno > 0) {
                $structure = @imap_fetchstructure($this->connection, $msg->msgno);
                $formatted['has_attachment'] = $this->hasAttachments($structure);

                if ($rawHeaders !== '') {
                    // Unsubscribe headers
                    $unsubInfo = $this->parseUnsubscribeHeaders($rawHeaders);
                    $formatted['unsubscribe_url'] = $unsubInfo['unsubscribe_url'];
                    $formatted['unsubscribe_email'] = $unsubInfo['unsubscribe_email'];
                    $formatted['unsubscribe_one_click'] = $unsubInfo['unsubscribe_one_click'];
                    
                    // Threading headers (In-Reply-To, References)
                    $threadingInfo = $this->parseThreadingHeaders($rawHeaders);
                    $formatted['in_reply_to'] = $threadingInfo['in_reply_to'];
                    $formatted['references'] = $threadingInfo['references'];
                }
                
                // Snippet comes from cached body or on-demand; skip expensive body fetch for list view
                $formatted['snippet'] = null;
            }
            
            $messages[] = $formatted;
        }
        
        // Sort by date descending for newest first
        if ($sortOrder === 'desc') {
            usort($messages, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
        }
        
        if ($verbose) {
            $returnedUids = array_map(fn($m) => $m['uid'] ?? 'null', $messages);
            error_log("ImapService::getMessages - returning " . count($messages) . " messages with UIDs: " . implode(', ', $returnedUids));
        }

        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
        ];
    }
    
    /**
     * Get messages since a given UID (for incremental sync)
     * Returns messages with UID > $sinceUid
     */
    public function getMessagesSince(string $folder, int $sinceUid, int $limit = 100): array
    {
        if (!$this->selectFolder($folder)) {
            return ['messages' => [], 'count' => 0, 'uidnext' => 0, 'uidvalidity' => 0, 'total' => 0];
        }
        
        // Get current folder status for UIDNEXT, UIDVALIDITY, and MESSAGES (total)
        $uidnext = 0;
        $uidvalidity = 0;
        $total = 0;
        
        if ($this->isOAuthConnection) {
            $status = $this->getFolderStatusOAuth($folder);
            $uidnext = $status['uidnext'] ?? 0;
            $uidvalidity = $status['uidvalidity'] ?? 0;
            $total = $status['messages'] ?? 0;
        } else {
            $server = $this->buildConnectionString($folder);
            $statusObj = @imap_status($this->connection, $server, SA_ALL);
            if ($statusObj) {
                $uidnext = $statusObj->uidnext ?? 0;
                $uidvalidity = $statusObj->uidvalidity ?? 0;
                $total = $statusObj->messages ?? 0;
            }
        }
        
        // If UIDNEXT hasn't changed, no new messages
        if ($uidnext <= $sinceUid) {
            return ['messages' => [], 'count' => 0, 'uidnext' => $uidnext, 'uidvalidity' => $uidvalidity, 'total' => $total];
        }
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->getMessagesSinceOAuth($folder, $sinceUid, $limit, $uidnext, $uidvalidity, $total);
        }
        
        // Search for messages with UID > sinceUid.
        //
        // Primary path: the efficient server-side UID-range search. This works
        // on Gmail and most IMAP servers.
        //
        // Fallback: some c-client builds (notably against Dovecot) silently
        // return an empty set for the "n:*" UID-range form, so the range search
        // yields nothing even when new mail exists -- which would make every
        // sync persist zero messages. When the range search comes back empty,
        // enumerate every UID with the universally-supported 'ALL' search and
        // filter to UID > sinceUid client-side.
        $searchRange = ($sinceUid + 1) . ':*';
        $uids = @imap_search($this->connection, 'UID ' . $searchRange, SE_UID);

        if ($uids === false || empty($uids)) {
            $allUids = @imap_search($this->connection, 'ALL', SE_UID);
            if (is_array($allUids) && !empty($allUids)) {
                $uids = array_values(array_filter(
                    $allUids,
                    static fn($u) => (int)$u > $sinceUid
                ));
            } else {
                $uids = [];
            }
        }

        if (empty($uids)) {
            return ['messages' => [], 'count' => 0, 'uidnext' => $uidnext, 'uidvalidity' => $uidvalidity, 'total' => $total];
        }

        // Process the oldest unseen UIDs first so repeated batches advance the
        // high-water mark incrementally without skipping a backlog. (The final
        // result is still date-sorted, newest first, below.)
        sort($uids, SORT_NUMERIC);
        $uids = array_slice($uids, 0, $limit);
        
        $messages = [];
        foreach ($uids as $uid) {
            $overview = @imap_fetch_overview($this->connection, (string)$uid, FT_UID);
            if ($overview && isset($overview[0])) {
                $msg = $overview[0];

                // Same rationale as getMessages(): fetch raw headers up front
                // so formatMessageOverview can return full To and Cc lists.
                $rawHeaders = @imap_fetchheader($this->connection, $uid, FT_UID) ?: '';

                $formatted = $this->formatMessageOverview($msg, $rawHeaders);

                // Check for attachments
                $msgno = @imap_msgno($this->connection, $uid);
                if ($msgno > 0) {
                    $structure = @imap_fetchstructure($this->connection, $msgno);
                    $formatted['has_attachment'] = $this->hasAttachments($structure);

                    if ($rawHeaders !== '') {
                        $threadingInfo = $this->parseThreadingHeaders($rawHeaders);
                        $formatted['in_reply_to'] = $threadingInfo['in_reply_to'];
                        $formatted['references'] = $threadingInfo['references'];
                    }
                    
                    $formatted['snippet'] = null;
                }
                
                $messages[] = $formatted;
            }
        }
        
        // Sort by date descending (newest first)
        usort($messages, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
        
        return [
            'messages' => $messages,
            'count' => count($messages),
            'uidnext' => $uidnext,
            'uidvalidity' => $uidvalidity,
            'total' => $total,
        ];
    }
    
    /**
     * Get messages since a given UID for OAuth connection
     */
    private function getMessagesSinceOAuth(string $folder, int $sinceUid, int $limit, int $uidnext, int $uidvalidity, int $total): array
    {
        // UID SEARCH for messages with UID > sinceUid
        $tag = $this->getNextTag();
        $searchRange = ($sinceUid + 1) . ':*';
        $this->writeLine("{$tag} UID SEARCH UID {$searchRange}");

        $response = $this->readResponse($tag);

        // Parse SEARCH response: * SEARCH 1234 1235 1236
        $uids = [];
        if (preg_match('/\* SEARCH\s+([\d\s]+)/i', $response, $m)) {
            $uids = array_map('intval', preg_split('/\s+/', trim($m[1])));
            $uids = array_filter($uids, fn($uid) => $uid > $sinceUid);
        }

        if (empty($uids)) {
            return ['messages' => [], 'count' => 0, 'uidnext' => $uidnext, 'uidvalidity' => $uidvalidity, 'total' => $total];
        }

        // Limit results
        $uids = array_slice($uids, 0, $limit);

        // Fetch headers (expanded HEADER.FIELDS, no ENVELOPE)
        $uidList = implode(',', $uids);
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uidList} (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES LIST-UNSUBSCRIBE LIST-UNSUBSCRIBE-POST)])");

        $response = $this->readMultilineResponse($tag);
        $messages = $this->parseFetchResponse($response);

        // Sort by date descending
        usort($messages, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));

        return [
            'messages' => $messages,
            'count' => count($messages),
            'uidnext' => $uidnext,
            'uidvalidity' => $uidvalidity,
            'total' => $total,
        ];
    }
    
    /**
     * Get folder status for multiple folders at once (efficient polling)
     */
    public function getMultiFolderStatus(array $folderNames): array
    {
        $result = [];
        
        foreach ($folderNames as $folderName) {
            if ($this->isOAuthConnection) {
                $status = $this->getFolderStatusOAuth($folderName);
            } else {
                $server = $this->buildConnectionString($folderName);
                $statusObj = @imap_status($this->connection, $server, SA_ALL);
                $status = [
                    'messages' => $statusObj ? $statusObj->messages : 0,
                    'unseen' => $statusObj ? $statusObj->unseen : 0,
                    'uidnext' => $statusObj ? $statusObj->uidnext : 0,
                    'uidvalidity' => $statusObj ? $statusObj->uidvalidity : 0,
                ];
            }
            
            $result[$folderName] = $status;
        }
        
        return $result;
    }
    
    /**
     * Get messages for OAuth connection.
     * Uses UID-based pagination instead of sequence numbers to handle UID gaps
     * and prevent message shift bugs when new mail arrives during pagination.
     */
    private function getMessagesOAuth(string $folder, int $page, int $limit, string $sortBy, string $sortOrder): array
    {
        // Get all UIDs via UID SEARCH ALL to determine actual UID boundaries
        $allUids = $this->searchMessagesOAuth('ALL');
        $total = count($allUids);
        
        if ($total === 0) {
            return ['messages' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'limit' => $limit];
        }
        
        // Sort UIDs ascending (they should already be, but ensure it)
        sort($allUids, SORT_NUMERIC);
        
        // Calculate pagination
        $pages = (int)ceil($total / $limit);
        $page = max(1, min($page, $pages));
        
        // Slice the UID list for the requested page
        if ($sortOrder === 'desc') {
            // Newest first: take from the end
            $offset = max(0, $total - ($page * $limit));
            $pageUids = array_slice($allUids, $offset, $limit);
            $pageUids = array_reverse($pageUids);
        } else {
            $offset = ($page - 1) * $limit;
            $pageUids = array_slice($allUids, $offset, $limit);
        }
        
        if (empty($pageUids)) {
            return ['messages' => [], 'total' => $total, 'page' => $page, 'pages' => $pages, 'limit' => $limit];
        }
        
        // UID FETCH with expanded HEADER.FIELDS (no ENVELOPE -- more reliable across servers)
        $uidList = implode(',', $pageUids);
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uidList} (UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES LIST-UNSUBSCRIBE LIST-UNSUBSCRIBE-POST)])");
        
        $response = $this->readMultilineResponse($tag);
        $messages = $this->parseFetchResponse($response);
        
        if ($sortOrder === 'desc') {
            usort($messages, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));
        }
        
        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $limit,
        ];
    }
    
    /**
     * Read multiline response for large data
     */
    private function readMultilineResponse(string $tag): string
    {
        $response = '';
        $maxLines = 5000;
        $lineCount = 0;
        
        while ($lineCount < $maxLines) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }
            
            $response .= $line . "\n";
            $lineCount++;
            
            // Check if this is the tagged response
            if (strpos($line, $tag . ' ') === 0) {
                break;
            }
        }
        
        return $response;
    }
    
    /**
     * Parse FETCH response into message array
     */
    private function parseFetchResponse(string $response): array
    {
        $messages = [];
        
        // Split response into individual message blocks
        $lines = explode("\n", $response);
        $currentMessage = [];
        $inEnvelope = false;
        $envelopeData = '';
        
        $inHeaders = false;
        $headerData = '';
        $headerLiteralSize = 0;
        $headerLiteralRead = 0;
        
        foreach ($lines as $line) {
            // Handle literal header data (after {size})
            if ($inHeaders) {
                $headerData .= $line . "\n";
                $headerLiteralRead += strlen($line) + 1;
                if ($headerLiteralRead >= $headerLiteralSize) {
                    $currentMessage['headers'] = $headerData;
                    $inHeaders = false;
                    $headerData = '';
                }
                continue;
            }
            
            // Match FETCH response line
            if (preg_match('/^\* (\d+) FETCH \(/i', $line, $m)) {
                if (!empty($currentMessage)) {
                    $messages[] = $this->formatOAuthMessage($currentMessage);
                }
                $currentMessage = [
                    'msgno' => (int)$m[1],
                    'raw' => $line,
                ];
                $inEnvelope = false;
                $inHeaders = false;
                $headerData = '';
                
                // Parse inline data
                if (preg_match('/UID (\d+)/', $line, $uid)) {
                    $currentMessage['uid'] = (int)$uid[1];
                }
                if (preg_match('/FLAGS \(([^)]*)\)/', $line, $flags)) {
                    $currentMessage['flags'] = $flags[1];
                }
                if (preg_match('/RFC822\.SIZE (\d+)/', $line, $size)) {
                    $currentMessage['size'] = (int)$size[1];
                }
                if (preg_match('/INTERNALDATE "([^"]+)"/', $line, $date)) {
                    $currentMessage['date'] = $date[1];
                }
                
                // Check for BODY[HEADER.FIELDS] literal
                if (preg_match('/BODY\[HEADER\.FIELDS[^\]]*\]\s*\{(\d+)\}/', $line, $headerMatch)) {
                    $headerLiteralSize = (int)$headerMatch[1];
                    $headerLiteralRead = 0;
                    $inHeaders = true;
                    $headerData = '';
                }
                
                // Check for BODYSTRUCTURE and detect attachments
                if (preg_match('/BODYSTRUCTURE \(/', $line)) {
                    $bsStart = strpos($line, 'BODYSTRUCTURE (') + 15;
                    $bsData = $this->extractBalancedParens(substr($line, $bsStart - 1));
                    if ($bsData !== null) {
                        $currentMessage['has_attachment'] = preg_match('/"ATTACHMENT"/i', $bsData) === 1;
                    }
                }
                
                // Check if ENVELOPE is on this line - extract it properly
                // Gmail format: ENVELOPE ("date" "subject" ((name NIL user host)) ...) BODY[...]
                if (preg_match('/ENVELOPE \(/', $line)) {
                    // Extract envelope by finding balanced parentheses
                    $envStart = strpos($line, 'ENVELOPE (') + 10; // Position after "ENVELOPE ("
                    $envelopeData = $this->extractBalancedParens(substr($line, $envStart - 1));
                    
                    if ($envelopeData !== null) {
                        // Remove outer parens
                        $envelopeData = substr($envelopeData, 1, -1);
                        // Debug: log first envelope
                        if (empty($messages)) {
                            error_log("ENVELOPE extracted: " . substr($envelopeData, 0, 300));
                        }
                        $currentMessage['envelope'] = $this->parseEnvelope($envelopeData);
                    } else {
                        // Multi-line ENVELOPE - shouldn't happen often with Gmail
                        $inEnvelope = true;
                        $envelopeData = substr($line, $envStart);
                        if (empty($messages)) {
                            error_log("ENVELOPE start (multi-line): " . substr($envelopeData, 0, 200));
                        }
                    }
                }
            } elseif ($inEnvelope) {
                $envelopeData .= ' ' . trim($line);
                // Check if envelope ends (balanced parentheses)
                if ($this->isBalancedParens($envelopeData)) {
                    $currentMessage['envelope'] = $this->parseEnvelope($envelopeData);
                    $inEnvelope = false;
                }
            } elseif (preg_match('/BODY\[HEADER\.FIELDS[^\]]*\]\s*\{(\d+)\}/', $line, $headerMatch)) {
                // BODY.PEEK header literal on separate line
                $headerLiteralSize = (int)$headerMatch[1];
                $headerLiteralRead = 0;
                $inHeaders = true;
                $headerData = '';
            }
        }
        
        // Don't forget the last message
        if (!empty($currentMessage) && isset($currentMessage['uid'])) {
            $messages[] = $this->formatOAuthMessage($currentMessage);
        }
        
        return $messages;
    }
    
    /**
     * Check if parentheses are balanced
     */
    private function isBalancedParens(string $str): bool
    {
        $count = 0;
        $inQuote = false;
        $inLiteral = false;
        $literalSize = 0;
        $literalRead = 0;
        $strLen = strlen($str);
        
        for ($i = 0; $i < $strLen; $i++) {
            $char = $str[$i];
            
            // Handle literal content (skip counting parens inside literals)
            if ($inLiteral) {
                $literalRead++;
                if ($literalRead >= $literalSize) {
                    $inLiteral = false;
                }
                continue;
            }
            
            // Check for literal start {size} (not inside quotes)
            if ($char === '{' && !$inQuote) {
                $closeBrace = strpos($str, '}', $i);
                if ($closeBrace !== false) {
                    $sizeStr = substr($str, $i + 1, $closeBrace - $i - 1);
                    if (is_numeric($sizeStr)) {
                        $literalSize = (int)$sizeStr;
                        $literalRead = 0;
                        $inLiteral = true;
                        $i = $closeBrace; // Skip to after }
                        // Skip whitespace/newline after }
                        while ($i + 1 < $strLen && ($str[$i + 1] === ' ' || $str[$i + 1] === "\n" || $str[$i + 1] === "\r")) {
                            $i++;
                        }
                        continue;
                    }
                }
            }
            
            if ($char === '"' && ($i === 0 || $str[$i-1] !== '\\')) {
                $inQuote = !$inQuote;
            }
            if (!$inQuote) {
                if ($char === '(') $count++;
                if ($char === ')') $count--;
            }
        }
        
        return $count === 0;
    }
    
    /**
     * Extract a balanced parentheses block starting at position 0
     * Returns the complete block including outer parens, or null if not balanced
     */
    private function extractBalancedParens(string $str): ?string
    {
        if (strlen($str) === 0 || $str[0] !== '(') {
            return null;
        }
        
        $count = 0;
        $inQuote = false;
        $inLiteral = false;
        $literalSize = 0;
        $literalRead = 0;
        $strLen = strlen($str);
        
        for ($i = 0; $i < $strLen; $i++) {
            $char = $str[$i];
            
            // Handle literal content (skip counting parens inside literals)
            if ($inLiteral) {
                $literalRead++;
                if ($literalRead >= $literalSize) {
                    $inLiteral = false;
                }
                continue;
            }
            
            // Check for literal start {size} (not inside quotes)
            if ($char === '{' && !$inQuote) {
                $closeBrace = strpos($str, '}', $i);
                if ($closeBrace !== false) {
                    $sizeStr = substr($str, $i + 1, $closeBrace - $i - 1);
                    if (is_numeric($sizeStr)) {
                        $literalSize = (int)$sizeStr;
                        $literalRead = 0;
                        $inLiteral = true;
                        $i = $closeBrace; // Skip to after }
                        // Skip whitespace/newline after }
                        while ($i + 1 < $strLen && ($str[$i + 1] === ' ' || $str[$i + 1] === "\n" || $str[$i + 1] === "\r")) {
                            $i++;
                        }
                        continue;
                    }
                }
            }
            
            if ($char === '"' && ($i === 0 || $str[$i-1] !== '\\')) {
                $inQuote = !$inQuote;
            }
            if (!$inQuote) {
                if ($char === '(') $count++;
                if ($char === ')') {
                    $count--;
                    if ($count === 0) {
                        // Found the matching close paren
                        return substr($str, 0, $i + 1);
                    }
                }
            }
        }
        
        // Not balanced within this string
        return null;
    }
    
    /**
     * Parse IMAP ENVELOPE structure
     */
    private function parseEnvelope(string $data): array
    {
        // ENVELOPE format: (date subject from sender reply-to to cc bcc in-reply-to message-id)
        // This parser handles: quoted strings "...", NIL, parenthesized lists (...), and literals {size}
        
        $result = [
            'date' => '',
            'subject' => '',
            'from' => '',
            'from_email' => '',
            'to' => '',
            'in_reply_to' => null,
            'message_id' => '',
        ];
        
        // Extract quoted strings, literals, and parenthesized address lists
        $parts = [];
        $current = '';
        $inQuote = false;
        $inLiteral = false;
        $literalSize = 0;
        $literalRead = 0;
        $parenDepth = 0;
        $topLevelIndex = 0;
        $dataLen = strlen($data);
        
        for ($i = 0; $i < $dataLen; $i++) {
            $char = $data[$i];
            
            // Handle literal content (after {size}) - used for subjects with special chars like Hungarian
            if ($inLiteral) {
                $current .= $char;
                $literalRead++;
                if ($literalRead >= $literalSize) {
                    // Literal complete
                    if ($parenDepth === 0) {
                        $parts[$topLevelIndex] = $current;
                        $topLevelIndex++;
                        $current = '';
                    }
                    $inLiteral = false;
                }
                continue;
            }
            
            // Check for literal start {size} at top level or in parens (not inside quotes)
            if ($char === '{' && !$inQuote) {
                // Find the closing }
                $closeBrace = strpos($data, '}', $i);
                if ($closeBrace !== false) {
                    $sizeStr = substr($data, $i + 1, $closeBrace - $i - 1);
                    if (is_numeric($sizeStr)) {
                        $literalSize = (int)$sizeStr;
                        $literalRead = 0;
                        $inLiteral = true;
                        $i = $closeBrace; // Skip to after }
                        // Skip whitespace/newline after } (literal content starts after)
                        while ($i + 1 < $dataLen && ($data[$i + 1] === ' ' || $data[$i + 1] === "\n" || $data[$i + 1] === "\r")) {
                            $i++;
                        }
                        continue;
                    }
                }
            }
            
            // Handle quote toggle (but not escaped quotes)
            if ($char === '"' && ($i === 0 || $data[$i-1] !== '\\')) {
                if (!$inQuote) {
                    // Starting a quote
                    $inQuote = true;
                    if ($parenDepth > 0) {
                        $current .= $char;
                    }
                } else {
                    // Ending a quote
                    $inQuote = false;
                    if ($parenDepth > 0) {
                        $current .= $char;
                    } else {
                        // Top-level quoted string ends
                        $parts[$topLevelIndex] = $current;
                        $topLevelIndex++;
                        $current = '';
                    }
                }
                continue;
            }
            
            // Handle content inside quotes
            if ($inQuote) {
                $current .= $char;
                continue;
            }
            
            // Handle NIL at top level
            if ($parenDepth === 0 && $char === 'N' && substr($data, $i, 3) === 'NIL') {
                $parts[$topLevelIndex] = 'NIL';
                $topLevelIndex++;
                $i += 2; // Skip 'IL'
                continue;
            }
            
            // Handle parentheses (address lists)
            if ($char === '(') {
                $parenDepth++;
                if ($parenDepth === 1) {
                    $current = '';
                } else {
                    $current .= $char;
                }
            } elseif ($char === ')') {
                $parenDepth--;
                if ($parenDepth === 0) {
                    $parts[$topLevelIndex] = '(' . $current . ')';
                    $topLevelIndex++;
                    $current = '';
                } else {
                    $current .= $char;
                }
            } elseif ($parenDepth > 0) {
                $current .= $char;
            }
            // Ignore spaces between elements at top level
        }
        
        // Debug: log parts found
        error_log("parseEnvelope: Found " . count($parts) . " parts, keys: " . implode(',', array_keys($parts)));
        foreach ($parts as $idx => $part) {
            error_log("parseEnvelope: part[$idx] = " . substr($part, 0, 80));
        }
        
        // Assign parts to envelope fields
        // Index: 0=date, 1=subject, 2=from, 3=sender, 4=reply-to, 5=to, 6=cc, 7=bcc, 8=in-reply-to, 9=message-id
        if (isset($parts[0]) && $parts[0] !== 'NIL') $result['date'] = $this->decodeImapString($parts[0]);
        if (isset($parts[1]) && $parts[1] !== 'NIL') $result['subject'] = $this->decodeImapString($parts[1]);
        
        // Parse FROM address (index 2) - format: ((name NIL mailbox host))
        if (isset($parts[2]) && $parts[2] !== 'NIL') {
            $fromParsed = $this->parseAddressList($parts[2]);
            if (!empty($fromParsed)) {
                $result['from'] = $fromParsed[0]['display'] ?? $fromParsed[0]['email'] ?? '';
                $result['from_email'] = $fromParsed[0]['email'] ?? '';
            }
        }
        
        // Parse TO address (index 5)
        if (isset($parts[5]) && $parts[5] !== 'NIL') {
            $toParsed = $this->parseAddressList($parts[5]);
            if (!empty($toParsed)) {
                $result['to'] = $toParsed[0]['display'] ?? $toParsed[0]['email'] ?? '';
            }
        }
        
        // In-Reply-To (index 8)
        if (isset($parts[8]) && $parts[8] !== 'NIL') {
            $result['in_reply_to'] = trim($this->decodeImapString($parts[8]), '<>');
        }
        
        // Message-ID (index 9)
        if (isset($parts[9]) && $parts[9] !== 'NIL') {
            $result['message_id'] = trim($this->decodeImapString($parts[9]), '<>');
        }
        
        return $result;
    }
    
    /**
     * Parse IMAP address list
     */
    private function parseAddressList(string $data): array
    {
        $addresses = [];
        
        // IMAP address list format: ((name adl mailbox host)(name adl mailbox host)...)
        // Each address is: (name adl mailbox host) where values are "quoted", NIL, or atom
        
        // Find innermost parentheses groups (addresses don't have nested parens)
        if (preg_match_all('/\(([^()]+)\)/', $data, $addrMatches)) {
            foreach ($addrMatches[1] as $addrContent) {
                // Parse the 4 space-separated parts: name, adl, mailbox, host
                // Each part is either: "quoted string", NIL, or unquoted-atom
                $parts = [];
                $remaining = trim($addrContent);
                
                for ($i = 0; $i < 4 && $remaining !== ''; $i++) {
                    $remaining = ltrim($remaining);
                    
                    if (str_starts_with($remaining, '"')) {
                        // Quoted string - find closing quote
                        if (preg_match('/^"([^"]*)"/', $remaining, $m)) {
                            $parts[] = $m[1];
                            $remaining = substr($remaining, strlen($m[0]));
                        } else {
                            break;
                        }
                    } elseif (str_starts_with($remaining, 'NIL')) {
                        $parts[] = '';
                        $remaining = substr($remaining, 3);
                    } else {
                        // Unquoted atom - read until space
                        if (preg_match('/^(\S+)/', $remaining, $m)) {
                            $parts[] = $m[1];
                            $remaining = substr($remaining, strlen($m[0]));
                        } else {
                            break;
                        }
                    }
                }
                
                // parts[0]=name, parts[1]=adl, parts[2]=mailbox, parts[3]=host
                if (count($parts) >= 4) {
                    $name = $parts[0];
                    $mailbox = $parts[2];
                    $host = $parts[3];
                    
                    $email = ($mailbox && $host) ? "{$mailbox}@{$host}" : $mailbox;
                    $displayName = $this->decodeImapString($name);
                    
                    $addresses[] = [
                        'name' => $displayName,
                        'email' => $email,
                        'display' => $displayName ? "{$displayName} <{$email}>" : $email,
                    ];
                }
            }
        }
        
        return $addresses;
    }

    
    /**
     * Decode IMAP string (handle =?UTF-8?... encoding and charset conversion)
     */
    private function decodeImapString(string $str): string
    {
        $str = trim($str);
        
        if (empty($str)) {
            return $str;
        }
        
        // Handle MIME encoded words (e.g., =?UTF-8?B?...?= or =?ISO-8859-2?Q?...?=)
        if (preg_match('/=\?[^?]+\?[BQ]\?[^?]*\?=/i', $str)) {
            $decoded = $this->decodeMimeHeader($str);
            // Ensure result is valid UTF-8
            if (!mb_check_encoding($decoded, 'UTF-8')) {
                $decoded = $this->convertToUtf8($decoded, 'ISO-8859-2');
            }
            return $decoded;
        }
        
        // Check if string is already valid UTF-8
        if (mb_check_encoding($str, 'UTF-8')) {
            // Looks like valid UTF-8, but might be misinterpreted Latin characters
            // Check for common sign of misencoding (high bytes that don't form valid UTF-8 sequences)
            if (preg_match('/[\x80-\xFF]/', $str)) {
                // Try to detect if it's really a Central European charset
                $detected = mb_detect_encoding($str, ['UTF-8', 'ISO-8859-2', 'Windows-1250', 'ISO-8859-1'], true);
                if ($detected && $detected !== 'UTF-8') {
                    return $this->convertToUtf8($str, $detected);
                }
            }
            return $str;
        }
        
        // Not valid UTF-8 - try to convert from common charsets
        // Try Hungarian/Central European charsets first
        $charsetsToTry = ['ISO-8859-2', 'Windows-1250', 'ISO-8859-1', 'UTF-8'];
        foreach ($charsetsToTry as $charset) {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT', $str);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }
        
        // Last resort - remove invalid characters
        return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
    }
    
    /**
     * Format OAuth message data to match standard format
     */
    private function formatOAuthMessage(array $data): array
    {
        $flags = $data['flags'] ?? '';
        $rawHeaders = $data['headers'] ?? '';
        $envelope = $data['envelope'] ?? [];
        
        // Parse all fields from raw headers (primary source)
        $parsedHeaders = [];
        if (!empty($rawHeaders)) {
            $parsedHeaders = $this->parseHeaders($rawHeaders);
        }
        
        // Build fields from headers, falling back to ENVELOPE if present (backward compat)
        $subject = $this->decodeMimeHeader($parsedHeaders['subject'] ?? $envelope['subject'] ?? '(No Subject)');
        $date = $parsedHeaders['date'] ?? $envelope['date'] ?? $data['date'] ?? '';
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            $timestamp = time();
        }
        
        $messageId = trim($parsedHeaders['message-id'] ?? $envelope['message_id'] ?? '', '<>');
        
        // Parse addresses from raw headers
        $fromArray = !empty($parsedHeaders['from'])
            ? $this->parseHeaderAddresses($parsedHeaders['from'])
            : [];
        $toArray = !empty($parsedHeaders['to'])
            ? $this->parseHeaderAddresses($parsedHeaders['to'])
            : [];
        $ccArray = !empty($parsedHeaders['cc'])
            ? $this->parseHeaderAddresses($parsedHeaders['cc'])
            : [];
        
        // Fallback to ENVELOPE data if headers didn't parse addresses
        if (empty($fromArray) && !empty($envelope['from_email'])) {
            $fromArray[] = [
                'name' => ($envelope['from'] ?? '') !== ($envelope['from_email'] ?? '') ? ($envelope['from'] ?? '') : '',
                'email' => $envelope['from_email'],
            ];
        }
        if (empty($toArray) && !empty($envelope['to'])) {
            $toEmail = $this->extractEmail($envelope['to']);
            $toArray[] = [
                'name' => $envelope['to'] !== $toEmail ? (preg_replace('/<[^>]+>/', '', $envelope['to']) ?? '') : '',
                'email' => $toEmail,
            ];
        }
        
        $fromName = $fromArray[0]['name'] ?? $fromArray[0]['email'] ?? '';
        $fromEmail = $fromArray[0]['email'] ?? '';
        
        // Threading and unsubscribe from raw headers
        $unsubscribeUrl = null;
        $unsubscribeEmail = null;
        $unsubscribeOneClick = false;
        $inReplyTo = null;
        $references = [];
        
        if (!empty($rawHeaders)) {
            $unsubInfo = $this->parseUnsubscribeHeaders($rawHeaders);
            $unsubscribeUrl = $unsubInfo['unsubscribe_url'];
            $unsubscribeEmail = $unsubInfo['unsubscribe_email'];
            $unsubscribeOneClick = $unsubInfo['unsubscribe_one_click'];
            
            $threadingInfo = $this->parseThreadingHeaders($rawHeaders);
            $inReplyTo = $threadingInfo['in_reply_to'];
            $references = $threadingInfo['references'] ?? [];
        }
        
        if (empty($inReplyTo) && !empty($envelope['in_reply_to'])) {
            $inReplyTo = $envelope['in_reply_to'];
        }
        
        return [
            'uid' => $data['uid'] ?? 0,
            'msgno' => $data['msgno'] ?? 0,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'subject' => $subject,
            'from' => $fromArray,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'to' => $toArray,
            'cc' => $ccArray,
            'date' => $date,
            'timestamp' => $timestamp,
            'size' => $data['size'] ?? 0,
            'seen' => stripos($flags, '\\Seen') !== false,
            'flagged' => stripos($flags, '\\Flagged') !== false,
            'answered' => stripos($flags, '\\Answered') !== false,
            'deleted' => stripos($flags, '\\Deleted') !== false,
            'draft' => stripos($flags, '\\Draft') !== false,
            'important' => $this->isHighImportance($parsedHeaders),
            'has_attachment' => $data['has_attachment'] ?? false,
            'unsubscribe_url' => $unsubscribeUrl,
            'unsubscribe_email' => $unsubscribeEmail,
            'unsubscribe_one_click' => $unsubscribeOneClick,
        ];
    }

    /**
     * Format message overview for list display.
     *
     * IMPORTANT: parses ALL recipients in To and Cc, not just the first one.
     * The frontend caches list-fetch results onto the same object as single-
     * message fetches via upsertMessage(); a truncated to/cc here will clobber
     * the rich arrays from the single-message endpoint and break Reply-All.
     *
     * @param object $msg       imap_fetch_overview row
     * @param string $rawHeaders Optional raw header block (for Cc, which the
     *                          IMAP overview does not include)
     */
    private function formatMessageOverview($msg, string $rawHeaders = ''): array
    {
        // Get message_id and normalize it (strip angle brackets)
        $messageId = '';
        if (!empty($msg->message_id)) {
            $messageId = trim($msg->message_id, '<>');
        }
        
        // If still empty, try fetching the header directly
        if (empty($messageId) && isset($msg->msgno) && $this->connection) {
            try {
                $header = imap_fetchheader($this->connection, $msg->msgno);
                if (preg_match('/^Message-ID:\s*<?([^>\s]+)>?\s*$/im', $header, $matches)) {
                    $messageId = $matches[1];
                }
            } catch (\Exception $e) {
                // Ignore errors, message_id will remain empty
            }
        }
        
        // Decode fields
        $fromStr = isset($msg->from) ? $this->decodeMimeHeader($msg->from) : '';
        $fromEmail = $this->extractEmail($msg->from ?? '');
        $toStr = isset($msg->to) ? $this->decodeMimeHeader($msg->to) : '';

        // Format from as array for frontend compatibility
        $fromArray = [];
        if (!empty($fromEmail)) {
            $fromName = $fromStr !== $fromEmail ? trim(preg_replace('/<[^>]+>/', '', $fromStr) ?? '') : '';
            $fromArray[] = [
                'name' => $fromName,
                'email' => $fromEmail,
            ];
        }
        
        // Parse the FULL recipient list from the overview's To header string
        // (parseHeaderAddresses splits on commas while respecting quoted names).
        $toArray = !empty($toStr) ? $this->parseHeaderAddresses($toStr) : [];

        // Cc is not part of the IMAP overview. If callers supplied raw headers
        // (most list paths fetch them anyway for unsubscribe/threading), parse
        // them here so cached entries carry the full Cc list. Without this,
        // Reply-All on cached threads would silently drop Cc recipients.
        $ccArray = [];
        $important = false;
        if ($rawHeaders !== '') {
            $parsedHeaders = $this->parseHeaders($rawHeaders);
            if (!empty($parsedHeaders['cc'])) {
                $ccArray = $this->parseHeaderAddresses($parsedHeaders['cc']);
            }
            if (empty($toArray) && !empty($parsedHeaders['to'])) {
                $toArray = $this->parseHeaderAddresses($parsedHeaders['to']);
            }
            // Detect sender-set high importance (Outlook etc.) for the list badge.
            $important = $this->isHighImportance($parsedHeaders);
        }
        
        return [
            'uid' => $msg->uid,
            'msgno' => $msg->msgno,
            'message_id' => $messageId, // Unique Message-ID header for labels
            'in_reply_to' => null, // Will be populated when fetching headers
            'references' => [], // Will be populated when fetching headers
            'subject' => isset($msg->subject) ? $this->decodeMimeHeader($msg->subject) : '(No Subject)',
            'from' => $fromArray,
            'from_name' => $fromStr,
            'from_email' => $fromEmail,
            'to' => $toArray,
            'cc' => $ccArray,
            'date' => $msg->date ?? '',
            'timestamp' => isset($msg->udate) ? $msg->udate : strtotime($msg->date ?? 'now'),
            'size' => $msg->size ?? 0,
            'seen' => (bool)($msg->seen ?? false),
            'flagged' => (bool)($msg->flagged ?? false),
            'answered' => (bool)($msg->answered ?? false),
            'deleted' => (bool)($msg->deleted ?? false),
            'draft' => (bool)($msg->draft ?? false),
            'important' => $important,
            'has_attachment' => false, // Will be determined when viewing full message
            'unsubscribe_url' => null,
            'unsubscribe_email' => null,
            'unsubscribe_one_click' => false,
        ];
    }
    
    /**
     * Parse threading headers (In-Reply-To, References) from raw headers
     */
    private function parseThreadingHeaders(string $rawHeaders): array
    {
        $result = [
            'in_reply_to' => null,
            'references' => []
        ];
        
        // Parse In-Reply-To header (single Message-ID)
        if (preg_match('/^In-Reply-To:\s*<?([^>\s\r\n]+)>?\s*$/im', $rawHeaders, $matches)) {
            $result['in_reply_to'] = trim($matches[1], '<>');
        }
        
        // Parse References header (space/newline separated list of Message-IDs)
        // References can span multiple lines with folding whitespace
        if (preg_match('/^References:\s*(.*?)(?=^[^\s]|\z)/ims', $rawHeaders, $matches)) {
            $refsString = $matches[1];
            // Extract all Message-IDs from the References header
            if (preg_match_all('/<([^>]+)>/', $refsString, $refMatches)) {
                $result['references'] = $refMatches[1];
            }
        }
        
        return $result;
    }

    /**
     * Get full message by UID
     */
    public function getMessage(string $folder, int $uid): ?array
    {
        // Per-call telemetry. Off by default - this fires for every body
        // open in a busy mailbox and was dominating php_errors.log without
        // surfacing real bugs. Set FLOWONE_IMAP_VERBOSE=1 to re-enable
        // when diagnosing a specific UID resolution problem.
        $verbose = (bool)($_ENV['FLOWONE_IMAP_VERBOSE'] ?? getenv('FLOWONE_IMAP_VERBOSE') ?: false);

        if (!$this->selectFolder($folder)) {
            error_log("ImapService::getMessage - selectFolder FAILED for: $folder uid=$uid");
            return null;
        }

        if ($this->isOAuthConnection) {
            return $this->getMessageOAuth($folder, $uid);
        }

        $total = imap_num_msg($this->connection);
        $msgno = imap_msgno($this->connection, $uid);

        if ($msgno === 0) {
            // This is the one case worth logging unconditionally: the
            // caller asked for a UID that the server doesn't have.
            // That's exactly the stale-mirror class of bug the sync
            // engine is supposed to clean up, so leaving the breadcrumb
            // is useful even in steady state.
            error_log("ImapService::getMessage - UID not found folder=$folder uid=$uid total=$total");
            if ($verbose && $total > 0) {
                $overview = @imap_fetch_overview($this->connection, "1:5", 0);
                if ($overview) {
                    $sampleUids = array_map(fn($m) => $m->uid ?? 'null', $overview);
                    error_log("ImapService::getMessage - Sample UIDs in folder: " . implode(', ', $sampleUids));
                }
            }
            return null;
        }

        if ($verbose) {
            error_log("ImapService::getMessage - folder=$folder uid=$uid msgno=$msgno total=$total");
        }
        
        $header = imap_headerinfo($this->connection, $msgno);
        $structure = imap_fetchstructure($this->connection, $uid, FT_UID);
        
        if (!$header || !$structure) {
            return null;
        }
        
        // Parse body and attachments
        $body = $this->getBody($uid, $structure);
        $attachments = $this->getAttachments($uid, $structure);
        
        // Handle CID (Content-ID) embedded images in HTML
        if (!empty($body['html'])) {
            $inlineImages = $this->getInlineImages($uid, $structure);
            $body['html'] = $this->replaceCidReferences($body['html'], $inlineImages);
        }
        
        // Normalize message_id (strip angle brackets)
        $messageId = isset($header->message_id) ? trim($header->message_id, '<>') : '';
        
        // Check for linked account header
        $linkedAccount = $this->getCustomHeader($uid, 'X-Linked-Account');
        $autoLabel = $this->getCustomHeader($uid, 'X-Auto-Label');

        // Detect sender-set high importance from the raw headers.
        $importantMsg = $this->isHighImportance(
            $this->parseHeaders(@imap_fetchheader($this->connection, $uid, FT_UID) ?: '')
        );
        
        // Get unsubscribe info
        $unsubscribeInfo = $this->getUnsubscribeInfo($uid);
        
        // Get spam info
        $spamInfo = $this->getSpamInfo($uid);
        
        // Detect if this is a reaction email from Gmail/Outlook
        $subject = isset($header->subject) ? $this->decodeMimeHeader($header->subject) : '(No Subject)';
        $fromList = $this->formatAddressList($header->from ?? []);
        $fromName = $fromList[0]['name'] ?? $fromList[0]['email'] ?? '';
        $fromEmail = $fromList[0]['email'] ?? '';
        $inReplyTo = isset($header->in_reply_to) ? trim($header->in_reply_to, '<>') : null;
        $reactionInfo = $this->detectReactionEmail(
            $subject,
            $body['html'] ?? '',
            $body['text'] ?? '',
            $inReplyTo,
            $fromName,
            $fromEmail
        );
        
        return array_merge([
            'uid' => $uid,
            'msgno' => $msgno,
            'message_id' => $messageId, // Unique Message-ID header for labels
            'subject' => $subject,
            'from' => $fromList,
            'to' => $this->formatAddressList($header->to ?? []),
            'cc' => $this->formatAddressList($header->cc ?? []),
            'bcc' => $this->formatAddressList($header->bcc ?? []),
            'reply_to' => $this->formatAddressList($header->reply_to ?? []),
            'date' => $header->date ?? '',
            'timestamp' => isset($header->udate) ? $header->udate : strtotime($header->date ?? 'now'),
            'size' => $header->Size ?? 0,
            'seen' => trim($header->Seen ?? '') === 'S',
            'flagged' => trim($header->Flagged ?? '') === 'F',
            'answered' => trim($header->Answered ?? '') === 'A',
            'important' => $importantMsg,
            'body_html' => $body['html'] ?? '',
            'body_text' => $body['text'] ?? '',
            'body_calendar' => $body['calendar'] ?? '',
            'attachments' => $attachments,
            'has_attachment' => count($attachments) > 0,
            'linked_account' => $linkedAccount,
            'auto_label' => $autoLabel,
            'unsubscribe_url' => $unsubscribeInfo['unsubscribe_url'],
            'unsubscribe_email' => $unsubscribeInfo['unsubscribe_email'],
            'unsubscribe_one_click' => $unsubscribeInfo['unsubscribe_one_click'],
            'spam_score' => $spamInfo['spam_score'],
            'spam_threshold' => $spamInfo['spam_threshold'],
            'spam_flag' => $spamInfo['spam_flag'],
            'spam_tests' => $spamInfo['spam_tests'],
        ], $reactionInfo);
    }
    
    /**
     * Get message for OAuth connection
     */
    private function getMessageOAuth(string $folder, int $uid): ?array
    {
        // Fetch full message (headers + body)
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uid} (FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[])");
        
        // Read response with body
        $response = '';
        $inLiteral = false;
        $literalSize = 0;
        $literalRead = 0;
        $bodyContent = '';
        
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }
            
            // Check for literal notation {size}
            if (!$inLiteral && preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                $literalSize = (int)$m[1];
                $inLiteral = true;
                $response .= $line . "\n";
                continue;
            }
            
            if ($inLiteral) {
                // Read literal data
                $bodyContent .= $line . "\n";
                $literalRead += strlen($line) + 1;
                
                if ($literalRead >= $literalSize) {
                    $inLiteral = false;
                }
            } else {
                $response .= $line . "\n";
            }
            
            // Check if this is the tagged response
            if (strpos($line, $tag . ' ') === 0) {
                break;
            }
        }
        
        if (empty($bodyContent)) {
            error_log("ImapService::getMessageOAuth - No message body retrieved for UID $uid");
            return null;
        }
        
        // Clean up body content - remove any trailing IMAP response data
        // This can happen when the literal size tracking isn't exact
        // Note: Using ?? $bodyContent to preserve value if preg_replace fails (returns null on error)
        
        // Remove various patterns of IMAP metadata that can leak into the body
        // Pattern 1: INTERNALDATE at the end (with or without complete FLAGS)
        $bodyContent = preg_replace('/\s*INTERNALDATE\s+"[^"]*".*$/s', '', $bodyContent) ?? $bodyContent;
        
        // Pattern 2: ) * NNNN FETCH (... pattern
        $bodyContent = preg_replace('/\)\s*\*\s*\d+\s+FETCH\s+\(.*$/s', '', $bodyContent) ?? $bodyContent;
        
        // Pattern 3: ) A0004 OK Success or similar tagged response
        $bodyContent = preg_replace('/\)\s*[A-Z]\d+\s+OK\s+.*$/s', '', $bodyContent) ?? $bodyContent;
        
        // Pattern 4: FLAGS (\Seen) alone or with incomplete data
        $bodyContent = preg_replace('/\s*FLAGS\s+\(.*$/s', '', $bodyContent) ?? $bodyContent;
        
        // Pattern 5: UID NNNN FLAGS patterns
        $bodyContent = preg_replace('/\s*UID\s+\d+\s+FLAGS\s+\(.*$/s', '', $bodyContent) ?? $bodyContent;
        
        // Pattern 6: RFC822.SIZE NNNN patterns
        $bodyContent = preg_replace('/\s*RFC822\.SIZE\s+\d+.*$/s', '', $bodyContent) ?? $bodyContent;
        
        // Pattern 7: Standalone * NNNN FETCH lines
        $bodyContent = preg_replace('/\s*\*\s*\d+\s+FETCH\s+.*$/s', '', $bodyContent) ?? $bodyContent;
        
        // Pattern 8: Trailing closing parentheses with whitespace
        $bodyContent = preg_replace('/\)\s*\)\s*$/s', '', $bodyContent) ?? $bodyContent;
        
        // Trim trailing whitespace
        $bodyContent = rtrim($bodyContent ?? '');
        
        // Remove single trailing closing parenthesis if present
        $bodyContent = preg_replace('/\)\s*$/', '', $bodyContent) ?? $bodyContent;
        
        // Parse headers and body
        $headerEnd = strpos($bodyContent, "\r\n\r\n");
        if ($headerEnd === false) {
            $headerEnd = strpos($bodyContent, "\n\n");
        }
        
        $rawHeaders = $headerEnd !== false ? substr($bodyContent, 0, $headerEnd) : $bodyContent;
        $rawBody = $headerEnd !== false ? substr($bodyContent, $headerEnd + 2) : '';
        
        // Parse headers
        $headers = $this->parseHeaders($rawHeaders);
        
        // Get flags from response
        $flags = '';
        if (preg_match('/FLAGS \(([^)]*)\)/', $response, $m)) {
            $flags = $m[1];
        }
        
        // Get date from response
        $date = $headers['date'] ?? '';
        if (preg_match('/INTERNALDATE "([^"]+)"/', $response, $m)) {
            $date = $date ?: $m[1];
        }
        
        // Get size from response
        $size = 0;
        if (preg_match('/RFC822\.SIZE (\d+)/', $response, $m)) {
            $size = (int)$m[1];
        }
        
        // Parse body content (handle multipart)
        $body = $this->parseBodyContent($rawBody, $headers);
        
        // Final cleanup: Remove any IMAP metadata that leaked into body content
        $bodyHtml = $this->cleanImapMetadataFromBody($body['html'] ?? '');
        $bodyText = $this->cleanImapMetadataFromBody($body['text'] ?? '');
        $bodyCalendar = $body['calendar'] ?? '';
        
        // Parse attachments
        $attachments = $this->parseAttachmentsFromContent($rawBody, $headers);
        
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            $timestamp = time();
        }
        
        // Parse unsubscribe headers
        $unsubscribeInfo = $this->parseUnsubscribeHeaders($rawHeaders);
        
        // Parse spam headers
        $spamInfo = $this->parseSpamHeaders($rawHeaders);
        
        // Detect if this is a reaction email from Gmail/Outlook
        $subject = $this->decodeMimeHeader($headers['subject'] ?? '(No Subject)');
        $fromList = $this->parseHeaderAddresses($headers['from'] ?? '');
        $fromName = $fromList[0]['name'] ?? $fromList[0]['email'] ?? '';
        $fromEmail = $fromList[0]['email'] ?? '';
        $inReplyTo = isset($headers['in-reply-to']) ? trim($headers['in-reply-to'], '<>') : null;
        $reactionInfo = $this->detectReactionEmail(
            $subject,
            $bodyHtml,
            $bodyText,
            $inReplyTo,
            $fromName,
            $fromEmail
        );
        
        return array_merge([
            'uid' => $uid,
            'msgno' => 0,
            'message_id' => trim($headers['message-id'] ?? '', '<>'),
            'subject' => $subject,
            'from' => $fromList,
            'to' => $this->parseHeaderAddresses($headers['to'] ?? ''),
            'cc' => $this->parseHeaderAddresses($headers['cc'] ?? ''),
            'bcc' => [],
            'reply_to' => $this->parseHeaderAddresses($headers['reply-to'] ?? $headers['from'] ?? ''),
            'date' => $date,
            'timestamp' => $timestamp,
            'size' => $size,
            'seen' => stripos($flags, '\\Seen') !== false,
            'flagged' => stripos($flags, '\\Flagged') !== false,
            'answered' => stripos($flags, '\\Answered') !== false,
            'important' => $this->isHighImportance($headers),
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'body_calendar' => $bodyCalendar,
            'attachments' => $attachments,
            'has_attachment' => count($attachments) > 0,
            'linked_account' => null,
            'auto_label' => null,
            'unsubscribe_url' => $unsubscribeInfo['unsubscribe_url'],
            'unsubscribe_email' => $unsubscribeInfo['unsubscribe_email'],
            'unsubscribe_one_click' => $unsubscribeInfo['unsubscribe_one_click'],
            'spam_score' => $spamInfo['spam_score'],
            'spam_threshold' => $spamInfo['spam_threshold'],
            'spam_flag' => $spamInfo['spam_flag'],
            'spam_tests' => $spamInfo['spam_tests'],
        ], $reactionInfo);
    }
    
    /**
     * Clean IMAP metadata that may have leaked into body content
     */
    private function cleanImapMetadataFromBody(string $content): string
    {
        if (empty($content)) {
            return $content;
        }
        
        // Remove IMAP response patterns from end of body
        $patterns = [
            // INTERNALDATE with or without FLAGS
            '/\s*INTERNALDATE\s+"[^"]*".*$/s',
            // FLAGS pattern
            '/\s*FLAGS\s+\([^)]*\)?.*$/s',
            // UID FETCH patterns
            '/\s*UID\s+\d+\s+FLAGS\s+\([^)]*\)?.*$/s',
            // * NNNN FETCH patterns
            '/\s*\*\s*\d+\s+FETCH\s+\(.*$/s',
            // Tagged OK responses
            '/\s*[A-Z]\d+\s+OK\s+(Success|Completed|Done).*$/si',
            // RFC822.SIZE
            '/\s*RFC822\.SIZE\s+\d+.*$/s',
            // Trailing closing parentheses
            '/\)\s*\)\s*$/s',
        ];
        
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content) ?? $content;
        }
        
        return rtrim($content ?? '');
    }
    
    /**
     * Parse raw email headers
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $currentHeader = '';
        $currentValue = '';
        
        $lines = preg_split('/\r?\n/', $rawHeaders);
        
        foreach ($lines as $line) {
            // Continuation line (starts with whitespace)
            if (preg_match('/^\s+/', $line)) {
                $currentValue .= ' ' . trim($line);
            } elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
                // Save previous header
                if ($currentHeader) {
                    $headers[strtolower($currentHeader)] = $currentValue;
                }
                $currentHeader = $m[1];
                $currentValue = $m[2];
            }
        }
        
        // Save last header
        if ($currentHeader) {
            $headers[strtolower($currentHeader)] = $currentValue;
        }
        
        return $headers;
    }

    /**
     * Decide whether a parsed header map (lowercased keys, from parseHeaders())
     * represents a sender-set high-importance message. Mirrors the header set
     * written by SmtpService::applyImportance().
     */
    private function isHighImportance(array $parsedHeaders): bool
    {
        if (strtolower(trim($parsedHeaders['importance'] ?? '')) === 'high') {
            return true;
        }
        $xPriority = trim($parsedHeaders['x-priority'] ?? '');
        if ($xPriority !== '' && ($xPriority[0] === '1' || $xPriority[0] === '2')) {
            return true;
        }
        if (strtolower(trim($parsedHeaders['priority'] ?? '')) === 'urgent') {
            return true;
        }
        if (strtolower(trim($parsedHeaders['x-msmail-priority'] ?? '')) === 'high') {
            return true;
        }
        return false;
    }
    
    /**
     * Parse header address string into array format
     */
    private function parseHeaderAddresses(string $addressStr): array
    {
        $addresses = [];
        $addressStr = trim($addressStr);
        
        if (empty($addressStr)) {
            return $addresses;
        }
        
        // Handle multiple addresses separated by comma
        $parts = preg_split('/,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $addressStr);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            // Format: "Name" <email@example.com> or email@example.com
            if (preg_match('/^"?([^"<]*)"?\s*<([^>]+)>/', $part, $m)) {
                $name = trim($m[1]);
                $email = trim($m[2]);
                $name = $this->decodeMimeHeader($name);
            } elseif (preg_match('/^([^<]+)<([^>]+)>/', $part, $m)) {
                $name = trim($m[1]);
                $email = trim($m[2]);
                $name = $this->decodeMimeHeader($name);
            } else {
                $email = $part;
                $name = '';
            }
            
            $addresses[] = [
                'email' => $email,
                'name' => $name,
                'display' => $name ? "{$name} <{$email}>" : $email,
            ];
        }
        
        return $addresses;
    }
    
    /**
     * Parse body content for OAuth
     */
    private function parseBodyContent(string $rawBody, array $headers): array
    {
        $result = ['html' => '', 'text' => '', 'calendar' => ''];
        
        $contentType = $headers['content-type'] ?? 'text/plain';
        
        // Check if multipart
        if (stripos($contentType, 'multipart') !== false) {
            // Extract boundary
            if (preg_match('/boundary=["\']?([^"\';\s]+)["\']?/i', $contentType, $m)) {
                $boundary = $m[1];
                $parts = $this->parseMultipart($rawBody, $boundary);
                
                foreach ($parts as $part) {
                    $partContentType = strtolower($part['content-type'] ?? 'text/plain');
                    $partContent = $part['body'] ?? '';
                    
                    // Handle nested multipart
                    if (stripos($partContentType, 'multipart') !== false) {
                        if (preg_match('/boundary=["\']?([^"\';\s]+)["\']?/i', $partContentType, $m2)) {
                            $nestedParts = $this->parseMultipart($partContent, $m2[1]);
                            foreach ($nestedParts as $nested) {
                                $nestedType = strtolower($nested['content-type'] ?? '');
                                if (stripos($nestedType, 'text/html') !== false && empty($result['html'])) {
                                    $result['html'] = $this->decodePartContent($nested['body'] ?? '', $nested);
                                } elseif (stripos($nestedType, 'text/plain') !== false && empty($result['text'])) {
                                    $result['text'] = $this->decodePartContent($nested['body'] ?? '', $nested);
                                } elseif (stripos($nestedType, 'text/calendar') !== false && empty($result['calendar'])) {
                                    $result['calendar'] = $this->decodePartContent($nested['body'] ?? '', $nested);
                                }
                            }
                        }
                    } elseif (stripos($partContentType, 'text/html') !== false && empty($result['html'])) {
                        $result['html'] = $this->decodePartContent($partContent, $part);
                    } elseif (stripos($partContentType, 'text/plain') !== false && empty($result['text'])) {
                        $result['text'] = $this->decodePartContent($partContent, $part);
                    } elseif (stripos($partContentType, 'text/calendar') !== false && empty($result['calendar'])) {
                        $result['calendar'] = $this->decodePartContent($partContent, $part);
                    }
                }
            }
        } else {
            // Single part message
            $decoded = $this->decodePartContent($rawBody, $headers);
            
            if (stripos($contentType, 'text/html') !== false) {
                $result['html'] = $decoded;
            } elseif (stripos($contentType, 'text/calendar') !== false) {
                $result['calendar'] = $decoded;
            } else {
                $result['text'] = $decoded;
            }
        }
        
        return $result;
    }
    
    /**
     * Parse multipart message
     */
    private function parseMultipart(string $body, string $boundary): array
    {
        $parts = [];
        
        // Split by boundary
        $segments = preg_split('/--' . preg_quote($boundary, '/') . '/s', $body);
        
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (empty($segment) || $segment === '--') {
                continue;
            }
            
            // Split headers from body. Track the separator length so the body
            // offset is correct: "\r\n\r\n" is 4 bytes, "\n\n" is 2. Using a
            // hard-coded +2 for the CRLF case left a stray "\r\n" at the start
            // of every part, injecting blank lines / corrupting decoded text.
            $sep = "\r\n\r\n";
            $headerEnd = strpos($segment, $sep);
            if ($headerEnd === false) {
                $sep = "\n\n";
                $headerEnd = strpos($segment, $sep);
            }
            
            if ($headerEnd === false) {
                continue;
            }
            
            $partHeaders = $this->parseHeaders(substr($segment, 0, $headerEnd));
            $partBody = substr($segment, $headerEnd + strlen($sep));
            
            $parts[] = array_merge($partHeaders, ['body' => $partBody]);
        }
        
        return $parts;
    }
    
    /**
     * Decode part content based on encoding
     */
    private function decodePartContent(string $content, array $part): string
    {
        $encoding = strtolower($part['content-transfer-encoding'] ?? '');
        
        // First decode the transfer encoding
        if (strpos($encoding, 'base64') !== false) {
            $content = base64_decode($content);
        } elseif (strpos($encoding, 'quoted-printable') !== false) {
            // Fix soft line breaks in quoted-printable before decoding
            $content = str_replace("=\r\n", '', $content);
            $content = str_replace("=\n", '', $content);
            $content = quoted_printable_decode($content);
        }
        
        // Handle charset conversion
        $contentType = $part['content-type'] ?? '';
        $charset = 'UTF-8'; // Default
        
        if (preg_match('/charset=["\']?([^"\';\s]+)["\']?/i', $contentType, $m)) {
            $charset = strtoupper(trim($m[1]));
        }
        
        // Convert to UTF-8 if needed
        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            $content = $this->convertToUtf8($content, $charset);
        } else {
            // Ensure valid UTF-8 even if already marked as UTF-8
            if (!mb_check_encoding($content, 'UTF-8')) {
                // Try to fix invalid UTF-8 by detecting actual encoding
                $detected = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-2', 'ISO-8859-1', 'Windows-1250'], true);
                if ($detected && $detected !== 'UTF-8') {
                    $content = mb_convert_encoding($content, 'UTF-8', $detected);
                } else {
                    // Remove invalid UTF-8 sequences
                    $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
                }
            }
        }
        
        return $content;
    }
    
    /**
     * Parse attachments from message content
     */
    private function parseAttachmentsFromContent(string $rawBody, array $headers): array
    {
        $attachments = [];
        
        $contentType = $headers['content-type'] ?? 'text/plain';
        
        if (stripos($contentType, 'multipart') === false) {
            return $attachments;
        }
        
        if (!preg_match('/boundary=["\']?([^"\';\s]+)["\']?/i', $contentType, $m)) {
            return $attachments;
        }
        
        $boundary = $m[1];
        $parts = $this->parseMultipart($rawBody, $boundary);
        $partIndex = 1;
        
        foreach ($parts as $part) {
            $partContentType = strtolower($part['content-type'] ?? '');
            $disposition = $part['content-disposition'] ?? '';
            
            // Check if attachment
            $isAttachment = stripos($disposition, 'attachment') !== false;
            $filename = '';
            
            // Get filename from disposition or content-type
            if (preg_match('/filename=["\']?([^"\';\s]+)["\']?/i', $disposition, $fm)) {
                $filename = $this->decodeMimeHeader($fm[1]);
                $isAttachment = true;
            } elseif (preg_match('/name=["\']?([^"\';\s]+)["\']?/i', $partContentType, $fm)) {
                $filename = $this->decodeMimeHeader($fm[1]);
                if (stripos($partContentType, 'text/plain') === false && 
                    stripos($partContentType, 'text/html') === false) {
                    $isAttachment = true;
                }
            }
            
            if ($isAttachment && $filename) {
                $attachments[] = [
                    'part' => (string)$partIndex,
                    'filename' => $filename,
                    'size' => strlen($part['body'] ?? ''),
                    'type' => preg_replace('/;.*/', '', $partContentType ?? '') ?? $partContentType ?? '',
                    'encoding' => $part['content-transfer-encoding'] ?? '',
                ];
            }
            
            $partIndex++;
        }
        
        return $attachments;
    }
    
    /**
     * Get a custom header value from message
     */
    private function getCustomHeader(int $uid, string $headerName): ?string
    {
        $rawHeaders = @imap_fetchheader($this->connection, $uid, FT_UID);
        if (!$rawHeaders) {
            return null;
        }
        
        // Search for the header (case-insensitive)
        $pattern = '/^' . preg_quote($headerName, '/') . ':\s*(.+)$/mi';
        if (preg_match($pattern, $rawHeaders, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Parse List-Unsubscribe headers from raw email headers
     * Returns array with: url (https), email (mailto), one_click (bool)
     */
    private function parseUnsubscribeHeaders(string $rawHeaders): array
    {
        $result = [
            'unsubscribe_url' => null,
            'unsubscribe_email' => null,
            'unsubscribe_one_click' => false,
        ];
        
        // Look for List-Unsubscribe header
        // Format: <mailto:unsub@example.com>, <https://example.com/unsub?id=123>
        if (preg_match('/^List-Unsubscribe:\s*(.+?)(?:\r?\n(?!\s)|$)/mi', $rawHeaders, $matches)) {
            $unsubValue = trim($matches[1]);
            
            // Handle multi-line headers (continuation lines start with whitespace)
            if (preg_match('/^List-Unsubscribe:\s*(.+?)(?=\r?\n[^\s]|\r?\n$|$)/mis', $rawHeaders, $multiMatch)) {
                $unsubValue = preg_replace('/\r?\n\s+/', ' ', trim($multiMatch[1])) ?? $unsubValue;
            }
            
            // Extract mailto: URL
            if (preg_match('/<mailto:([^>]+)>/i', $unsubValue, $mailtoMatch)) {
                $result['unsubscribe_email'] = $mailtoMatch[1];
            }
            
            // Extract https: URL (prefer https over http)
            if (preg_match('/<(https?:\/\/[^>]+)>/i', $unsubValue, $urlMatch)) {
                $result['unsubscribe_url'] = $urlMatch[1];
            }
        }
        
        // Look for List-Unsubscribe-Post header (indicates one-click support)
        // Format: List-Unsubscribe=One-Click
        if (preg_match('/^List-Unsubscribe-Post:\s*(.+)$/mi', $rawHeaders, $postMatch)) {
            $postValue = trim($postMatch[1]);
            if (stripos($postValue, 'List-Unsubscribe=One-Click') !== false) {
                $result['unsubscribe_one_click'] = true;
            }
        }
        
        return $result;
    }
    
    /**
     * Get unsubscribe info for a message (IMAP connection)
     */
    public function getUnsubscribeInfo(int $uid): array
    {
        $rawHeaders = @imap_fetchheader($this->connection, $uid, FT_UID);
        if (!$rawHeaders) {
            return [
                'unsubscribe_url' => null,
                'unsubscribe_email' => null,
                'unsubscribe_one_click' => false,
            ];
        }
        
        return $this->parseUnsubscribeHeaders($rawHeaders);
    }
    
    /**
     * Parse X-Spam headers from raw email headers
     */
    private function parseSpamHeaders(string $rawHeaders): array
    {
        $result = [
            'spam_score' => null,
            'spam_threshold' => null,
            'spam_flag' => null,
            'spam_tests' => [],
        ];
        
        // Parse X-Spam-Flag: YES/NO
        if (preg_match('/^X-Spam-Flag:\s*(YES|NO)/mi', $rawHeaders, $m)) {
            $result['spam_flag'] = strtoupper($m[1]) === 'YES';
        }
        
        // Parse X-Spam-Status: Yes/No, score=5.2 required=5.0 tests=...
        if (preg_match('/^X-Spam-Status:\s*(.+?)(?=\r?\n[^\s]|\r?\n$|$)/mis', $rawHeaders, $m)) {
            $statusLine = preg_replace('/\r?\n\s+/', ' ', trim($m[1])) ?? trim($m[1]);
            
            // Extract score
            if (preg_match('/score=([0-9.-]+)/i', $statusLine, $scoreMatch)) {
                $result['spam_score'] = (float)$scoreMatch[1];
            }
            
            // Extract threshold
            if (preg_match('/required=([0-9.-]+)/i', $statusLine, $threshMatch)) {
                $result['spam_threshold'] = (float)$threshMatch[1];
            }
            
            // Extract tests (limited to avoid huge arrays)
            if (preg_match('/tests=([^\s]+)/i', $statusLine, $testsMatch)) {
                $tests = array_filter(explode(',', $testsMatch[1]));
                $result['spam_tests'] = array_slice($tests, 0, 20); // Limit to 20 tests
            }
            
            // Set spam_flag from status if not already set
            if ($result['spam_flag'] === null && preg_match('/^(Yes|No)/i', $statusLine, $yesNo)) {
                $result['spam_flag'] = strtolower($yesNo[1]) === 'yes';
            }
        }
        
        // Fallback: Parse X-Spam-Score if present
        if ($result['spam_score'] === null && preg_match('/^X-Spam-Score:\s*([0-9.-]+)/mi', $rawHeaders, $m)) {
            $result['spam_score'] = (float)$m[1];
        }
        
        return $result;
    }
    
    /**
     * Get spam info for a message
     */
    public function getSpamInfo(int $uid): array
    {
        $rawHeaders = @imap_fetchheader($this->connection, $uid, FT_UID);
        if (!$rawHeaders) {
            return [
                'spam_score' => null,
                'spam_threshold' => null,
                'spam_flag' => null,
                'spam_tests' => [],
            ];
        }
        
        return $this->parseSpamHeaders($rawHeaders);
    }
    
    /**
     * Get raw email source and parsed header information (like Gmail's "Show Original")
     */
    /**
     * Get a human-readable MIME structure tree for debugging
     */
    public function getMimeStructureTree(string $folder, int $uid): ?array
    {
        if (!$this->selectFolder($folder)) {
            return null;
        }

        $structure = imap_fetchstructure($this->connection, $uid, FT_UID);
        if (!$structure) {
            return null;
        }

        $mimeTypes = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER'];
        $encodings = ['7BIT', 'BINARY', 'BASE64', 'QUOTED-PRINTABLE', '8BIT'];

        $buildTree = function ($part, string $partNum = '') use (&$buildTree, $mimeTypes, $encodings, $uid) {
            $type = $mimeTypes[$part->type] ?? 'UNKNOWN';
            $subtype = strtolower($part->subtype ?? 'unknown');
            $encoding = $encodings[$part->encoding ?? 0] ?? 'UNKNOWN';

            $node = [
                'part' => $partNum ?: 'ROOT',
                'mime' => strtolower($type) . '/' . $subtype,
                'encoding' => $encoding,
                'bytes' => $part->bytes ?? null,
            ];

            if ($part->parameters) {
                foreach ($part->parameters as $param) {
                    $node['params'][strtolower($param->attribute)] = $param->value;
                }
            }
            if (isset($part->disposition)) {
                $node['disposition'] = $part->disposition;
            }

            if ($part->type == 0) {
                $content = imap_fetchbody($this->connection, $uid, $partNum ?: '1', FT_UID | FT_PEEK);
                $node['raw_length'] = strlen($content);
                $decoded = $this->decodeContent($content, $part->encoding ?? 0);
                $node['decoded_length'] = strlen($decoded);
                $node['preview'] = mb_substr($decoded, 0, 200);
            }

            if (isset($part->parts) && $part->parts) {
                $node['children'] = [];
                foreach ($part->parts as $index => $child) {
                    $childPart = $partNum ? "$partNum." . ($index + 1) : (string)($index + 1);
                    $node['children'][] = $buildTree($child, $childPart);
                }
            }

            return $node;
        };

        return [
            'uid' => $uid,
            'folder' => $folder,
            'structure' => $buildTree($structure),
        ];
    }

    public function getOriginalMessage(string $folder, int $uid): ?array
    {
        if (!$this->selectFolder($folder)) {
            return null;
        }
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->getOriginalMessageOAuth($folder, $uid);
        }
        
        $msgno = imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            return null;
        }
        
        // Get raw headers
        $rawHeaders = @imap_fetchheader($this->connection, $uid, FT_UID);
        if (!$rawHeaders) {
            return null;
        }
        
        // Get raw body
        $rawBody = @imap_body($this->connection, $uid, FT_UID);
        
        // Combine for full raw source
        $rawSource = $rawHeaders . "\r\n" . $rawBody;
        
        // Get header info
        $header = imap_headerinfo($this->connection, $msgno);
        
        // Parse authentication results
        $authResults = $this->parseAuthenticationResults($rawHeaders);
        
        // Parse key headers
        $messageId = isset($header->message_id) ? trim($header->message_id, '<>') : '';
        $date = $header->date ?? '';
        $timestamp = isset($header->udate) ? $header->udate : strtotime($date);
        
        // Calculate delivery time if we can find Received headers
        $deliveryTime = $this->parseDeliveryTime($rawHeaders, $timestamp);
        
        // Extract authentication-related headers for debugging
        $authHeaders = $this->extractAuthHeaders($rawHeaders);
        
        return [
            'message_id' => $messageId ? "<{$messageId}>" : '',
            'date' => $date,
            'timestamp' => $timestamp,
            'delivery_time' => $deliveryTime,
            'from' => $this->formatAddressList($header->from ?? []),
            'to' => $this->formatAddressList($header->to ?? []),
            'cc' => $this->formatAddressList($header->cc ?? []),
            'reply_to' => $this->formatAddressList($header->reply_to ?? []),
            'subject' => isset($header->subject) ? $this->decodeMimeHeader($header->subject) : '(No Subject)',
            'size' => strlen($rawSource),
            'spf' => $authResults['spf'],
            'dkim' => $authResults['dkim'],
            'dmarc' => $authResults['dmarc'],
            'auth_headers' => $authHeaders,
            'raw_headers' => $rawHeaders,
            'raw_source' => $rawSource,
        ];
    }
    
    /**
     * Extract authentication-related headers for debugging
     */
    private function extractAuthHeaders(string $rawHeaders): array
    {
        $authHeaders = [];
        $normalizedHeaders = preg_replace('/\r?\n[ \t]+/', ' ', $rawHeaders) ?? $rawHeaders;
        
        // List of authentication-related header patterns
        $patterns = [
            'Authentication-Results' => '/^Authentication-Results:\s*(.+)$/mi',
            'ARC-Authentication-Results' => '/^ARC-Authentication-Results:\s*(.+)$/mi',
            'Received-SPF' => '/^Received-SPF:\s*(.+)$/mi',
            'DKIM-Signature' => '/^DKIM-Signature:\s*(.{0,100})/mi',
            'X-Google-DKIM-Signature' => '/^X-Google-DKIM-Signature:\s*(.{0,50})/mi',
            'X-Spam-Status' => '/^X-Spam-Status:\s*(.+)$/mi',
            'X-Spam-Flag' => '/^X-Spam-Flag:\s*(.+)$/mi',
        ];
        
        foreach ($patterns as $name => $pattern) {
            if (preg_match_all($pattern, $normalizedHeaders, $matches)) {
                $authHeaders[$name] = $matches[1];
            }
        }
        
        return $authHeaders;
    }
    
    /**
     * Get original message for OAuth connection
     */
    private function getOriginalMessageOAuth(string $folder, int $uid): ?array
    {
        // Fetch full message
        $tag = $this->sendCommand("UID FETCH {$uid} (BODY.PEEK[] FLAGS INTERNALDATE RFC822.SIZE)");
        if (!$tag) {
            return null;
        }
        
        $response = $this->readUntilTag($tag);
        if (!$response || strpos($response, 'OK') === false) {
            return null;
        }
        
        // Extract raw content
        if (!preg_match('/\{(\d+)\}\r\n/s', $response, $matches)) {
            return null;
        }
        
        $size = (int)$matches[1];
        $startPos = strpos($response, $matches[0]) + strlen($matches[0]);
        $rawSource = substr($response, $startPos, $size);
        
        // Split headers and body
        $headerEnd = strpos($rawSource, "\r\n\r\n");
        if ($headerEnd === false) {
            $headerEnd = strpos($rawSource, "\n\n");
        }
        
        $rawHeaders = $headerEnd !== false ? substr($rawSource, 0, $headerEnd) : $rawSource;
        
        // Parse headers
        $headers = $this->parseHeadersFromString($rawHeaders);
        
        // Parse authentication results
        $authResults = $this->parseAuthenticationResults($rawHeaders);
        
        // Get date
        $date = $headers['date'] ?? '';
        $timestamp = $date ? strtotime($date) : time();
        
        // Calculate delivery time
        $deliveryTime = $this->parseDeliveryTime($rawHeaders, $timestamp);
        
        // Extract authentication-related headers for debugging
        $authHeaders = $this->extractAuthHeaders($rawHeaders);
        
        return [
            'message_id' => $headers['message-id'] ?? '',
            'date' => $date,
            'timestamp' => $timestamp,
            'delivery_time' => $deliveryTime,
            'from' => $this->parseHeaderAddresses($headers['from'] ?? ''),
            'to' => $this->parseHeaderAddresses($headers['to'] ?? ''),
            'cc' => $this->parseHeaderAddresses($headers['cc'] ?? ''),
            'reply_to' => $this->parseHeaderAddresses($headers['reply-to'] ?? $headers['from'] ?? ''),
            'subject' => $this->decodeMimeHeader($headers['subject'] ?? '(No Subject)'),
            'size' => $size,
            'spf' => $authResults['spf'],
            'dkim' => $authResults['dkim'],
            'dmarc' => $authResults['dmarc'],
            'auth_headers' => $authHeaders,
            'raw_headers' => $rawHeaders,
            'raw_source' => $rawSource,
        ];
    }
    
    /**
     * Parse authentication results (SPF, DKIM, DMARC) from headers
     */
    private function parseAuthenticationResults(string $rawHeaders): array
    {
        $results = [
            'spf' => ['status' => 'unknown', 'details' => ''],
            'dkim' => ['status' => 'unknown', 'details' => ''],
            'dmarc' => ['status' => 'unknown', 'details' => ''],
        ];
        
        // Normalize headers - unfold multiline headers (lines starting with whitespace are continuations)
        $normalizedHeaders = preg_replace('/\r?\n[ \t]+/', ' ', $rawHeaders) ?? $rawHeaders;
        
        // ============ SPF DETECTION ============
        
        // Method 1: Authentication-Results header
        if (preg_match('/Authentication-Results:.*?\bspf\s*=\s*(pass|fail|softfail|neutral|none|temperror|permerror)\b/is', $normalizedHeaders, $m)) {
            $results['spf']['status'] = strtoupper($m[1]);
        }
        // Method 2: ARC-Authentication-Results
        elseif (preg_match('/ARC-Authentication-Results:.*?\bspf\s*=\s*(pass|fail|softfail|neutral|none|temperror|permerror)\b/is', $normalizedHeaders, $m)) {
            $results['spf']['status'] = strtoupper($m[1]);
        }
        // Method 3: Received-SPF header (common format)
        elseif (preg_match('/^Received-SPF:\s*(Pass|Fail|SoftFail|Neutral|None|TempError|PermError)\b/mi', $normalizedHeaders, $m)) {
            $results['spf']['status'] = strtoupper($m[1]);
        }
        // Method 4: X-Received-SPF
        elseif (preg_match('/^X-Received-SPF:\s*(pass|fail|softfail|neutral|none)\b/mi', $normalizedHeaders, $m)) {
            $results['spf']['status'] = strtoupper($m[1]);
        }
        // Method 5: SpamAssassin X-Spam-Status
        elseif (preg_match('/X-Spam-Status:.*?(SPF_PASS|SPF_FAIL|SPF_SOFTFAIL|SPF_NEUTRAL|SPF_NONE)/i', $normalizedHeaders, $m)) {
            $results['spf']['status'] = str_replace('SPF_', '', strtoupper($m[1]));
        }
        
        // Get SPF details (IP/domain)
        if (preg_match('/Received-SPF:.*?client-ip=([0-9a-f.:]+)/i', $normalizedHeaders, $m)) {
            $results['spf']['details'] = 'IP ' . $m[1];
        } elseif (preg_match('/smtp\.mailfrom=([^\s;,\)\]]+)/i', $normalizedHeaders, $m)) {
            $results['spf']['details'] = $m[1];
        } elseif (preg_match('/envelope-from=([^\s;,\)\]]+)/i', $normalizedHeaders, $m)) {
            $results['spf']['details'] = $m[1];
        }
        
        // ============ DKIM DETECTION ============
        
        // Method 1: Authentication-Results header
        if (preg_match('/Authentication-Results:.*?\bdkim\s*=\s*(pass|fail|neutral|none|temperror|permerror|policy)\b/is', $normalizedHeaders, $m)) {
            $results['dkim']['status'] = strtoupper($m[1]);
        }
        // Method 2: ARC-Authentication-Results
        elseif (preg_match('/ARC-Authentication-Results:.*?\bdkim\s*=\s*(pass|fail|neutral|none|temperror|permerror)\b/is', $normalizedHeaders, $m)) {
            $results['dkim']['status'] = strtoupper($m[1]);
        }
        // Method 3: X-DKIM-Authentication-Results
        elseif (preg_match('/^X-DKIM[^:]*:\s*(pass|fail|neutral|none)\b/mi', $normalizedHeaders, $m)) {
            $results['dkim']['status'] = strtoupper($m[1]);
        }
        // Method 4: SpamAssassin
        elseif (preg_match('/X-Spam-Status:.*?(DKIM_VALID|DKIM_INVALID)/i', $normalizedHeaders, $m)) {
            $results['dkim']['status'] = strpos($m[1], 'VALID') !== false ? 'PASS' : 'FAIL';
        }
        
        // Get DKIM signing domain from signature or auth results
        if (preg_match('/header\.d=([^\s;,\)\]]+)/i', $normalizedHeaders, $m)) {
            $results['dkim']['details'] = 'domain ' . $m[1];
        } elseif (preg_match('/^DKIM-Signature:.*?\bd=([^\s;]+)/mi', $normalizedHeaders, $m)) {
            $domain = trim($m[1], ';');
            if ($results['dkim']['status'] === 'unknown') {
                $results['dkim']['details'] = 'Signed by ' . $domain;
            } else {
                $results['dkim']['details'] = 'domain ' . $domain;
            }
        }
        
        // ============ DMARC DETECTION ============
        
        // Method 1: Authentication-Results header
        if (preg_match('/Authentication-Results:.*?\bdmarc\s*=\s*(pass|fail|bestguesspass|none|temperror|permerror)\b/is', $normalizedHeaders, $m)) {
            $results['dmarc']['status'] = strtoupper($m[1]);
        }
        // Method 2: ARC-Authentication-Results
        elseif (preg_match('/ARC-Authentication-Results:.*?\bdmarc\s*=\s*(pass|fail|bestguesspass|none)\b/is', $normalizedHeaders, $m)) {
            $results['dmarc']['status'] = strtoupper($m[1]);
        }
        // Method 3: X-DMARC-Info or similar
        elseif (preg_match('/^X-DMARC[^:]*:.*?(pass|fail|none)\b/mi', $normalizedHeaders, $m)) {
            $results['dmarc']['status'] = strtoupper($m[1]);
        }
        
        // Get DMARC policy domain
        if (preg_match('/header\.from=([^\s;,\)\]]+)/i', $normalizedHeaders, $m)) {
            $results['dmarc']['details'] = 'from ' . $m[1];
        }
        
        // ============ FINAL STATUS ADJUSTMENTS ============
        
        // If we have a DKIM signature but no verification result, mark as "signed" (not verified)
        if ($results['dkim']['status'] === 'unknown' && !empty($results['dkim']['details'])) {
            $results['dkim']['status'] = 'signed';
        }
        
        // Check what authentication headers exist
        $hasAuthResults = preg_match('/^Authentication-Results:/mi', $normalizedHeaders);
        $hasArcAuthResults = preg_match('/^ARC-Authentication-Results:/mi', $normalizedHeaders);
        $hasReceivedSpf = preg_match('/^Received-SPF:/mi', $normalizedHeaders);
        
        // Check if specific auth types are mentioned in the Authentication-Results header
        $authResultsContent = '';
        if (preg_match('/^Authentication-Results:(.+?)(?=^[A-Z]|\z)/mis', $normalizedHeaders, $arm)) {
            $authResultsContent = $arm[1];
        }
        
        $spfMentioned = preg_match('/\bspf\s*=/i', $authResultsContent);
        $dkimMentioned = preg_match('/\bdkim\s*=/i', $authResultsContent);
        $dmarcMentioned = preg_match('/\bdmarc\s*=/i', $authResultsContent);
        
        if ($hasAuthResults || $hasArcAuthResults) {
            // Server has auth results - if a specific type isn't mentioned, it's not being checked
            if ($results['spf']['status'] === 'unknown') {
                if ($hasReceivedSpf || $spfMentioned) {
                    // SPF was checked but we couldn't parse the result - keep as unknown
                } else {
                    $results['spf']['status'] = 'not_checked';
                    $results['spf']['details'] = 'Server does not verify SPF';
                }
            }
            if ($results['dkim']['status'] === 'unknown' && empty($results['dkim']['details'])) {
                if ($dkimMentioned) {
                    // DKIM was checked but we couldn't parse it
                } else {
                    $results['dkim']['status'] = 'not_checked';
                    $results['dkim']['details'] = 'Server does not verify DKIM';
                }
            }
            if ($results['dmarc']['status'] === 'unknown') {
                if ($dmarcMentioned) {
                    // DMARC was checked but we couldn't parse it
                } else {
                    $results['dmarc']['status'] = 'not_checked';
                    $results['dmarc']['details'] = 'Server does not verify DMARC';
                }
            }
        } else {
            // No auth headers at all - server doesn't verify anything
            if ($results['spf']['status'] === 'unknown') {
                $results['spf']['status'] = 'not_checked';
                $results['spf']['details'] = 'Server does not verify SPF';
            }
            if ($results['dkim']['status'] === 'unknown') {
                $results['dkim']['status'] = 'not_checked';
                if (empty($results['dkim']['details'])) {
                    $results['dkim']['details'] = 'Server does not verify DKIM';
                }
            }
            if ($results['dmarc']['status'] === 'unknown') {
                $results['dmarc']['status'] = 'not_checked';
                $results['dmarc']['details'] = 'Server does not verify DMARC';
            }
        }
        
        return $results;
    }
    
    /**
     * Parse delivery time from Received headers
     */
    private function parseDeliveryTime(string $rawHeaders, int $sentTimestamp): ?array
    {
        // Find all Received headers (newest first in email)
        preg_match_all('/^Received:.*?;\s*(.+?)$/mi', $rawHeaders, $matches);
        
        if (empty($matches[1])) {
            return null;
        }
        
        // First received header has the final delivery timestamp
        $deliveryDateStr = trim($matches[1][0]);
        $deliveryTimestamp = strtotime($deliveryDateStr);
        
        if ($deliveryTimestamp === false || $deliveryTimestamp <= 0) {
            return null;
        }
        
        // Calculate delivery duration
        $durationSecs = $deliveryTimestamp - $sentTimestamp;
        
        // Format duration
        if ($durationSecs < 0) {
            $durationStr = 'N/A';
        } elseif ($durationSecs < 60) {
            $durationStr = $durationSecs . ' second' . ($durationSecs !== 1 ? 's' : '');
        } elseif ($durationSecs < 3600) {
            $mins = round($durationSecs / 60);
            $durationStr = $mins . ' minute' . ($mins !== 1 ? 's' : '');
        } else {
            $hours = round($durationSecs / 3600, 1);
            $durationStr = $hours . ' hour' . ($hours !== 1 ? 's' : '');
        }
        
        return [
            'timestamp' => $deliveryTimestamp,
            'duration' => $durationSecs >= 0 ? $durationSecs : null,
            'duration_text' => 'Delivered after ' . $durationStr,
        ];
    }

    /**
     * Get message body (HTML and plain text)
     */
    private function getBody(int $uid, $structure, string $partNum = ''): array
    {
        $body = ['html' => '', 'text' => '', 'calendar' => ''];
        
        if (!$structure->parts && $structure->type == 0) {
            // Simple message
            $content = imap_fetchbody($this->connection, $uid, $partNum ?: '1', FT_UID | FT_PEEK);
            $content = $this->decodeContent($content, $structure->encoding ?? 0);
            $content = $this->convertCharset($content, $structure->parameters ?? []);
            
            if (strtolower($structure->subtype ?? '') === 'html') {
                $body['html'] = $content;
            } elseif (strtolower($structure->subtype ?? '') === 'calendar') {
                $body['calendar'] = $content;
            } else {
                $body['text'] = $content;
            }
        } elseif ($structure->parts) {
            // Multipart message
            foreach ($structure->parts as $index => $part) {
                $currentPart = $partNum ? "$partNum." . ($index + 1) : (string)($index + 1);
                
                if ($part->type == 0) { // Text
                    $content = imap_fetchbody($this->connection, $uid, $currentPart, FT_UID | FT_PEEK);
                    $content = $this->decodeContent($content, $part->encoding ?? 0);
                    $content = $this->convertCharset($content, $part->parameters ?? []);
                    
                    if (strtolower($part->subtype ?? '') === 'html' && empty($body['html'])) {
                        $body['html'] = $content;
                    } elseif (strtolower($part->subtype ?? '') === 'calendar' && empty($body['calendar'])) {
                        $body['calendar'] = $content;
                    } elseif (strtolower($part->subtype ?? '') === 'plain' && empty($body['text'])) {
                        $body['text'] = $content;
                    }
                } elseif ($part->type == 1) { // Multipart
                    $nested = $this->getBody($uid, $part, $currentPart);
                    if (empty($body['html']) && !empty($nested['html'])) {
                        $body['html'] = $nested['html'];
                    }
                    if (empty($body['text']) && !empty($nested['text'])) {
                        $body['text'] = $nested['text'];
                    }
                    if (empty($body['calendar']) && !empty($nested['calendar'])) {
                        $body['calendar'] = $nested['calendar'];
                    }
                } elseif ($part->type == 2) { // Message (message/rfc822) - forwarded emails
                    if (isset($part->parts) && $part->parts) {
                        $nested = $this->getBody($uid, $part, $currentPart);
                        if (empty($body['html']) && !empty($nested['html'])) {
                            $body['html'] = $nested['html'];
                        }
                        if (empty($body['text']) && !empty($nested['text'])) {
                            $body['text'] = $nested['text'];
                        }
                        if (empty($body['calendar']) && !empty($nested['calendar'])) {
                            $body['calendar'] = $nested['calendar'];
                        }
                    }
                }
            }
        }
        
        return $body;
    }

    /**
     * Get attachments from message
     */
    private function getAttachments(int $uid, $structure, string $partNum = ''): array
    {
        $attachments = [];
        
        if (!$structure->parts) {
            return $attachments;
        }
        
        foreach ($structure->parts as $index => $part) {
            $currentPart = $partNum ? "$partNum." . ($index + 1) : (string)($index + 1);
            
            // Check if it's an attachment
            $isAttachment = false;
            $filename = '';
            
            // Check disposition
            if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                $isAttachment = true;
                if ($part->dparameters) {
                    foreach ($part->dparameters as $param) {
                        if (strtolower($param->attribute) === 'filename') {
                            $filename = $this->decodeMimeHeader($param->value);
                        }
                    }
                }
            }
            
            // Check parameters for name
            if ($part->parameters) {
                foreach ($part->parameters as $param) {
                    if (strtolower($param->attribute) === 'name') {
                        $filename = $this->decodeMimeHeader($param->value);
                        if ($part->type !== 0 && $part->type !== 1) {
                            $isAttachment = true;
                        }
                    }
                }
            }
            
            // Type-based detection (images, etc. that aren't inline)
            // Skip type 2 (message/rfc822) from auto-attachment detection so we can recurse into it
            if (!$isAttachment && $part->type > 1 && $part->type !== 2 && $part->type !== 6) {
                $isAttachment = true;
            }
            
            if ($isAttachment && $filename) {
                $attachments[] = [
                    'part' => $currentPart,
                    'filename' => $filename,
                    'size' => $part->bytes ?? 0,
                    'type' => $this->getMimeType($part),
                    'encoding' => $part->encoding ?? 0,
                ];
            }
            
            // Recurse into nested parts (multipart and message/rfc822)
            if (($part->type == 1 || $part->type == 2) && isset($part->parts)) {
                $nested = $this->getAttachments($uid, $part, $currentPart);
                $attachments = array_merge($attachments, $nested);
            }
        }
        
        return $attachments;
    }

    /**
     * Get inline images with Content-ID for embedding in HTML
     */
    private function getInlineImages(int $uid, $structure, string $partNum = ''): array
    {
        $images = [];
        
        if (!$structure->parts) {
            return $images;
        }
        
        foreach ($structure->parts as $index => $part) {
            $currentPart = $partNum ? "$partNum." . ($index + 1) : (string)($index + 1);
            
            // Check if this is an inline image with Content-ID
            $contentId = null;
            $isInline = false;
            
            // Check disposition for inline
            if (isset($part->disposition) && strtolower($part->disposition) === 'inline') {
                $isInline = true;
            }
            
            // Get Content-ID from id property
            if (isset($part->id)) {
                $contentId = trim($part->id, '<>');
            }
            
            // Also check dparameters for content-id
            if (!$contentId && isset($part->dparameters) && is_array($part->dparameters)) {
                foreach ($part->dparameters as $param) {
                    if (strtolower($param->attribute) === 'content-id') {
                        $contentId = trim($param->value, '<>');
                    }
                }
            }
            
            // If it's an image with Content-ID (inline or referenced by cid:)
            if ($contentId && $part->type == 5) { // Type 5 = IMAGE
                $mimeType = $this->getMimeType($part);
                $content = imap_fetchbody($this->connection, $uid, $currentPart, FT_UID | FT_PEEK);
                $content = $this->decodeContent($content, $part->encoding ?? 0);
                
                $images[$contentId] = [
                    'mimeType' => $mimeType,
                    'data' => base64_encode($content),
                ];
            }
            
            // Recurse into nested parts (multipart and message/rfc822)
            if (($part->type == 1 || $part->type == 2) && isset($part->parts)) {
                $nested = $this->getInlineImages($uid, $part, $currentPart);
                $images = array_merge($images, $nested);
            }
        }
        
        return $images;
    }
    
    /**
     * Replace cid: references in HTML with base64 data URIs
     */
    private function replaceCidReferences(string $html, array $inlineImages): string
    {
        if (empty($inlineImages)) {
            return $html;
        }
        
        // Replace cid:xxx references with data URIs
        foreach ($inlineImages as $contentId => $imageData) {
            $dataUri = 'data:' . $imageData['mimeType'] . ';base64,' . $imageData['data'];
            
            // Replace various formats of cid references
            $patterns = [
                '/src\s*=\s*["\']cid:' . preg_quote($contentId, '/') . '["\']/i',
                '/src\s*=\s*cid:' . preg_quote($contentId, '/') . '(?=["\'\s>])/i',
            ];
            
            foreach ($patterns as $pattern) {
                $html = preg_replace($pattern, 'src="' . $dataUri . '"', $html) ?? $html;
            }
        }
        
        return $html ?? '';
    }

    /**
     * Download attachment
     */
    public function getAttachment(string $folder, int $uid, string $part): ?array
    {
        if (!$this->selectFolder($folder)) {
            error_log("[IMAP] getAttachment: selectFolder failed for '$folder' (uid=$uid, part=$part)");
            return null;
        }
        
        if ($this->isOAuthConnection) {
            return $this->getAttachmentOAuth($uid, $part);
        }

        $structure = imap_fetchstructure($this->connection, $uid, FT_UID);
        if (!$structure) {
            error_log("[IMAP] getAttachment: imap_fetchstructure returned false for uid=$uid in folder '$folder' - " . imap_last_error());
            return null;
        }
        $partStructure = $this->getPartStructure($structure, $part);
        
        if (!$partStructure) {
            error_log("[IMAP] getAttachment: part '$part' not found in structure for uid=$uid in folder '$folder'");
            return null;
        }
        
        $content = imap_fetchbody($this->connection, $uid, $part, FT_UID | FT_PEEK);
        $content = $this->decodeContent($content, $partStructure->encoding ?? 0);
        
        $filename = '';
        if ($partStructure->dparameters) {
            foreach ($partStructure->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    $filename = $this->decodeMimeHeader($param->value);
                }
            }
        }
        if (!$filename && $partStructure->parameters) {
            foreach ($partStructure->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    $filename = $this->decodeMimeHeader($param->value);
                }
            }
        }
        
        return [
            'filename' => $filename ?: 'attachment',
            'type' => $this->getMimeType($partStructure),
            'content' => $content,
            'size' => strlen($content),
        ];
    }

    /**
     * Get attachment via raw IMAP commands (OAuth stream connection)
     */
    private function getAttachmentOAuth(int $uid, string $part): ?array
    {
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uid} (BODY[{$part}] BODY[{$part}.MIME])");

        $partContent = '';
        $mimeHeaders = '';
        $inLiteral = false;
        $literalBytes = 0;
        $literalCollected = 0;
        $collectingPart = false;
        $collectingMime = false;

        while (true) {
            $line = $this->readLine();
            if ($line === false) break;

            if (strpos($line, "{$tag} OK") === 0 || strpos($line, "{$tag} NO") === 0 || strpos($line, "{$tag} BAD") === 0) {
                break;
            }

            if ($inLiteral) {
                $chunk = $line;
                if ($collectingMime) {
                    $mimeHeaders .= $chunk . "\r\n";
                } else {
                    $partContent .= $chunk . "\r\n";
                }
                $literalCollected += strlen($chunk) + 2;
                if ($literalCollected >= $literalBytes) {
                    if ($collectingMime) {
                        $mimeHeaders = substr($mimeHeaders, 0, $literalBytes);
                    } else {
                        $partContent = substr($partContent, 0, $literalBytes);
                    }
                    $inLiteral = false;
                    $collectingPart = false;
                    $collectingMime = false;
                }
                continue;
            }

            if (preg_match('/BODY\[' . preg_quote($part, '/') . '\.MIME\] \{(\d+)\}/', $line, $m)) {
                $collectingMime = true;
                $literalBytes = (int)$m[1];
                $literalCollected = 0;
                $inLiteral = true;
                $mimeHeaders = '';
                continue;
            }

            if (preg_match('/BODY\[' . preg_quote($part, '/') . '\] \{(\d+)\}/', $line, $m)) {
                $collectingPart = true;
                $literalBytes = (int)$m[1];
                $literalCollected = 0;
                $inLiteral = true;
                $partContent = '';
                continue;
            }
        }

        if (empty($partContent)) {
            return null;
        }

        $encoding = 0; // 7BIT
        $filename = 'attachment';
        $mimeType = 'application/octet-stream';

        if (!empty($mimeHeaders)) {
            if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $mimeHeaders, $m)) {
                $enc = strtolower($m[1]);
                $encodingMap = ['7bit' => 0, '8bit' => 1, 'binary' => 2, 'base64' => 3, 'quoted-printable' => 4];
                $encoding = $encodingMap[$enc] ?? 0;
            }
            if (preg_match('/filename[*]?="?([^";\r\n]+)"?/i', $mimeHeaders, $m)) {
                $filename = trim($m[1]);
            } elseif (preg_match('/name[*]?="?([^";\r\n]+)"?/i', $mimeHeaders, $m)) {
                $filename = trim($m[1]);
            }
            if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $mimeHeaders, $m)) {
                $mimeType = trim($m[1]);
            }
        }

        $content = $this->decodeContent($partContent, $encoding);

        return [
            'filename' => $filename,
            'type' => $mimeType,
            'content' => $content,
            'size' => strlen($content),
        ];
    }

    /**
     * Get part structure by part number
     */
    private function getPartStructure($structure, string $partNum)
    {
        $parts = explode('.', $partNum);
        $current = $structure;
        
        foreach ($parts as $num) {
            $index = (int)$num - 1;
            if (!isset($current->parts[$index])) {
                return null;
            }
            $current = $current->parts[$index];
        }
        
        return $current;
    }

    /**
     * Set message flags
     */
    public function setFlag(string $folder, int $uid, string $flag, bool $value): bool
    {
        if (!$this->selectFolder($folder)) {
            return false;
        }
        
        $flags = [
            'seen' => '\\Seen',
            'flagged' => '\\Flagged',
            'answered' => '\\Answered',
            'deleted' => '\\Deleted',
            'draft' => '\\Draft',
        ];
        
        $imapFlag = $flags[strtolower($flag)] ?? null;
        if (!$imapFlag) {
            return false;
        }
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->setFlagOAuth($uid, $imapFlag, $value);
        }
        
        if ($value) {
            return imap_setflag_full($this->connection, (string)$uid, $imapFlag, ST_UID);
        } else {
            return imap_clearflag_full($this->connection, (string)$uid, $imapFlag, ST_UID);
        }
    }
    
    /**
     * Set flag for OAuth connection
     */
    private function setFlagOAuth(int $uid, string $flag, bool $value): bool
    {
        $tag = $this->getNextTag();
        $command = $value ? '+FLAGS' : '-FLAGS';
        $this->writeLine("{$tag} UID STORE {$uid} {$command} ({$flag})");
        
        $response = $this->readResponse($tag);
        
        return strpos($response, "{$tag} OK") !== false;
    }

    /**
     * Move message to folder.
     * After a successful move, the new UID can be retrieved via getLastMoveNewUid().
     */
    public function moveMessage(string $folder, int $uid, string $targetFolder): bool
    {
        $this->lastError = null;
        $this->lastMoveNewUid = null;

        if (!$this->isConnected()) {
            $this->lastError = 'IMAP not connected';
            error_log("ImapService::moveMessage - Not connected");
            return false;
        }
        
        error_log("ImapService::moveMessage - Moving UID {$uid} from '{$folder}' to '{$targetFolder}'");
        
        if (!$this->selectFolder($folder)) {
            $this->lastError = "Failed to select source folder: {$folder}";
            error_log("ImapService::moveMessage - Failed to select source folder: {$folder}");
            return false;
        }
        
        // For non-ASCII folder names, encode to UTF7-IMAP
        // For ASCII-only names, use as-is (more reliable with some IMAP servers)
        $encodedTarget = $targetFolder;
        if (preg_match('/[^\x20-\x7E]/', $targetFolder)) {
            $encodedTarget = mb_convert_encoding($targetFolder, 'UTF7-IMAP', 'UTF-8');
            error_log("ImapService::moveMessage - Non-ASCII folder, encoded: '{$encodedTarget}'");
        } else {
            error_log("ImapService::moveMessage - ASCII folder, using as-is: '{$targetFolder}'");
        }
        
        // Capture UIDNEXT of target folder before the move so we can detect the new UID
        $uidNextBefore = $this->getTargetUidNext($targetFolder);
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            $result = $this->moveMessageOAuth($uid, $encodedTarget);
            if (!$result) {
                $this->lastError = $this->lastError ?: 'OAuth IMAP move failed';
            }
            if ($result) {
                $this->detectNewUidAfterMove($targetFolder, $uidNextBefore);
            }
            return $result;
        }
        
        // Clear any previous errors
        imap_errors();
        
        // First verify message exists in source folder
        $msgInfo = @imap_fetch_overview($this->connection, (string)$uid, FT_UID);
        if (empty($msgInfo)) {
            $this->lastError = "Message UID {$uid} not found in folder {$folder}";
            error_log("ImapService::moveMessage - Message UID {$uid} not found in source folder");
            return false;
        }
        error_log("ImapService::moveMessage - Verified message exists, subject: " . ($msgInfo[0]->subject ?? 'N/A'));
        $messageIdForLookup = !empty($msgInfo[0]->message_id) ? trim((string)$msgInfo[0]->message_id, '<>') : null;
        
        // Try to move the message
        $result = @imap_mail_move($this->connection, (string)$uid, $encodedTarget, CP_UID);
        
        if ($result) {
            @imap_expunge($this->connection);
            $this->detectNewUidAfterMove($targetFolder, $uidNextBefore, $messageIdForLookup);
            error_log("ImapService::moveMessage - Successfully moved UID {$uid} to {$targetFolder}, newUid=" . ($this->lastMoveNewUid ?? 'unknown'));
            return true;
        }
        
        $error = imap_last_error();
        error_log("ImapService::moveMessage - imap_mail_move failed for UID {$uid}: " . ($error ?: 'Unknown error'));
        
        // Collect all errors for debugging
        $errors = imap_errors();
        if ($errors) {
            error_log("ImapService::moveMessage - All IMAP errors: " . implode(', ', $errors));
        }
        
        // Fallback: Try with copy + delete approach
        error_log("ImapService::moveMessage - Attempting fallback: copy + delete");
        
        // Try copy
        $copyResult = @imap_mail_copy($this->connection, (string)$uid, $encodedTarget, CP_UID);
        if (!$copyResult) {
            $copyError = imap_last_error() ?: 'Unknown error';
            $this->lastError = "IMAP move failed: " . ($error ?: 'Unknown') . " | Copy fallback failed: {$copyError}";
            error_log("ImapService::moveMessage - Fallback copy also failed: {$copyError}");
            return false;
        }
        
        // Copy succeeded, now delete original
        $deleteResult = @imap_delete($this->connection, (string)$uid, FT_UID);
        if ($deleteResult) {
            @imap_expunge($this->connection);
            $this->detectNewUidAfterMove($targetFolder, $uidNextBefore, $messageIdForLookup);
            error_log("ImapService::moveMessage - Fallback succeeded (copy + delete), newUid=" . ($this->lastMoveNewUid ?? 'unknown'));
            return true;
        }
        
        $deleteError = imap_last_error() ?: 'Unknown error';
        $this->lastError = "Copy succeeded but delete failed: {$deleteError}";
        error_log("ImapService::moveMessage - Fallback delete failed: {$deleteError}");
        return false;
    }

    /**
     * Get UIDNEXT for a target folder (without changing the currently selected folder).
     */
    private function getTargetUidNext(string $folder): int
    {
        try {
            $status = $this->getFolderStatus($folder);
            return $status['uidnext'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * After a successful move/copy, detect the new UID in the target folder.
     * IMAP servers assign UIDNEXT (captured before the move) as the new UID.
     */
    private function detectNewUidAfterMove(string $targetFolder, int $uidNextBefore, ?string $sourceMessageId = null): void
    {
        if ($this->lastMoveNewUid !== null) {
            return;
        }

        if ($uidNextBefore <= 0) {
            error_log("ImapService::detectNewUidAfterMove - No UIDNEXT captured, attempting Message-ID lookup");
            $this->resolveMovedUidByMessageId($targetFolder, $sourceMessageId);
            return;
        }

        try {
            $statusAfter = $this->getFolderStatus($targetFolder);
            $uidNextAfter = $statusAfter['uidnext'] ?? 0;

            if ($uidNextAfter === $uidNextBefore + 1) {
                // Deterministic: exactly one message arrived -- it must be ours
                $this->lastMoveNewUid = $uidNextBefore;
                error_log("ImapService::detectNewUidAfterMove - Detected newUid={$uidNextBefore} (UIDNEXT {$uidNextBefore}->{$uidNextAfter})");
            } elseif ($uidNextAfter > $uidNextBefore) {
                // Non-deterministic jump -- try Message-ID resolution first
                if ($sourceMessageId) {
                    $this->resolveMovedUidByMessageId($targetFolder, $sourceMessageId);
                }
                if ($this->lastMoveNewUid === null) {
                    // Last-resort best guess; log so it's observable
                    $this->lastMoveNewUid = $uidNextAfter - 1;
                    error_log("ImapService::detectNewUidAfterMove - [BEST-GUESS] UIDNEXT jumped {$uidNextBefore}->{$uidNextAfter}, guessed newUid=" . ($uidNextAfter - 1));
                }
            } else {
                error_log("ImapService::detectNewUidAfterMove - UIDNEXT did not advance ({$uidNextBefore}->{$uidNextAfter}), cannot determine newUid");
            }
        } catch (\Exception $e) {
            error_log("ImapService::detectNewUidAfterMove - Error: " . $e->getMessage());
        }

        // Final fallback when no UID could be resolved yet
        if ($this->lastMoveNewUid === null && $sourceMessageId) {
            $this->resolveMovedUidByMessageId($targetFolder, $sourceMessageId);
        }
    }

    /**
     * Resolve the moved message in the target folder using its Message-ID.
     */
    private function resolveMovedUidByMessageId(string $targetFolder, ?string $sourceMessageId): void
    {
        if (empty($sourceMessageId) || $this->isOAuthConnection) {
            return;
        }

        try {
            $matches = $this->searchHeader($targetFolder, 'Message-ID', $sourceMessageId);
            if (empty($matches)) {
                return;
            }

            usort($matches, function ($a, $b) {
                $tsCompare = (($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
                if ($tsCompare !== 0) {
                    return $tsCompare;
                }
                return (($b['uid'] ?? 0) <=> ($a['uid'] ?? 0));
            });

            $this->lastMoveNewUid = (int)($matches[0]['uid'] ?? 0) ?: null;
            if ($this->lastMoveNewUid !== null) {
                error_log("ImapService::resolveMovedUidByMessageId - Resolved newUid={$this->lastMoveNewUid} using Message-ID {$sourceMessageId}");
            }
        } catch (\Throwable $e) {
            error_log("ImapService::resolveMovedUidByMessageId - Error: " . $e->getMessage());
        }
    }
    
    /**
     * Move message via OAuth IMAP connection.
     *
     * Prefers RFC 6851 UID MOVE (atomic, preserves all flags including \Seen).
     * Falls back to UID COPY + UID STORE \Deleted + EXPUNGE on servers that
     * do not advertise the MOVE capability. The fallback restores \Seen on the
     * new UID in the target folder when the source message was already read,
     * because Gmail's IMAP does NOT preserve \Seen across UID COPY.
     */
    private function moveMessageOAuth(int $uid, string $targetFolder): bool
    {
        if ($this->hasCapability('MOVE')) {
            return $this->moveMessageOAuthAtomic($uid, $targetFolder);
        }
        return $this->moveMessageOAuthCopyDelete($uid, $targetFolder);
    }

    /**
     * RFC 6851 atomic UID MOVE. One round trip, server-side flag preservation.
     */
    private function moveMessageOAuthAtomic(int $uid, string $targetFolder): bool
    {
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID MOVE {$uid} \"{$targetFolder}\"");
        $response = $this->readResponse($tag);

        if (preg_match('/COPYUID \d+ \d+ (\d+(?:,\d+)*)/i', $response, $matches)) {
            $newUids = array_map('intval', explode(',', $matches[1]));
            $resolvedUid = end($newUids);
            if ($resolvedUid) {
                $this->lastMoveNewUid = $resolvedUid;
            }
        }

        if (strpos($response, "{$tag} OK") === false) {
            error_log("ImapService::moveMessageOAuthAtomic - UID MOVE failed: " . $response);
            $this->lastError = 'UID MOVE failed';
            return false;
        }

        return true;
    }

    /**
     * Fallback path for servers without MOVE capability.
     * Captures source \Seen state, performs UID COPY + STORE \Deleted + EXPUNGE,
     * then re-applies \Seen on the new UID in the target folder if necessary.
     */
    private function moveMessageOAuthCopyDelete(int $uid, string $targetFolder): bool
    {
        $sourceWasSeen = $this->isUidSeen($uid);

        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID COPY {$uid} \"{$targetFolder}\"");
        $response = $this->readResponse($tag);

        if (preg_match('/COPYUID \d+ \d+ (\d+(?:,\d+)*)/i', $response, $matches)) {
            $newUids = array_map('intval', explode(',', $matches[1]));
            $resolvedUid = end($newUids);
            if ($resolvedUid) {
                $this->lastMoveNewUid = $resolvedUid;
            }
        }

        if (strpos($response, "{$tag} OK") === false) {
            error_log("ImapService::moveMessageOAuthCopyDelete - Failed to copy: " . $response);
            return false;
        }

        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID STORE {$uid} +FLAGS (\\Deleted)");
        $response = $this->readResponse($tag);

        if (strpos($response, "{$tag} OK") === false) {
            error_log("ImapService::moveMessageOAuthCopyDelete - Failed to mark deleted: " . $response);
            return false;
        }

        $tag = $this->getNextTag();
        $this->writeLine("{$tag} EXPUNGE");
        $response = $this->readResponse($tag);

        if (strpos($response, "{$tag} OK") === false) {
            return false;
        }

        if ($sourceWasSeen && $this->lastMoveNewUid !== null) {
            $this->reapplySeenInTargetFolder($targetFolder, $this->lastMoveNewUid);
        }

        return true;
    }

    /**
     * Returns true if the given UID in the currently-selected folder has the
     * \Seen flag set. Used by the copy+delete fallback path so it can restore
     * \Seen on the new UID in the target folder.
     */
    private function isUidSeen(int $uid): bool
    {
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uid} (FLAGS)");
        $response = $this->readResponse($tag);

        if (strpos($response, "{$tag} OK") === false) {
            return false;
        }
        if (preg_match('/FLAGS\s+\(([^)]*)\)/i', $response, $m)) {
            return stripos($m[1], '\\Seen') !== false;
        }
        return false;
    }

    /**
     * SELECT the target folder, STORE \Seen on the new UID, and return. Used to
     * restore the seen state lost by Gmail's UID COPY on the fallback path.
     */
    private function reapplySeenInTargetFolder(string $targetFolder, int $newUid): void
    {
        if (!$this->selectFolderOAuth($targetFolder)) {
            error_log("ImapService::reapplySeenInTargetFolder - Could not SELECT {$targetFolder} to restore \\Seen on UID {$newUid}");
            return;
        }
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID STORE {$newUid} +FLAGS (\\Seen)");
        $this->readResponse($tag);
    }

    /**
     * Delete message permanently
     */
    public function deleteMessage(string $folder, int $uid): bool
    {
        if (!$this->isConnected()) {
            error_log("ImapService::deleteMessage - Not connected");
            return false;
        }
        
        error_log("ImapService::deleteMessage - Deleting UID {$uid} from folder: {$folder}");
        
        if (!$this->selectFolder($folder)) {
            error_log("ImapService::deleteMessage - Failed to select folder: {$folder}");
            return false;
        }
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->deleteMessageOAuth($uid);
        }
        
        // Use imap_delete with UID flag, then expunge
        $result = @imap_delete($this->connection, (string)$uid, FT_UID);
        
        if ($result) {
            @imap_expunge($this->connection);
            error_log("ImapService::deleteMessage - Successfully deleted UID {$uid}");
            return true;
        }
        
        error_log("ImapService::deleteMessage - imap_delete failed for UID {$uid}: " . imap_last_error());
        return false;
    }
    
    /**
     * Delete message via OAuth IMAP connection
     */
    private function deleteMessageOAuth(int $uid): bool
    {
        // Mark message as deleted
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID STORE {$uid} +FLAGS (\\Deleted)");
        $response = $this->readResponse($tag);
        
        if (strpos($response, "{$tag} OK") === false) {
            error_log("ImapService::deleteMessageOAuth - Failed to mark as deleted: " . $response);
            return false;
        }
        
        // Expunge to permanently delete
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} EXPUNGE");
        $response = $this->readResponse($tag);
        
        return strpos($response, "{$tag} OK") !== false;
    }

    /**
     * Create a new folder
     */
    public function createFolder(string $name): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        // Handle OAuth connection
        if ($this->isOAuthConnection) {
            return $this->createFolderOAuth($name);
        }
        
        // Prefix with INBOX. if not already (Dovecot requires this)
        if (stripos($name, 'INBOX.') !== 0 && strtoupper($name) !== 'INBOX') {
            $name = 'INBOX.' . $name;
        }
        
        $encodedName = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
        $server = $this->buildConnectionString('');
        $fullPath = $server . $encodedName;
        
        $result = @imap_createmailbox($this->connection, $fullPath);
        
        if (!$result) {
            $errors = imap_errors();
            error_log("Failed to create folder '$name': " . implode(', ', $errors ?: ['Unknown error']));
        }
        
        return $result;
    }
    
    /**
     * Create folder via OAuth stream connection
     */
    private function createFolderOAuth(string $name): bool
    {
        if (!$this->streamConnection) {
            return false;
        }
        
        // Encode folder name for IMAP
        $encodedName = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
        
        // Send CREATE command
        $tag = 'A' . str_pad(++$this->commandCounter, 4, '0', STR_PAD_LEFT);
        $command = "$tag CREATE \"$encodedName\"\r\n";
        
        fwrite($this->streamConnection, $command);
        
        while (($line = fgets($this->streamConnection, 8192)) !== false) {
            if (strpos($line, "$tag OK") === 0) {
                return true;
            }
            if (strpos($line, "$tag NO") === 0 || strpos($line, "$tag BAD") === 0) {
                error_log("Failed to create folder '$name' via OAuth: $line");
                return false;
            }
        }
        
        return false;
    }

    /**
     * Delete a folder
     */
    public function deleteFolder(string $name): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        // Handle OAuth connection
        if ($this->isOAuthConnection) {
            return $this->deleteFolderOAuth($name);
        }
        
        // Prefix with INBOX. if not already (for standard IMAP)
        if (stripos($name, 'INBOX.') !== 0 && strtoupper($name) !== 'INBOX') {
            $name = 'INBOX.' . $name;
        }
        
        $encodedName = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
        $server = $this->buildConnectionString('');
        
        $result = @imap_deletemailbox($this->connection, $server . $encodedName);
        
        if (!$result) {
            $errors = imap_errors();
            error_log("Failed to delete folder '$name': " . implode(', ', $errors ?: ['Unknown error']));
        }
        
        return $result;
    }
    
    /**
     * Delete folder via OAuth stream connection
     */
    private function deleteFolderOAuth(string $name): bool
    {
        if (!$this->streamConnection) {
            return false;
        }
        
        // Encode folder name for IMAP
        $encodedName = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
        
        // Send DELETE command
        $tag = 'A' . str_pad(++$this->commandCounter, 4, '0', STR_PAD_LEFT);
        $command = "$tag DELETE \"$encodedName\"\r\n";
        
        fwrite($this->streamConnection, $command);
        
        $response = '';
        while (($line = fgets($this->streamConnection, 8192)) !== false) {
            $response .= $line;
            if (strpos($line, "$tag OK") === 0) {
                return true;
            }
            if (strpos($line, "$tag NO") === 0 || strpos($line, "$tag BAD") === 0) {
                error_log("Failed to delete folder '$name' via OAuth: $line");
                return false;
            }
        }
        
        error_log("Failed to delete folder '$name' via OAuth: No response");
        return false;
    }

    /**
     * Empty all messages from a folder (permanently delete)
     */
    public function emptyFolder(string $folder): int|false
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        // Select the folder using the existing connection
        if (!$this->selectFolder($folder)) {
            error_log("Failed to select folder for emptying: $folder");
            return false;
        }
        
        if ($this->isOAuthConnection) {
            return $this->emptyFolderOAuth();
        }
        
        // Get message count
        $info = imap_check($this->connection);
        $count = $info ? $info->Nmsgs : 0;
        
        if ($count === 0) {
            return 0;
        }
        
        // Mark all messages for deletion using a range
        @imap_delete($this->connection, "1:$count");
        
        // Expunge to permanently delete
        @imap_expunge($this->connection);
        
        return $count;
    }

    private function emptyFolderOAuth(): int|false
    {
        $allUids = $this->searchMessagesOAuth('ALL');
        $count = count($allUids);

        if ($count === 0) {
            return 0;
        }

        $uidRange = implode(',', $allUids);

        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID STORE {$uidRange} +FLAGS (\\Deleted)");
        $response = $this->readResponse($tag);

        if (strpos($response, "{$tag} OK") === false) {
            error_log("emptyFolderOAuth: STORE failed: $response");
            return false;
        }

        $tag = $this->getNextTag();
        $this->writeLine("{$tag} EXPUNGE");
        $response = $this->readResponse($tag);

        if (strpos($response, "{$tag} OK") === false) {
            error_log("emptyFolderOAuth: EXPUNGE failed: $response");
            return false;
        }

        return $count;
    }

    /**
     * Rename/move a folder
     */
    public function renameFolder(string $oldName, string $newName): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        // Handle OAuth connection
        if ($this->isOAuthConnection) {
            return $this->renameFolderOAuth($oldName, $newName);
        }
        
        // Prefix with INBOX. if not already
        if (stripos($oldName, 'INBOX.') !== 0 && strtoupper($oldName) !== 'INBOX') {
            $oldName = 'INBOX.' . $oldName;
        }
        if (stripos($newName, 'INBOX.') !== 0 && strtoupper($newName) !== 'INBOX') {
            $newName = 'INBOX.' . $newName;
        }
        
        $encodedOld = mb_convert_encoding($oldName, 'UTF7-IMAP', 'UTF-8');
        $encodedNew = mb_convert_encoding($newName, 'UTF7-IMAP', 'UTF-8');
        $server = $this->buildConnectionString('');

        // Drain stale errors that may belong to an earlier IMAP call so the
        // post-rename diagnostic only sees errors for THIS rename.
        @imap_errors();
        @imap_alerts();

        $result = @imap_renamemailbox($this->connection, $server . $encodedOld, $server . $encodedNew);

        // PHP's bundled IMAP client occasionally returns false even when
        // Dovecot/Exchange completed the RENAME, because it gets confused by
        // unsolicited untagged responses (LIST, NOTIFY, OK with response
        // codes) sent during/after the operation. To distinguish a real
        // failure from a false-negative, we check imap_errors() AND verify
        // the destination mailbox now exists.
        if (!$result) {
            $errors = imap_errors() ?: [];
            $alerts = imap_alerts() ?: [];

            // Probe the destination. imap_list against the exact path
            // returns an array on success, false on failure.
            $listResult = false;
            try {
                $listResult = @imap_list($this->connection, $server, $encodedNew);
            } catch (\Throwable $probeErr) {
                // ignore; we'll fall through to the failure path below
            }

            if (is_array($listResult) && count($listResult) > 0) {
                error_log("[ImapService::renameFolder] imap_renamemailbox returned false for '$oldName' -> '$newName' "
                    . "but destination exists; treating as success. Errors=" . implode(', ', $errors)
                    . " Alerts=" . implode(', ', $alerts));
                // Drain so callers see a clean state.
                @imap_errors();
                @imap_alerts();
                return true;
            }

            $diag = !empty($errors) ? implode(', ', $errors)
                : (!empty($alerts) ? implode(', ', $alerts) : 'Unknown error');
            error_log("Failed to rename folder '$oldName' to '$newName': " . $diag);
        }

        return $result;
    }
    
    /**
     * Rename folder via OAuth stream connection
     */
    private function renameFolderOAuth(string $oldName, string $newName): bool
    {
        if (!$this->streamConnection) {
            return false;
        }
        
        // Encode folder names for IMAP
        $encodedOld = mb_convert_encoding($oldName, 'UTF7-IMAP', 'UTF-8');
        $encodedNew = mb_convert_encoding($newName, 'UTF7-IMAP', 'UTF-8');
        
        // Send RENAME command
        $tag = 'A' . str_pad(++$this->commandCounter, 4, '0', STR_PAD_LEFT);
        $command = "$tag RENAME \"$encodedOld\" \"$encodedNew\"\r\n";
        
        fwrite($this->streamConnection, $command);
        
        while (($line = fgets($this->streamConnection, 8192)) !== false) {
            if (strpos($line, "$tag OK") === 0) {
                return true;
            }
            if (strpos($line, "$tag NO") === 0 || strpos($line, "$tag BAD") === 0) {
                error_log("Failed to rename folder '$oldName' to '$newName' via OAuth: $line");
                return false;
            }
        }
        
        return false;
    }

    /**
     * Search messages
     */
    /**
     * Parse natural date string to timestamp
     * Supports: "december 2024", "dec 2024", "25 december 2024", "2024-12-25"
     */
    private function parseNaturalDate(string $dateStr): ?int
    {
        $dateStr = trim($dateStr);
        
        // Try standard formats first
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false) {
            return $timestamp;
        }
        
        // Month names mapping
        $months = [
            'jan' => 1, 'january' => 1,
            'feb' => 2, 'february' => 2,
            'mar' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4,
            'may' => 5,
            'jun' => 6, 'june' => 6,
            'jul' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10,
            'nov' => 11, 'november' => 11,
            'dec' => 12, 'december' => 12,
        ];
        
        // Try "month year" format (e.g., "december 2024", "dec 2024")
        if (preg_match('/^([a-z]+)\s+(\d{4})$/i', $dateStr, $match)) {
            $monthName = strtolower($match[1]);
            $year = (int)$match[2];
            if (isset($months[$monthName])) {
                return mktime(0, 0, 0, $months[$monthName], 1, $year);
            }
        }
        
        // Try "day month year" format (e.g., "25 december 2024")
        if (preg_match('/^(\d{1,2})\s+([a-z]+)\s+(\d{4})$/i', $dateStr, $match)) {
            $day = (int)$match[1];
            $monthName = strtolower($match[2]);
            $year = (int)$match[3];
            if (isset($months[$monthName])) {
                return mktime(0, 0, 0, $months[$monthName], $day, $year);
            }
        }
        
        return null;
    }
    
    /**
     * Parse Gmail-style search query into IMAP criteria
     * Supports: from: to: subject: has:attachment is:unread is:starred before: after: label:
     * Combine with spaces or && (both work as AND)
     */
    private function parseSearchQuery(string $query): array
    {
        $criteria = [];
        $textSearch = [];
        $hasAttachment = false;
        $labelFilters = [];
        
        // Normalize && to spaces (IMAP AND is implicit with space-separated criteria)
        $query = str_replace('&&', ' ', $query);
        $query = preg_replace('/\s+/', ' ', trim($query));
        
        // label:name - extract label filters (stored in database, not IMAP)
        if (preg_match_all('/label:(?:"([^"]+)"|(\S+))/i', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = !empty($match[1]) ? $match[1] : $match[2];
                $labelFilters[] = $value;
            }
            $query = preg_replace('/label:(?:"[^"]+"|[^\s]+)/i', '', $query);
        }
        
        // Extract operators using regex
        
        // involves:email - searches both FROM and TO (for finding all emails with a contact)
        // Uses IMAP OR: OR FROM "x" TO "x"
        if (preg_match_all('/involves:(?:"([^"]+)"|(\S+))/i', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = !empty($match[1]) ? $match[1] : $match[2];
                $escaped = addslashes($value);
                // IMAP OR syntax: OR <criterion1> <criterion2>
                // We also add CC to catch all conversations
                $criteria[] = 'OR OR FROM "' . $escaped . '" TO "' . $escaped . '" CC "' . $escaped . '"';
            }
            $query = preg_replace('/involves:(?:"[^"]+"|[^\s]+)/i', '', $query);
        }
        
        // from:email or from:"name email"
        if (preg_match_all('/from:(?:"([^"]+)"|(\S+))/i', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = !empty($match[1]) ? $match[1] : $match[2];
                $criteria[] = 'FROM "' . addslashes($value) . '"';
            }
            $query = preg_replace('/from:(?:"[^"]+"|[^\s]+)/i', '', $query);
        }
        
        // to:email
        if (preg_match_all('/to:(?:"([^"]+)"|(\S+))/i', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = !empty($match[1]) ? $match[1] : $match[2];
                $criteria[] = 'TO "' . addslashes($value) . '"';
            }
            $query = preg_replace('/to:(?:"[^"]+"|[^\s]+)/i', '', $query);
        }
        
        // subject:text or subject:"text with spaces"
        if (preg_match_all('/subject:(?:"([^"]+)"|(\S+))/i', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = !empty($match[1]) ? $match[1] : $match[2];
                $criteria[] = 'SUBJECT "' . addslashes($value) . '"';
            }
            $query = preg_replace('/subject:(?:"[^"]+"|[^\s]+)/i', '', $query);
        }
        
        // msgid:message-id - search by Message-ID header
        if (preg_match_all('/msgid:(?:"([^"]+)"|(\S+))/i', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = !empty($match[1]) ? $match[1] : $match[2];
                // Clean the message ID
                $value = trim($value, '<>');
                $criteria[] = 'HEADER Message-ID "<' . addslashes($value) . '>"';
            }
            $query = preg_replace('/msgid:(?:"[^"]+"|[^\s]+)/i', '', $query);
        }
        
        // has:attachment - search for multipart messages (best IMAP approximation)
        if (preg_match('/has:attachment/i', $query)) {
            $hasAttachment = true;
            $query = preg_replace('/has:attachment/i', '', $query);
        }
        
        // is:unread
        if (preg_match('/is:unread/i', $query)) {
            $criteria[] = 'UNSEEN';
            $query = preg_replace('/is:unread/i', '', $query);
        }
        
        // is:read
        if (preg_match('/is:read/i', $query)) {
            $criteria[] = 'SEEN';
            $query = preg_replace('/is:read/i', '', $query);
        }
        
        // is:starred
        if (preg_match('/is:starred/i', $query)) {
            $criteria[] = 'FLAGGED';
            $query = preg_replace('/is:starred/i', '', $query);
        }
        
        // before: with flexible date formats
        // Match before: followed by either quoted string, date format, or "month year"
        $datePattern = '/before:(?:"([^"]+)"|(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})|(\d{1,2}\s+[a-z]+\s+\d{4})|([a-z]+\s+\d{4})|([a-z]+))/i';
        if (preg_match($datePattern, $query, $match)) {
            $dateStr = $match[1] ?: $match[2] ?: $match[3] ?: $match[4] ?: $match[5];
            $timestamp = $this->parseNaturalDate($dateStr);
            if ($timestamp) {
                // IMAP BEFORE is exclusive (does not include the date), so +1 day to make it inclusive
                $criteria[] = 'BEFORE ' . date('d-M-Y', strtotime('+1 day', $timestamp));
            }
            // Remove the matched pattern from query
            $query = preg_replace('/before:(?:"[^"]+"|' . preg_quote($dateStr, '/') . ')/i', '', $query);
        }
        
        // after: with flexible date formats
        $datePattern = '/after:(?:"([^"]+)"|(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})|(\d{1,2}\s+[a-z]+\s+\d{4})|([a-z]+\s+\d{4})|([a-z]+))/i';
        if (preg_match($datePattern, $query, $match)) {
            $dateStr = $match[1] ?: $match[2] ?: $match[3] ?: $match[4] ?: $match[5];
            $timestamp = $this->parseNaturalDate($dateStr);
            if ($timestamp) {
                $criteria[] = 'SINCE ' . date('d-M-Y', $timestamp);
            }
            // Remove the matched pattern from query
            $query = preg_replace('/after:(?:"[^"]+"|' . preg_quote($dateStr, '/') . ')/i', '', $query);
        }
        
        // Remaining text is general search
        $query = trim(preg_replace('/\s+/', ' ', $query));
        if (!empty($query)) {
            $textSearch[] = $query;
        }
        
        return ['criteria' => $criteria, 'text' => $textSearch, 'hasAttachment' => $hasAttachment, 'labels' => $labelFilters];
    }
    
    public function search(string $folder, string $query, array $filters = []): array
    {
        if (!$this->selectFolder($folder)) {
            return [];
        }
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->searchOAuth($folder, $query, $filters);
        }
        
        // Parse the search query for Gmail-style operators
        $parsed = $this->parseSearchQuery($query);
        $criteria = $parsed['criteria'];
        $textSearch = $parsed['text'];
        $filterAttachments = $parsed['hasAttachment'] ?? false;
        $this->lastSearchLabelFilters = $parsed['labels'] ?? [];
        
        // Add filters from API params (for backwards compatibility)
        if (!empty($filters['from'])) {
            $criteria[] = 'FROM "' . addslashes($filters['from']) . '"';
        }
        
        if (!empty($filters['to'])) {
            $criteria[] = 'TO "' . addslashes($filters['to']) . '"';
        }
        
        if (!empty($filters['since'])) {
            $criteria[] = 'SINCE ' . date('d-M-Y', strtotime($filters['since']));
        }
        
        if (!empty($filters['before'])) {
            $criteria[] = 'BEFORE ' . date('d-M-Y', strtotime($filters['before'] . ' +1 day'));
        }
        
        if (isset($filters['unread']) && $filters['unread']) {
            $criteria[] = 'UNSEEN';
        }
        
        if (isset($filters['flagged']) && $filters['flagged']) {
            $criteria[] = 'FLAGGED';
        }
        
        // For text search, search in TEXT (which includes subject, from, body)
        // IMAP TEXT search is more reliable than complex OR statements
        if (!empty($textSearch)) {
            foreach ($textSearch as $term) {
                // Use TEXT for general search - it searches headers and body
                $criteria[] = 'TEXT "' . addslashes($term) . '"';
            }
        }
        
        // Build final search string
        $searchString = !empty($criteria) ? implode(' ', $criteria) : 'ALL';
        
        error_log("IMAP Search: $searchString");
        
        $results = @imap_search($this->connection, $searchString, SE_UID);
        
        if ($results === false) {
            // Try simpler search if complex one fails
            if (!empty($textSearch)) {
                // Fallback: just search TEXT
                $simpleSearch = 'TEXT "' . addslashes(implode(' ', $textSearch)) . '"';
                error_log("IMAP Search fallback: $simpleSearch");
                $results = @imap_search($this->connection, $simpleSearch, SE_UID);
            }
            
            if ($results === false) {
                error_log("IMAP Search failed: " . imap_last_error());
                return [];
            }
        }
        
        // Sort results by date descending (newest first)
        rsort($results);
        
        // Get message details for results
        $messages = [];
        $limit = $filterAttachments ? 200 : 100; // Fetch more if filtering attachments
        
        foreach (array_slice($results, 0, $limit) as $uid) {
            $msgno = @imap_msgno($this->connection, $uid);
            if ($msgno > 0) {
                // If filtering attachments, check structure first
                if ($filterAttachments) {
                    $structure = @imap_fetchstructure($this->connection, $msgno);
                    if (!$this->hasAttachments($structure)) {
                        continue; // Skip messages without attachments
                    }
                }
                
                $overview = @imap_fetch_overview($this->connection, (string)$msgno, 0);
                if ($overview && isset($overview[0])) {
                    $formatted = $this->formatMessageOverview($overview[0]);
                    
                    // Check for attachments
                    if (!isset($structure)) {
                        $structure = @imap_fetchstructure($this->connection, $msgno);
                    }
                    $formatted['has_attachment'] = $this->hasAttachments($structure);
                    unset($structure); // Reset for next iteration
                    
                    $messages[] = $formatted;
                    
                    // Stop at 100 results
                    if (count($messages) >= 100) {
                        break;
                    }
                }
            }
        }
        
        return $messages;
    }
    
    /**
     * Search for messages by specific header value
     * Tries HEADER search first, falls back to TEXT search if not supported
     */
    public function searchHeader(string $folder, string $headerName, string $headerValue): array
    {
        if (!$this->selectFolder($folder)) {
            return [];
        }
        
        // Clean the header value (remove whitespace, angle brackets, quotes)
        $cleanValue = trim($headerValue);
        $cleanValue = trim($cleanValue, '<>"\'');
        $cleanValue = trim($cleanValue);
        
        if (empty($cleanValue)) {
            return [];
        }
        
        $results = false;
        
        // Try HEADER search first (standard IMAP)
        $searchCriteria = 'HEADER ' . $headerName . ' "' . addslashes($cleanValue) . '"';
        error_log("IMAP Header Search: $searchCriteria");
        $results = @imap_search($this->connection, $searchCriteria, SE_UID);
        
        // If HEADER search fails, try TEXT search as fallback
        // TEXT searches the entire message (headers + body)
        if ($results === false) {
            $error = imap_last_error();
            imap_errors(); // Clear error queue
            error_log("IMAP Header Search failed ($error), trying TEXT fallback");
            
            // For Message-ID and References, the value is unique enough
            // that TEXT search should work well
            $textSearch = 'TEXT "' . addslashes($cleanValue) . '"';
            error_log("IMAP Text Search: $textSearch");
            $results = @imap_search($this->connection, $textSearch, SE_UID);
            
            if ($results === false) {
                $error = imap_last_error();
                imap_errors();
                error_log("IMAP Text Search also failed: " . ($error ?: 'no results'));
                return [];
            }
        }
        
        // Fetch basic info for found messages
        $messages = [];
        foreach ($results as $uid) {
            $msgno = @imap_msgno($this->connection, $uid);
            if ($msgno <= 0) continue;
            
            $header = @imap_headerinfo($this->connection, $msgno);
            if (!$header) continue;
            
            $messageId = isset($header->message_id) ? trim($header->message_id, '<>') : '';
            $fromEmail = '';
            if (isset($header->from[0])) {
                $fromEmail = ($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? '');
            }
            
            $messages[] = [
                'uid' => $uid,
                'message_id' => $messageId,
                'subject' => isset($header->subject) ? $this->decodeMimeHeader($header->subject) : '',
                'from_email' => $fromEmail,
                'timestamp' => isset($header->udate) ? $header->udate : strtotime($header->date ?? 'now'),
                'date' => $header->date ?? '',
            ];
        }
        
        error_log("IMAP Header/Text Search found " . count($messages) . " messages");
        return $messages;
    }
    
    /**
     * Search for OAuth connection
     */
    private function searchOAuth(string $folder, string $query, array $filters = []): array
    {
        // Parse the search query
        $parsed = $this->parseSearchQuery($query);
        $criteria = $parsed['criteria'];
        $textSearch = $parsed['text'];
        $this->lastSearchLabelFilters = $parsed['labels'] ?? [];
        
        // Add filters from API params
        if (!empty($filters['from'])) {
            $criteria[] = 'FROM "' . addslashes($filters['from']) . '"';
        }
        if (!empty($filters['to'])) {
            $criteria[] = 'TO "' . addslashes($filters['to']) . '"';
        }
        if (!empty($filters['since'])) {
            $criteria[] = 'SINCE ' . date('d-M-Y', strtotime($filters['since']));
        }
        if (!empty($filters['before'])) {
            $criteria[] = 'BEFORE ' . date('d-M-Y', strtotime($filters['before'] . ' +1 day'));
        }
        if (!empty($filters['unread'])) {
            $criteria[] = 'UNSEEN';
        }
        if (!empty($filters['flagged'])) {
            $criteria[] = 'FLAGGED';
        }
        
        // Add text search
        if (!empty($textSearch)) {
            foreach ($textSearch as $term) {
                $criteria[] = 'TEXT "' . addslashes($term) . '"';
            }
        }
        
        $searchString = !empty($criteria) ? implode(' ', $criteria) : 'ALL';
        
        // Execute search
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID SEARCH {$searchString}");
        
        $response = $this->readResponse($tag);
        
        // Parse search results (UIDs)
        $uids = [];
        if (preg_match('/\* SEARCH (.+)/i', $response, $m)) {
            $uids = array_filter(array_map('intval', preg_split('/\s+/', trim($m[1]))));
        }
        
        if (empty($uids)) {
            return [];
        }
        
        // Sort by UID descending (newest first)
        rsort($uids);
        
        // Limit results
        $uids = array_slice($uids, 0, 100);
        
        // Fetch message details for search results
        $messages = [];
        $uidList = implode(',', $uids);
        
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uidList} (UID FLAGS INTERNALDATE RFC822.SIZE ENVELOPE)");
        
        $response = $this->readMultilineResponse($tag);
        $messages = $this->parseFetchResponse($response);
        
        // Sort by timestamp descending
        usort($messages, fn($a, $b) => ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0));
        
        return $messages;
    }
    
    /**
     * Check if a message structure has attachments
     */
    private function hasAttachments($structure): bool
    {
        if (!$structure) {
            return false;
        }
        
        // Multipart messages might have attachments
        if (isset($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $part) {
                // Check disposition for attachment
                if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                    return true;
                }
                // Check for inline with filename (also considered attachment)
                if (isset($part->dparameters) && is_array($part->dparameters)) {
                    foreach ($part->dparameters as $param) {
                        if (strtolower($param->attribute) === 'filename') {
                            return true;
                        }
                    }
                }
                // Check parameters for name (older style)
                if (isset($part->parameters) && is_array($part->parameters)) {
                    foreach ($part->parameters as $param) {
                        if (strtolower($param->attribute) === 'name') {
                            return true;
                        }
                    }
                }
                // Recursively check nested parts
                if ($this->hasAttachments($part)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get a short snippet (preview) of the message body
     * Returns first ~150 characters of plain text
     */
    private function getMessageSnippet(int $uid, $structure): ?string
    {
        if (!$structure) {
            return null;
        }
        
        try {
            // Find the text/plain part (fallback to html)
            $partNumber = $this->findTextPart($structure, 'plain');
            if (!$partNumber) {
                $partNumber = $this->findTextPart($structure, 'html');
            }

            if (!$partNumber) {
                // Simple text message (no parts)
                if (isset($structure->type) && $structure->type == 0) {
                    $partNumber = '1';
                } else {
                    return null;
                }
            }

            // Fetch first chunk of the part
            $body = @imap_fetchbody($this->connection, $uid, $partNumber, FT_UID | FT_PEEK);
            if (!$body) {
                return null;
            }

            // Decode transfer encoding (base64 / quoted-printable / etc.)
            $encoding = $this->getPartEncoding($structure, $partNumber);
            $body = $this->decodeBody($body, $encoding);

            // Convert charset to UTF-8 using the part's parameters (important for accented characters)
            $partStruct = $this->getPartStructure($structure, $partNumber);
            $partParams = $partStruct && isset($partStruct->parameters) ? $partStruct->parameters : [];
            $body = $this->convertCharset($body, $partParams);

            // Detect if content is HTML (check actual content, not just part type)
            $isHtml = false;
            if (isset($structure->subtype) && strtolower($structure->subtype) === 'html') {
                $isHtml = true;
            } elseif (preg_match('/^\s*<(!DOCTYPE|html|head|body|meta)/i', $body)) {
                $isHtml = true;
            } elseif (preg_match('/<(div|p|span|table|br|img)[^>]*>/i', $body)) {
                $isHtml = true;
            }

            // If HTML, strip tags properly
            if ($isHtml) {
                // Remove style/script blocks first (they contain non-displayable content)
                // Using ?? to preserve value if preg_replace fails
                $body = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $body) ?? $body;
                $body = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $body) ?? $body;
                $body = strip_tags($body ?? '');
            }

            // Clean up: decode HTML entities, normalize whitespace
            $body = html_entity_decode($body ?? '', ENT_QUOTES, 'UTF-8');
            
            // Strip forwarded message markers and headers from snippet
            $body = $this->stripForwardedHeaders($body);
            
            $body = preg_replace('/\s+/', ' ', $body) ?? $body;
            $body = trim($body ?? '');

            // Return first 150 characters (preserving multi-byte chars)
            if (mb_strlen($body) > 150) {
                return mb_substr($body, 0, 150);
            }

            return $body ?: null;
            
        } catch (\Exception $e) {
            error_log("getMessageSnippet error for UID $uid: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Strip forwarded message markers and embedded headers from snippet text
     */
    private function stripForwardedHeaders(string $body): string
    {
        // Remove forwarded message separator lines (Gmail, Outlook, generic)
        // e.g. "---------- Forwarded message ----------"
        $body = preg_replace('/-{3,}\s*(Forwarded message|Továbbított üzenet|Weitergeleitet|Original Message|Eredeti üzenet)\s*-{3,}/i', ' ', $body) ?? $body;
        
        // Remove inline forwarded-message header fields (multilingual)
        // From/Feladó/Von, Date/Dátum/Datum, Subject/Tárgy/Betreff, To/Címzett/An
        $headerLabels = 'From|Feladó|Von|De|Da|Date|Dátum|Datum|Sent|Elküldve|Subject|Tárgy|Betreff|Objet|Oggetto|To|Címzett|An|À|Cc|Másolat';
        $body = preg_replace('/(?:^|\s)(?:' . $headerLabels . '):\s*[^\n]{0,200}/im', ' ', $body) ?? $body;
        
        return $body;
    }
    
    /**
     * Find text part number in message structure
     */
    private function findTextPart($structure, string $subtype = 'plain', string $prefix = ''): ?string
    {
        // Simple message (no parts)
        if (!isset($structure->parts) || empty($structure->parts)) {
            if (isset($structure->type) && $structure->type == 0 && 
                isset($structure->subtype) && strtolower($structure->subtype) === $subtype) {
                return $prefix ?: '1';
            }
            return null;
        }
        
        // Multipart message
        foreach ($structure->parts as $i => $part) {
            $partNum = $prefix ? $prefix . '.' . ($i + 1) : ($i + 1);
            
            // Check if this part is text/plain or text/html
            if (isset($part->type) && $part->type == 0 &&
                isset($part->subtype) && strtolower($part->subtype) === $subtype) {
                // Skip if it's an attachment
                if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
                    continue;
                }
                return (string)$partNum;
            }
            
            // Recurse into nested multiparts
            if (isset($part->parts) && !empty($part->parts)) {
                $found = $this->findTextPart($part, $subtype, (string)$partNum);
                if ($found) {
                    return $found;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get encoding for a specific part
     */
    private function getPartEncoding($structure, string $partNumber): int
    {
        // For simple messages (no parts), encoding is directly on structure
        if (!isset($structure->parts) || empty($structure->parts)) {
            return $structure->encoding ?? 0;
        }
        
        // For multipart messages, traverse to the correct part
        $parts = explode('.', $partNumber);
        $current = $structure;
        
        foreach ($parts as $partIdx) {
            $idx = (int)$partIdx - 1;
            if (isset($current->parts[$idx])) {
                $current = $current->parts[$idx];
            } else {
                break;
            }
        }
        
        return $current->encoding ?? 0;
    }
    
    /**
     * Decode body based on encoding
     */
    private function decodeBody(string $body, int $encoding): string
    {
        switch ($encoding) {
            case 3: // BASE64
                return base64_decode($body);
            case 4: // QUOTED-PRINTABLE
                return quoted_printable_decode($body);
            case 1: // 8BIT
            case 2: // BINARY
            default:
                return $body;
        }
    }

    /**
     * Save message to Drafts folder
     */
    public function saveDraft(string $rawMessage, string $draftsFolder = 'Drafts'): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        $draftsFolder = mb_convert_encoding($draftsFolder, 'UTF7-IMAP', 'UTF-8');
        $server = $this->buildConnectionString($draftsFolder);
        
        return imap_append($this->connection, $server, $rawMessage, '\\Draft');
    }
    
    /**
     * Save message to Drafts folder and return the new UID
     */
    public function saveDraftAndGetUid(string $rawMessage, string $draftsFolder = 'Drafts'): ?int
    {
        if (!$this->isConnected()) {
            error_log("ImapService::saveDraftAndGetUid - Not connected");
            return null;
        }
        
        $encodedFolder = mb_convert_encoding($draftsFolder, 'UTF7-IMAP', 'UTF-8');
        $server = $this->buildConnectionString($encodedFolder);
        
        // Get UIDNEXT before appending
        $statusBefore = @imap_status($this->connection, $server, SA_UIDNEXT);
        $uidNextBefore = $statusBefore ? $statusBefore->uidnext : 0;
        
        // Append the draft
        $result = @imap_append($this->connection, $server, $rawMessage, '\\Draft');
        
        if (!$result) {
            error_log("ImapService::saveDraftAndGetUid - imap_append failed: " . imap_last_error());
            return null;
        }
        
        // Select the Drafts folder
        if (!$this->selectFolder($draftsFolder)) {
            error_log("ImapService::saveDraftAndGetUid - Failed to select folder: {$draftsFolder}");
            return null;
        }
        
        // Get UIDNEXT after appending - the new message should have UID = uidNextBefore
        $statusAfter = @imap_status($this->connection, $server, SA_UIDNEXT);
        
        if ($statusAfter && $statusAfter->uidnext > $uidNextBefore) {
            // New message UID is the UIDNEXT value from before the append
            $newUid = $uidNextBefore;
            error_log("ImapService::saveDraftAndGetUid - New draft UID: {$newUid}");
            return (int)$newUid;
        }
        
        // Fallback: search for the most recent message
        $uids = @imap_sort($this->connection, SORTARRIVAL, 1, SE_UID);
        if (!empty($uids)) {
            $newUid = $uids[0];
            error_log("ImapService::saveDraftAndGetUid - Fallback UID from sort: {$newUid}");
            return (int)$newUid;
        }
        
        error_log("ImapService::saveDraftAndGetUid - Could not determine new UID");
        return null;
    }

    /**
     * Save message to Sent folder
     */
    public function saveToSent(string $rawMessage, string $sentFolder = 'Sent'): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        $sentFolder = mb_convert_encoding($sentFolder, 'UTF7-IMAP', 'UTF-8');
        $server = $this->buildConnectionString($sentFolder);
        
        return imap_append($this->connection, $server, $rawMessage, '\\Seen');
    }

    // Helper methods

    private function decodeMimeHeader(string $text): string
    {
        $decoded = imap_mime_header_decode($text);
        $result = '';
        
        foreach ($decoded as $part) {
            $charset = $part->charset === 'default' ? 'UTF-8' : $part->charset;
            $result .= $this->convertToUtf8($part->text, $charset);
        }
        
        // Fallback: If result still contains raw quoted-printable patterns (=XX), decode them
        // This handles malformed headers that aren't properly MIME-encoded
        if (preg_match('/=[0-9A-F]{2}/i', $result)) {
            $decoded = quoted_printable_decode($result);
            // Ensure valid UTF-8
            $result = mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
        }
        
        return $result;
    }
    
    /**
     * Safely convert text to UTF-8 from any charset
     */
    private function convertToUtf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        
        // Already UTF-8
        if ($charset === 'UTF-8' || $charset === 'UTF8') {
            // Ensure valid UTF-8 by removing invalid sequences
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        
        // Charset aliases for Hungarian/Central European encodings
        $charsetAliases = [
            'WINDOWS-1250' => ['CP1250', 'WIN-1250', 'WINDOWS1250'],
            'ISO-8859-2' => ['ISO8859-2', 'LATIN2', 'LATIN-2', 'ISO_8859-2'],
            'ISO-8859-1' => ['ISO8859-1', 'LATIN1', 'LATIN-1', 'ISO_8859-1'],
        ];
        
        // Build list of charsets to try
        $charsetsToTry = [$charset];
        
        // Add variations without hyphens
        $charsetsToTry[] = str_replace('-', '', $charset);
        $charsetsToTry[] = str_replace('_', '-', $charset);
        
        // Add Windows/CP variations
        if (strpos($charset, 'WINDOWS') !== false) {
            $num = preg_replace('/[^0-9]/', '', $charset);
            $charsetsToTry[] = 'CP' . $num;
            $charsetsToTry[] = 'WINDOWS-' . $num;
        }
        
        // Check for known aliases
        foreach ($charsetAliases as $canonical => $aliases) {
            if ($charset === $canonical || in_array($charset, $aliases)) {
                array_unshift($charsetsToTry, $canonical);
                $charsetsToTry = array_merge($charsetsToTry, $aliases);
            }
        }
        
        // Remove duplicates
        $charsetsToTry = array_unique($charsetsToTry);
        
        // Try iconv first with TRANSLIT to preserve characters
        foreach ($charsetsToTry as $cs) {
            $converted = @iconv($cs, 'UTF-8//TRANSLIT', $text);
            if ($converted !== false && strlen($converted) > 0) {
                return $converted;
            }
        }
        
        // Try iconv with IGNORE as fallback
        foreach ($charsetsToTry as $cs) {
            $converted = @iconv($cs, 'UTF-8//IGNORE', $text);
            if ($converted !== false && strlen($converted) > 0) {
                return $converted;
            }
        }
        
        // Try mb_convert_encoding for common encodings (handles Hungarian well)
        $mbEncodings = ['ISO-8859-2', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1250', 'Windows-1252', 'ASCII'];
        foreach ($mbEncodings as $enc) {
            if (stripos($charset, str_replace('-', '', $enc)) !== false || 
                stripos($charset, $enc) !== false) {
                try {
                    $result = @mb_convert_encoding($text, 'UTF-8', $enc);
                    if ($result !== false && strlen($result) > 0) {
                        return $result;
                    }
                } catch (\Throwable $e) {
                    // Continue trying
                }
            }
        }
        
        // Last resort: try auto-detection
        try {
            $detected = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-2', 'ISO-8859-1', 'Windows-1250', 'ASCII'], true);
            if ($detected && $detected !== 'UTF-8') {
                $result = @mb_convert_encoding($text, 'UTF-8', $detected);
                if ($result !== false) {
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        
        // Return as-is (might have some garbled chars but won't crash)
        return $text;
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return $matches[1];
        }
        return $from;
    }

    private function formatAddressList(array $addresses): array
    {
        $result = [];
        foreach ($addresses as $addr) {
            $email = ($addr->mailbox ?? '') . '@' . ($addr->host ?? '');
            $name = isset($addr->personal) ? $this->decodeMimeHeader($addr->personal) : '';
            $result[] = [
                'email' => $email,
                'name' => $name,
                'display' => $name ? "$name <$email>" : $email,
            ];
        }
        return $result;
    }

    private function decodeContent(string $content, int $encoding): string
    {
        switch ($encoding) {
            case 3: // BASE64
                return base64_decode($content);
            case 4: // QUOTED-PRINTABLE
                return quoted_printable_decode($content);
            default:
                return $content;
        }
    }

    private function convertCharset(string $content, array $parameters): string
    {
        $charset = 'UTF-8';
        foreach ($parameters as $param) {
            if (strtolower($param->attribute) === 'charset') {
                $charset = $param->value;
                break;
            }
        }
        
        if (strtoupper($charset) !== 'UTF-8' && strtoupper($charset) !== 'UTF8') {
            $content = $this->convertToUtf8($content, $charset);
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            // Part claims UTF-8 but the bytes are not valid UTF-8 (commonly a
            // mislabelled ISO-8859-2 / Windows-1250 message). Detect the real
            // encoding and convert so it does not render as mojibake.
            $detected = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-2', 'ISO-8859-1', 'Windows-1250'], true);
            if ($detected && $detected !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $detected);
            } else {
                // Strip invalid byte sequences as a last resort.
                $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            }
        }
        
        return $content;
    }

    private function getMimeType($part): string
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type = $types[$part->type] ?? 'application';
        $subtype = strtolower($part->subtype ?? 'octet-stream');
        return "$type/$subtype";
    }
    
    /**
     * Get label filters from the last search query
     * Labels are stored in database, not IMAP, so they're extracted for post-filtering
     */
    public function getLastSearchLabelFilters(): array
    {
        return $this->lastSearchLabelFilters;
    }
    
    /**
     * Search messages and return UIDs
     */
    public function searchMessages(string $criteria = 'ALL'): array
    {
        if (!$this->isConnected()) {
            return [];
        }
        
        // OAuth connection uses stream-based commands
        if ($this->isOAuthConnection) {
            return $this->searchMessagesOAuth($criteria);
        }
        
        $results = @imap_search($this->connection, $criteria, SE_UID);
        
        if ($results === false) {
            return [];
        }
        
        return $results;
    }
    
    /**
     * Get all UIDs in a folder (for reconciliation)
     * Used by cron job to compare database records against actual IMAP state
     * 
     * @param string $folder Folder name
     * @return array|false Array of UIDs, or false on failure
     */
    public function searchAllUids(string $folder): array|false
    {
        if (!$this->selectFolder($folder)) {
            return false;
        }
        
        return $this->searchMessages('ALL');
    }
    
    /**
     * Search messages using OAuth stream connection
     */
    private function searchMessagesOAuth(string $criteria = 'ALL'): array
    {
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID SEARCH {$criteria}");
        
        $response = $this->readResponse($tag);
        
        // Parse search results (UIDs)
        $uids = [];
        if (preg_match('/\* SEARCH (.+)/i', $response, $m)) {
            $uids = array_filter(array_map('intval', preg_split('/\s+/', trim($m[1]))));
        }
        
        return $uids;
    }
    
    /**
     * Get UIDs with timestamps from a folder (lightweight, for cross-folder sorting).
     *
     * Returns array of ['uid' => int, 'timestamp' => int]. Always returns the
     * UIDs we *could* parse, even if some headers in the folder are corrupt;
     * see getLastScanMeta() for state, fallback_stage, and bad_uids[].
     *
     * The fallback ladder is:
     *   full-range -> binary split -> 50-msg chunks -> per-UID FT_UID
     *
     * A folder visible in imap_list MUST be represented in the result of
     * the all-folders scan, even when fetch fails. The caller surfaces the
     * folder via degraded_folders[] when retrieved < total.
     */
    public function getUidsWithTimestamps(string $folder): array
    {
        $this->resetScanMeta($folder);

        if (!$this->selectFolder($folder)) {
            $this->markScanFailure(
                'selectFolder failed',
                self::SCAN_STAGE_FULL_RANGE
            );
            StructuredLog::emit('allmail_skip', [
                'folder_path' => $folder,
                'reason' => 'selectFolder failed',
                'fallback_stage' => self::SCAN_STAGE_FULL_RANGE,
            ]);
            return [];
        }

        if ($this->isOAuthConnection) {
            return $this->getUidsWithTimestampsOAuth($folder);
        }

        $total = @imap_num_msg($this->connection);
        if ($total === false) {
            $this->markScanFailure(
                'imap_num_msg returned false (connection dead?)',
                self::SCAN_STAGE_FULL_RANGE
            );
            StructuredLog::emit('allmail_skip', [
                'folder_path' => $folder,
                'reason' => 'imap_num_msg returned false',
            ]);
            return [];
        }

        $this->lastScanMeta['total'] = (int) $total;
        if ($total === 0) {
            $this->lastScanMeta['state'] = 'healthy';
            return [];
        }

        $start = max(1, $total - self::ALLMAIL_SCAN_LIMIT + 1);

        $startedAt = microtime(true);
        $parsed = $this->scanNativeRange($folder, $start, $total, 0, self::SCAN_STAGE_FULL_RANGE);

        $this->finalizeScanMeta($folder, $parsed, $startedAt);
        return $parsed;
    }

    /**
     * OAuth path: get UIDs with timestamps using raw socket commands.
     *
     * Parity with the native path: same fallback ladder, same parseable-UID
     * rules, same memory bounds, same structured logs.
     */
    private function getUidsWithTimestampsOAuth(string $folder): array
    {
        $startedAt = microtime(true);

        $allUids = $this->searchMessagesOAuth('ALL');
        if (empty($allUids)) {
            $this->lastScanMeta['state'] = 'healthy';
            $this->lastScanMeta['total'] = 0;
            $this->finalizeScanMeta($folder, [], $startedAt);
            return [];
        }

        if (count($allUids) > self::ALLMAIL_SCAN_LIMIT) {
            $allUids = array_slice($allUids, -self::ALLMAIL_SCAN_LIMIT);
        }
        $this->lastScanMeta['total'] = count($allUids);

        $parsed = $this->scanOAuthUids($folder, $allUids, 0, self::SCAN_STAGE_FULL_RANGE);
        $this->finalizeScanMeta($folder, $parsed, $startedAt);
        return $parsed;
    }

    /**
     * Read-only accessor for the result metadata of the most recent scan.
     */
    public function getLastScanMeta(): array
    {
        return $this->lastScanMeta;
    }

    private function resetScanMeta(string $folder): void
    {
        $this->lastScanMeta = [
            'folder_path' => $folder,
            'state' => 'healthy',
            'fallback_stage' => self::SCAN_STAGE_FULL_RANGE,
            'total' => 0,
            'retrieved' => 0,
            'bad_uids' => [],
            'bad_uids_truncated_count' => 0,
            'truncated' => [],
            'failure_reason' => null,
            'segments_attempted' => 0,
            'duration_ms' => 0,
        ];
    }

    private function markScanFailure(string $reason, string $fallbackStage): void
    {
        $this->lastScanMeta['state'] = 'degraded';
        $this->lastScanMeta['fallback_stage'] = $fallbackStage;
        $this->lastScanMeta['failure_reason'] = $reason;
    }

    private function recordBadUid(int $uid, string $reason): void
    {
        $cap = self::SCAN_MAX_BAD_UIDS_REPORTED;
        if (count($this->lastScanMeta['bad_uids']) < $cap) {
            $this->lastScanMeta['bad_uids'][] = $uid;
        } else {
            $this->lastScanMeta['bad_uids_truncated_count']++;
            if (!in_array('bad_uids', $this->lastScanMeta['truncated'], true)) {
                $this->lastScanMeta['truncated'][] = 'bad_uids';
                StructuredLog::emit('truncation', [
                    'folder_path' => $this->lastScanMeta['folder_path'] ?? '',
                    'reason' => 'bad_uids_capped_at_' . $cap,
                ]);
            }
        }
        $this->lastScanMeta['state'] = 'degraded';
        if ($this->lastScanMeta['failure_reason'] === null) {
            $this->lastScanMeta['failure_reason'] = $reason;
        }
    }

    private function finalizeScanMeta(string $folder, array $parsed, float $startedAt): void
    {
        $this->lastScanMeta['retrieved'] = count($parsed);
        $this->lastScanMeta['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        // Promote retrieved < total to degraded if we didn't already.
        if ($this->lastScanMeta['state'] === 'healthy'
            && $this->lastScanMeta['total'] > 0
            && $this->lastScanMeta['retrieved'] < $this->lastScanMeta['total']
            && $this->lastScanMeta['fallback_stage'] === self::SCAN_STAGE_FULL_RANGE) {
            $this->lastScanMeta['state'] = 'degraded';
            $this->lastScanMeta['failure_reason'] = $this->lastScanMeta['failure_reason']
                ?? 'retrieved fewer messages than expected';
        }

        if ($this->lastScanMeta['fallback_stage'] !== self::SCAN_STAGE_FULL_RANGE
            || $this->lastScanMeta['state'] !== 'healthy') {
            StructuredLog::emit('allmail_fallback', [
                'folder_path' => $folder,
                'fallback_stage' => $this->lastScanMeta['fallback_stage'],
                'duration_ms' => $this->lastScanMeta['duration_ms'],
                'reason' => $this->lastScanMeta['failure_reason'],
            ]);
        }
    }

    /**
     * Parse one imap_fetch_overview row into our [uid,timestamp] shape, or
     * return null if the row fails the four-clause "parseable UID" rule.
     *
     * Definition (per Wave 1 plan):
     *   - uid is a positive integer
     *   - INTERNALDATE parses to a valid Unix ts, OR a fallback ts is
     *     recorded and we annotate the meta once
     *   - the UID has not already been seen in this scan
     *   - mb_decode_mimeheader on subject/from does not throw a ValueError
     */
    private function parseOverviewRow(object $row, array &$seen, ?string &$fallbackTsSource): ?array
    {
        $uid = isset($row->uid) ? (int) $row->uid : 0;
        if ($uid <= 0) {
            return null;
        }
        if (isset($seen[$uid])) {
            return null;
        }

        $ts = null;
        if (isset($row->udate) && is_numeric($row->udate) && (int) $row->udate > 0) {
            $ts = (int) $row->udate;
        } else {
            $candidate = isset($row->date) && is_string($row->date) ? @strtotime($row->date) : false;
            if ($candidate !== false && $candidate > 0) {
                $ts = (int) $candidate;
            } else {
                $ts = time();
                $fallbackTsSource = $fallbackTsSource ?? 'now';
            }
        }

        // mb_decode_mimeheader can throw ValueError on PHP 8.x for malformed
        // encoded-words. Header decode failure should not exclude the UID,
        // but it does promote us to "degraded" because the header is broken.
        try {
            if (isset($row->subject) && is_string($row->subject) && $row->subject !== '') {
                @mb_decode_mimeheader($row->subject);
            }
            if (isset($row->from) && is_string($row->from) && $row->from !== '') {
                @mb_decode_mimeheader($row->from);
            }
        } catch (\Throwable $e) {
            $this->lastScanMeta['state'] = 'degraded';
            $this->lastScanMeta['failure_reason'] = $this->lastScanMeta['failure_reason']
                ?? 'mb_decode_mimeheader threw on uid ' . $uid;
        }

        if (count($seen) >= self::SCAN_MAX_UID_TRACK) {
            if (!in_array('uid_track', $this->lastScanMeta['truncated'], true)) {
                $this->lastScanMeta['truncated'][] = 'uid_track';
                StructuredLog::emit('truncation', [
                    'folder_path' => $this->lastScanMeta['folder_path'] ?? '',
                    'reason' => 'uid_track_capped_at_' . self::SCAN_MAX_UID_TRACK,
                ]);
            }
            return null;
        }

        $seen[$uid] = true;
        return ['uid' => $uid, 'timestamp' => $ts];
    }

    /**
     * Native (non-OAuth) scan with the full fallback ladder. Returns the
     * parsed [uid,timestamp] entries. Updates $this->lastScanMeta in place.
     *
     * @param int $depth          How deep we are in the recursive split.
     * @param string $stage       Stage that is currently being attempted; the
     *                            scan promotes its meta to whatever stage
     *                            actually delivered the data.
     */
    private function scanNativeRange(string $folder, int $start, int $end, int $depth, string $stage): array
    {
        if ($end < $start) {
            return [];
        }

        $segCount = ++$this->lastScanMeta['segments_attempted'];
        if ($segCount > self::SCAN_MAX_SEGMENTS_PENDING) {
            if (!in_array('segments_pending', $this->lastScanMeta['truncated'], true)) {
                $this->lastScanMeta['truncated'][] = 'segments_pending';
                StructuredLog::emit('truncation', [
                    'folder_path' => $folder,
                    'reason' => 'segments_pending_capped_at_' . self::SCAN_MAX_SEGMENTS_PENDING,
                ]);
            }
            $this->markScanFailure('segments_pending cap reached', $stage);
            return [];
        }

        $range = "{$start}:{$end}";
        $overviews = @imap_fetch_overview($this->connection, $range, 0);

        if ($overviews !== false && is_array($overviews)) {
            $seen = [];
            $fallbackTsSource = null;
            $parsed = [];
            foreach ($overviews as $row) {
                $entry = $this->parseOverviewRow($row, $seen, $fallbackTsSource);
                if ($entry === null) {
                    $rowUid = isset($row->uid) ? (int) $row->uid : 0;
                    if ($rowUid > 0) {
                        $this->recordBadUid($rowUid, 'unparseable_overview_row');
                    }
                    continue;
                }
                $parsed[] = $entry;
            }
            // Promote stage only if we descended below full-range.
            if ($stage !== self::SCAN_STAGE_FULL_RANGE
                && $this->stageRank($stage) > $this->stageRank($this->lastScanMeta['fallback_stage'])) {
                $this->lastScanMeta['fallback_stage'] = $stage;
            }
            return $parsed;
        }

        // imap_fetch_overview returned false. Descend the ladder.
        if ($depth >= self::SCAN_MAX_SPLIT_DEPTH) {
            $this->markScanFailure(
                'imap_fetch_overview false at max split depth (range ' . $range . ')',
                self::SCAN_STAGE_PER_UID
            );
            return $this->scanNativePerUid($folder, $start, $end);
        }

        $size = $end - $start + 1;
        if ($size <= self::SCAN_CHUNK_SIZE) {
            // Below chunk size: fall straight to per-UID for this segment.
            $this->markScanFailure(
                'imap_fetch_overview false at chunk size (range ' . $range . ')',
                self::SCAN_STAGE_PER_UID
            );
            return $this->scanNativePerUid($folder, $start, $end);
        }

        if ($size <= self::SCAN_MIN_SPLIT_SIZE) {
            $this->markScanFailure(
                'imap_fetch_overview false at split floor (range ' . $range . ')',
                self::SCAN_STAGE_CHUNK_50
            );
            return $this->scanNativeChunked($folder, $start, $end);
        }

        // Binary split: recursively halve.
        $mid = intdiv($start + $end, 2);
        $this->markScanFailure(
            'imap_fetch_overview false; binary-split at depth ' . ($depth + 1) . ' (range ' . $range . ')',
            self::SCAN_STAGE_BINARY_SPLIT
        );
        $left = $this->scanNativeRange($folder, $start, $mid, $depth + 1, self::SCAN_STAGE_BINARY_SPLIT);
        $right = $this->scanNativeRange($folder, $mid + 1, $end, $depth + 1, self::SCAN_STAGE_BINARY_SPLIT);
        return array_merge($left, $right);
    }

    /**
     * Walk the failing segment in fixed-size chunks. Used as the last common
     * tier above per-UID FT_UID.
     */
    private function scanNativeChunked(string $folder, int $start, int $end): array
    {
        $parsed = [];
        for ($s = $start; $s <= $end; $s += self::SCAN_CHUNK_SIZE) {
            $e = min($end, $s + self::SCAN_CHUNK_SIZE - 1);
            $range = "{$s}:{$e}";
            $overviews = @imap_fetch_overview($this->connection, $range, 0);
            if ($overviews === false || !is_array($overviews)) {
                $perUid = $this->scanNativePerUid($folder, $s, $e);
                $parsed = array_merge($parsed, $perUid);
                continue;
            }
            $seen = [];
            $fallbackTsSource = null;
            foreach ($overviews as $row) {
                $entry = $this->parseOverviewRow($row, $seen, $fallbackTsSource);
                if ($entry === null) {
                    $rowUid = isset($row->uid) ? (int) $row->uid : 0;
                    if ($rowUid > 0) {
                        $this->recordBadUid($rowUid, 'unparseable_overview_row');
                    }
                    continue;
                }
                $parsed[] = $entry;
            }
        }
        if ($this->stageRank(self::SCAN_STAGE_CHUNK_50) > $this->stageRank($this->lastScanMeta['fallback_stage'])) {
            $this->lastScanMeta['fallback_stage'] = self::SCAN_STAGE_CHUNK_50;
        }
        return $parsed;
    }

    /**
     * Last-resort per-UID FT_UID fetch within a sequence-number range. Walks
     * each msgno individually so a single bad row can't poison the rest.
     */
    private function scanNativePerUid(string $folder, int $start, int $end): array
    {
        $parsed = [];
        $seen = [];
        $fallbackTsSource = null;
        for ($n = $start; $n <= $end; $n++) {
            $row = @imap_fetch_overview($this->connection, (string) $n, 0);
            if ($row === false || !is_array($row) || empty($row[0])) {
                $this->recordBadUid($n, 'per_uid_msgno_unfetchable');
                continue;
            }
            $entry = $this->parseOverviewRow($row[0], $seen, $fallbackTsSource);
            if ($entry === null) {
                $rowUid = isset($row[0]->uid) ? (int) $row[0]->uid : 0;
                if ($rowUid > 0) {
                    $this->recordBadUid($rowUid, 'per_uid_unparseable_row');
                }
                continue;
            }
            $parsed[] = $entry;
        }
        if ($this->stageRank(self::SCAN_STAGE_PER_UID) > $this->stageRank($this->lastScanMeta['fallback_stage'])) {
            $this->lastScanMeta['fallback_stage'] = self::SCAN_STAGE_PER_UID;
        }
        return $parsed;
    }

    /**
     * OAuth scan: walk the UID list with a tiered ladder analogous to the
     * native path. Full batch first; if it returns nothing usable we
     * binary-split, then chunk, then fall through to a per-UID fetch.
     *
     * @param int[] $uids
     */
    private function scanOAuthUids(string $folder, array $uids, int $depth, string $stage): array
    {
        if (empty($uids)) {
            return [];
        }
        $segCount = ++$this->lastScanMeta['segments_attempted'];
        if ($segCount > self::SCAN_MAX_SEGMENTS_PENDING) {
            if (!in_array('segments_pending', $this->lastScanMeta['truncated'], true)) {
                $this->lastScanMeta['truncated'][] = 'segments_pending';
                StructuredLog::emit('truncation', [
                    'folder_path' => $folder,
                    'reason' => 'segments_pending_capped_at_' . self::SCAN_MAX_SEGMENTS_PENDING,
                ]);
            }
            $this->markScanFailure('segments_pending cap reached', $stage);
            return [];
        }

        $entries = $this->fetchOAuthBatch($uids);
        if ($entries !== null && !empty($entries)) {
            if ($stage !== self::SCAN_STAGE_FULL_RANGE
                && $this->stageRank($stage) > $this->stageRank($this->lastScanMeta['fallback_stage'])) {
                $this->lastScanMeta['fallback_stage'] = $stage;
            }
            return $entries;
        }

        if ($depth >= self::SCAN_MAX_SPLIT_DEPTH || count($uids) === 1) {
            $this->markScanFailure(
                'OAuth UID FETCH unparseable; falling to per-UID',
                self::SCAN_STAGE_PER_UID
            );
            return $this->scanOAuthPerUid($uids);
        }

        $size = count($uids);
        if ($size <= self::SCAN_CHUNK_SIZE) {
            $this->markScanFailure(
                'OAuth UID FETCH unparseable at chunk size',
                self::SCAN_STAGE_PER_UID
            );
            return $this->scanOAuthPerUid($uids);
        }

        if ($size <= self::SCAN_MIN_SPLIT_SIZE) {
            $this->markScanFailure(
                'OAuth UID FETCH unparseable at split floor',
                self::SCAN_STAGE_CHUNK_50
            );
            return $this->scanOAuthChunked($uids);
        }

        $mid = intdiv($size, 2);
        $left = array_slice($uids, 0, $mid);
        $right = array_slice($uids, $mid);
        $this->markScanFailure(
            'OAuth UID FETCH unparseable; binary-split at depth ' . ($depth + 1),
            self::SCAN_STAGE_BINARY_SPLIT
        );
        return array_merge(
            $this->scanOAuthUids($folder, $left, $depth + 1, self::SCAN_STAGE_BINARY_SPLIT),
            $this->scanOAuthUids($folder, $right, $depth + 1, self::SCAN_STAGE_BINARY_SPLIT)
        );
    }

    /**
     * OAuth chunked scan: 50-uid groups, falling back to per-UID on failure.
     *
     * @param int[] $uids
     */
    private function scanOAuthChunked(array $uids): array
    {
        $out = [];
        foreach (array_chunk($uids, self::SCAN_CHUNK_SIZE) as $chunk) {
            $entries = $this->fetchOAuthBatch($chunk);
            if ($entries === null || empty($entries)) {
                $out = array_merge($out, $this->scanOAuthPerUid($chunk));
                continue;
            }
            $out = array_merge($out, $entries);
        }
        if ($this->stageRank(self::SCAN_STAGE_CHUNK_50) > $this->stageRank($this->lastScanMeta['fallback_stage'])) {
            $this->lastScanMeta['fallback_stage'] = self::SCAN_STAGE_CHUNK_50;
        }
        return $out;
    }

    /**
     * OAuth per-UID scan. Walks each UID individually so one bad row can't
     * take down the whole chunk.
     *
     * @param int[] $uids
     */
    private function scanOAuthPerUid(array $uids): array
    {
        $out = [];
        $seen = [];
        foreach ($uids as $uid) {
            $entries = $this->fetchOAuthBatch([$uid]);
            if ($entries === null || empty($entries)) {
                $this->recordBadUid((int) $uid, 'oauth_per_uid_unfetchable');
                continue;
            }
            foreach ($entries as $entry) {
                $entryUid = (int) ($entry['uid'] ?? 0);
                if ($entryUid <= 0 || isset($seen[$entryUid])) {
                    continue;
                }
                $seen[$entryUid] = true;
                $out[] = $entry;
            }
        }
        if ($this->stageRank(self::SCAN_STAGE_PER_UID) > $this->stageRank($this->lastScanMeta['fallback_stage'])) {
            $this->lastScanMeta['fallback_stage'] = self::SCAN_STAGE_PER_UID;
        }
        return $out;
    }

    /**
     * Issue one OAuth UID FETCH for a batch and parse the response into
     * [uid,timestamp] entries. Returns null on transport-level failure
     * (so callers can decide whether to recurse), or [] for no usable rows.
     *
     * @param int[] $uids
     * @return array|null
     */
    private function fetchOAuthBatch(array $uids): ?array
    {
        if (empty($uids)) {
            return [];
        }
        $uidList = implode(',', array_map('intval', $uids));
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uidList} (UID INTERNALDATE)");

        try {
            $response = $this->readMultilineResponse($tag);
        } catch (\Throwable $e) {
            return null;
        }
        if ($response === '' || $response === null) {
            return null;
        }

        $entries = [];
        $seen = [];
        if (preg_match_all('/\* \d+ FETCH \(([^)]+)\)/i', $response, $blocks)) {
            foreach ($blocks[1] as $block) {
                $uid = null;
                $date = null;
                if (preg_match('/UID\s+(\d+)/i', $block, $m)) {
                    $uid = (int) $m[1];
                }
                if (preg_match('/INTERNALDATE\s+"([^"]+)"/i', $block, $m)) {
                    $parsed = @strtotime($m[1]);
                    if ($parsed !== false && $parsed > 0) {
                        $date = (int) $parsed;
                    }
                }
                if ($uid === null || $uid <= 0) {
                    continue;
                }
                if (isset($seen[$uid])) {
                    continue;
                }
                if ($date === null) {
                    $date = time();
                }
                if (count($seen) >= self::SCAN_MAX_UID_TRACK) {
                    if (!in_array('uid_track', $this->lastScanMeta['truncated'], true)) {
                        $this->lastScanMeta['truncated'][] = 'uid_track';
                        StructuredLog::emit('truncation', [
                            'folder_path' => $this->lastScanMeta['folder_path'] ?? '',
                            'reason' => 'uid_track_capped_at_' . self::SCAN_MAX_UID_TRACK,
                        ]);
                    }
                    break;
                }
                $seen[$uid] = true;
                $entries[] = ['uid' => $uid, 'timestamp' => $date];
            }
        }
        return $entries;
    }

    private function stageRank(string $stage): int
    {
        return match ($stage) {
            self::SCAN_STAGE_FULL_RANGE => 0,
            self::SCAN_STAGE_BINARY_SPLIT => 1,
            self::SCAN_STAGE_CHUNK_50 => 2,
            self::SCAN_STAGE_PER_UID => 3,
            default => 0,
        };
    }
    
    /**
     * Fetch full message details for specific UIDs in a folder
     * Used by All Mail pagination to get details only for the current page
     */
    public function getMessageDetailsByUids(string $folder, array $uids): array
    {
        if (empty($uids) || !$this->selectFolder($folder)) {
            return [];
        }
        
        if ($this->isOAuthConnection) {
            return $this->getMessageDetailsByUidsOAuth($uids);
        }
        
        $messages = [];
        foreach ($uids as $uid) {
            $msgno = @imap_msgno($this->connection, $uid);
            if ($msgno <= 0) {
                continue;
            }
            
            $overview = @imap_fetch_overview($this->connection, (string)$uid, FT_UID);
            if (!$overview || !isset($overview[0])) {
                continue;
            }

            $rawHeaders = @imap_fetchheader($this->connection, $uid, FT_UID) ?: '';
            $formatted = $this->formatMessageOverview($overview[0], $rawHeaders);

            $structure = @imap_fetchstructure($this->connection, $msgno);
            $formatted['has_attachment'] = $this->hasAttachments($structure);
            if ($rawHeaders) {
                $unsubInfo = $this->parseUnsubscribeHeaders($rawHeaders);
                $formatted['unsubscribe_url'] = $unsubInfo['unsubscribe_url'];
                $formatted['unsubscribe_email'] = $unsubInfo['unsubscribe_email'];
                $formatted['unsubscribe_one_click'] = $unsubInfo['unsubscribe_one_click'];
                
                $threadingInfo = $this->parseThreadingHeaders($rawHeaders);
                $formatted['in_reply_to'] = $threadingInfo['in_reply_to'];
                $formatted['references'] = $threadingInfo['references'];
            }
            
            $formatted['snippet'] = null;
            $messages[] = $formatted;
        }
        
        return $messages;
    }
    
    /**
     * OAuth path: fetch full message details for specific UIDs
     */
    private function getMessageDetailsByUidsOAuth(array $uids): array
    {
        $uidList = implode(',', $uids);
        $tag = $this->getNextTag();
        $this->writeLine("{$tag} UID FETCH {$uidList} (UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES LIST-UNSUBSCRIBE LIST-UNSUBSCRIBE-POST)])");
        
        $response = $this->readMultilineResponse($tag);
        return $this->parseFetchResponse($response);
    }
    
    /**
     * Batch fetch full message bodies for multiple UIDs in a single folder.
     * OAuth: uses one UID FETCH command per chunk (10 UIDs).
     * Non-OAuth: per-message fetch (PHP imap extension limitation).
     *
     * @return array<int, array> Parsed messages keyed by UID
     */
    public function getMessagesBatch(string $folder, array $uids): array
    {
        if (empty($uids) || !$this->selectFolder($folder)) {
            return [];
        }
        
        if ($this->isOAuthConnection) {
            return $this->getMessagesBatchOAuth($uids);
        }
        
        $messages = [];
        foreach ($uids as $uid) {
            $msg = $this->getMessageNonOAuth((int)$uid);
            if ($msg) {
                $messages[(int)$uid] = $msg;
            }
        }
        return $messages;
    }
    
    /**
     * OAuth batch: fetch full bodies for multiple UIDs in one command per chunk.
     */
    private function getMessagesBatchOAuth(array $uids): array
    {
        $messages = [];
        $chunks = array_chunk(array_map('intval', $uids), 10);
        
        foreach ($chunks as $chunk) {
            $uidList = implode(',', $chunk);
            $tag = $this->getNextTag();
            $this->writeLine("{$tag} UID FETCH {$uidList} (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[])");
            
            $parsed = $this->readBatchBodyResponse($tag);
            foreach ($parsed as $uid => $msgData) {
                $messages[$uid] = $msgData;
            }
        }
        
        return $messages;
    }
    
    /**
     * Read a multi-message FETCH response where each message contains a body literal.
     * @return array<int, array> Parsed messages keyed by UID
     */
    private function readBatchBodyResponse(string $tag): array
    {
        $messages = [];
        $metaLine = '';
        $bodyContent = '';
        $inLiteral = false;
        $literalSize = 0;
        $literalRead = 0;
        $currentUid = null;
        $maxLines = 100000;
        $lineCount = 0;
        
        while ($lineCount < $maxLines) {
            $line = $this->readLine();
            $lineCount++;
            if ($line === null) {
                break;
            }
            
            if (strpos($line, $tag . ' ') === 0) {
                if ($currentUid !== null && !empty($bodyContent)) {
                    $messages[$currentUid] = $this->parseSingleBodyFetch($currentUid, $metaLine, $bodyContent);
                }
                break;
            }
            
            if ($inLiteral) {
                $bodyContent .= $line . "\n";
                $literalRead += strlen($line) + 1;
                
                if ($literalRead >= $literalSize) {
                    $inLiteral = false;
                }
                continue;
            }
            
            if (preg_match('/^\* (\d+) FETCH \(/i', $line)) {
                if ($currentUid !== null && !empty($bodyContent)) {
                    $messages[$currentUid] = $this->parseSingleBodyFetch($currentUid, $metaLine, $bodyContent);
                }
                
                $metaLine = $line;
                $bodyContent = '';
                $currentUid = null;
                
                if (preg_match('/UID (\d+)/', $line, $m)) {
                    $currentUid = (int)$m[1];
                }
                
                if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                    $literalSize = (int)$m[1];
                    $literalRead = 0;
                    $inLiteral = true;
                    $bodyContent = '';
                }
            } elseif (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
                $literalSize = (int)$m[1];
                $literalRead = 0;
                $inLiteral = true;
                $bodyContent = '';
            }
        }
        
        return $messages;
    }
    
    /**
     * Parse a single message from batch body fetch (meta line + raw MIME body).
     */
    private function parseSingleBodyFetch(int $uid, string $metaLine, string $rawBody): array
    {
        $flags = '';
        if (preg_match('/FLAGS \(([^)]*)\)/', $metaLine, $m)) {
            $flags = $m[1];
        }
        
        $date = '';
        if (preg_match('/INTERNALDATE "([^"]+)"/', $metaLine, $m)) {
            $date = $m[1];
        }
        
        $size = 0;
        if (preg_match('/RFC822\.SIZE (\d+)/', $metaLine, $m)) {
            $size = (int)$m[1];
        }
        
        $rawBody = preg_replace('/\s*INTERNALDATE\s+"[^"]*".*$/s', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('/\)\s*\*\s*\d+\s+FETCH\s+\(.*$/s', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('/\)\s*[A-Z]\d+\s+OK\s+.*$/s', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('/\s*FLAGS\s+\(.*$/s', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('/\s*UID\s+\d+\s+FLAGS\s+\(.*$/s', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('/\s*RFC822\.SIZE\s+\d+.*$/s', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('/\s*\*\s*\d+\s+FETCH\s+.*$/s', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('/\)\s*\)\s*$/s', '', $rawBody) ?? $rawBody;
        $rawBody = preg_replace('/\)\s*$/', '', $rawBody) ?? $rawBody;
        $rawBody = rtrim($rawBody);
        
        $headerEnd = strpos($rawBody, "\r\n\r\n");
        if ($headerEnd === false) {
            $headerEnd = strpos($rawBody, "\n\n");
        }
        
        $rawHeaders = $headerEnd !== false ? substr($rawBody, 0, $headerEnd) : $rawBody;
        $rawBodyContent = $headerEnd !== false ? substr($rawBody, $headerEnd + 2) : '';
        
        $headers = $this->parseHeaders($rawHeaders);
        $body = $this->parseBodyContent($rawBodyContent, $headers);
        
        $bodyHtml = $this->cleanImapMetadataFromBody($body['html'] ?? '');
        $bodyText = $this->cleanImapMetadataFromBody($body['text'] ?? '');
        $bodyCalendar = $body['calendar'] ?? '';
        $attachments = $this->parseAttachmentsFromContent($rawBodyContent, $headers);
        
        $parsedDate = $headers['date'] ?? $date;
        $timestamp = strtotime($parsedDate);
        if ($timestamp === false) {
            $timestamp = time();
        }
        
        $unsubscribeInfo = $this->parseUnsubscribeHeaders($rawHeaders);
        $spamInfo = $this->parseSpamHeaders($rawHeaders);
        
        $subject = $this->decodeMimeHeader($headers['subject'] ?? '(No Subject)');
        $fromList = $this->parseHeaderAddresses($headers['from'] ?? '');
        $fromName = $fromList[0]['name'] ?? $fromList[0]['email'] ?? '';
        $fromEmail = $fromList[0]['email'] ?? '';
        $inReplyTo = isset($headers['in-reply-to']) ? trim($headers['in-reply-to'], '<>') : null;
        
        $reactionInfo = $this->detectReactionEmail($subject, $bodyHtml, $bodyText, $inReplyTo, $fromName, $fromEmail);
        
        return array_merge([
            'uid' => $uid,
            'msgno' => 0,
            'message_id' => trim($headers['message-id'] ?? '', '<>'),
            'subject' => $subject,
            'from' => $fromList,
            'to' => $this->parseHeaderAddresses($headers['to'] ?? ''),
            'cc' => $this->parseHeaderAddresses($headers['cc'] ?? ''),
            'bcc' => [],
            'reply_to' => $this->parseHeaderAddresses($headers['reply-to'] ?? $headers['from'] ?? ''),
            'date' => $parsedDate,
            'timestamp' => $timestamp,
            'size' => $size,
            'seen' => stripos($flags, '\\Seen') !== false,
            'flagged' => stripos($flags, '\\Flagged') !== false,
            'answered' => stripos($flags, '\\Answered') !== false,
            'important' => $this->isHighImportance($headers),
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'body_calendar' => $bodyCalendar,
            'attachments' => $attachments,
            'has_attachment' => count($attachments) > 0,
            'linked_account' => null,
            'auto_label' => null,
            'unsubscribe_url' => $unsubscribeInfo['unsubscribe_url'],
            'unsubscribe_email' => $unsubscribeInfo['unsubscribe_email'],
            'unsubscribe_one_click' => $unsubscribeInfo['unsubscribe_one_click'],
            'spam_score' => $spamInfo['spam_score'],
            'spam_threshold' => $spamInfo['spam_threshold'],
            'spam_flag' => $spamInfo['spam_flag'],
            'spam_tests' => $spamInfo['spam_tests'],
        ], $reactionInfo);
    }
    
    /**
     * Non-OAuth full message fetch. Assumes folder is already selected.
     */
    private function getMessageNonOAuth(int $uid): ?array
    {
        $msgno = @imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            return null;
        }
        
        $header = @imap_headerinfo($this->connection, $msgno);
        $structure = @imap_fetchstructure($this->connection, $uid, FT_UID);
        
        if (!$header || !$structure) {
            return null;
        }
        
        $body = $this->getBody($uid, $structure);
        $attachments = $this->getAttachments($uid, $structure);
        
        if (!empty($body['html'])) {
            $inlineImages = $this->getInlineImages($uid, $structure);
            $body['html'] = $this->replaceCidReferences($body['html'], $inlineImages);
        }
        
        $messageId = isset($header->message_id) ? trim($header->message_id, '<>') : '';
        $linkedAccount = $this->getCustomHeader($uid, 'X-Linked-Account');
        $autoLabel = $this->getCustomHeader($uid, 'X-Auto-Label');
        $unsubscribeInfo = $this->getUnsubscribeInfo($uid);
        $spamInfo = $this->getSpamInfo($uid);
        $importantMsg = $this->isHighImportance(
            $this->parseHeaders(@imap_fetchheader($this->connection, $uid, FT_UID) ?: '')
        );
        
        $subject = isset($header->subject) ? $this->decodeMimeHeader($header->subject) : '(No Subject)';
        $fromList = $this->formatAddressList($header->from ?? []);
        $fromName = $fromList[0]['name'] ?? $fromList[0]['email'] ?? '';
        $fromEmail = $fromList[0]['email'] ?? '';
        $inReplyTo = isset($header->in_reply_to) ? trim($header->in_reply_to, '<>') : null;
        
        $reactionInfo = $this->detectReactionEmail(
            $subject, $body['html'] ?? '', $body['text'] ?? '', $inReplyTo, $fromName, $fromEmail
        );
        
        return array_merge([
            'uid' => $uid,
            'msgno' => $msgno,
            'message_id' => $messageId,
            'subject' => $subject,
            'from' => $fromList,
            'to' => $this->formatAddressList($header->to ?? []),
            'cc' => $this->formatAddressList($header->cc ?? []),
            'bcc' => $this->formatAddressList($header->bcc ?? []),
            'reply_to' => $this->formatAddressList($header->reply_to ?? []),
            'date' => $header->date ?? '',
            'timestamp' => isset($header->udate) ? $header->udate : strtotime($header->date ?? 'now'),
            'size' => $header->Size ?? 0,
            'seen' => trim($header->Seen ?? '') === 'S',
            'flagged' => trim($header->Flagged ?? '') === 'F',
            'answered' => trim($header->Answered ?? '') === 'A',
            'important' => $importantMsg,
            'body_html' => $body['html'] ?? '',
            'body_text' => $body['text'] ?? '',
            'body_calendar' => $body['calendar'] ?? '',
            'attachments' => $attachments,
            'has_attachment' => count($attachments) > 0,
            'linked_account' => $linkedAccount,
            'auto_label' => $autoLabel,
            'unsubscribe_url' => $unsubscribeInfo['unsubscribe_url'],
            'unsubscribe_email' => $unsubscribeInfo['unsubscribe_email'],
            'unsubscribe_one_click' => $unsubscribeInfo['unsubscribe_one_click'],
            'spam_score' => $spamInfo['spam_score'],
            'spam_threshold' => $spamInfo['spam_threshold'],
            'spam_flag' => $spamInfo['spam_flag'],
            'spam_tests' => $spamInfo['spam_tests'],
        ], $reactionInfo);
    }
    
    /**
     * Get raw message source (for copying/forwarding)
     */
    public function getRawMessage(int $uid): ?string
    {
        if (!$this->isConnected()) {
            return null;
        }
        
        $msgno = @imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            return null;
        }
        
        // Get header and body separately to ensure complete message
        $header = @imap_fetchheader($this->connection, $msgno, FT_UID);
        $body = @imap_body($this->connection, $msgno, FT_UID);
        
        if ($header === false || $body === false) {
            return null;
        }
        
        return $header . $body;
    }
    
    /**
     * Append a message to a folder
     */
    public function appendMessage(string $folder, string $rawMessage, ?string $flags = null, ?string $date = null): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        $mailbox = $this->buildConnectionString($folder);
        
        // Default flags for new messages
        if ($flags === null) {
            $flags = "\\Seen"; // Mark as read by default since it's a sync
        }
        
        $result = @imap_append($this->connection, $mailbox, $rawMessage, $flags, $date);
        
        if ($result === false) {
            error_log("imap_append failed: " . imap_last_error());
        }
        
        return $result;
    }
    
    /**
     * Delete a message by UID
     */
    public function deleteMessageByUid(int $uid): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        
        $result = @imap_delete($this->connection, $uid, FT_UID);
        
        if ($result) {
            @imap_expunge($this->connection);
        }
        
        return $result;
    }
    
    /**
     * Get all addresses the user can send from (primary + linked accounts with SMTP)
     */
    public function getSendAddresses(string $primaryEmail, AccountService $accountService): array
    {
        $addresses = [
            [
                'email' => $primaryEmail,
                'name' => null,
                'is_primary' => true,
            ]
        ];
        
        // Get all accounts (both separate and linked)
        $accounts = $accountService->getAccounts($primaryEmail);
        
        foreach ($accounts as $account) {
            // Only include accounts with SMTP configured
            if (!empty($account['smtp_host'])) {
                $addresses[] = [
                    'email' => $account['account_email'],
                    'name' => $account['display_name'],
                    'is_primary' => false,
                    'account_id' => $account['id'],
                    'account_type' => $account['account_type'],
                ];
            }
        }
        
        return $addresses;
    }
    
    /**
     * Detect if an email is a reaction email from Gmail/Outlook
     * Returns reaction info to be merged into message data
     */
    private function detectReactionEmail(
        string $subject,
        string $bodyHtml,
        string $bodyText,
        ?string $inReplyTo = null,
        ?string $fromName = null,
        ?string $fromEmail = null
    ): array {
        static $detector = null;
        
        if ($detector === null) {
            $detector = new ReactionDetectorService();
        }
        
        $result = $detector->detect($subject, $bodyHtml, $bodyText, $inReplyTo, $fromEmail);
        
        // Add reactor name if it's a reaction
        if ($result['is_reaction']) {
            $result['reactor_name'] = $detector->extractReactorName($bodyHtml, $bodyText, $fromName ?? '');
        }
        
        return [
            'is_reaction_email' => $result['is_reaction'],
            'reaction_confidence' => $result['confidence'],
            'reaction_emoji' => $result['emoji'],
            'reaction_score' => $result['score'],
        ];
    }
}

