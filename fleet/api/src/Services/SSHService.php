<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;
use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * SSH Service for remote server connections
 */
class SSHService
{
    private Container $container;
    private EncryptionService $encryption;
    private ?SSH2 $ssh = null;
    private ?SFTP $sftp = null;
    private array $connectionInfo = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->encryption = $container->get(EncryptionService::class);
    }

    /**
     * Connect to a server
     * Only creates SSH2 connection. SFTP is created lazily on first file transfer.
     */
    public function connect(string $host, int $port, string $username, string $password): bool
    {
        $cli = php_sapi_name() === 'cli';
        try {
            if ($cli) fwrite(STDERR, "  [SSH] Connecting to {$host}:{$port}...\n");
            
            // Create SSH2 connection for exec operations
            $this->ssh = new SSH2($host, $port);
            $this->ssh->setTimeout(30);

            if ($cli) fwrite(STDERR, "  [SSH] Logging in as {$username}...\n");
            if (!$this->ssh->login($username, $password)) {
                if ($cli) fwrite(STDERR, "  [SSH] Login FAILED\n");
                $this->ssh = null;
                return false;
            }
            if ($cli) fwrite(STDERR, "  [SSH] Login OK\n");

            // Warm-up: phpseclib3 leaves an internal channel open after login().
            // Run a dummy exec to flush it. If it fails (channel conflict),
            // rebuild the connection -- the second one always works clean.
            try {
                if ($cli) fwrite(STDERR, "  [SSH] Warm-up exec('true')...\n");
                $this->ssh->exec('true');
                if ($cli) fwrite(STDERR, "  [SSH] Warm-up OK - connection ready\n");
            } catch (\Exception $warmupEx) {
                if ($cli) fwrite(STDERR, "  [SSH] Warm-up failed ({$warmupEx->getMessage()}), rebuilding connection...\n");
                $this->ssh->disconnect();
                $this->ssh = new SSH2($host, $port);
                $this->ssh->setTimeout(120);
                if (!$this->ssh->login($username, $password)) {
                    if ($cli) fwrite(STDERR, "  [SSH] Rebuild login FAILED\n");
                    $this->ssh = null;
                    return false;
                }
                // Second warmup on rebuilt connection
                try {
                    $this->ssh->exec('true');
                    if ($cli) fwrite(STDERR, "  [SSH] Rebuilt connection ready\n");
                } catch (\Exception $warmup2) {
                    if ($cli) fwrite(STDERR, "  [SSH] WARNING: Rebuilt connection warmup also failed: {$warmup2->getMessage()}\n");
                }
            }

            $this->connectionInfo = [
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'password' => $password,
            ];

            return true;
        } catch (\Exception $e) {
            if ($cli) fwrite(STDERR, "  [SSH] Connection exception: {$e->getMessage()}\n");
            error_log("SSH connection failed: " . $e->getMessage());
            $this->sftp = null;
            $this->ssh = null;
            return false;
        }
    }

    /**
     * Get or create SFTP connection (lazy initialization)
     */
    private function getSftp(): ?SFTP
    {
        if ($this->sftp) {
            return $this->sftp;
        }

        if (empty($this->connectionInfo)) {
            return null;
        }

        try {
            $info = $this->connectionInfo;
            $this->sftp = new SFTP($info['host'], $info['port']);
            $this->sftp->setTimeout(60);

            // Support both password and key auth (key-only servers - e.g. a
            // hardened box reached as pxr - could not SFTP at all before).
            $loggedIn = false;
            if (!empty($info['password'])) {
                $loggedIn = $this->sftp->login($info['username'], $info['password']);
            } elseif (!empty($info['private_key'])) {
                $key = PublicKeyLoader::load($info['private_key'], $info['passphrase'] ?? false);
                $loggedIn = $this->sftp->login($info['username'], $key);
            }

            if (!$loggedIn) {
                error_log("SFTP login failed");
                $this->sftp = null;
                return null;
            }

            return $this->sftp;
        } catch (\Exception $e) {
            error_log("SFTP connection failed: " . $e->getMessage());
            $this->sftp = null;
            return null;
        }
    }

    /**
     * Connect using SSH key
     */
    public function connectWithKey(string $host, int $port, string $username, string $privateKey, ?string $passphrase = null): bool
    {
        try {
            $this->ssh = new SSH2($host, $port);
            $this->ssh->setTimeout(30);

            $key = PublicKeyLoader::load($privateKey, $passphrase ?? false);

            if (!$this->ssh->login($username, $key)) {
                $this->ssh = null;
                return false;
            }

            // Warm-up: flush any internal channels left by login()
            try {
                $this->ssh->exec('true');
            } catch (\Exception $warmupEx) {
                $this->ssh->disconnect();
                $this->ssh = new SSH2($host, $port);
                $this->ssh->setTimeout(120);
                $key = PublicKeyLoader::load($privateKey, $passphrase ?? false);
                if (!$this->ssh->login($username, $key)) {
                    $this->ssh = null;
                    return false;
                }
            }

            $this->connectionInfo = [
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'private_key' => $privateKey,
                'passphrase' => $passphrase,
            ];

            return true;
        } catch (\Exception $e) {
            error_log("SSH key connection failed: " . $e->getMessage());
            $this->ssh = null;
            return false;
        }
    }

    /**
     * Connect to a server from database record
     */
    public function connectToServer(array $server): bool
    {
        $host = $server['ip_address'];
        $port = $server['ssh_port'] ?? 22;
        $user = $server['ssh_user'] ?? 'root';

        // Try key-based auth first (from server's key_path field). If that key IS
        // the fleet-wide management key, it may be passphrase-protected - pass it.
        if (!empty($server['key_path']) && file_exists($server['key_path'])) {
            error_log("SSH: Trying key auth with key_path: " . $server['key_path']);
            $mgmtForKeyPath = $this->managementKey();
            $kpPass = ($mgmtForKeyPath !== null && $mgmtForKeyPath['path'] === $server['key_path'])
                ? $mgmtForKeyPath['passphrase'] : null;
            $result = $this->connectWithKeyFile($host, $port, $user, $server['key_path'], $kpPass);
            if ($result) {
                error_log("SSH: Key auth successful");
                return true;
            }
            error_log("SSH: Key auth failed");
        }

        // Try password auth
        if (!empty($server['ssh_password_encrypted'])) {
            try {
                $password = $this->encryption->decrypt($server['ssh_password_encrypted']);
                if ($password) {
                    error_log("SSH: Trying password auth");
                    $result = $this->connect($host, $port, $user, $password);
                    if ($result) {
                        error_log("SSH: Password auth successful");
                        return true;
                    }
                    error_log("SSH: Password auth failed");
                }
            } catch (\Exception $e) {
                error_log("SSH: Failed to decrypt password: " . $e->getMessage());
            }
        }

        // Try fallback key location
        $fallbackKeyPath = $this->container->getConfig('ssh.key_path') . 'server_' . $server['id'] . '.key';
        if (file_exists($fallbackKeyPath)) {
            error_log("SSH: Trying fallback key: " . $fallbackKeyPath);
            if ($this->connectWithKeyFile($host, $port, $user, $fallbackKeyPath)) {
                return true;
            }
        }

        // LAST RESORT: the fleet-wide management key (vps-sftp-access). Its public
        // half is authorized on pxr on every hardened box, so this recovers access
        // even when the per-server key is gone or the panel record was re-added.
        // We try the hardened profile (pxr@harden_port) and the box's stored
        // user/port, so it works mid-transition too.
        $mgmt = $this->managementKey();
        if ($mgmt !== null) {
            $hardenPort = (int)($this->container->getConfig('ssh.harden_port') ?: 1985);
            $attempts = [
                ['port' => $hardenPort, 'user' => 'pxr'],
                ['port' => $port,       'user' => $user],
                ['port' => $port,       'user' => 'pxr'],
            ];
            $seen = [];
            foreach ($attempts as $a) {
                $k = $a['user'] . ':' . $a['port'];
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                error_log("SSH: Trying management key as {$a['user']}@{$a['port']}");
                if ($this->connectWithKeyFile($host, $a['port'], $a['user'], $mgmt['path'], $mgmt['passphrase'])) {
                    error_log("SSH: Management key auth successful ({$a['user']}@{$a['port']})");
                    return true;
                }
            }
        }

        error_log("SSH: No valid credentials found for server " . ($server['name'] ?? $server['id']));
        return false;
    }

    /**
     * Connect using key file path
     */
    public function connectWithKeyFile(string $host, int $port, string $username, string $keyPath, ?string $passphrase = null): bool
    {
        if (!file_exists($keyPath)) {
            error_log("SSH: Key file not found: " . $keyPath);
            return false;
        }

        $keyContents = file_get_contents($keyPath);
        return $this->connectWithKey($host, $port, $username, $keyContents, $passphrase);
    }

    /**
     * The fleet-wide MANAGEMENT key (private half of ssh.pxr_authorized_key, e.g.
     * vps-sftp-access). Returns ['path' => ..., 'passphrase' => ...] when configured,
     * else null. Used as a connect fallback so the FM can reach pxr on any hardened
     * box even when a per-server key is missing.
     *
     * Resolution order:
     *   1) Key pasted/rotated from the dashboard Settings page (encrypted in the
     *      `settings` table). Materialised to a 0600 cache file on demand.
     *   2) Config-file key (ssh.management_key_path) - legacy fallback.
     */
    public function managementKey(): ?array
    {
        // 1) DB-managed key (set from the dashboard) takes precedence.
        try {
            $db = $this->container->getDatabase();
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $st->execute(['ssh_management_key']);
            $enc = $st->fetchColumn();
            if ($enc !== false && $enc !== null && $enc !== '') {
                $priv = $this->encryption->decrypt((string)$enc);
                if ($priv !== '') {
                    $st->execute(['ssh_management_key_passphrase']);
                    $passEnc = $st->fetchColumn();
                    $pass = ($passEnc !== false && $passEnc !== null && $passEnc !== '')
                        ? $this->encryption->decrypt((string)$passEnc)
                        : '';
                    $path = $this->cacheManagementKey($priv);
                    if ($path !== null) {
                        return ['path' => $path, 'passphrase' => $pass === '' ? null : $pass];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('managementKey: DB read failed: ' . $e->getMessage());
        }

        // 2) Config-file fallback.
        $path = trim((string)($this->container->getConfig('ssh.management_key_path') ?: ''));
        if ($path === '' || !file_exists($path)) {
            return null;
        }
        $pass = (string)($this->container->getConfig('ssh.management_key_passphrase') ?: '');
        return ['path' => $path, 'passphrase' => $pass === '' ? null : $pass];
    }

    /**
     * Write the DB-stored management key to a 0600 cache file the SSH layer can
     * read by path. Only rewrites when the contents change to avoid churn.
     */
    private function cacheManagementKey(string $privateKey): ?string
    {
        $dir = rtrim((string)($this->container->getConfig('ssh.key_path') ?: '/var/www/vps-fleet/var/keys/'), '/') . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '_fleet_mgmt.key';
        $existing = @file_exists($file) ? @file_get_contents($file) : false;
        if ($existing !== $privateKey) {
            if (@file_put_contents($file, $privateKey) === false) {
                error_log('managementKey: could not write cache key to ' . $file);
                return null;
            }
            @chmod($file, 0600);
        }
        return $file;
    }

    /**
     * Test SSH connection
     */
    public function testConnection(string $host, int $port, string $username, string $password, int $timeout = 10): array
    {
        try {
            $ssh = new SSH2($host, $port);
            $ssh->setTimeout($timeout);

            if (!$ssh->login($username, $password)) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed',
                ];
            }

            $info = $this->collectSystemInfo($ssh);
            $ssh->disconnect();

            return array_merge(['success' => true], $info);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test SSH connection with key-based authentication
     */
    public function testConnectionWithKey(string $host, int $port, string $username, string $keyPath, ?string $passphrase = null, int $timeout = 10): array
    {
        try {
            // Read the key file
            $keyFile = str_replace('~', getenv('HOME') ?: '/root', $keyPath);
            if (!file_exists($keyFile)) {
                return [
                    'success' => false,
                    'error' => "Key file not found: {$keyPath}",
                ];
            }

            $privateKey = file_get_contents($keyFile);
            $key = PublicKeyLoader::load($privateKey, ($passphrase === null || $passphrase === '') ? false : $passphrase);

            $ssh = new SSH2($host, $port);
            $ssh->setTimeout($timeout);

            if (!$ssh->login($username, $key)) {
                return [
                    'success' => false,
                    'error' => 'Key authentication failed',
                ];
            }

            $info = $this->collectSystemInfo($ssh);
            $ssh->disconnect();

            return array_merge(['success' => true], $info);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Probe a freshly-connected box for OS identity + live system metrics in a
     * single round trip. Used by the connection-test endpoints so the operator
     * sees OS / CPU load / memory / disk the moment SSH succeeds - without having
     * to wait for the agent heartbeat (which may not be installed yet).
     *
     * Returns: hostname, os, uptime (human), uptime_seconds, cpu{load_1m/5m/15m},
     * memory{total_mb/used_mb/percent}, disk{total_gb/used_gb/free_gb/percent}.
     * Every field is best-effort; missing tools just yield null/omitted keys.
     */
    private function collectSystemInfo(SSH2 $ssh): array
    {
        // One labelled probe = one exec round trip. Each value is independently
        // guarded with 2>/dev/null so a missing tool never breaks the others.
        $probe = <<<'SH'
echo "HOSTNAME=$(hostname 2>/dev/null)"
echo "OS=$(. /etc/os-release 2>/dev/null; printf '%s' "$PRETTY_NAME")"
echo "UPTIME=$(uptime -p 2>/dev/null)"
echo "UPTIME_SEC=$(cut -d' ' -f1 /proc/uptime 2>/dev/null)"
echo "LOAD=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null)"
echo "MEM=$(free -m 2>/dev/null | awk '/^Mem:/{print $2" "$3}')"
echo "DISK=$(df -BG / 2>/dev/null | awk 'NR==2{print $2" "$3" "$4" "$5}')"
# Live service states. Runs as the connected user (systemctl is-active / cat are
# read-only - no root needed), so the Services panel reflects reality instead of
# the agent heartbeat. running = a candidate unit is active; stopped = a unit
# file exists but is not active; disabled = no such unit on this box.
svc() {
  k="$1"; shift
  for u in "$@"; do
    [ "$(systemctl is-active "$u" 2>/dev/null)" = active ] && { echo "SVC ${k}=running"; return; }
  done
  for u in "$@"; do
    systemctl cat "$u" >/dev/null 2>&1 && { echo "SVC ${k}=stopped"; return; }
  done
  echo "SVC ${k}=disabled"
}
svc openlitespeed lshttpd lsws
svc mariadb mariadb mysql
svc redis redis-server redis
svc meilisearch meilisearch
svc postfix postfix
svc dovecot dovecot
svc spamassassin spamassassin spamd
svc fail2ban fail2ban
svc firewalld firewalld
svc collab collab-server
svc mailsync mailsync-server
svc fleet_agent fleet-agent
SH;

        // CRLF -> LF: this heredoc lives in a file that may be saved with Windows
        // line endings, and `for ...; do\r` / `done\r` would break the remote shell.
        $probe = str_replace("\r\n", "\n", $probe);
        $raw = (string) $ssh->exec($probe);

        $kv = [];
        $services = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            // Service lines first so "SVC foo=running" doesn't land in $kv.
            if (strpos($line, 'SVC ') === 0) {
                $pair = substr($line, 4);
                if (strpos($pair, '=') !== false) {
                    [$sk, $sv] = explode('=', $pair, 2);
                    $services[trim($sk)] = trim($sv);
                }
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $kv[trim($k)] = trim($v);
        }

        $info = [
            'hostname' => ($kv['HOSTNAME'] ?? '') !== '' ? $kv['HOSTNAME'] : null,
            'os'       => ($kv['OS'] ?? '') !== '' ? $kv['OS'] : null,
            'uptime'   => ($kv['UPTIME'] ?? '') !== '' ? $kv['UPTIME'] : null,
        ];

        if (isset($kv['UPTIME_SEC']) && is_numeric((string) strtok($kv['UPTIME_SEC'], '.'))) {
            $info['uptime_seconds'] = (int) $kv['UPTIME_SEC'];
        }

        if (!empty($kv['LOAD'])) {
            $parts = preg_split('/\s+/', trim($kv['LOAD']));
            if ($parts && count($parts) >= 3) {
                $info['cpu'] = [
                    'load_1m'  => (float) $parts[0],
                    'load_5m'  => (float) $parts[1],
                    'load_15m' => (float) $parts[2],
                ];
            }
        }

        if (!empty($kv['MEM'])) {
            $parts = preg_split('/\s+/', trim($kv['MEM']));
            if ($parts && count($parts) >= 2) {
                $total = (int) $parts[0];
                $used  = (int) $parts[1];
                $info['memory'] = [
                    'total_mb' => $total,
                    'used_mb'  => $used,
                    'percent'  => $total > 0 ? round($used / $total * 100, 1) : 0,
                ];
            }
        }

        if (!empty($kv['DISK'])) {
            $parts = preg_split('/\s+/', trim($kv['DISK']));
            if ($parts && count($parts) >= 4) {
                $info['disk'] = [
                    'total_gb' => (int) rtrim($parts[0], 'G'),
                    'used_gb'  => (int) rtrim($parts[1], 'G'),
                    'free_gb'  => (int) rtrim($parts[2], 'G'),
                    'percent'  => (int) rtrim($parts[3], '%'),
                ];
            }
        }

        // Docker-managed box: prefer FlowOne compose container states for the
        // app tier over the (non-existent) systemd units, so Test Connection
        // doesn't show every service as 'disabled'. Additive + gated: on a
        // native box the query returns nothing and $services stands.
        $dockerRaw = (string) $ssh->exec(
            'command -v docker >/dev/null 2>&1 && docker ps -a '
            . '--filter label=com.docker.compose.project=flowone '
            . '--format \'{{.Label "com.docker.compose.service"}}={{.State}}\' 2>/dev/null'
        );
        $dockerServices = self::mapDockerAppServices($dockerRaw);
        if ($dockerServices) {
            $services = array_merge($services, $dockerServices);
        }

        if (!empty($services)) {
            $info['services'] = $services;
        }

        return $info;
    }

    /**
     * compose service => dashboard health key (mirror of agent Lib\DockerHealth).
     * Mail/security services stay host-managed, so they are not listed here.
     */
    private const DOCKER_SERVICE_TO_HEALTHKEY = [
        'web'         => 'openlitespeed',
        'mariadb'     => 'mariadb',
        'redis'       => 'redis',
        'meilisearch' => 'meilisearch',
        'collab'      => 'collab',
        'mailsync'    => 'mailsync',
    ];

    /**
     * PURE: parse `service=state` lines from a docker ps label query and remap to
     * dashboard health keys. docker 'running' => 'running', else 'stopped'.
     */
    public static function mapDockerAppServices(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }
            [$svc, $state] = explode('=', $line, 2);
            $svc = trim($svc);
            if (!isset(self::DOCKER_SERVICE_TO_HEALTHKEY[$svc])) {
                continue;
            }
            $out[self::DOCKER_SERVICE_TO_HEALTHKEY[$svc]] = strtolower(trim($state)) === 'running' ? 'running' : 'stopped';
        }
        return $out;
    }

    /**
     * Log into an existing SSH2 object using whichever auth is stored in $info
     * (password or private key). Used by the reconnect/retry paths so that
     * key-authenticated servers can recover after a channel error too.
     */
    private function loginWith(SSH2 $ssh, array $info): bool
    {
        try {
            if (!empty($info['password'])) {
                return $ssh->login($info['username'], $info['password']);
            }
            if (!empty($info['private_key'])) {
                $key = PublicKeyLoader::load($info['private_key'], $info['passphrase'] ?? false);
                return $ssh->login($info['username'], $key);
            }
        } catch (\Exception $e) {
            error_log("SSH re-login failed: " . $e->getMessage());
        }
        return false;
    }

    /**
     * Reset the SSH connection (needed after SFTP operations to clear channel)
     */
    public function resetConnection(): bool
    {
        if (empty($this->connectionInfo)) {
            return false;
        }

        // Capture connection info before disconnect() clears it
        $info = $this->connectionInfo;

        // Disconnect
        $this->disconnect();

        // Reconnect with whichever auth we originally used
        if (!empty($info['password'])) {
            return $this->connect($info['host'], $info['port'], $info['username'], $info['password']);
        }
        if (!empty($info['private_key'])) {
            return $this->connectWithKey($info['host'], $info['port'], $info['username'], $info['private_key'], $info['passphrase'] ?? null);
        }

        return false;
    }

    /**
     * Whether the active connection is the root account. Drives auto-sudo: once a
     * box is hardened (root SSH denied), the Fleet Manager connects as the
     * unprivileged "pxr" user and must elevate privileged commands via sudo.
     */
    public function isRoot(): bool
    {
        $user = $this->connectionInfo['username'] ?? 'root';
        return $user === 'root' || $user === '';
    }

    /**
     * The user the active connection authenticated as (default 'root').
     */
    public function getConnectionUser(): string
    {
        return $this->connectionInfo['username'] ?? 'root';
    }

    /**
     * Wrap a command so it runs as root when we're logged in as a non-root user
     * (pxr) with passwordless sudo. base64 piping avoids every quoting/escaping
     * pitfall - pipes, redirects, heredocs and quotes survive untouched and the
     * inner command's exit status is preserved. Root connections run verbatim.
     */
    private function maybeSudo(string $command): string
    {
        // Normalize CRLF -> LF before anything runs remotely. PHP heredocs in a
        // file saved with Windows line endings carry \r, which breaks every
        // remote POSIX shell: `for ...; do\r` / `then\r` become syntax errors and
        // a trailing \r silently corrupts variable values (e.g. CNF=/root/.my.cnf\r
        // points mysql at a file that does not exist). Remote commands always run
        // under sh/bash, so LF is unconditionally correct here.
        $command = str_replace("\r\n", "\n", $command);

        if ($this->isRoot()) {
            return $command;
        }
        $b64 = base64_encode($command);
        return "echo {$b64} | base64 -d | sudo -n bash";
    }

    /**
     * Execute a command
     */
    public function exec(string $command, bool $pty = false): array
    {
        if (!$this->ssh) {
            return ['success' => false, 'error' => 'Not connected'];
        }

        // Elevate to root via sudo when connected as the unprivileged pxr user.
        $command = $this->maybeSudo($command);

        // Verbose CLI logging: show what command is being executed
        if (php_sapi_name() === 'cli' && strlen($command) < 200) {
            $shortCmd = substr(trim($command), 0, 120);
            fwrite(STDERR, "  [SSH] exec: {$shortCmd}" . (strlen($command) > 120 ? '...' : '') . "\n");
        }

        try {
            if ($pty) {
                $this->ssh->enablePTY();
            }

            $output = $this->ssh->exec($command);
            $exitCode = $this->ssh->getExitStatus();

            return [
                'success' => $exitCode === 0,
                'output' => $output,
                'exit_code' => $exitCode,
            ];
        } catch (\Exception $e) {
            $cli = php_sapi_name() === 'cli';
            // Channel error: create a completely fresh SSH2 object and retry
            if (!empty($this->connectionInfo)) {
                $errMsg = $e->getMessage();
                if ($cli) fwrite(STDERR, "  [SSH] EXEC ERROR: {$errMsg}\n");
                if ($cli) fwrite(STDERR, "  [SSH] Creating fresh connection for retry...\n");
                error_log("SSH exec error: {$errMsg} - creating fresh connection...");
                
                $info = $this->connectionInfo;
                
                // Kill the old SSH2 object entirely
                try { $this->ssh->disconnect(); } catch (\Exception $dc) {}
                $this->ssh = null;
                
                // Create a brand new SSH2 object (no SFTP, no reset)
                try {
                    $this->ssh = new SSH2($info['host'], $info['port']);
                    $this->ssh->setTimeout(120);
                    
                    if ($cli) fwrite(STDERR, "  [SSH] Retry: logging in...\n");
                    $loginOk = $this->loginWith($this->ssh, $info);
                    
                    if ($loginOk) {
                        // Warm-up the retry connection too
                        try {
                            if ($cli) fwrite(STDERR, "  [SSH] Retry: warm-up exec('true')...\n");
                            $this->ssh->exec('true');
                            if ($cli) fwrite(STDERR, "  [SSH] Retry: warm-up OK\n");
                        } catch (\Exception $warmupEx) {
                            if ($cli) fwrite(STDERR, "  [SSH] Retry: warm-up failed ({$warmupEx->getMessage()}), rebuilding again...\n");
                            $this->ssh->disconnect();
                            $this->ssh = new SSH2($info['host'], $info['port']);
                            $this->ssh->setTimeout(120);
                            if (!$this->loginWith($this->ssh, $info)) {
                                if ($cli) fwrite(STDERR, "  [SSH] Retry: second rebuild login FAILED\n");
                                return ['success' => false, 'error' => 'SSH reconnect failed after channel error'];
                            }
                            // One more warmup
                            try { $this->ssh->exec('true'); } catch (\Exception $w) {
                                if ($cli) fwrite(STDERR, "  [SSH] Retry: third warmup also failed: {$w->getMessage()}\n");
                            }
                        }
                        
                        if ($cli) fwrite(STDERR, "  [SSH] Retry: executing original command...\n");
                        $output = $this->ssh->exec($command);
                        $exitCode = $this->ssh->getExitStatus();
                        if ($cli) fwrite(STDERR, "  [SSH] Retry: command completed (exit={$exitCode})\n");
                        return [
                            'success' => $exitCode === 0,
                            'output' => $output,
                            'exit_code' => $exitCode,
                        ];
                    } else {
                        if ($cli) fwrite(STDERR, "  [SSH] Retry: login FAILED\n");
                    }
                } catch (\Exception $retryEx) {
                    if ($cli) fwrite(STDERR, "  [SSH] Retry FAILED: {$retryEx->getMessage()}\n");
                    return ['success' => false, 'error' => 'Retry failed: ' . $retryEx->getMessage()];
                }
            }
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute command with timeout
     */
    public function execWithTimeout(string $command, int $timeout = 300): array
    {
        if (!$this->ssh) {
            return ['success' => false, 'error' => 'Not connected'];
        }

        $this->ssh->setTimeout($timeout);
        return $this->exec($command);
    }

    /**
     * Execute command in background
     */
    public function execBackground(string $command): array
    {
        return $this->exec("nohup {$command} > /dev/null 2>&1 &");
    }

    /**
     * Stream command output (for live progress)
     */
    public function execStream(string $command, callable $callback): array
    {
        if (!$this->ssh) {
            return ['success' => false, 'error' => 'Not connected'];
        }

        try {
            $this->ssh->exec($this->maybeSudo($command), function($str) use ($callback) {
                $callback($str);
            });

            $exitCode = $this->ssh->getExitStatus();
            return [
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload a file
     */
    public function uploadFile(string $localPath, string $remotePath): bool
    {
        $sftp = $this->getSftp();
        if (!$sftp) {
            error_log("SFTP not available for upload");
            return false;
        }

        try {
            // Root: write straight to the destination.
            if ($this->isRoot()) {
                return $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE);
            }

            // Non-root (pxr): SFTP can only write where pxr can, so stage in /tmp
            // then sudo-move into place (exec() auto-elevates).
            $tmp = '/tmp/.fleet-up-' . bin2hex(random_bytes(6));
            if (!$sftp->put($tmp, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
                return false;
            }
            $mv = $this->exec('mv -f ' . escapeshellarg($tmp) . ' ' . escapeshellarg($remotePath));
            if (!$mv['success']) {
                $this->exec('rm -f ' . escapeshellarg($tmp));
                return false;
            }
            return true;
        } catch (\Exception $e) {
            error_log("SFTP upload failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Upload content directly
     */
    public function uploadContent(string $content, string $remotePath): bool
    {
        // Root + SFTP available: binary-safe direct write.
        if ($this->isRoot()) {
            $sftp = $this->getSftp();
            if ($sftp) {
                return $sftp->put($remotePath, $content);
            }
        }

        // Non-root (or no SFTP): write via a heredoc through exec(), which
        // auto-elevates with sudo so root-owned destinations still succeed.
        // Single-quoted delimiter = no bash expansion of the content.
        $result = $this->exec("cat > {$remotePath} << 'FLEETEOF'\n{$content}\nFLEETEOF");
        return $result['success'];
    }

    /**
     * Download a file
     */
    public function downloadFile(string $remotePath, string $localPath): bool
    {
        if (!$this->ssh) {
            return false;
        }

        try {
            $content = $this->ssh->exec($this->maybeSudo("cat {$remotePath}"));
            if ($this->ssh->getExitStatus() === 0) {
                return file_put_contents($localPath, $content) !== false;
            }
            return false;
        } catch (\Exception $e) {
            error_log("Download failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if file exists
     */
    public function fileExists(string $path): bool
    {
        $result = $this->exec("test -f {$path} && echo 'yes' || echo 'no'");
        return trim($result['output'] ?? '') === 'yes';
    }

    /**
     * Check if directory exists
     */
    public function dirExists(string $path): bool
    {
        $result = $this->exec("test -d {$path} && echo 'yes' || echo 'no'");
        return trim($result['output'] ?? '') === 'yes';
    }

    /**
     * Create directory
     */
    public function mkdir(string $path, bool $recursive = true): bool
    {
        $flag = $recursive ? '-p' : '';
        $result = $this->exec("mkdir {$flag} {$path}");
        return $result['success'];
    }

    /**
     * Set file permissions
     */
    public function chmod(string $path, string $mode): bool
    {
        $result = $this->exec("chmod {$mode} {$path}");
        return $result['success'];
    }

    /**
     * Set file ownership
     */
    public function chown(string $path, string $owner, ?string $group = null, bool $recursive = false): bool
    {
        $ownership = $group ? "{$owner}:{$group}" : $owner;
        $flag = $recursive ? '-R' : '';
        $result = $this->exec("chown {$flag} {$ownership} {$path}");
        return $result['success'];
    }

    /**
     * Get system info
     */
    public function getSystemInfo(): array
    {
        if (!$this->ssh) {
            return [];
        }

        return [
            'hostname' => trim($this->ssh->exec('hostname')),
            'os' => trim($this->ssh->exec('cat /etc/os-release | grep PRETTY_NAME | cut -d\'"\' -f2')),
            'kernel' => trim($this->ssh->exec('uname -r')),
            'cpu_cores' => (int)trim($this->ssh->exec('nproc')),
            'memory_total' => trim($this->ssh->exec('free -h | grep Mem | awk \'{print $2}\'')),
            'disk_total' => trim($this->ssh->exec('df -h / | tail -1 | awk \'{print $2}\'')),
            'uptime' => trim($this->ssh->exec('uptime -p')),
        ];
    }

    /**
     * Check if a service is running
     */
    public function isServiceRunning(string $service): bool
    {
        $result = $this->exec("systemctl is-active {$service}");
        return trim($result['output'] ?? '') === 'active';
    }

    /**
     * Start a service
     */
    public function startService(string $service): bool
    {
        $result = $this->exec("systemctl start {$service}");
        return $result['success'];
    }

    /**
     * Stop a service
     */
    public function stopService(string $service): bool
    {
        $result = $this->exec("systemctl stop {$service}");
        return $result['success'];
    }

    /**
     * Restart a service
     */
    public function restartService(string $service): bool
    {
        $result = $this->exec("systemctl restart {$service}");
        return $result['success'];
    }

    /**
     * Disconnect
     */
    public function disconnect(): void
    {
        if ($this->sftp) {
            $this->sftp->disconnect();
            $this->sftp = null;
        }

        if ($this->ssh) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }

        $this->connectionInfo = [];
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->ssh !== null && $this->ssh->isConnected();
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

