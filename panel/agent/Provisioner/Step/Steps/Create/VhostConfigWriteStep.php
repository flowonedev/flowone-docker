<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Create;

use VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate;
use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;
use VpsAdmin\Agent\Provisioner\Support\ResourceNameDeriver;

/**
 * Write the per-site vhost.conf to
 *   <vhostsRoot>/<domain>/vhost.conf
 *
 * vhostsRoot defaults to /usr/local/lsws/conf/vhosts; in tests the
 * SiteContext.config['ols_config_root'] overrides this so the test
 * never touches the live OLS install.
 *
 * Idempotence:
 *   - check() returns true iff the file exists AND its sha256 matches
 *     the hash we last persisted. This handles two cases:
 *       a) brand-new step (no hash) -> false -> execute renders
 *       b) someone hand-edited vhost.conf -> hash mismatch -> false
 *          -> execute overwrites and re-records hash.
 *   - execute() always re-renders from the template. Atomic write
 *     ensures readers see a consistent file.
 *
 * Compensation: SAFE_ROLLBACK. We delete the file we wrote. The
 * parent directory (vhosts/<domain>/) is left alone because OLS will
 * recreate it on the next vhost create with the same name.
 */
final class VhostConfigWriteStep extends AbstractStep
{
    // Fallback owner used when payload['skip_sftp']=true. Mirrors
    // HomeDirCreateStep::FALLBACK_OWNER so the vhost.conf user/group
    // directives line up with the actual docroot ownership.
    private const FALLBACK_OWNER = 'www-data';
    private const FALLBACK_GROUP = 'www-data';

    public function __construct(
        private readonly VhostConfigTemplate $template,
    ) {
    }

    public function name(): string
    {
        return StepName::VHOST_CONFIG_WRITE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $ols = $ctx->requireAdapters()->ols;
        $path = $ols->vhostConfigPath($ctx->domain());
        $fs = $ctx->requireAdapters()->fs;
        if (!$fs->exists($path)) {
            return false;
        }
        $expectedHash = $state->data['content_sha256'] ?? null;
        if (!is_string($expectedHash) || $expectedHash === '') {
            return false;
        }
        $actual = @hash_file('sha256', $path);
        return is_string($actual) && hash_equals($expectedHash, $actual);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $path = $ols->vhostConfigPath($ctx->domain());
        $vars = $this->collectTemplateVars($ctx);

        $events = [StepEvent::info('rendering vhost.conf', [
            'path' => $path, 'php_lsapi' => $vars['php_lsapi'] ?? VhostConfigTemplate::DEFAULT_PHP_LSAPI,
        ])];

        try {
            $content = $this->template->render($vars);
        } catch (\Throwable $e) {
            return StepResult::failure($state, "template render failed: " . $e->getMessage(), $events);
        }

        try {
            $ols->writeVhostConfig($ctx->domain(), $content);
        } catch (\Throwable $e) {
            return StepResult::failure($state, "writeVhostConfig failed: " . $e->getMessage(), $events);
        }

        $hash = hash('sha256', $content);
        $events[] = StepEvent::info('vhost.conf written', ['path' => $path, 'sha256' => $hash, 'bytes' => strlen($content)]);

        return StepResult::success(
            $state->mergeData([
                'path' => $path,
                'content_sha256' => $hash,
                'bytes' => strlen($content),
            ])->withCompleted(),
            $events,
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $domain = $ctx->domain();
        $events = [StepEvent::info('compensate: removing vhost.conf', ['domain' => $domain])];
        try {
            $ols->removeVhostConfig($domain);
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning(
                'compensate: removeVhostConfig failed',
                ['domain' => $domain, 'error' => $e->getMessage()]
            );
        }
        return StepResult::success($state, $events);
    }

    /**
     * @return array<string,mixed>
     */
    private function collectTemplateVars(SiteContext $ctx): array
    {
        $skipSftp = !empty($ctx->payload['skip_sftp']);

        // Resolve site_user. Resolution order matches every other
        // create step:
        //   1. previously-persisted state (when siteRow.state is
        //      eventually hydrated by the orchestrator)
        //   2. explicit payload override
        //   3. denormalised siteRow column
        //   4. shared derivation OR skip_sftp fallback
        $siteUser = $this->lookupFromState($ctx, StepName::SFTP_USER_CREATE, 'user')
            ?? ($ctx->payload['sftp_user'] ?? $ctx->siteRow['sftp_user'] ?? null);
        if (!is_string($siteUser) || $siteUser === '') {
            $siteUser = $skipSftp
                ? self::FALLBACK_OWNER
                : ResourceNameDeriver::sftpName($ctx->domain());
        }

        $siteGroup = $this->lookupFromState($ctx, StepName::SFTP_GROUP_CREATE, 'group')
            ?? ($ctx->payload['sftp_group'] ?? $ctx->siteRow['sftp_group'] ?? null);
        if (!is_string($siteGroup) || $siteGroup === '') {
            $siteGroup = $skipSftp ? self::FALLBACK_GROUP : $siteUser;
        }

        return [
            'site_user' => $siteUser,
            'site_group' => $siteGroup,
            'php_lsapi' => $ctx->payload['php_lsapi']
                ?? $ctx->siteRow['php_lsapi']
                ?? VhostConfigTemplate::DEFAULT_PHP_LSAPI,
            'admin_email' => $ctx->payload['admin_email']
                ?? $ctx->siteRow['admin_email']
                ?? 'admin@' . $ctx->domain(),
            'memory_soft_limit' => $ctx->payload['memory_soft_limit'] ?? '1024M',
            'memory_hard_limit' => $ctx->payload['memory_hard_limit'] ?? '1024M',
        ];
    }

    private function lookupFromState(SiteContext $ctx, string $stepName, string $key): ?string
    {
        $stateJson = $ctx->siteRow['state'] ?? null;
        $map = is_string($stateJson) ? json_decode($stateJson, true) : (is_array($stateJson) ? $stateJson : null);
        if (is_array($map)
            && isset($map[$stepName]['data'][$key])
            && is_string($map[$stepName]['data'][$key])
        ) {
            return $map[$stepName]['data'][$key];
        }
        return null;
    }
}
