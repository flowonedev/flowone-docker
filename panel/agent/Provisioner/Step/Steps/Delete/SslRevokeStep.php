<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Delete;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Revoke + delete the Let's Encrypt cert when a site is being torn
 * down.
 *
 * Saga position:
 *   In the DELETE direction this runs EARLY (right after
 *   PRE_DELETE_SNAPSHOT). Reasoning: revoking the cert is a network
 *   call out to the ACME endpoint; if it fails (e.g. ACME down,
 *   network blip), we want to know BEFORE the destructive teardown
 *   has begun so we can abort cleanly. The teardown can always be
 *   retried; a half-revoked cert paired with deleted vhost files is
 *   still recoverable from the snapshot, but it's an awkward state.
 *
 *   Deliberately NOT in the ARCHIVE sequence: an archived site keeps
 *   its cert in /etc/letsencrypt/live/<domain>/ so a RESTORE can
 *   re-wire the existing fullchain.pem rather than burn another
 *   issuance against the per-account rate limit.
 *
 * Compensation policy: DEGRADE_ONLY.
 *   Once a cert is revoked there is no un-revoke. We accept that
 *   failures during a DELETE leave the cert in whatever state
 *   certbot reported; there's no way to put the toothpaste back.
 *
 * Idempotency:
 *   - check() returns true iff there is no cert on disk for this
 *     domain. A re-run after a successful revoke is therefore a
 *     no-op.
 *   - execute() skips when no cert exists. When a cert exists, it
 *     calls SslAdapter::revokeCert() which itself is idempotent
 *     (revoke + delete + cleanRenewalConfig).
 *
 * Skip cases (return success without an attempt):
 *   - SslAdapter not wired (test harness)
 *   - cert never existed for this domain
 *   - single-label hostname (no public CA cert was ever possible)
 *
 * State data persisted on success:
 *   - revoked         bool  - whether we actually called revoke
 *   - cert_was_present bool - what the FS looked like before we ran
 */
final class SslRevokeStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::SSL_REVOKE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $ssl = $ctx->requireAdapters()->ssl;
        if ($ssl === null) {
            // No adapter wired: treat as already-completed for
            // resume purposes so the saga doesn't get stuck.
            return !empty($state->data['outcome']);
        }
        // The cert is gone (or was never there). Nothing to do.
        return !$ssl->certificateExists($ctx->domain());
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $domain = $ctx->domain();
        $events = [];

        // Skip 1: single-label - no public cert ever existed.
        if (strpos($domain, '.') === false) {
            $events[] = StepEvent::info('ssl_revoke: single-label hostname, no cert to revoke', [
                'domain' => $domain,
            ]);
            return StepResult::success(
                $state->mergeData([
                    'outcome' => 'skipped_single_label',
                    'cert_was_present' => false,
                ])->withCompleted(),
                $events,
            );
        }

        // Skip 2: adapter not wired (test contexts).
        $ssl = $ctx->requireAdapters()->ssl;
        if ($ssl === null) {
            $events[] = StepEvent::warning(
                'ssl_revoke: SslAdapter not wired - leaving cert (if any) untouched',
                ['domain' => $domain],
            );
            return StepResult::success(
                $state->mergeData([
                    'outcome' => 'skipped_no_adapter',
                    'cert_was_present' => false,
                ])->withCompleted(),
                $events,
            );
        }

        // Skip 3: nothing to revoke.
        if (!$ssl->certificateExists($domain)) {
            $events[] = StepEvent::info('ssl_revoke: no cert on disk; nothing to revoke', [
                'domain' => $domain,
            ]);
            return StepResult::success(
                $state->mergeData([
                    'outcome' => 'no_cert',
                    'cert_was_present' => false,
                    'revoked' => false,
                ])->withCompleted(),
                $events,
            );
        }

        // Real revoke + delete.
        $events[] = StepEvent::info('ssl_revoke: revoking + deleting cert', [
            'domain' => $domain,
        ]);

        try {
            $ok = $ssl->revokeCert($domain);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "ssl_revoke: certbot invocation threw: " . $e->getMessage(),
                $events,
            );
        }

        if (!$ok) {
            // Soft-failure: revoke or delete returned non-zero. The
            // saga step records this as a warning rather than failing
            // outright - the destructive teardown should still
            // proceed because the operator has explicitly asked for
            // the site to go away. Renewal config is gone either
            // way (cleanRenewalConfig runs on every revoke call).
            $events[] = StepEvent::warning(
                'ssl_revoke: certbot reported a problem; delete will proceed regardless',
                ['domain' => $domain],
            );
            return StepResult::success(
                $state->mergeData([
                    'outcome' => 'failed_certbot',
                    'cert_was_present' => true,
                    'revoked' => false,
                ])->withCompleted(),
                $events,
            );
        }

        $events[] = StepEvent::info('ssl_revoke: cert revoked + deleted', [
            'domain' => $domain,
        ]);

        return StepResult::success(
            $state->mergeData([
                'outcome' => 'revoked',
                'cert_was_present' => true,
                'revoked' => true,
            ])->withCompleted(),
            $events,
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        // Cannot un-revoke. DEGRADE_ONLY policy means this is never
        // called by the orchestrator anyway (AbstractStep::compensate
        // throws on DEGRADE_ONLY by default; we override here so
        // defensive callers that invoke compensate without checking
        // policy don't get a LogicException).
        return StepResult::success(
            $state,
            [StepEvent::info(
                'compensate: ssl_revoke is irreversible; renewal cron has been cleaned'
            )]
        );
    }
}
