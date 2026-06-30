<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Suspend;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Replace the live vhost.conf with a maintenance-mode config that
 * returns HTTP 503 for every request, and stash the original next
 * to it as `vhost.conf.suspended-backup` so RESUME can restore the
 * site verbatim.
 *
 * Why a config swap and not a listener-level removal:
 *   - We want the DNS record to keep resolving (no surprises for
 *     downstream monitors / clients) but every request to bounce
 *     back a stable maintenance message. Pulling the listener map
 *     entry would yield "connection refused" which is harder to
 *     differentiate from outages.
 *   - The backup file lives in the same directory as vhost.conf,
 *     not in /tmp, so a restore cannot fail because /tmp was
 *     cleaned between SUSPEND and RESUME.
 *
 * Idempotence:
 *   - check() returns true iff the backup file already exists AND
 *     the live config contains the SUSPENDED_MARKER. Re-running on
 *     a suspended site is a no-op.
 *
 * Compensation: SAFE_ROLLBACK.
 *   compensate() restores the backup file to vhost.conf (if the
 *   backup exists) so the saga's failure leaves the site as-it-was
 *   rather than half-suspended. If the backup is missing we cannot
 *   safely restore and warn rather than crashing the saga.
 *
 * Payload knobs:
 *   - suspend_message=<string>   - text shown in the 503 body.
 *                                  Default: "Site temporarily unavailable."
 */
final class VhostSuspendStep extends AbstractStep
{
    /**
     * Magic string baked into the suspended vhost.conf so a future
     * SUSPEND run can detect "already suspended" without parsing
     * the OLS config tree.
     */
    private const SUSPENDED_MARKER = '# flowone:suspended=true';

    private const BACKUP_SUFFIX = '.suspended-backup';

    private const DEFAULT_MESSAGE = 'Site temporarily unavailable.';

    public function name(): string
    {
        return StepName::VHOST_SUSPEND;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $ols = $ctx->requireAdapters()->ols;
        $fs = $ctx->requireAdapters()->fs;
        $domain = $ctx->domain();

        $livePath = $ols->vhostConfigPath($domain);
        $backupPath = $livePath . self::BACKUP_SUFFIX;

        // Defensive: writeAtomic() does rename() under the hood and
        // PHP's stat cache for the destination path can be stale across
        // back-to-back orchestrator runs on the same SiteContext (the
        // saga's first run does check()->execute()->writeAtomic; the
        // second run's check() then sees the cached "absent" stat).
        // Clearing the cache for these specific paths costs us nothing
        // and makes idempotence robust.
        clearstatcache(true, $livePath);
        clearstatcache(true, $backupPath);

        if (!$fs->isFile($backupPath)) {
            return false;
        }
        if (!$fs->isFile($livePath)) {
            return false;
        }
        $live = $fs->readFile($livePath);
        return is_string($live) && str_contains($live, self::SUSPENDED_MARKER);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $fs = $ctx->requireAdapters()->fs;
        $domain = $ctx->domain();
        $livePath = $ols->vhostConfigPath($domain);
        $backupPath = $livePath . self::BACKUP_SUFFIX;

        $events = [StepEvent::info('suspending vhost', [
            'domain' => $domain,
            'live' => $livePath,
            'backup' => $backupPath,
        ])];

        if (!$fs->isFile($livePath)) {
            return StepResult::failure(
                $state->mergeData(['domain' => $domain, 'live_path' => $livePath]),
                "cannot suspend: vhost.conf missing at {$livePath}",
                $events,
            );
        }

        $original = $fs->readFile($livePath);
        if (!is_string($original)) {
            return StepResult::failure(
                $state->mergeData(['domain' => $domain, 'live_path' => $livePath]),
                "cannot read vhost.conf at {$livePath}",
                $events,
            );
        }

        // Two writes need to land atomically: backup + suspended live.
        // Order matters: write the backup FIRST so a crash between the
        // two writes leaves us with backup + original (recoverable),
        // never with no backup + suspended-live (unrecoverable).
        try {
            if (!$fs->isFile($backupPath)) {
                $fs->writeAtomic($backupPath, $original, 0640);
                $events[] = StepEvent::info('original vhost.conf backed up', [
                    'backup' => $backupPath,
                    'bytes' => strlen($original),
                ]);
            } else {
                $events[] = StepEvent::info(
                    'backup already exists; keeping the older one (closer to true pre-suspend state)',
                    ['backup' => $backupPath],
                );
            }
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData(['domain' => $domain]),
                "could not write backup file {$backupPath}: " . $e->getMessage(),
                $events,
            );
        }

        $message = $this->resolveMessage($ctx);
        $suspended = $this->renderSuspendedConfig($domain, $message);

        try {
            $ols->writeVhostConfig($domain, $suspended);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state->mergeData([
                    'domain' => $domain,
                    'backup_written' => true,
                    'backup_path' => $backupPath,
                ]),
                "could not write suspended vhost.conf at {$livePath}: " . $e->getMessage(),
                $events,
            );
        }
        $events[] = StepEvent::info('suspended vhost.conf written', [
            'live' => $livePath,
            'bytes' => strlen($suspended),
        ]);

        return StepResult::success(
            $state->mergeData([
                'domain' => $domain,
                'live_path' => $livePath,
                'backup_path' => $backupPath,
                'suspended_marker' => self::SUSPENDED_MARKER,
                'suspended_at' => time(),
            ])->withCompleted(),
            $events,
            [
                'live_bytes' => strlen($suspended),
                'backup_bytes' => strlen($original),
            ],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        $ols = $ctx->requireAdapters()->ols;
        $fs = $ctx->requireAdapters()->fs;
        $domain = $ctx->domain();
        $livePath = $ols->vhostConfigPath($domain);
        $backupPath = $livePath . self::BACKUP_SUFFIX;

        if (!$fs->isFile($backupPath)) {
            return StepResult::success(
                $state,
                [StepEvent::warning(
                    'compensate: no backup found; cannot restore vhost.conf automatically',
                    ['backup' => $backupPath]
                )]
            );
        }
        $original = $fs->readFile($backupPath);
        if (!is_string($original)) {
            return StepResult::success(
                $state,
                [StepEvent::warning(
                    'compensate: backup unreadable; leaving suspended config in place',
                    ['backup' => $backupPath]
                )]
            );
        }
        try {
            $ols->writeVhostConfig($domain, $original);
            $fs->deleteFile($backupPath);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "compensate: restore failed: " . $e->getMessage(),
                [StepEvent::warning('compensate restore failed')],
            );
        }
        return StepResult::success(
            $state,
            [StepEvent::info('compensate: vhost.conf restored from backup', [
                'live' => $livePath, 'backup' => $backupPath,
            ])]
        );
    }

    private function resolveMessage(SiteContext $ctx): string
    {
        $msg = $ctx->payload['suspend_message'] ?? null;
        if (is_string($msg) && trim($msg) !== '') {
            // Keep it ASCII-printable; OLS configs are not HTML-aware
            // and we don't want stray quotes breaking the parser.
            $sanitized = preg_replace('/[^\x20-\x7e]/', ' ', $msg) ?? self::DEFAULT_MESSAGE;
            return substr($sanitized, 0, 200);
        }
        return self::DEFAULT_MESSAGE;
    }

    /**
     * Render an OLS vhost.conf that bounces everything to 503.
     *
     * The config defines a single "context /" that returns 503 with
     * an inline body. No docRoot is referenced so even a broken
     * filesystem layout under /home/<domain>/ does not affect the
     * suspended response.
     */
    private function renderSuspendedConfig(string $domain, string $message): string
    {
        $marker = self::SUSPENDED_MARKER;
        return <<<CONF
{$marker}
# Suspended at: {$this->isoNow()}
# Domain: {$domain}
# Re-run RESUME saga to restore. The original config lives in
# vhost.conf{$this->backupSuffix()}.

errorlog \$VH_ROOT/logs/error.log {
  useServer               0
  logLevel                NOTICE
}

accesslog \$VH_ROOT/logs/access.log {
  useServer               0
  rollingSize             10M
  keepDays                7
}

errorpage 503 {
  url                     /__suspended.html
}

context / {
  type                    redirect
  uri                     /__suspended.html
  externalRedirect        0
  statusCode              503
}

context /__suspended.html {
  type                    appserver
  location                inline
  binPath                 inline
  appType                 static
  body                    {$this->renderBody($message)}
}
CONF;
    }

    private function renderBody(string $message): string
    {
        $escaped = str_replace(['"', "\n"], ['\\"', ' '], $message);
        return '"' . $escaped . '"';
    }

    private function isoNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function backupSuffix(): string
    {
        return self::BACKUP_SUFFIX;
    }
}
