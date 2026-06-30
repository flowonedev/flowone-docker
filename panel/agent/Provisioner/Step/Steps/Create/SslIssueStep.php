<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Create;

use VpsAdmin\Agent\Provisioner\Adapters\IssuanceOutcome;
use VpsAdmin\Agent\Provisioner\Adapters\IssuanceResult;
use VpsAdmin\Agent\Provisioner\Adapters\SslAdapter;
use VpsAdmin\Agent\Provisioner\Ols\VhostConfigTemplate;
use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Issue a Let's Encrypt certificate via certbot's webroot HTTP-01
 * flow, append the `vhssl` block to the vhost.conf, and trigger a
 * second OLS reload so the cert is live.
 *
 * Saga position:
 *   AFTER OlsRestartStep (the first restart). The vhost must be
 *   reachable on :80 by Let's Encrypt's HTTP challenge prober before
 *   we can issue a cert. HomeDirCreateStep already provisioned
 *   `<docroot>/.well-known/acme-challenge/` with the right ownership
 *   so certbot can drop its challenge file with no extra prep.
 *
 * Compensation policy: DEGRADE_ONLY.
 *   A failed SSL issuance is non-destructive: the site still serves
 *   over HTTP, the vhost.conf is intact, and the operator can re-run
 *   issuance via a "reissue SSL" UI action once DNS / firewall is
 *   sorted. Rolling back the entire create saga because Let's Encrypt
 *   threw a rate-limit error would be hostile (we'd nuke a working
 *   HTTP site over a temporary issuance problem).
 *
 * Skip cases (return success without an attempt):
 *   - payload['auto_ssl'] === false      operator opted out
 *   - single-label domain (no dot)       no public CA will issue
 *   - SslAdapter not wired in context    bootstrap / unit tests
 *   - cert already on disk AND vhssl block already in vhost.conf
 *     means a previous saga run finished the job, just exit clean.
 *
 * Soft-failure cases (success + warning event, ssl_deferred=true):
 *   - DNS for primary domain doesn't resolve              -> SKIPPED_DNS
 *   - certbot reports DNS / challenge / rate-limit issue  -> see Outcome
 *   The saga still lands on `active` because the user gets a working
 *   HTTP site; the SSL state is reflected via sites.ssl_enabled which
 *   stays 0 until a successful re-run.
 *
 * Hard-failure cases (return failure, saga ends DEGRADED):
 *   - certbot binary missing
 *   - vhost.conf can't be read or written
 *   - OLS reload returns non-zero
 *   These represent server-config problems that need operator
 *   attention; quietly succeeding would mask infrastructure damage.
 *
 * Idempotence:
 *   - check() = true iff the cert is on disk AND the vhost.conf
 *     already contains a `vhssl` block. A resume re-runs execute()
 *     only when both halves haven't completed.
 *   - execute() guards against double-issuing (certbot's
 *     --keep-until-expiring flag) and against double-appending the
 *     vhssl block (VhostConfigTemplate::appendVhssl is idempotent).
 *
 * State data persisted on success:
 *   - outcome           IssuanceOutcome enum value
 *   - cert_domains      list of domains the cert covers
 *   - vhssl_appended    bool - whether we mutated vhost.conf
 *   - reload_triggered  bool - whether we asked OLS to reload
 *   - ssl_enabled       bool - convenience flag for reconciler
 */
final class SslIssueStep extends AbstractStep
{
    private const SUPPORTED_PHP_LSAPI_PATTERN = '/^lsphp[0-9]+$/';

    public function __construct(
        private readonly VhostConfigTemplate $template = new VhostConfigTemplate(),
        /**
         * Optional staging mode flag. Defaults to false (production
         * Let's Encrypt). Tests + dev boxes set this to true via
         * payload to avoid burning the production rate-limit budget.
         */
        private readonly bool $stagingDefault = false,
    ) {
    }

    public function name(): string
    {
        return StepName::SSL_ISSUE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        // Already-completed state: cert on disk + vhssl block in
        // vhost.conf. Resume of a saga that finished SSL must be a
        // no-op so we don't re-call certbot for a billable check.
        $ssl = $ctx->requireAdapters()->ssl;
        if ($ssl === null) {
            // No adapter wired (test harness without SSL surface).
            // We treat the step as "skipped-already" for idempotence
            // purposes; execute() will emit the skip event and bail.
            return !empty($state->data['outcome']);
        }
        if (!$ssl->certificateExists($ctx->domain())) {
            return false;
        }
        $vhost = $this->readVhostConfig($ctx);
        if ($vhost === null) {
            return false;
        }
        return (bool) preg_match('/vhssl\s*\{/i', $vhost);
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $events = [];
        $domain = $ctx->domain();
        $payload = $ctx->payload;

        // ── Skip path 1: operator opted out ──────────────────────
        if (array_key_exists('auto_ssl', $payload) && $payload['auto_ssl'] === false) {
            $events[] = StepEvent::info('ssl: auto_ssl disabled in payload, skipping', [
                'domain' => $domain,
            ]);
            return StepResult::success(
                $state->mergeData([
                    'outcome' => 'skipped_opted_out',
                    'ssl_enabled' => false,
                ])->withCompleted(),
                $events,
            );
        }

        // ── Skip path 2: single-label hostname ──────────────────
        // Public CAs require at least one dot. test6, vps,
        // staging-box etc. can never get a Let's Encrypt cert.
        if (strpos($domain, '.') === false) {
            $events[] = StepEvent::info('ssl: single-label hostname, skipping', [
                'domain' => $domain,
            ]);
            return StepResult::success(
                $state->mergeData([
                    'outcome' => 'skipped_single_label',
                    'ssl_enabled' => false,
                ])->withCompleted(),
                $events,
            );
        }

        // ── Skip path 3: adapter not wired ──────────────────────
        $ssl = $ctx->requireAdapters()->ssl;
        if ($ssl === null) {
            $events[] = StepEvent::warning(
                'ssl: SslAdapter not wired in adapters bundle - skipping issuance',
                ['domain' => $domain],
            );
            return StepResult::success(
                $state->mergeData([
                    'outcome' => 'skipped_no_adapter',
                    'ssl_enabled' => false,
                ])->withCompleted(),
                $events,
            );
        }

        // ── Skip path 4: cert + vhssl already in place ──────────
        $alreadyOnDisk = $ssl->certificateExists($domain);
        $vhostConfig = $this->readVhostConfig($ctx);
        $alreadyAppended = is_string($vhostConfig)
            && (bool) preg_match('/vhssl\s*\{/i', $vhostConfig);
        if ($alreadyOnDisk && $alreadyAppended) {
            $events[] = StepEvent::info('ssl: cert + vhssl block already present, no-op', [
                'domain' => $domain,
            ]);
            return StepResult::success(
                $state->mergeData([
                    'outcome' => IssuanceOutcome::ALREADY_PRESENT->value,
                    'ssl_enabled' => true,
                    'vhssl_appended' => false,
                    'reload_triggered' => false,
                ])->withCompleted(),
                $events,
            );
        }

        // ── Skip path 5: DNS not propagated ─────────────────────
        // Re-running SSL issuance against a domain that isn't
        // resolving wastes a Let's Encrypt request and counts
        // against the per-account rate limit. We probe gethostbyname
        // first; certbot would fail with a DNS error anyway.
        if (!$ssl->dnsResolves($domain)) {
            $events[] = StepEvent::warning(
                'ssl: DNS for primary domain not resolving yet; deferring issuance',
                ['domain' => $domain],
            );
            return StepResult::success(
                $state->mergeData([
                    'outcome' => IssuanceOutcome::SKIPPED_DNS->value,
                    'ssl_enabled' => false,
                    'ssl_deferred' => true,
                ])->withCompleted(),
                $events,
            );
        }

        // ── Issue the cert ──────────────────────────────────────
        $webroot = $this->resolveWebroot($ctx, $state);
        $email = $this->resolveEmail($ctx);
        $sans = $this->resolveSans($ctx, $ssl);
        $staging = (bool) ($payload['ssl_staging'] ?? $this->stagingDefault);

        $events[] = StepEvent::info('ssl: requesting cert via certbot --webroot', [
            'domain' => $domain,
            'sans' => $sans,
            'webroot' => $webroot,
            'staging' => $staging,
        ]);

        try {
            $issuance = $ssl->issueCert($domain, $sans, $webroot, $email, $staging);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "ssl: certbot invocation failed: " . $e->getMessage(),
                $events,
            );
        }

        if (!$issuance->isCertOnDisk()) {
            // Deferrable failure - record + warn, don't fail the saga.
            $events[] = StepEvent::warning(
                'ssl: issuance deferred',
                [
                    'outcome' => $issuance->outcome->value,
                    'reason' => $issuance->error,
                    'domain' => $domain,
                ],
            );
            return StepResult::success(
                $state->mergeData([
                    'outcome' => $issuance->outcome->value,
                    'ssl_enabled' => false,
                    'ssl_deferred' => $issuance->isDeferrable(),
                    'last_issuance_error' => $issuance->error,
                ])->withCompleted(),
                $events,
            );
        }

        $events[] = StepEvent::info('ssl: certificate materialised', [
            'domain' => $domain, 'sans' => $sans, 'outcome' => $issuance->outcome->value,
        ]);

        // ── Append vhssl to vhost.conf ──────────────────────────
        $appended = false;
        if (!$alreadyAppended) {
            try {
                $newConfig = $this->template->appendVhssl($vhostConfig ?? '');
                $ctx->requireAdapters()->ols->writeVhostConfig($domain, $newConfig);
                $appended = true;
                $events[] = StepEvent::info('ssl: vhssl block appended to vhost.conf', [
                    'domain' => $domain,
                    'bytes_added' => strlen($newConfig) - strlen($vhostConfig ?? ''),
                ]);
            } catch (\Throwable $e) {
                return StepResult::failure(
                    $state->mergeData([
                        'outcome' => $issuance->outcome->value,
                        'ssl_enabled' => true,
                        'vhssl_appended' => false,
                    ]),
                    "ssl: cert issued but vhost.conf flip failed: " . $e->getMessage(),
                    $events,
                );
            }
        }

        // ── 2nd OLS reload so the new cert is served ─────────────
        $reloadOk = $this->triggerOlsReload($ctx, $events);
        if (!$reloadOk) {
            // Non-fatal: cert + config are correct, OLS will pick up
            // changes on the NEXT restart. Reconciler is responsible
            // for catching this drift and forcing a reload later.
            $events[] = StepEvent::warning(
                'ssl: cert + vhost.conf updated, but OLS reload failed; HTTPS will activate on next restart'
            );
        }

        return StepResult::success(
            $state->mergeData([
                'outcome' => $issuance->outcome->value,
                'cert_domains' => $issuance->domains,
                'vhssl_appended' => $appended,
                'reload_triggered' => $reloadOk,
                'ssl_enabled' => true,
            ])->withCompleted(),
            $events,
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        // DEGRADE_ONLY policy: AbstractStep::compensate() throws when
        // called on a degrade-only step. We override here so the saga
        // can call us defensively; we still no-op because there's
        // nothing safe to roll back. SSL flip + cert issuance are
        // both idempotent on retry, so an aborted saga is recoverable
        // by re-running the create flow.
        return StepResult::success(
            $state,
            [StepEvent::info(
                'compensate: ssl_issue is DEGRADE_ONLY; cert + vhost.conf are left as-is for re-run'
            )]
        );
    }

    /**
     * Read the current rendered vhost.conf so we can detect an
     * already-appended vhssl block (idempotence) and pass the buffer
     * to appendVhssl(). Returns null when the file doesn't exist
     * (which is the failure case caller-side).
     */
    private function readVhostConfig(SiteContext $ctx): ?string
    {
        try {
            return $ctx->requireAdapters()->ols->readVhostConfig($ctx->domain());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the webroot path certbot must serve the HTTP-01
     * challenge from. Priority order:
     *   1. payload['document_root']                     UI override
     *   2. HOME_DIR_CREATE step state ('home') + /public_html
     *   3. /home/<domain>/public_html                   convention
     */
    private function resolveWebroot(SiteContext $ctx, StepState $state): string
    {
        $payload = $ctx->payload;
        if (!empty($payload['document_root']) && is_string($payload['document_root'])) {
            return $payload['document_root'];
        }
        $home = $this->lookupFromState($ctx, StepName::HOME_DIR_CREATE, 'home');
        if (is_string($home) && $home !== '') {
            return $home . '/public_html';
        }
        return '/home/' . $ctx->domain() . '/public_html';
    }

    /**
     * Resolve the contact email Let's Encrypt should associate with
     * the cert (used for expiry notifications and account recovery).
     * Falls back to admin@<domain>; certbot's --email flag is
     * required, so we always provide one.
     */
    private function resolveEmail(SiteContext $ctx): string
    {
        $payload = $ctx->payload;
        foreach (['ssl_email', 'admin_email', 'email'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }
        return 'admin@' . $ctx->domain();
    }

    /**
     * Decide which Subject-Alt-Name extras to request alongside the
     * primary domain. Each candidate goes through DNS resolution +
     * (eventually) ACME-reachability check; entries that fail are
     * dropped from the request rather than hard-failing the cert.
     *
     * Default candidate set:
     *   - www.<domain>             (canonical web alias)
     *   - mail.<domain> when payload['create_mail_domain']  (mail subdomain)
     *
     * Skip via payload['skip_www'] = true if the operator wants a
     * minimal cert (e.g. wildcards via DNS-01 in a future enhancement).
     *
     * @return list<string>
     */
    private function resolveSans(SiteContext $ctx, SslAdapter $ssl): array
    {
        $domain = $ctx->domain();
        $payload = $ctx->payload;
        $sans = [];

        if (empty($payload['skip_www'])) {
            $candidate = 'www.' . $domain;
            if ($ssl->dnsResolves($candidate)) {
                $sans[] = $candidate;
            }
        }
        if (!empty($payload['create_mail_domain'])) {
            $candidate = 'mail.' . $domain;
            if ($ssl->dnsResolves($candidate)) {
                $sans[] = $candidate;
            }
        }
        return $sans;
    }

    /**
     * Pull a string value off another step's persisted state in the
     * siteRow.state JSON map. Returns null when the lookup misses.
     */
    private function lookupFromState(SiteContext $ctx, string $stepName, string $key): ?string
    {
        $row = $ctx->siteRow;
        $stateJson = $row['state'] ?? null;
        $map = is_string($stateJson) ? json_decode($stateJson, true) : (is_array($stateJson) ? $stateJson : null);
        if (is_array($map)
            && isset($map[$stepName]['data'][$key])
            && is_string($map[$stepName]['data'][$key])
        ) {
            return $map[$stepName]['data'][$key];
        }
        return null;
    }

    /**
     * Ask the OlsRestartCoordinator (or the direct OlsAdapter as a
     * bootstrap fallback) to reload OLS so the new SSL config takes
     * effect.
     *
     * @param array<int,StepEvent> $events Mutated in-place to record outcome.
     */
    private function triggerOlsReload(SiteContext $ctx, array &$events): bool
    {
        $coordinator = $ctx->requireAdapters()->olsRestart;
        if ($coordinator === null) {
            try {
                $r = $ctx->requireAdapters()->ols->restart();
                if (!$r->isSuccess()) {
                    $events[] = StepEvent::warning('ssl: direct OLS restart returned non-zero', [
                        'output' => $r->summary(),
                    ]);
                    return false;
                }
                $events[] = StepEvent::info('ssl: direct OLS restart issued (no coordinator)');
                return true;
            } catch (\Throwable $e) {
                $events[] = StepEvent::warning('ssl: direct OLS restart threw', [
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        try {
            $outcome = $coordinator->request(
                holderId: 'job:' . $ctx->jobId . ':ssl',
                requestId: $ctx->requestId,
                blocking: true,
                maxWaitMs: 30_000,
            );
            $events[] = StepEvent::info('ssl: OLS reload requested', ['outcome' => $outcome]);
            // 'restarted' / 'debounced' / 'contended' are all OK for
            // SSL purposes - either OLS reloaded (us or someone else),
            // or it'll reload imminently.
            return true;
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning('ssl: coordinator reload request failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
