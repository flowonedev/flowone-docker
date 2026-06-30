<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\App;

use VpsAdmin\Agent\Installers\WordPressInstaller;
use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * Optional final step in the CREATE saga: install an application
 * (currently WordPress) into the freshly provisioned site.
 *
 * Gated entirely on payload['install_app']:
 *
 *   payload['install_app'] = [
 *     'app_slug'       => 'wordpress',        // required, only 'wordpress' is supported today
 *     'admin_user'     => 'admin',            // optional, defaults to 'admin'
 *     'admin_email'    => 'me@example.com',   // optional, defaults to admin@<domain>
 *     'admin_password' => 'secret',           // optional, auto-generated if absent
 *     'site_title'     => 'My Site',          // optional, defaults to <domain>
 *   ]
 *
 * When the payload entry is absent or empty, the step is a no-op (check()
 * returns true, execute() short-circuits with an info event). This keeps
 * bare site creation behaving exactly as before - no DB writes, no WP-CLI
 * calls.
 *
 * Inputs sourced from the saga so the step does not re-derive things
 * earlier steps already produced:
 *
 *   - document root: <home>/public_html, where <home> matches HomeDirCreateStep
 *   - site_user:     ResourceNameDeriver::sftpName($domain) - same algorithm
 *                    SftpUserCreateStep used. When payload['skip_sftp'] is
 *                    true (admin opted out of an SFTP user) we fall back
 *                    to www-data so WP-CLI runs as the web user instead.
 *   - db_name:       ResourceNameDeriver::dbName($domain) - matches
 *                    DatabaseCreateStep.
 *   - db_user:       ResourceNameDeriver::dbUser($domain) - matches
 *                    DatabaseUserCreateStep.
 *   - db_password:   pulled from SecretVault at site:<domain>/db_password
 *                    (the same scope DatabaseUserCreateStep stored it under).
 *
 * Idempotency:
 *   - check() returns true if site_applications already has an active
 *     row for (domain, app_slug). The reconciler re-runs the CREATE
 *     saga for pending_dns sites; this step must not re-run wp core
 *     install on a site that already has WordPress.
 *   - execute() re-checks before doing any work and short-circuits with
 *     a "already_installed" event when the row is present.
 *
 * Compensation: DEGRADE_ONLY.
 *
 *   This step sits AT THE END of the CREATE saga. By the time it runs,
 *   the entire site infrastructure (SFTP user, home dir, vhost, OLS
 *   config, DB, DB user, DNS zone, SSL) is in place and the site is
 *   functional with or without WordPress on top. If WP install fails:
 *
 *     - we DO call cleanupFailedInstall() inside WordPressInstaller
 *       (removes half-extracted WP files so the docroot is clean for
 *       a retry)
 *     - we DO NOT compensate backwards (which would tear down the
 *       working site infrastructure)
 *     - the saga lands in degraded; the site row stays 'active' or
 *       'pending_dns' so the operator can retry the WP install from
 *       the UI without recreating the site.
 *
 *   The compensate() method is therefore a no-op event log entry. The
 *   "app.uninstall as compensator" plan-doc phrasing refers to the
 *   conceptual symmetry; in practice the orchestrator's DEGRADE_ONLY
 *   policy prevents compensate() from ever being called for the last
 *   step in a sequence.
 *
 * What this step does NOT do (intentional scope limits):
 *   - It does not create the database. DatabaseCreateStep already did.
 *     WordPressInstaller::createDatabase is idempotent (CREATE IF NOT
 *     EXISTS + ALTER USER to sync password) so passing the existing
 *     name/user/password is safe and just confirms the grants.
 *   - It does not run a fresh OLS reload. The OlsRestartStep earlier
 *     in the saga already did that. WordPressInstaller does its own
 *     belt-and-braces reload but it's a no-op when the vhost is
 *     already live.
 */
final class InstallAppStep extends AbstractStep
{
    /**
     * @param ?WordPressInstaller $installer Optional injected installer.
     *                  Tests pass a stub; in production SagaRegistry
     *                  constructs one from the agent config.
     * @param array<string,mixed> $defaultConfig Agent-style config array,
     *                  used to lazily build a WordPressInstaller when one
     *                  was not injected. Production callers pass the
     *                  config.php contents; tests pass [].
     */
    public function __construct(
        private readonly ?WordPressInstaller $installer = null,
        private readonly array $defaultConfig = [],
    ) {
    }

    public function name(): string
    {
        return StepName::INSTALL_APP;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $appConfig = $this->resolveAppConfig($ctx);
        if ($appConfig === null) {
            return true;
        }

        try {
            $pdo = $ctx->database->pdo();
            $stmt = $pdo->prepare(
                "SELECT id FROM site_applications WHERE domain = ? AND app_slug = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$ctx->domain(), $appConfig['app_slug']]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $appConfig = $this->resolveAppConfig($ctx);
        if ($appConfig === null) {
            return StepResult::success(
                $state->mergeData(['skipped' => true, 'reason' => 'no install_app payload'])
                      ->withCompleted(),
                [StepEvent::info('install_app skipped (no app requested)')]
            );
        }

        $appSlug = $appConfig['app_slug'];
        if ($appSlug !== 'wordpress') {
            return StepResult::failure(
                $state,
                "install_app: unsupported app_slug '{$appSlug}' (only 'wordpress' is supported in V2)",
            );
        }

        if ($this->check($ctx, $state)) {
            return StepResult::success(
                $state->mergeData([
                    'already_installed' => true,
                    'app_slug' => $appSlug,
                ])->withCompleted(),
                [StepEvent::info('install_app skipped (already installed)', ['app_slug' => $appSlug])]
            );
        }

        $domain = $ctx->domain();
        $homeDir = '/home/' . $domain;
        $docRoot = $homeDir . '/public_html';

        $siteUser = !empty($ctx->payload['skip_sftp'])
            ? 'www-data'
            : ResourceNameDeriver::sftpName($domain);

        $dbName = ResourceNameDeriver::dbName($domain);
        $dbUser = ResourceNameDeriver::dbUser($domain);

        try {
            $dbPassword = $ctx->vault->get('site:' . $domain, 'db_password', $ctx->actor);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "install_app: cannot retrieve db_password from vault for {$domain}: " . $e->getMessage(),
            );
        }

        $adminUser = $appConfig['admin_user'] ?? 'admin';
        $adminPassword = $appConfig['admin_password'] ?? $this->generatePassword();
        $adminEmail = $appConfig['admin_email'] ?? "admin@{$domain}";
        $siteTitle = $appConfig['site_title'] ?? $domain;

        $events = [StepEvent::info('install_app starting', [
            'app_slug' => $appSlug,
            'doc_root' => $docRoot,
            'site_user' => $siteUser,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'admin_user' => $adminUser,
        ])];

        $installer = $this->installer ?? new WordPressInstaller(
            $this->defaultConfig,
            new InstallAppStepNullLogger(),
        );

        $installParams = [
            'domain' => $domain,
            'document_root' => $docRoot,
            'site_user' => $siteUser,
            'admin_email' => $adminEmail,
            'admin_user' => $adminUser,
            'admin_password' => $adminPassword,
            'site_title' => $siteTitle,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_password' => $dbPassword,
        ];

        try {
            $result = $installer->install($installParams, $ctx->actor->username);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "install_app: installer threw " . $e::class . ": " . $e->getMessage(),
                $events,
            );
        }

        sodium_memzero($dbPassword);

        if (!($result['success'] ?? false)) {
            $err = (string) ($result['error'] ?? $result['message'] ?? 'unknown installer error');
            return StepResult::failure(
                $state,
                "install_app: installer reported failure: {$err}",
                $events,
            );
        }

        $version = (string) ($result['data']['version'] ?? 'latest');
        $adminUrl = (string) ($result['data']['admin_url'] ?? "https://{$domain}/wp-admin/");

        try {
            $ctx->vault->put(
                'site:' . $domain,
                'app_' . $appSlug . '_admin_password',
                $adminPassword,
                $ctx->actor,
                "{$appSlug} admin password for {$domain}",
            );
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning(
                'install_app: failed to vault admin password (install succeeded)',
                ['error' => $e->getMessage()],
            );
        }

        sodium_memzero($adminPassword);

        try {
            $this->upsertAppRecord(
                $ctx,
                $domain,
                $appSlug,
                $version,
                $docRoot,
                $adminUrl,
                $adminUser,
                $dbName,
            );
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning(
                'install_app: failed to upsert site_applications row (install succeeded)',
                ['error' => $e->getMessage()],
            );
        }

        $events[] = StepEvent::info('install_app completed', [
            'app_slug' => $appSlug,
            'version' => $version,
            'admin_url' => $adminUrl,
        ]);

        return StepResult::success(
            $state->mergeData([
                'app_slug' => $appSlug,
                'version' => $version,
                'install_path' => $docRoot,
                'admin_url' => $adminUrl,
                'admin_user' => $adminUser,
                'database' => $dbName,
            ])->withCompleted(),
            $events,
            ['installed' => 1],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        // DEGRADE_ONLY: see the class doc for why we never roll an install
        // backwards. This compensate() should not be invoked by the
        // orchestrator (it short-circuits on DEGRADE_ONLY); we keep a
        // proper implementation only so direct callers get a sane response
        // rather than the base-class LogicException.
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: install_app NOT rolled back (DEGRADE_ONLY); use app.uninstall manually if needed',
                ['app_slug' => $state->data['app_slug'] ?? null]
            )],
        );
    }

    /**
     * @return array{app_slug:string,admin_user:?string,admin_email:?string,admin_password:?string,site_title:?string}|null
     */
    private function resolveAppConfig(SiteContext $ctx): ?array
    {
        $raw = $ctx->payload['install_app'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        $slug = trim((string) ($raw['app_slug'] ?? ''));
        if ($slug === '') {
            return null;
        }
        return [
            'app_slug' => $slug,
            'admin_user' => isset($raw['admin_user']) ? (string) $raw['admin_user'] : null,
            'admin_email' => isset($raw['admin_email']) ? (string) $raw['admin_email'] : null,
            'admin_password' => isset($raw['admin_password']) ? (string) $raw['admin_password'] : null,
            'site_title' => isset($raw['site_title']) ? (string) $raw['site_title'] : null,
        ];
    }

    private function upsertAppRecord(
        SiteContext $ctx,
        string $domain,
        string $appSlug,
        string $version,
        string $docRoot,
        string $adminUrl,
        string $adminUser,
        string $dbName,
    ): void {
        $pdo = $ctx->database->pdo();
        // Idempotent insert: if a row already exists for (domain, app_slug)
        // we update the version + paths in place instead of failing the
        // step. This makes reruns and the reconciler's "retry SSL/install"
        // path safe.
        $stmt = $pdo->prepare(
            "INSERT INTO site_applications
                (domain, app_slug, app_version, install_path, admin_url, admin_user, database_name,
                 installed_by, status, installed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?,
                     (SELECT id FROM admin_users WHERE username = ? LIMIT 1), 'active', NOW())
             ON DUPLICATE KEY UPDATE
                app_version = VALUES(app_version),
                install_path = VALUES(install_path),
                admin_url = VALUES(admin_url),
                admin_user = VALUES(admin_user),
                database_name = VALUES(database_name),
                status = 'active'"
        );
        $stmt->execute([
            $domain,
            $appSlug,
            $version,
            $docRoot,
            $adminUrl,
            $adminUser,
            $dbName,
            $ctx->actor->username,
        ]);
    }

    private function generatePassword(int $length = 20): string
    {
        // Shell-safe chars only: WordPressInstaller passes this through
        // escapeshellarg but we still avoid `$ ! # &` etc. for the
        // double-defence belt and braces.
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-+=@';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }
}

/**
 * Stub logger so WordPressInstaller's `$logger->warning(...)` call does
 * not blow up when invoked from the saga. The real warnings already
 * surface through StepEvent in the caller, so we deliberately drop the
 * installer's own log line on the floor here.
 */
final class InstallAppStepNullLogger
{
    public function warning(string $msg): void
    {
    }

    public function info(string $msg): void
    {
    }

    public function error(string $msg): void
    {
    }
}
