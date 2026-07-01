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

    private Container $container;
    private SSHService $ssh;
    private ComposeEnvRenderer $envRenderer;
    private TemplateService $templates;
    private EncryptionService $encryption;
    private \PDO $db;
    private array $log = [];

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
        try {
            $server = $this->getServer($serverId);
            $this->logLine("Connecting to {$server['name']}...");
            if (!$this->ssh->connectToServer($server)) {
                throw new \RuntimeException('SSH connection failed');
            }

            $variables = $this->templates->generateServerVariables($server);

            // 1. Docker engine + compose plugin
            if (empty($options['skip_docker_install'])) {
                $this->logLine('Ensuring Docker Engine + compose plugin...');
                $this->run(self::dockerInstallCmd(), 600, 'docker install');
            }

            // 2. Stack directory + compose file
            $this->run('mkdir -p ' . escapeshellarg(self::STACK_DIR), 30, 'mkdir stack');
            $this->uploadComposeFile($options);

            // 3. Render + upload the per-host .env (fails loudly on missing secrets)
            $this->logLine('Rendering per-host .env...');
            $envBody = $this->envRenderer->render($variables, [
                'enable_ssl' => $options['enable_ssl'] ?? true,
                'registry'   => $options['registry'] ?? ($this->container->getConfig('docker.registry') ?? 'flowone'),
                'tag'        => $options['tag'] ?? ($this->container->getConfig('docker.tag') ?? 'latest'),
            ]);
            if (!$this->ssh->uploadContent($envBody, self::ENV_FILE)) {
                throw new \RuntimeException('Failed to upload .env');
            }
            $this->run('chmod 600 ' . escapeshellarg(self::ENV_FILE), 30, 'chmod .env');

            // 4. Pull images + bring the stack up
            $this->logLine('Pulling images...');
            $this->run(self::pullCmd(), 900, 'compose pull');
            $this->logLine('Starting stack...');
            $this->run(self::upCmd(), 300, 'compose up');

            // 5. Wait for health
            $healthy = $this->waitHealthy((int) ($options['wait_timeout'] ?? 180));

            return [
                'success' => $healthy,
                'healthy' => $healthy,
                'log' => $this->log,
            ];
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
     * Update a single service to the current image (Docker equivalent of the
     * retired panel_update/email_update, and of APP_UPDATE for one app).
     */
    public function updateService(int $serverId, string $service): array
    {
        if (!in_array($service, self::SERVICES, true)) {
            return ['success' => false, 'error' => "unknown service: {$service}"];
        }
        $this->log = [];
        try {
            $server = $this->getServer($serverId);
            if (!$this->ssh->connectToServer($server)) {
                throw new \RuntimeException('SSH connection failed');
            }
            $this->run(self::pullCmd($service), 900, "pull {$service}");
            $this->run(self::upCmd($service), 180, "up {$service}");
            return ['success' => true, 'log' => $this->log];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'log' => $this->log];
        } finally {
            if ($this->ssh->isConnected()) {
                $this->ssh->disconnect();
            }
        }
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
     * Upload the version-controlled docker-compose.yml. Source order:
     * explicit option, Fleet config (docker.compose_path), else the packaged copy.
     */
    private function uploadComposeFile(array $options): void
    {
        $source = $options['compose_source']
            ?? $this->container->getConfig('docker.compose_path')
            ?? ($this->container->getConfig('packages.path') . 'docker-compose.yml');

        if (!is_file($source)) {
            throw new \RuntimeException("compose file not found at: {$source}");
        }
        if (!$this->ssh->uploadFile($source, self::COMPOSE_FILE)) {
            throw new \RuntimeException('Failed to upload docker-compose.yml');
        }
        $this->logLine("Uploaded compose file from {$source}");
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
    }
}
