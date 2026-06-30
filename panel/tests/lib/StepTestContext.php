<?php

declare(strict_types=1);

namespace VpsAdmin\Tests\Lib;

use VpsAdmin\Agent\Provisioner\Adapters\Adapters;
use VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\MysqlAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\NasAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\OlsAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;
use VpsAdmin\Agent\Provisioner\Adapters\SftpAdapter;
use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\Services\SecretVault;
use VpsAdmin\Agent\Provisioner\Services\ServerCapabilities;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Support\MysqlAdminCredentials;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Shared scaffolding for Step-4 provisioning step tests.
 *
 * Builds a SiteContext with:
 *   - real PanelDatabase + AuditLogger + SecretVault (using a per-test
 *     master key under /tmp so no real prod secrets leak)
 *   - a sandboxed OlsAdapter pointed at $sandboxRoot/ols/ so writes
 *     don't touch the live OLS install
 *   - a real ProcessCommandRunner (sftp + ols restart need real exec)
 *   - a FilesystemAdapter whose allowed-roots include $sandboxRoot and
 *     a per-domain /home/<flowone_test_*> entry
 *   - an optional MysqlAdapter using credentials passed via env or
 *     PanelDatabase defaults (skip helper available for tests that
 *     can't get admin grants)
 *
 * Each test that needs a SiteContext calls
 *   $bundle = StepTestContext::build($options);
 * and gets back a struct with the context + ancillary handles (sandbox
 * paths, the seed httpd_config.conf path, etc) for assertions.
 */
final class StepTestContext
{
    /**
     * Build a fresh sandboxed SiteContext + ancillary handles.
     *
     * @param array{
     *   domain?: string,
     *   payload?: array<string,mixed>,
     *   site_row_overrides?: array<string,mixed>,
     *   ols?: bool,
     *   sftp?: bool,
     *   mysql?: array{user?:string, pass?:string, socket?:string}|null,
     *   seed_main_config?: string|null,
     * } $opts
     * @return array{
     *   ctx: SiteContext,
     *   sandbox_root: string,
     *   ols_config_root: string,
     *   main_config_path: string,
     *   fs: FilesystemAdapter,
     *   ols: OlsAdapter,
     *   mysql: MysqlAdapter,
     *   sftp: SftpAdapter,
     *   nas: NasAdapter,
     *   runner: ProcessCommandRunner
     * }
     */
    public static function build(array $opts = []): array
    {
        $domain = $opts['domain'] ?? ('flowone_test_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.local');

        // Sandbox directory under /tmp so cleanup is trivial.
        $sandbox = sys_get_temp_dir() . '/flowone_step_test_' . substr(bin2hex(random_bytes(4)), 0, 8);
        @mkdir($sandbox, 0755, true);
        $olsRoot = $sandbox . '/ols';
        @mkdir($olsRoot . '/vhosts', 0755, true);

        // Seed httpd_config.conf inside the sandbox so OLS-related
        // steps have something to mutate.
        $mainConfigPath = $olsRoot . '/httpd_config.conf';
        $seed = $opts['seed_main_config'] ?? self::defaultMainConfigSeed();
        file_put_contents($mainConfigPath, $seed);

        // Adapter wiring.
        $runner = new ProcessCommandRunner();
        $fs = new FilesystemAdapter(
            runner: $runner,
            allowedRoots: [$sandbox, sys_get_temp_dir()],
        );

        $ols = new OlsAdapter(
            runner: $runner,
            fs: $fs,
            configRoot: $olsRoot,
        );

        $sftp = new SftpAdapter(runner: $runner);
        $nas = new NasAdapter(runner: $runner);

        // MySQL: use admin creds if provided, otherwise default panel
        // creds. Tests that need destructive privs will SKIP if these
        // are insufficient.
        $mysqlOpts = $opts['mysql'] ?? null;
        $mysql = self::buildMysqlAdapter($runner, $mysqlOpts);

        // Core services.
        $db = PanelDatabase::fromDefaultConfigFiles();
        $masker = new SecretMasker();
        $audit = new AuditLogger($db, $masker);
        $keyPath = self::ensureTestMasterKey();
        $vault = new SecretVault($db, $keyPath);
        $caps = new ServerCapabilities();

        $siteRow = array_merge([
            'id' => 99000 + random_int(0, 999),
            'domain' => $domain,
            'state' => null,
        ], $opts['site_row_overrides'] ?? []);

        $ctx = new SiteContext(
            siteRow: $siteRow,
            jobId: $siteRow['id'],
            requestId: 'req-' . substr(bin2hex(random_bytes(4)), 0, 8),
            actor: ActorContext::cli('step-test'),
            audit: $audit,
            vault: $vault,
            capabilities: $caps,
            database: $db,
            payload: $opts['payload'] ?? [],
            dryRun: false,
            adapters: new Adapters(
                runner: $runner,
                fs: $fs,
                ols: $ols,
                mysql: $mysql,
                sftp: $sftp,
                nas: $nas,
                olsRestart: null,
            ),
        );

        return [
            'ctx' => $ctx,
            'sandbox_root' => $sandbox,
            'ols_config_root' => $olsRoot,
            'main_config_path' => $mainConfigPath,
            'fs' => $fs,
            'ols' => $ols,
            'mysql' => $mysql,
            'sftp' => $sftp,
            'nas' => $nas,
            'runner' => $runner,
        ];
    }

    /**
     * Helper to update a SiteContext with a new siteRow (e.g. after a
     * step writes back its state). SiteContext is immutable so this
     * builds a fresh instance with everything else preserved.
     *
     * @param array<string,mixed> $newSiteRow
     */
    public static function withUpdatedSiteRow(SiteContext $ctx, array $newSiteRow): SiteContext
    {
        return new SiteContext(
            siteRow: $newSiteRow,
            jobId: $ctx->jobId,
            requestId: $ctx->requestId,
            actor: $ctx->actor,
            audit: $ctx->audit,
            vault: $ctx->vault,
            capabilities: $ctx->capabilities,
            database: $ctx->database,
            payload: $ctx->payload,
            dryRun: $ctx->dryRun,
            deadlineUnixMicro: $ctx->deadlineUnixMicro,
            adapters: $ctx->adapters,
        );
    }

    public static function teardown(array $bundle): void
    {
        $sandbox = $bundle['sandbox_root'] ?? null;
        if (is_string($sandbox) && str_starts_with($sandbox, sys_get_temp_dir() . '/flowone_step_test_')) {
            self::rmtree($sandbox);
        }
    }

    private static function defaultMainConfigSeed(): string
    {
        // Minimal but realistic httpd_config.conf so OLS mutator has
        // listeners to target. Two listeners (Default, SSL) like the
        // real install.
        return <<<CONF
serverName                FlowOneTestServer
user                      nobody
group                     nogroup
priority                  0
inMemBufSize              60M
swappingDir               /tmp/lshttpd/swap
autoFix503                1

listener Default {
  address                 *:80
  secure                  0
}

listener SSL {
  address                 *:443
  secure                  1
}

CONF;
    }

    /**
     * Best-effort master key path. Reused across tests so the vault
     * survives between test files in the same run.
     */
    public static function ensureTestMasterKey(): string
    {
        $path = sys_get_temp_dir() . '/flowone_step_test_master.key';
        if (!file_exists($path)) {
            file_put_contents($path, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            @chmod($path, 0400);
        }
        return $path;
    }

    /**
     * @param array{user?:string, pass?:string, socket?:string}|null $opts
     */
    private static function buildMysqlAdapter(
        ProcessCommandRunner $runner,
        ?array $opts
    ): MysqlAdapter {
        // When a test passes explicit mysql creds, honour them verbatim.
        if ($opts !== null) {
            $db = PanelDatabase::fromDefaultConfigFiles();
            $defaults = $db->config();

            $user = $opts['user'] ?? ($defaults['user'] ?? 'vpsadmin');
            $pass = $opts['pass'] ?? ($defaults['password'] ?? '');
            $socket = $opts['socket'] ?? ($defaults['socket'] ?? '/run/mysqld/mysqld.sock');

            return new MysqlAdapter(
                runner: $runner,
                credentialsProvider: static fn(): array => [
                    'user' => $user,
                    'password' => $pass,
                    'socket' => $socket,
                ],
            );
        }

        // Default: resolve admin credentials exactly the way the
        // production worker does (database_admin block -> /root/.my.cnf
        // -> panel DB user). This lets the destructive DB-step tests
        // actually run on a server that has /root/.my.cnf instead of
        // SKIPping, because the narrowly-scoped panel user cannot CREATE
        // or DROP databases. Tests still SKIP via $mysqlCanDestructiveDDL
        // when no admin grant is reachable.
        return new MysqlAdapter(
            runner: $runner,
            credentialsProvider: MysqlAdminCredentials::providerFromDefaultConfigFiles(),
        );
    }

    private static function rmtree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $entries = @scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                self::rmtree($path . '/' . $e);
            }
        }
        @rmdir($path);
    }
}
