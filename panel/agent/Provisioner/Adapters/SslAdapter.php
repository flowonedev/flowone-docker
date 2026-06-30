<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * Wraps `certbot` for HTTP-01 webroot issuance, revocation, and
 * existence checks. Sole purpose: keep certbot's CLI surface OUT of
 * SslIssueStep/SslRevokeStep so the steps stay focused on saga
 * semantics and the adapter can be swapped for a fake in tests.
 *
 * Why webroot-mode and not standalone:
 *   - OLS is already bound to :80 / :443, so standalone would need
 *     the saga to stop OLS, run certbot, restart OLS - way too
 *     destructive for a per-site issuance.
 *   - HomeDirCreateStep guarantees `<docroot>/.well-known/acme-challenge`
 *     exists with the right ownership before the SSL step runs, so
 *     certbot can drop its challenge file with no extra prep.
 *
 * Cert storage layout (managed by certbot, queried by us):
 *   /etc/letsencrypt/live/<domain>/fullchain.pem
 *   /etc/letsencrypt/live/<domain>/privkey.pem
 *   /etc/letsencrypt/live/<domain>/cert.pem
 *   /etc/letsencrypt/live/<domain>/chain.pem
 *   /etc/letsencrypt/renewal/<domain>.conf       (renewal config)
 *   /etc/letsencrypt/archive/<domain>/...        (historical certs)
 *
 * Idempotency:
 *   - issueCert() is safe to re-run: certbot's --expand flag handles
 *     "cert exists, add a SAN" without revoking the old one. If the
 *     cert is already present and SAN list matches, certbot exits
 *     successfully without any work.
 *   - revokeCert() with --reason=cessationOfOperation is the safer
 *     of the revoke reasons (vs unspecified/superseded) when we're
 *     actually deleting the site. After revoke we also `delete`
 *     so the renewal cron doesn't keep trying to renew a dead cert.
 *   - certificateExists() is a pure filesystem stat, no certbot call.
 *
 * Failure semantics:
 *   - Network errors, DNS not propagated, ACME-challenge unreachable:
 *     returned as IssuanceOutcome::SKIPPED_DNS / SKIPPED_CHALLENGE
 *     so the saga step can succeed-with-warning rather than failing
 *     the whole site creation. Operator can re-run via reissue.
 *   - certbot binary missing / unauthorized: thrown as
 *     RuntimeException because that's a server-config problem, not
 *     a per-site issue.
 *
 * Legacy parity:
 *   Mirrors what panel/agent/Actions/VhostAction.php's
 *   requestSslCertificate() did, including the www.<domain> +
 *   mail.<domain> SAN expansion logic, but split out so the saga
 *   step can compose the call without inheriting the whole 6000-line
 *   action class.
 */
final class SslAdapter
{
    /** Default certbot binary path. Overridable for tests. */
    private const DEFAULT_CERTBOT = '/usr/bin/certbot';
    /** Where Let's Encrypt stores live cert symlinks. */
    private const LIVE_DIR = '/etc/letsencrypt/live';
    /** Where Let's Encrypt stores per-cert renewal configs. */
    private const RENEWAL_DIR = '/etc/letsencrypt/renewal';
    /** certbot's wallclock budget. The acme-v2 round-trip for one
     *  domain typically completes in <15s; we allow generous headroom
     *  for slow DNS and rate-limit-aware retries inside certbot. */
    private const ISSUE_TIMEOUT_SECONDS = 120;
    /** Revoke is local + a quick ACME call to mark the cert dead. */
    private const REVOKE_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $certbotBinary = self::DEFAULT_CERTBOT,
        private readonly string $liveDir = self::LIVE_DIR,
        private readonly string $renewalDir = self::RENEWAL_DIR,
    ) {
    }

    /**
     * Has `certbot certificates` already provisioned a cert for this
     * domain? We trust the filesystem: certbot writes fullchain+privkey
     * symlinks atomically, so the presence of both is the canonical
     * "cert exists" signal.
     */
    public function certificateExists(string $domain): bool
    {
        $live = $this->liveDir . '/' . $domain;
        return is_file($live . '/fullchain.pem')
            && is_file($live . '/privkey.pem');
    }

    /**
     * Does the domain resolve via the system resolver to anything
     * other than itself? gethostbyname returns the input string when
     * no record is found.
     */
    public function dnsResolves(string $domain): bool
    {
        if ($domain === '' || strpos($domain, '.') === false) {
            return false;
        }
        $ip = gethostbyname($domain);
        return $ip !== $domain && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Issue (or expand) a certificate for $domain plus optional SANs.
     *
     * @param string       $domain        Primary domain (CN).
     * @param list<string> $sans          Subject-Alt-Name extras (e.g.
     *                                    ['www.example.com', 'mail.example.com']).
     *                                    Pre-filtered by the caller for
     *                                    DNS-resolves + ACME-reachable.
     * @param string       $webroot       Path to <docroot> (must contain
     *                                    .well-known/acme-challenge/).
     * @param string       $email         Contact email for cert expiry
     *                                    notifications. Required by ACME.
     * @param bool         $staging       Use Let's Encrypt staging
     *                                    endpoint (does not consume the
     *                                    rate-limit budget; certs are
     *                                    NOT browser-trusted).
     */
    public function issueCert(
        string $domain,
        array $sans,
        string $webroot,
        string $email,
        bool $staging = false,
    ): IssuanceResult {
        $args = [
            'certonly',
            '--webroot',
            '-w', $webroot,
            '-d', $domain,
        ];
        foreach ($sans as $san) {
            $args[] = '-d';
            $args[] = $san;
        }
        $args[] = '--email';
        $args[] = $email;
        $args[] = '--agree-tos';
        $args[] = '--non-interactive';
        $args[] = '--expand';
        $args[] = '--keep-until-expiring';
        if ($staging) {
            $args[] = '--staging';
        }

        $r = $this->runner->run(
            binary: $this->certbotBinary,
            args: $args,
            timeoutSeconds: self::ISSUE_TIMEOUT_SECONDS,
        );

        if ($r->isSuccess()) {
            return new IssuanceResult(
                outcome: IssuanceOutcome::ISSUED,
                domains: array_merge([$domain], $sans),
                rawOutput: $r->stdout . $r->stderr,
            );
        }

        $combined = strtolower($r->stdout . "\n" . $r->stderr);
        $outcome = $this->classifyFailure($combined);
        return new IssuanceResult(
            outcome: $outcome,
            domains: array_merge([$domain], $sans),
            rawOutput: $r->stdout . $r->stderr,
            error: $this->humanError($combined),
        );
    }

    /**
     * Revoke + delete a certificate. Idempotent: returns true if the
     * cert was either revoked successfully or wasn't present to begin
     * with. Returns false ONLY when the operation actually failed
     * (e.g. ACME endpoint refused the revocation).
     *
     * Why both revoke AND delete:
     *   - revoke tells the ACME server the cert is dead (so it can't
     *     be used by an attacker who exfiltrated the privkey before
     *     deletion).
     *   - delete removes /etc/letsencrypt/live/<d>, archive/<d>, and
     *     renewal/<d>.conf so certbot's renewal cron stops trying
     *     to renew a cert that no longer exists.
     */
    public function revokeCert(string $domain): bool
    {
        if (!$this->certificateExists($domain)) {
            // Nothing to revoke. Renewal config might still linger
            // from a half-finished previous delete; clean it up.
            $this->cleanRenewalConfig($domain);
            return true;
        }

        $live = $this->liveDir . '/' . $domain;
        $r = $this->runner->run(
            binary: $this->certbotBinary,
            args: [
                'revoke',
                '--cert-path', $live . '/fullchain.pem',
                '--reason', 'cessationOfOperation',
                '--non-interactive',
            ],
            timeoutSeconds: self::REVOKE_TIMEOUT_SECONDS,
        );
        $revokedOk = $r->isSuccess();

        // delete is best-effort even if revoke failed - we still
        // want the renewal cron to stop targeting this domain.
        $r2 = $this->runner->run(
            binary: $this->certbotBinary,
            args: [
                'delete',
                '--cert-name', $domain,
                '--non-interactive',
            ],
            timeoutSeconds: self::REVOKE_TIMEOUT_SECONDS,
        );
        $deletedOk = $r2->isSuccess();

        $this->cleanRenewalConfig($domain);

        return $revokedOk && $deletedOk;
    }

    /**
     * Strip the renewal config in case `certbot delete` left an
     * orphaned file (rare but observed when the cert dir was already
     * removed manually before deletion).
     */
    private function cleanRenewalConfig(string $domain): void
    {
        $renewal = $this->renewalDir . '/' . $domain . '.conf';
        if (is_file($renewal)) {
            @unlink($renewal);
        }
    }

    /**
     * Map certbot's stderr into our enum. Order-sensitive: rate-limit
     * checks must come before "too many" because some server responses
     * mention both.
     */
    private function classifyFailure(string $combinedOutput): IssuanceOutcome
    {
        if (strpos($combinedOutput, 'too many certificates') !== false
            || strpos($combinedOutput, 'rate limit') !== false
        ) {
            return IssuanceOutcome::RATE_LIMITED;
        }
        if (strpos($combinedOutput, 'dns problem') !== false
            || strpos($combinedOutput, 'nxdomain') !== false
            || strpos($combinedOutput, 'no a/aaaa record') !== false
        ) {
            return IssuanceOutcome::SKIPPED_DNS;
        }
        if (strpos($combinedOutput, 'unauthorized') !== false
            || strpos($combinedOutput, 'challenge did not pass') !== false
            || strpos($combinedOutput, 'fetching') !== false
            || strpos($combinedOutput, 'connection refused') !== false
        ) {
            return IssuanceOutcome::SKIPPED_CHALLENGE;
        }
        return IssuanceOutcome::FAILED;
    }

    /**
     * One-line human summary used in saga events. Keeps stack traces
     * out of operator logs while preserving the actionable category.
     */
    private function humanError(string $combinedOutput): string
    {
        return match ($this->classifyFailure($combinedOutput)) {
            IssuanceOutcome::RATE_LIMITED => 'Let\'s Encrypt rate limit hit; retry later',
            IssuanceOutcome::SKIPPED_DNS => 'DNS for primary domain not propagated yet',
            IssuanceOutcome::SKIPPED_CHALLENGE => 'ACME HTTP-01 challenge unreachable (check :80 + firewall)',
            IssuanceOutcome::FAILED => 'certbot exited non-zero; see saga events for raw output',
            default => 'unknown',
        };
    }
}
