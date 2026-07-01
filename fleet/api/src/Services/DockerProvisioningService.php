<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * DockerProvisioningService — deploy a server's FlowOne stack via Docker Compose.
 *
 * Phase D of the native->docker migration. This is the compose-native counterpart
 * to the ~8,354-line native ProvisioningService: instead of ~30 apt/systemctl
 * steps that compile the whole stack onto the host, it ships ONE rendered `.env`
 * plus the version-controlled `docker-compose.yml`, pulls the pre-built images
 * from the registry, and brings the stack up. The images are immutable (BAKE
 * bucket); only the `.env` differs per host (INJECT bucket); live data + the
 * non-regenerable keys arrive via the migration/ snapshot+restore tooling
 * (MIGRATE bucket).
 *
 * It is authored as a NEW, focused module and does NOT touch the native
 * ProvisioningService, so old (native) and new (docker) provisioning run in
 * parallel during cutover — the plan's core safety property.
 *
 * Testability: the command construction and health parsing are pure `static`
 * methods (unit-tested in fleet/api/tests/docker-provisioning-test.php). The
 * SSH-driven orchestration (provisionDocker) is validated on the Phase E Linux
 * staging box — Docker Desktop on Windows can't stand in for a real target.
 */
class DockerProvisioningService
{
    /** On-box layout. */
    public const STACK_DIR   = '/opt/flowone';
    public const PROJECT     = 'flowone';
    public const COMPOSE_FILE = self::STACK_DIR . '/docker-compose.yml';
    public const ENV_FILE    = self::STACK_DIR . '/.env';
    public const JWT_VOLUME  = self::PROJECT . '_jwt_keys';

    /** Bridge-net services this deploy manages (health-tracked). */
    public const SERVICES = ['mariadb', 'redis', 'meilisearch', 'web', 'collab', 'mailsync'];

    /** Day-2 helper scripts shipped next to the stack (SSL + mailbox + DNS ops). */
    public const HELPER_SCRIPTS = ['obtain-certs.sh', 'create-mail-account.sh', 'dns-records.sh'];

    private Container $container;
    private SSHService $ssh;
    private ComposeEnvRenderer $envRenderer;
    private TemplateService $templates;
    private EncryptionService $encryption;
    private \PDO $db;
    private array $log = [];
    /** When set (deploy launched from the dashboard), progress + log stream to this row. */
    private ?int $deploymentId = null;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->ssh = $container->get(SSHService::class);
        $this->envRenderer = $container->get(ComposeEnvRenderer::class);
        $this->templates = $container->get(TemplateService::class);
        $this->encryption = $container->get(EncryptionService::class);
        $this->db = $container->getDatabase();
    }

    // =========================================================================
    // PURE COMMAND / PARSING HELPERS (unit-testable, no side effects)
    // =========================================================================

    /** Base `docker compose` invocation pinned to our project + files. */
    public static function composeBase(): string
    {
        return sprintf(
            'docker compose -p %s -f %s --env-file %s',
            escapeshellarg(self::PROJECT),
            escapeshellarg(self::COMPOSE_FILE),
            escapeshellarg(self::ENV_FILE)
        );
    }

    /** `docker compose pull` (whole stack, or a single service). */
    public static function pullCmd(?string $service = null): string
    {
        return self::composeBase() . ' pull' . ($service ? ' ' . escapeshellarg($service) : '');
    }

    /**
     * Registry host from a `registry/namespace` value: 'ghcr.io/flowonedev' ->
     * 'ghcr.io'. A bare host (no slash) is returned unchanged. `docker login`
     * takes the host only, never the namespace.
     */
    public static function registryHost(string $registry): string
    {
        return explode('/', trim($registry), 2)[0];
    }

    /**
     * `docker login` for pulling PRIVATE images. Feeds the token via stdin
     * (`--password-stdin`) using printf so it never appears in the process
     * arg list, and prints nothing but the token to that pipe. Callers must
     * keep the token out of logs.
     */
    public static function dockerLoginCmd(string $registryHost, string $user, string $token): string
    {
        return sprintf(
            'printf %%s %s | docker login %s -u %s --password-stdin',
            escapeshellarg($token),
            escapeshellarg($registryHost),
            escapeshellarg($user)
        );
    }

    /**
     * `docker compose up -d`. For a single-service update use --no-deps so peers
     * aren't recreated (the Docker equivalent of the retired panel/email updates).
     */
    public static function upCmd(?string $service = null): string
    {
        $base = self::composeBase() . ' up -d --remove-orphans';
        return $service ? self::composeBase() . ' up -d --no-deps ' . escapeshellarg($service) : $base;
    }

    /** Machine-readable status of every service (one JSON object per line). */
    public static function psJsonCmd(): string
    {
        return self::composeBase() . ' ps --format json';
    }

    /**
     * Warm + verify the DB schema inside the running web container. The web
     * healthcheck curls /api/auth/me, which runs index.php's auto-migration, so
     * by the time the stack is healthy the base migrations have applied; this
     * then constructs the lazy-ensure services so tables like the Kanban/
     * ProjectHub/Drive columns exist before the first real user request (avoids
     * "Unknown column" on a fresh box). Best-effort: -T disables the TTY.
     */
    public static function ensureSchemaCmd(): string
    {
        return self::composeBase()
            . ' exec -T web /usr/local/lsws/lsphp83/bin/php '
            . '/var/www/vps-email/backend/scripts/ensure-schema.php';
    }

    /**
     * Install Docker Engine + the compose plugin if absent (idempotent). Uses the
     * official convenience script (Debian/Ubuntu targets) and enables the daemon.
     */
    public static function dockerInstallCmd(): string
    {
        return 'if ! command -v docker >/dev/null 2>&1; then '
            . 'curl -fsSL https://get.docker.com | sh && systemctl enable --now docker; '
            . 'fi; docker compose version >/dev/null 2>&1 || '
            . '{ apt-get update && apt-get install -y docker-compose-plugin; }';
    }

    /** Seed a named volume from a host directory using a throwaway helper container. */
    public static function seedVolumeCmd(string $volume, string $srcDir, string $helperImage = 'alpine:3.20'): string
    {
        return sprintf(
            'docker run --rm -v %s:/dst -v %s:/src:ro %s sh -c %s',
            escapeshellarg($volume),
            escapeshellarg($srcDir),
            escapeshellarg($helperImage),
            escapeshellarg('cp -a /src/. /dst/ && chmod 600 /dst/jwt-private.pem 2>/dev/null; chmod 644 /dst/jwt-public.pem 2>/dev/null; true')
        );
    }

    /** `docker compose restart <service>` (used to reload the mail pod after a cert flip). */
    public static function restartCmd(string $service): string
    {
        return self::composeBase() . ' restart ' . escapeshellarg($service);
    }

    /** Remote test for an existing Let's Encrypt cert lineage (exit 0 = present + non-empty). */
    public static function certPresentCmd(string $certName): string
    {
        return 'test -s ' . escapeshellarg("/etc/letsencrypt/live/{$certName}/fullchain.pem");
    }

    /**
     * obtain-certs.sh invocation — one SAN cert (--cert-name lineage) covering every
     * public host the box serves. All domains must already resolve here (HTTP-01).
     */
    public static function obtainCertsCmd(string $email, string $certName, array $domains): string
    {
        $args = '';
        foreach ($domains as $d) {
            $args .= ' ' . escapeshellarg($d);
        }
        return sprintf(
            'bash %s --email=%s --cert-name=%s%s',
            escapeshellarg(self::STACK_DIR . '/obtain-certs.sh'),
            escapeshellarg($email),
            escapeshellarg($certName),
            $args
        );
    }

    /**
     * Print "<domain> <first-ipv4|none>" for each domain, resolved with the box's
     * own resolver (getent is always present, unlike dig). Lets us keep the SAN
     * request to domains that actually point here — so ONE missing A record can't
     * fail the entire Let's Encrypt cert (the bug that left boxes on self-signed).
     */
    public static function resolveHostsCmd(array $domains): string
    {
        $clean = array_values(array_filter(array_map('strval', $domains)));
        if (empty($clean)) {
            return 'true';
        }
        $list = implode(' ', array_map('escapeshellarg', $clean));
        return 'for d in ' . $list . '; do '
            . 'ip=$(getent ahostsv4 "$d" 2>/dev/null | awk \'{print $1; exit}\'); '
            . 'echo "$d ${ip:-none}"; done';
    }

    /**
     * Parse resolveHostsCmd output → the subset of domains whose resolved IPv4
     * equals $ip (i.e. genuinely point at this box, so HTTP-01 will pass).
     *
     * @return string[]
     */
    public static function parseResolvableHosts(string $raw, string $ip): array
    {
        $ok = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 2 && $ip !== '' && $parts[1] === $ip) {
                $ok[] = $parts[0];
            }
        }
        return array_values(array_unique($ok));
    }

    /** create-mail-account.sh invocation — idempotent mailbox upsert in the mail DB. */
    public static function createMailboxCmd(string $email, string $password, int $quotaMb = 2048): string
    {
        return sprintf(
            'bash %s --email=%s --password=%s --quota-mb=%d --stack-dir=%s',
            escapeshellarg(self::STACK_DIR . '/create-mail-account.sh'),
            escapeshellarg($email),
            escapeshellarg($password),
            $quotaMb,
            escapeshellarg(self::STACK_DIR)
        );
    }

    /**
     * Resolve the default login mailbox for a server — parity with the native
     * ProvisioningService::resolveMailLogin(): local part 'robert' (or
     * MAIL_LOGIN_USER, sanitised), mailbox @<base mail domain>, password from
     * MAIL_LOGIN_PASS -> ADMIN_PASS -> generated. Every provisioned box gets one
     * real IMAP mailbox so the Email app has a working login out of the box.
     *
     * @return array{user:string,email:string,pass:string,generated:bool}
     */
    public static function resolveDefaultLogin(array $vars): array
    {
        $mailDomain = (string) ($vars['MAIL_DOMAIN'] ?? $vars['EMAIL_DOMAIN'] ?? '');
        $base = (string) preg_replace('/^mail\./', '', $mailDomain);

        $user = strtolower(trim((string) ($vars['MAIL_LOGIN_USER'] ?? 'robert')));
        $user = (string) preg_replace('/[^a-z0-9._-]/', '', $user);
        if ($user === '') {
            $user = 'robert';
        }

        $pass = (string) ($vars['MAIL_LOGIN_PASS'] ?? $vars['ADMIN_PASS'] ?? '');
        $generated = false;
        if ($pass === '') {
            $pass = bin2hex(random_bytes(9)); // 18 hex chars
            $generated = true;
        }

        return [
            'user' => $user,
            'email' => $base !== '' ? "{$user}@{$base}" : $user,
            'pass' => $pass,
            'generated' => $generated,
        ];
    }

    /**
     * Normalise LiveKit for the compose stack, where LiveKit is NOT a service —
     * it runs as an EXTERNAL server, unlike the native install which puts a
     * `livekit-server` (+ stunnel :7443) on the host and therefore always has a
     * valid ws_url. TemplateService still auto-generates a LIVEKIT_API_KEY for
     * every box (native parity), so a fresh Docker box arrives here with a key
     * but an empty LIVEKIT_WS_URL — which trips ComposeEnvRenderer's loud
     * "key set but ws_url empty" guard and blocks the whole deploy.
     *
     * Treat LiveKit as opt-in for compose: with no ws_url there is nowhere to
     * connect, so disable it (clear key + secret). Calls/huddles are simply off
     * until an operator points the box at an external LiveKit by setting
     * livekit_ws_url on the server. A ws_url that IS set is left untouched.
     *
     * @return array{vars: array, disabled: bool}
     */
    public static function normalizeLiveKit(array $vars): array
    {
        $wsUrl = trim((string) ($vars['LIVEKIT_WS_URL'] ?? ''));
        if ($wsUrl === '' && !empty($vars['LIVEKIT_API_KEY'])) {
            $vars['LIVEKIT_API_KEY'] = '';
            $vars['LIVEKIT_API_SECRET'] = '';
            return ['vars' => $vars, 'disabled' => true];
        }
        return ['vars' => $vars, 'disabled' => false];
    }

    /**
     * Parse `docker compose ps --format json` output into service => state.
     * Compose emits either a JSON array or one JSON object per line depending on
     * version; handle both. Returns ['web' => ['state' => 'running', 'health' => 'healthy'], ...].
     */
    public static function parsePsJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];

        $rows = [];
        // Try a single JSON array first.
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            $rows = $decoded;
        } else {
            // Fall back to newline-delimited JSON objects.
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $obj = json_decode($line, true);
                if (is_array($obj)) $rows[] = $obj;
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $name = $r['Service'] ?? $r['Name'] ?? null;
            if ($name === null) continue;
            $out[$name] = [
                'state'  => strtolower((string) ($r['State'] ?? 'unknown')),
                'health' => strtolower((string) ($r['Health'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Decide if the stack is healthy: every managed service must be running, and
     * any service that declares a healthcheck must report 'healthy'.
     */
    public static function isStackHealthy(array $states, array $services = self::SERVICES): bool
    {
        foreach ($services as $svc) {
            $s = $states[$svc] ?? null;
            if ($s === null) return false;
            if (($s['state'] ?? '') !== 'running') return false;
            $h = $s['health'] ?? '';
            if ($h !== '' && $h !== 'healthy') return false;
        }
        return true;
    }

    // =========================================================================
    // ORCHESTRATION (SSH side; validated on the Phase E Linux box)
    // =========================================================================

    /**
     * Provision (or re-deploy) the Docker stack on a server.
     *
     * @param array $options render options (enable_ssl, registry, tag) + orchestration
     *                       toggles (skip_docker_install, compose_source, seed_schema, wait_timeout).
     */
    public function provisionDocker(int $serverId, array $options = []): array
    {
        $this->log = [];
        $this->deploymentId = isset($options['deployment_id']) ? (int) $options['deployment_id'] : null;
        try {
            $this->markDeployment('running', 5, 'Connecting...');
            $server = $this->getServer($serverId);
            $this->logLine("Connecting to {$server['name']}...");
            if (!$this->ssh->connectToServer($server)) {
                throw new \RuntimeException('SSH connection failed');
            }

            $variables = $this->templates->generateServerVariables($server);

            // Fill + persist the non-regenerable crypto on a fresh box (loaded from
            // persisted columns / a migrated snapshot when present) so the renderer's
            // guards pass and every re-provision reuses the SAME keys.
            $secrets = ServerSecretGenerator::ensureDockerSecrets($variables);
            $variables = $secrets['vars'];
            if (!empty($secrets['generated'])) {
                $this->logLine('Generated crypto: ' . implode(', ', $secrets['generated']));
            }
            $lk = self::normalizeLiveKit($variables);
            $variables = $lk['vars'];
            if ($lk['disabled']) {
                $this->logLine('LiveKit disabled (no LIVEKIT_WS_URL configured) — calls/huddles off until an external LiveKit endpoint is set on the server.');
            }
            $this->templates->persistDockerSecrets($serverId, $variables);

            // Auto-size the heavy mail AV/spam services to the box's RAM (ClamAV
            // alone resident-loads ~1GB — on a <3GB box it OOM/swaps the stack).
            // Mirrors vps-bootstrap.sh; Rspamd/DKIM stay on regardless.
            $variables = $this->autoSizeMailServices($variables, $options);

            // Populate the "Server Credentials" panel (logins/keys/DNS) now that
            // all secrets are resolved — even if the stack later fails to become
            // healthy, the operator still has the generated credentials on record.
            $this->persistCredentialInventory($serverId, $variables);

            // 1. Docker engine + compose plugin
            if (empty($options['skip_docker_install'])) {
                $this->markDeployment(null, 15, 'Installing Docker...');
                $this->logLine('Ensuring Docker Engine + compose plugin...');
                $this->run(self::dockerInstallCmd(), 600, 'docker install');
            }

            // 2. Stack directory + compose file + sidecar files (mariadb-init, helpers)
            $this->markDeployment(null, 25, 'Shipping stack files...');
            $this->run('mkdir -p ' . escapeshellarg(self::STACK_DIR), 30, 'mkdir stack');
            $this->uploadComposeFile($options);
            $this->shipStackFiles($options);

            // 3. SSL ordering (chicken/egg): OLS refuses to start with ENABLE_SSL=1
            // when the cert file is missing, but certbot HTTP-01 needs the box up on
            // :80 first. So boot HTTP-first when SSL is wanted but the cert isn't
            // present yet; obtain it after `up`, then flip to HTTPS. A bare-IP host
            // (no LE) or an already-present cert skips straight to the final scheme.
            $wantSsl  = $options['enable_ssl'] ?? true;
            $certName = (string) ($variables['SERVER_FQDN'] ?? $variables['EMAIL_DOMAIN'] ?? '');
            $isIp     = (bool) preg_match('/^\d+(\.\d+){3}$/', $certName);
            $certPresent = $certName !== '' && $this->remoteTest(self::certPresentCmd($certName));
            $deferSsl = $wantSsl && !$isIp && !$certPresent;
            $initialSsl = $wantSsl && !$deferSsl;

            $this->markDeployment(null, 35, 'Rendering .env...');
            $this->logLine('Rendering per-host .env (SSL=' . ($initialSsl ? 'on' : 'off') . ')...');
            $this->renderAndUploadEnv($variables, $options, $initialSsl);

            // 3b. Seed the JWT PEM pair into the shared jwt_keys volume BEFORE the
            // stack comes up, so web/collab/mailsync mount a populated volume.
            $this->seedJwtVolume($variables);

            // 4. Pull images + bring the stack up
            $this->markDeployment(null, 50, 'Pulling images...');
            $this->maybeDockerLogin($options);
            $this->logLine('Pulling images...');
            $this->run(self::pullCmd(), 900, 'compose pull');
            $this->markDeployment(null, 65, 'Starting stack...');
            $this->logLine('Starting stack...');
            $this->run(self::upCmd(), 300, 'compose up');

            // 5. Wait for health
            $this->markDeployment(null, 75, 'Waiting for health...');
            $healthy = $this->waitHealthy((int) ($options['wait_timeout'] ?? 180));

            // 6. Obtain the SAN cert then flip SSL on (only if we deferred it above).
            if ($deferSsl) {
                $this->markDeployment(null, 85, 'Obtaining SSL certificate...');
                $this->obtainCertsAndEnableSsl($variables, $options, $certName, (string) ($server['ip_address'] ?? ''));
            }

            // 7. Warm the DB schema (best-effort) once web is serving, so the first
            // real user request doesn't race lazy DDL / hit missing columns.
            if ($healthy) {
                $this->markDeployment(null, 92, 'Warming schema...');
                $this->warmSchema();
                // 8. Seed the default login mailbox (parity with native robert@domain).
                $this->markDeployment(null, 96, 'Creating default mailbox...');
                $this->seedDefaultMailbox($variables);

                // 9. Host hardening (native parity) — the Docker path used to skip
                // this, leaving boxes on root@22/password with no firewall/fail2ban.
                // Runs fail2ban + firewalld + pxr@1985 key-only SSH (deny root),
                // then restarts Docker so its published-port rules survive firewalld.
                if (empty($options['skip_harden'])) {
                    $this->markDeployment(null, 98, 'Hardening host (SSH/firewall/fail2ban)...');
                    $this->hardenAfterProvision($serverId);
                }
            }

            $this->markDeployment($healthy ? 'success' : 'failed', 100,
                $healthy ? 'Completed' : 'Stack did not become healthy');
            $this->setServerStatus($serverId, $healthy ? 'active' : 'error');
            if ($healthy) {
                $this->persistDeployedTag($serverId, $this->resolveTag($options));
            }

            return [
                'success' => $healthy,
                'healthy' => $healthy,
                'log' => $this->log,
            ];
        } catch (\Throwable $e) {
            $this->logLine('ERROR: ' . $e->getMessage());
            $this->markDeployment('failed', null, 'Error: ' . substr($e->getMessage(), 0, 120));
            $this->setServerStatus($serverId, 'error');
            return ['success' => false, 'error' => $e->getMessage(), 'log' => $this->log];
        } finally {
            if ($this->ssh->isConnected()) {
                $this->ssh->disconnect();
            }
        }
    }

    /**
     * Day-2 SSL: (re)issue the Let's Encrypt SAN cert for whatever public domains
     * currently resolve to the box, then flip HTTPS on — WITHOUT a full
     * re-provision (no image pull, no container churn beyond `up web` + mail
     * reload). Use after adding/fixing DNS A records. Idempotent and safe to
     * repeat. Bare-IP boxes (no FQDN) are a no-op.
     *
     * @return array{success:bool, cert_present?:bool, error?:string, log:array}
     */
    public function renewSsl(int $serverId, array $options = []): array
    {
        $this->log = [];
        $this->deploymentId = isset($options['deployment_id']) ? (int) $options['deployment_id'] : null;
        try {
            $server = $this->getServer($serverId);
            $this->logLine("Connecting to {$server['name']} for SSL renewal...");
            if (!$this->ssh->connectToServer($server)) {
                throw new \RuntimeException('SSH connection failed');
            }

            $variables = $this->templates->generateServerVariables($server);
            $variables = ServerSecretGenerator::ensureDockerSecrets($variables)['vars'];
            $variables = self::normalizeLiveKit($variables)['vars'];

            $certName = (string) ($variables['SERVER_FQDN'] ?? $variables['EMAIL_DOMAIN'] ?? '');
            if ($certName === '' || preg_match('/^\d+(\.\d+){3}$/', $certName)) {
                $this->logLine('Server has no domain FQDN (bare IP) — nothing to secure.');
                return ['success' => false, 'error' => 'server has no domain FQDN for SSL', 'log' => $this->log];
            }

            $this->obtainCertsAndEnableSsl($variables, $options, $certName, (string) ($server['ip_address'] ?? ''));
            $present = $this->remoteTest(self::certPresentCmd($certName));
            $this->logLine($present ? 'SSL renewal complete — HTTPS active.' : 'SSL renewal did not produce a cert (see warnings).');
            return ['success' => $present, 'cert_present' => $present, 'log' => $this->log];
        } catch (\Throwable $e) {
            $this->logLine('ERROR: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'log' => $this->log];
        } finally {
            if ($this->ssh->isConnected()) {
                $this->ssh->disconnect();
            }
        }
    }

    /**
     * Day-2 backfill: (re)write the Server Credentials inventory for an already
     * provisioned Docker box, WITHOUT touching the running stack (no SSH to the
     * target, no image pull, no container churn). Variables — including all
     * generated secrets — are reloaded from the persisted `servers.*_encrypted`
     * columns, so this is safe, idempotent, and reproduces the exact logins the
     * box is already running with. Use it for boxes provisioned before the
     * inventory was recorded on the Docker path.
     *
     * @return array{success:bool, error?:string, log:array}
     */
    public function backfillCredentials(int $serverId): array
    {
        $this->log = [];
        try {
            $server = $this->getServer($serverId);
            $variables = $this->templates->generateServerVariables($server);
            $variables = ServerSecretGenerator::ensureDockerSecrets($variables)['vars'];
            $variables = self::normalizeLiveKit($variables)['vars'];
            // COALESCE-based persist: never rotates an existing secret, only fills gaps.
            $this->templates->persistDockerSecrets($serverId, $variables);
            $this->persistCredentialInventory($serverId, $variables);
            return ['success' => true, 'log' => $this->log];
        } catch (\Throwable $e) {
            $this->logLine('ERROR: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage(), 'log' => $this->log];
        }
    }

    /**
     * Docker Update: roll one or more already-running services to a chosen image
     * tag (Docker equivalent of the retired panel_update/email_update, and of
     * APP_UPDATE for the compose stack). Re-renders the per-host .env with the
     * new tag (preserving the box's current SSL state), logs the box in to the
     * registry, then `pull` + `up -d --no-deps` each selected service. The whole
     * stack keeps running; only the picked containers are recreated.
     *
     * @param string[] $services one or more of self::SERVICES
     * @param array    $options  may carry: tag, registry, deployment_id, registry_user/token
     */
    public function updateService(int $serverId, array $services, array $options = []): array
    {
        $this->log = [];
        $this->deploymentId = isset($options['deployment_id']) ? (int) $options['deployment_id'] : null;

        $services = array_values(array_unique(array_filter($services)));
        if (empty($services)) {
            return ['success' => false, 'error' => 'no services specified'];
        }
        $unknown = array_diff($services, self::SERVICES);
        if (!empty($unknown)) {
            return ['success' => false, 'error' => 'unknown service(s): ' . implode(', ', $unknown)];
        }

        try {
            $this->markDeployment('running', 5, 'Connecting...');
            $server = $this->getServer($serverId);
            $this->logLine("Connecting to {$server['name']}...");
            if (!$this->ssh->connectToServer($server)) {
                throw new \RuntimeException('SSH connection failed');
            }

            // Rebuild + persist variables so the re-render reuses the SAME
            // non-regenerable crypto (never rotate keys on an update).
            $variables = $this->templates->generateServerVariables($server);
            $secrets = ServerSecretGenerator::ensureDockerSecrets($variables);
            $variables = $secrets['vars'];
            $variables = self::normalizeLiveKit($variables)['vars'];
            $this->templates->persistDockerSecrets($serverId, $variables);

            // Keep the AV/spam profile RAM-appropriate on updates too, so a re-render
            // never silently re-enables ClamAV on a small box.
            $variables = $this->autoSizeMailServices($variables, $options);

            // Backfill/refresh the Server Credentials panel (covers boxes first
            // provisioned before the inventory was recorded on the Docker path).
            $this->persistCredentialInventory($serverId, $variables);

            // Re-ship the compose file (+ sidecar helpers) so compose-level changes
            // — e.g. a tuned healthcheck — reach the box on an update, not only on a
            // full provision. `up -d --no-deps <svc>` below then recreates the picked
            // services with the new config. Cheap + idempotent.
            $this->markDeployment(null, 15, 'Shipping stack files...');
            $this->run('mkdir -p ' . escapeshellarg(self::STACK_DIR), 30, 'mkdir stack');
            $this->uploadComposeFile($options);
            $this->shipStackFiles($options);

            // Preserve the box's current SSL state: HTTPS iff a real cert lineage
            // is already present for its FQDN (bare-IP boxes stay on HTTP).
            $certName = (string) ($variables['SERVER_FQDN'] ?? $variables['EMAIL_DOMAIN'] ?? '');
            $isIp = (bool) preg_match('/^\d+(\.\d+){3}$/', $certName);
            $enableSsl = $certName !== '' && !$isIp && $this->remoteTest(self::certPresentCmd($certName));

            $tag = $this->resolveTag($options);
            $this->markDeployment(null, 20, 'Rendering .env...');
            $this->logLine("Re-rendering .env (tag={$tag}, SSL=" . ($enableSsl ? 'on' : 'off') . ')...');
            $this->renderAndUploadEnv($variables, $options, $enableSsl);

            $this->markDeployment(null, 35, 'Logging in to registry...');
            $this->maybeDockerLogin($options);

            $total = count($services);
            foreach ($services as $i => $service) {
                $pct = 35 + (int) round((($i + 1) / $total) * 55); // 35 -> 90
                $this->markDeployment(null, $pct, "Updating {$service}...");
                $this->run(self::pullCmd($service), 900, "pull {$service}");
                $this->run(self::upCmd($service), 180, "up {$service}");
            }

            $this->persistDeployedTag($serverId, $tag);
            $this->markDeployment('success', 100, 'Completed');
            return ['success' => true, 'log' => $this->log];
        } catch (\Throwable $e) {
            $this->logLine('ERROR: ' . $e->getMessage());
            $this->markDeployment('failed', null, 'Error: ' . substr($e->getMessage(), 0, 120));
            return ['success' => false, 'error' => $e->getMessage(), 'log' => $this->log];
        } finally {
            if ($this->ssh->isConnected()) {
                $this->ssh->disconnect();
            }
        }
    }

    /**
     * Best-effort schema warm-up inside the web container. Never fails the
     * provision — a warning is logged if it errors (the app also self-heals on
     * first request), but a clean run makes the fresh box deterministic.
     */
    private function warmSchema(): void
    {
        $this->logLine('Warming DB schema...');
        $res = $this->ssh->execWithTimeout(self::ensureSchemaCmd(), 120);
        if (empty($res['success'])) {
            $this->logLine('WARN: schema warm-up non-zero exit (app self-heals on first request): '
                . substr(trim((string) ($res['output'] ?? $res['error'] ?? '')), -300));
            return;
        }
        $this->logLine('Schema warm-up ok');
    }

    /** Poll `docker compose ps` until healthy or timeout. */
    private function waitHealthy(int $timeoutSeconds): bool
    {
        $deadline = time() + $timeoutSeconds;
        do {
            $res = $this->ssh->exec(self::psJsonCmd());
            $states = self::parsePsJson((string) ($res['output'] ?? ''));
            if (self::isStackHealthy($states)) {
                $this->logLine('Stack healthy: ' . implode(', ', array_keys($states)));
                return true;
            }
            sleep(5);
        } while (time() < $deadline);

        $this->logLine('Timed out waiting for stack health.');
        return false;
    }

    /**
     * Resolve the docker-compose.yml source path on the Fleet host. Source order:
     * explicit option, Fleet config (docker.compose_path), else the packaged copy.
     */
    private function resolveComposeSource(array $options): string
    {
        return $options['compose_source']
            ?? $this->container->getConfig('docker.compose_path')
            ?? ($this->container->getConfig('packages.path') . 'docker-compose.yml');
    }

    /** Upload the version-controlled docker-compose.yml to the box. */
    private function uploadComposeFile(array $options): void
    {
        $source = $this->resolveComposeSource($options);
        if (!is_file($source)) {
            throw new \RuntimeException("compose file not found at: {$source}");
        }
        if (!$this->ssh->uploadFile($source, self::COMPOSE_FILE)) {
            throw new \RuntimeException('Failed to upload docker-compose.yml');
        }
        $this->logLine("Uploaded compose file from {$source}");
    }

    /**
     * Ship the files that live next to docker-compose.yml and are needed at boot
     * or for day-2 ops: the mariadb-init/ script (creates the mailserver DB +
     * least-privilege mailuser on a FRESH volume — the mail pod is dead without
     * it) and the obtain-certs / create-mail-account / dns-records helpers (used
     * by the SSL flip + default-mailbox steps here, and by operators afterwards).
     */
    private function shipStackFiles(array $options): void
    {
        $srcDir = dirname($this->resolveComposeSource($options));

        $init = $srcDir . '/mariadb-init/10-mailserver.sh';
        if (is_file($init)) {
            $this->run('mkdir -p ' . escapeshellarg(self::STACK_DIR . '/mariadb-init'), 30, 'mkdir mariadb-init');
            if (!$this->ssh->uploadFile($init, self::STACK_DIR . '/mariadb-init/10-mailserver.sh')) {
                throw new \RuntimeException('Failed to upload mariadb-init/10-mailserver.sh');
            }
        } else {
            $this->logLine('WARN: mariadb-init/10-mailserver.sh not found beside compose — mail DB will not auto-provision.');
        }

        foreach (self::HELPER_SCRIPTS as $h) {
            $p = $srcDir . '/' . $h;
            if (is_file($p) && $this->ssh->uploadFile($p, self::STACK_DIR . '/' . $h)) {
                $this->run('chmod +x ' . escapeshellarg(self::STACK_DIR . '/' . $h), 30, "chmod {$h}");
            }
        }
        $this->logLine('Shipped mariadb-init + day-2 helpers');
    }

    /** Render the per-host .env (fails loudly on missing secrets) and upload it (chmod 600). */
    private function renderAndUploadEnv(array $variables, array $options, bool $enableSsl): void
    {
        $envBody = $this->envRenderer->render($variables, [
            'enable_ssl' => $enableSsl,
            'registry'   => $options['registry'] ?? ($this->container->getConfig('docker.registry') ?? 'flowone'),
            'tag'        => $options['tag'] ?? ($this->container->getConfig('docker.tag') ?? 'latest'),
        ]);
        if (!$this->ssh->uploadContent($envBody, self::ENV_FILE)) {
            throw new \RuntimeException('Failed to upload .env');
        }
        $this->run('chmod 600 ' . escapeshellarg(self::ENV_FILE), 30, 'chmod .env');
    }

    /** Best-effort remote boolean test (exit 0 = true). Never throws. */
    private function remoteTest(string $command): bool
    {
        $res = $this->ssh->execWithTimeout($command, 30);
        return !empty($res['success']);
    }

    /**
     * Log the target host in to the image registry so it can pull PRIVATE
     * images. No-op when no credentials are configured (public images, or a
     * login already established on the box). Throws on an explicit auth failure
     * so a misconfigured token surfaces before the pull times out. The token is
     * never written to the log.
     *
     * @param array $options may carry registry/registry_user/registry_token overrides.
     */
    private function maybeDockerLogin(array $options): void
    {
        $registry = (string) ($options['registry'] ?? $this->container->getConfig('docker.registry') ?? '');
        $user     = (string) ($options['registry_user']  ?? $this->container->getConfig('docker.registry_user')  ?? '');
        $token    = (string) ($options['registry_token'] ?? $this->container->getConfig('docker.registry_token') ?? '');

        if ($user === '' || $token === '') {
            $this->logLine('No registry credentials configured — treating images as public (skipping docker login).');
            return;
        }

        $host = self::registryHost($registry);
        if ($host === '') {
            $this->logLine('WARN: registry has no host — skipping docker login.');
            return;
        }

        $this->logLine("Logging in to registry {$host} as {$user}...");
        // sensitive=true: the command pipes the token via printf, so its text must
        // never be echoed to the provision log.
        $res = $this->ssh->execWithTimeout(self::dockerLoginCmd($host, $user, $token), 60, true);
        if (empty($res['success'])) {
            // Deliberately do NOT include command output (could echo the token) —
            // just the host + hint.
            throw new \RuntimeException("docker login to {$host} failed — check docker.registry_user / docker.registry_token");
        }
        $this->logLine("Registry login ok ({$host})");
    }

    /**
     * Obtain a Let's Encrypt SAN cert covering every public host the box serves
     * (mail FQDN + webmail + panel), then re-render the .env with ENABLE_SSL=1 and
     * recreate web + reload mail so both pick up the real cert. Non-fatal: if
     * issuance fails the stack simply stays on HTTP and the operator can retry.
     */
    private function obtainCertsAndEnableSsl(array $variables, array $options, string $certName, string $serverIp): void
    {
        $email = (string) ($variables['ADMIN_EMAIL']
            ?? ('postmaster@' . ($variables['MAIL_DOMAIN'] ?? $variables['EMAIL_DOMAIN'] ?? '')));

        // Every public host the box could serve. The lineage (--cert-name) is a
        // label only; the SAN list is what LE validates over HTTP-01.
        $candidates = array_values(array_unique(array_filter([
            $certName,
            (string) ($variables['EMAIL_DOMAIN'] ?? ''),
            (string) ($variables['PANEL_DOMAIN'] ?? ''),
            (string) ($variables['MAIL_DOMAIN'] ?? ''),
        ])));

        // Only request the ones that actually resolve here. Without this, a single
        // missing A record (e.g. the apex or mail. host) fails the whole SAN and
        // the box silently stays on the OLS self-signed cert.
        $domains = $this->filterResolvable($candidates, $serverIp);
        $skipped = array_values(array_diff($candidates, $domains));
        if (!empty($skipped)) {
            $this->logLine('WARN: these domains do not resolve to ' . $serverIp
                . ' and were EXCLUDED from the cert — add A records -> ' . $serverIp
                . ' then re-run SSL: ' . implode(', ', $skipped));
        }
        if (empty($domains)) {
            $this->logLine('WARN: no public domains resolve to ' . $serverIp
                . '; leaving stack on HTTP. Add DNS A records, then re-provision or renew SSL.');
            return;
        }

        $this->logLine('Obtaining LE SAN cert (lineage ' . $certName . ') for: ' . implode(', ', $domains));
        $res = $this->ssh->execWithTimeout(self::obtainCertsCmd($email, $certName, $domains), 300);
        if (empty($res['success']) || !$this->remoteTest(self::certPresentCmd($certName))) {
            $this->logLine('WARN: cert issuance failed; leaving stack on HTTP: '
                . substr(trim((string) ($res['output'] ?? $res['error'] ?? '')), -300));
            return;
        }

        $this->logLine('Cert ready — enabling HTTPS and recreating web + mail...');
        $this->renderAndUploadEnv($variables, $options, true);
        $this->run(self::upCmd('web'), 180, 'up web (ssl)');
        $this->run(self::restartCmd('mail'), 120, 'restart mail (ssl)');
    }

    /** Keep only the domains whose public A record points at this box ($ip). */
    private function filterResolvable(array $domains, string $ip): array
    {
        $domains = array_values(array_unique(array_filter($domains)));
        if (empty($domains) || $ip === '') {
            return [];
        }
        $res = $this->ssh->exec(self::resolveHostsCmd($domains));
        return self::parseResolvableHosts((string) ($res['output'] ?? ''), $ip);
    }

    /**
     * Seed the default login mailbox so the Email app has a working login out of
     * the box — parity with the native ProvisioningService::seedMailAccount(). The
     * credential is logged into the deployment record so the operator can retrieve
     * it (native relied on the known ADMIN_PASS). Non-fatal.
     */
    private function seedDefaultMailbox(array $variables): void
    {
        $login = self::resolveDefaultLogin($variables);
        if ($login['email'] === '' || strpos($login['email'], '@') === false) {
            $this->logLine('WARN: no mail domain resolved — skipping default mailbox.');
            return;
        }

        $this->logLine("Seeding default login mailbox {$login['email']} (parity with native robert@domain)...");
        // sensitive=true: the command carries --password=<secret> on its arg list.
        $res = $this->ssh->execWithTimeout(self::createMailboxCmd($login['email'], $login['pass']), 120, true);
        if (empty($res['success'])) {
            $this->logLine('WARN: default mailbox creation failed (mail DB seeded yet?): '
                . substr(trim((string) ($res['output'] ?? $res['error'] ?? '')), -300));
            return;
        }

        $note = $login['generated']
            ? " password={$login['pass']} (auto-generated — save it)"
            : ' password=<the panel admin password for this server>';
        $this->logLine("Default login ready: {$login['email']}{$note}");
    }

    /**
     * Best-effort update of the dashboard deployment row (progress bar + streamed
     * log come from here). No-op when the deploy wasn't launched with a
     * deployment_id (e.g. a direct CLI run).
     */
    private function markDeployment(?string $status, ?int $progress = null, ?string $step = null): void
    {
        if (!$this->deploymentId) {
            return;
        }
        $sets = ['last_heartbeat = NOW()'];
        $params = [];
        if ($status !== null)   { $sets[] = 'status = ?';       $params[] = $status; }
        if ($progress !== null) { $sets[] = 'progress = ?';     $params[] = $progress; }
        if ($step !== null)     { $sets[] = 'current_step = ?';  $params[] = $step; }
        if ($status === 'running') {
            $sets[] = 'started_at = COALESCE(started_at, NOW())';
            $sets[] = 'pid = ?';
            $params[] = getmypid();
        }
        if ($status === 'success' || $status === 'failed') {
            $sets[] = 'completed_at = NOW()';
        }
        $params[] = $this->deploymentId;
        try {
            $this->db->prepare('UPDATE deployments SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        } catch (\Throwable $e) {
            // best-effort: never let dashboard bookkeeping fail the deploy
        }
    }

    /** Set the server row status (active on success, error on failure). Best-effort. */
    private function setServerStatus(int $serverId, string $status): void
    {
        try {
            $this->db->prepare('UPDATE servers SET status = ? WHERE id = ?')->execute([$status, $serverId]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Resolve the image tag this deploy uses. Order: explicit option -> Fleet
     * config (docker.tag) -> 'latest'. Kept in sync with renderAndUploadEnv so
     * the persisted tag matches what the .env actually points the images at.
     */
    private function resolveTag(array $options): string
    {
        return (string) ($options['tag'] ?? $this->container->getConfig('docker.tag') ?? 'latest');
    }

    /**
     * Record which image tag is now live on the server (migration 028 column).
     * Best-effort: never fails the deploy, and silently no-ops on a box whose
     * schema predates the column.
     */
    private function persistDeployedTag(int $serverId, string $tag): void
    {
        if ($tag === '') {
            return;
        }
        try {
            $this->db->prepare('UPDATE servers SET deployed_image_tag = ? WHERE id = ?')->execute([$tag, $serverId]);
            $this->logLine("Recorded deployed image tag: {$tag}");
        } catch (\Throwable $e) {
            // best-effort (column may not exist yet)
        }
    }

    /**
     * Mirror the native ProvisioningService credential inventory into
     * `server_credentials` so the dashboard's "Server Credentials" panel shows
     * the full set of generated logins/keys for a Docker-provisioned box. Before
     * this, the Docker path persisted secrets only to the `servers.*_encrypted`
     * columns and never populated this table, so the panel showed just the SSH
     * row. Values are encrypted at rest with the same EncryptionService the
     * native flow uses, and upserted (unique key: server_id + credential_key) so
     * re-provisions refresh values in place. Best-effort: never fails a deploy.
     */
    private function persistCredentialInventory(int $serverId, array $variables): void
    {
        $rows = self::buildCredentialRows($variables);
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO server_credentials (server_id, category, credential_key, label, value_encrypted, is_secret)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted), label = VALUES(label), is_secret = VALUES(is_secret)"
            );
            $stored = 0;
            foreach ($rows as [$category, $key, $label, $value, $isSecret]) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $stmt->execute([
                    $serverId,
                    $category,
                    $key,
                    $label,
                    $this->encryption->encrypt((string) $value),
                    $isSecret ? 1 : 0,
                ]);
                $stored++;
            }
            $this->logLine("Recorded {$stored} credentials in Fleet Manager (Server Credentials panel).");
        } catch (\Throwable $e) {
            // Convenience panel only — never block a deploy on it.
            $this->logLine('Warning: could not record credential inventory: ' . $e->getMessage());
        }
    }

    /**
     * Build the full credential inventory (pure; no DB/encryption) for a set of
     * rendered server variables — mirrors the native ProvisioningService set so
     * the dashboard panel is identical for Docker and native boxes. Rows with an
     * empty value are still returned; the writer skips them. Extracted as a
     * static so it can be unit-tested without a database.
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:bool}> [category, key, label, value, is_secret]
     */
    public static function buildCredentialRows(array $vars): array
    {
        $login = self::resolveDefaultLogin($vars);

        // DNS values an operator may need to publish at an external registrar
        // (domain delegated off-box). Mirror what the native flow records.
        $baseDomain = (string) preg_replace('/^panel\./', '', (string) ($vars['PANEL_DOMAIN'] ?? ''));
        $serverIp   = (string) ($vars['SERVER_IP'] ?? '');
        $spf        = ($baseDomain !== '' && $serverIp !== '') ? "v=spf1 a mx ip4:{$serverIp} -all" : '';
        $dmarcName  = $baseDomain !== '' ? "_dmarc.{$baseDomain}" : '';
        $dmarcValue = $baseDomain !== '' ? "v=DMARC1; p=reject; adkim=s; aspf=s; pct=100; rua=mailto:postmaster@{$baseDomain}; fo=1" : '';
        $mx         = $baseDomain !== '' ? "10 {$baseDomain}" : '';

        return [
            ['panel', 'ADMIN_EMAIL', 'Panel Admin Email', (string) ($vars['ADMIN_EMAIL'] ?? ''), false],
            ['panel', 'ADMIN_USER', 'Panel Admin Username', (string) ($vars['ADMIN_USER'] ?? 'pxradmin'), false],
            ['panel', 'ADMIN_PASS', 'Panel Admin Password', (string) ($vars['ADMIN_PASS'] ?? ''), true],

            ['database', 'DB_ROOT_USER', 'MariaDB Root User', 'root', false],
            ['database', 'DB_ROOT_PASS', 'MariaDB Root Password', (string) ($vars['DB_ROOT_PASS'] ?? ''), true],
            ['database', 'PANEL_DB_NAME', 'Panel+Email DB Name', (string) ($vars['PANEL_DB_NAME'] ?? ''), false],
            ['database', 'PANEL_DB_USER', 'Panel+Email DB User', (string) ($vars['PANEL_DB_USER'] ?? ''), false],
            ['database', 'PANEL_DB_PASS', 'Panel+Email DB Password', (string) ($vars['PANEL_DB_PASS'] ?? ''), true],
            ['database', 'MAIL_DB_NAME', 'Mail Server DB Name', (string) ($vars['MAIL_DB_NAME'] ?? ''), false],
            ['database', 'MAIL_DB_USER', 'Mail Server DB User', (string) ($vars['MAIL_DB_USER'] ?? ''), false],
            ['database', 'MAIL_DB_PASS', 'Mail Server DB Password', (string) ($vars['MAIL_DB_PASS'] ?? ''), true],

            ['services', 'REDIS_PASS', 'Redis Password', (string) ($vars['REDIS_PASS'] ?? ''), true],
            ['services', 'MEILI_MASTER_KEY', 'Meilisearch Master Key', (string) ($vars['MEILI_MASTER_KEY'] ?? ''), true],

            ['secrets', 'EMAIL_API_KEY', 'Email App API Key (Panel external_api)', (string) ($vars['EMAIL_API_KEY'] ?? ''), true],
            ['secrets', 'JWT_SECRET', 'JWT Secret', (string) ($vars['JWT_SECRET'] ?? ''), true],
            ['secrets', 'ENCRYPTION_KEY', 'Encryption Key', (string) ($vars['ENCRYPTION_KEY'] ?? ''), true],

            ['mail', 'MAIL_ADMIN_EMAIL', 'Email App Login (mailbox)', $login['email'], false],
            ['mail', 'MAIL_ADMIN_PASS', 'Email App Login Password', $login['pass'], true],

            ['ssh', 'SSH_USER', 'SSH User', (string) ($vars['SSH_USER'] ?? 'root'), false],
            ['ssh', 'SSH_PORT', 'SSH Port', (string) ($vars['SSH_PORT'] ?? '22'), false],

            ['dns', 'MX_RECORD', 'MX (name: ' . $baseDomain . ')', $mx, false],
            ['dns', 'SPF_RECORD', 'SPF TXT (name: ' . $baseDomain . ')', $spf, false],
            ['dns', 'DMARC_NAME', 'DMARC Record Name', $dmarcName, false],
            ['dns', 'DMARC_RECORD', 'DMARC TXT Value', $dmarcValue, false],
            ['dns', 'DKIM_DNS_NAME', 'DKIM Record Name', (string) ($vars['DKIM_DNS_NAME'] ?? ''), false],
            ['dns', 'DKIM_DNS_RECORD', 'DKIM TXT Value', (string) ($vars['DKIM_DNS_RECORD'] ?? ''), false],
        ];
    }

    /**
     * Seed the JWT RS256 PEM pair into the shared `jwt_keys` named volume. The
     * volume is created first (idempotent) so this can run before `compose up`;
     * a throwaway helper container copies the keys in and fixes perms.
     */
    private function seedJwtVolume(array $variables): void
    {
        $priv = (string) ($variables['JWT_PRIVATE_KEY_PEM'] ?? '');
        $pub  = (string) ($variables['JWT_PUBLIC_KEY_PEM'] ?? '');
        if ($priv === '' || $pub === '') {
            throw new \RuntimeException('JWT key pair missing; cannot seed jwt_keys volume');
        }

        $tmp = '/tmp/flowone-jwt-seed';
        $this->run('mkdir -p ' . escapeshellarg($tmp), 30, 'mkdir jwt seed dir');
        if (!$this->ssh->uploadContent($priv, $tmp . '/jwt-private.pem')) {
            throw new \RuntimeException('Failed to upload jwt-private.pem');
        }
        if (!$this->ssh->uploadContent($pub, $tmp . '/jwt-public.pem')) {
            throw new \RuntimeException('Failed to upload jwt-public.pem');
        }
        $this->run('docker volume create ' . escapeshellarg(self::JWT_VOLUME), 60, 'create jwt volume');
        $this->run(self::seedVolumeCmd(self::JWT_VOLUME, $tmp), 120, 'seed jwt volume');
        $this->run('rm -rf ' . escapeshellarg($tmp), 30, 'cleanup jwt seed dir');
        $this->logLine('Seeded JWT key pair into ' . self::JWT_VOLUME);
    }

    private function getServer(int $serverId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM servers WHERE id = ?');
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$server) {
            throw new \RuntimeException('Server not found');
        }
        return $server;
    }

    /**
     * Run the native-parity HOST HARDENING (fail2ban + firewall + pxr@1985
     * key-only SSH, deny root) after a successful Docker provision.
     *
     * Delegates to ProvisioningService::hardenExistingServer() — the SAME proven,
     * safe 3-phase logic the native full-provision uses — via an ISOLATED
     * Container so it gets its OWN SSH + DB handles (this service's SSHService is a
     * shared container singleton; the harden run reconnects and re-homes the box
     * to pxr@1985, so it must not fight our session). Non-fatal: a hardening
     * hiccup never fails the provision — the stack is already up and the box stays
     * reachable on whatever SSH profile it currently has.
     */
    private function hardenAfterProvision(int $serverId): void
    {
        try {
            if ($this->ssh->isConnected()) {
                $this->ssh->disconnect();
            }
            $isolated = new Container($this->container->getConfig());
            /** @var ProvisioningService $prov */
            $prov = $isolated->get(ProvisioningService::class);
            $res = $prov->hardenExistingServer($serverId, ['docker' => true]);
            foreach (($res['log'] ?? []) as $entry) {
                $msg = is_array($entry) ? ($entry['message'] ?? '') : (string) $entry;
                if ($msg !== '') {
                    $this->logLine('[harden] ' . $msg);
                }
            }
            if (empty($res['success'])) {
                $this->logLine('Hardening did not complete: ' . ($res['error'] ?? 'unknown')
                    . ' — box stays reachable on its current SSH profile; re-run `harden-server.php ' . $serverId . ' --docker`.');
            }
        } catch (\Throwable $e) {
            $this->logLine('Hardening error (non-fatal): ' . $e->getMessage());
        }
    }

    /**
     * Auto-size the heavy mail AV/spam services to the target box's RAM.
     *
     * ClamAV alone resident-loads ~1GB and SpamAssassin adds a few hundred MB; on
     * a box with <3GB total RAM they cause memory pressure / swap / OOM (this is
     * exactly why devcon3 sat at ~73% memory). Rspamd is light and always stays on
     * (DKIM/DMARC/spam scoring keep working). Mirrors vps-bootstrap.sh's sizing.
     *
     * Precedence: an explicit choice always wins — a `mail_enable_clamav` deploy
     * option, or a value already carried in $variables (e.g. from a migrated
     * snapshot .env). Only when nothing was specified do we size by RAM.
     */
    private function autoSizeMailServices(array $variables, array $options): array
    {
        if (array_key_exists('mail_enable_clamav', $options)) {
            $clam = !empty($options['mail_enable_clamav']) ? '1' : '0';
            $spam = array_key_exists('mail_enable_spamassassin', $options)
                ? (!empty($options['mail_enable_spamassassin']) ? '1' : '0')
                : $clam;
            $variables['MAIL_ENABLE_CLAMAV'] = $clam;
            $variables['MAIL_ENABLE_SPAMASSASSIN'] = $spam;
            $this->logLine("Mail AV/spam set by option: ClamAV={$clam}, SpamAssassin={$spam}.");
            return $variables;
        }
        // Honor an explicit value already resolved into the vars (migrated .env).
        if (isset($variables['MAIL_ENABLE_CLAMAV']) && $variables['MAIL_ENABLE_CLAMAV'] !== '') {
            return $variables;
        }

        $memMb = $this->detectMemMb();
        if ($memMb > 0 && $memMb < 3000) {
            $variables['MAIL_ENABLE_CLAMAV'] = '0';
            $variables['MAIL_ENABLE_SPAMASSASSIN'] = '0';
            $this->logLine("Detected {$memMb}MB RAM (<3GB): ClamAV + SpamAssassin OFF to avoid OOM (Rspamd/DKIM stay on). Add RAM to re-enable.");
        } else {
            $variables['MAIL_ENABLE_CLAMAV'] = '1';
            $variables['MAIL_ENABLE_SPAMASSASSIN'] = '1';
            $this->logLine(($memMb > 0 ? "Detected {$memMb}MB RAM (>=3GB)" : 'RAM unknown') . ': ClamAV + SpamAssassin ON.');
        }
        return $variables;
    }

    /** Total RAM of the connected target in MB (0 if it can't be read). */
    private function detectMemMb(): int
    {
        try {
            $res = $this->ssh->execWithTimeout("awk '/MemTotal/{print int(\$2/1024)}' /proc/meminfo", 15);
            return (int) trim((string) ($res['output'] ?? '0'));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Run a remote command with a timeout; throw with context on failure. */
    private function run(string $command, int $timeout, string $label): void
    {
        $res = $this->ssh->execWithTimeout($command, $timeout);
        if (empty($res['success'])) {
            $out = trim((string) ($res['output'] ?? $res['error'] ?? ''));
            throw new \RuntimeException("step '{$label}' failed: " . substr($out, -500));
        }
        $this->logLine("ok: {$label}");
    }

    private function logLine(string $msg): void
    {
        $this->log[] = $msg;
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, "  [docker-provision] {$msg}\n");
        }
        // Stream to the dashboard deployment row (append) so operators see live
        // progress. Best-effort — a logging failure must never abort the deploy.
        if ($this->deploymentId) {
            try {
                $stmt = $this->db->prepare(
                    'UPDATE deployments SET log = CONCAT(COALESCE(log, ""), ?), last_heartbeat = NOW() WHERE id = ?'
                );
                $stmt->execute([$msg . "\n", $this->deploymentId]);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }
}
