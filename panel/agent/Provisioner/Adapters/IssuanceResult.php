<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * Return value from SslAdapter::issueCert(). DTO only - no logic
 * beyond two convenience predicates the saga step uses to branch.
 *
 * Fields:
 *   - outcome      enum result. SslIssueStep maps this to a saga
 *                  state.data['outcome'] string and a vhssl-flip
 *                  decision.
 *   - domains      list of CN + SANs the cert covers (or would cover
 *                  on a successful issue). Mirrored to state so the
 *                  reconciler can verify the live cert's SAN list
 *                  matches what the saga thought it was issuing.
 *   - rawOutput    full stdout+stderr from certbot. Kept for the
 *                  case where classifyFailure() picks the wrong
 *                  bucket and an operator needs to look at the raw
 *                  output.
 *   - error        one-line human-readable summary derived from the
 *                  raw output. Null on success.
 *
 * Why a separate file (not embedded in SslAdapter.php):
 *   The agent's spl_autoload_register expects PSR-4 (one class per
 *   file at <classname>.php). Co-location would silently fail to
 *   resolve at runtime.
 */
final class IssuanceResult
{
    public function __construct(
        public readonly IssuanceOutcome $outcome,
        /** @var list<string> */
        public readonly array $domains,
        public readonly string $rawOutput,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * True only when the cert is actually on disk after the call.
     * Used by SslIssueStep to decide whether to proceed with the
     * vhssl-block flip + 2nd OLS reload.
     */
    public function isCertOnDisk(): bool
    {
        return in_array(
            $this->outcome,
            [IssuanceOutcome::ISSUED, IssuanceOutcome::ALREADY_PRESENT],
            true,
        );
    }

    /**
     * True for outcomes that indicate "this should re-run later but
     * not fail the saga right now". The step uses this to set
     * `ssl_deferred=true` in state so the UI can render a yellow
     * "SSL pending" badge instead of a red "SSL failed" one.
     */
    public function isDeferrable(): bool
    {
        return in_array(
            $this->outcome,
            [IssuanceOutcome::SKIPPED_DNS, IssuanceOutcome::SKIPPED_CHALLENGE, IssuanceOutcome::RATE_LIMITED],
            true,
        );
    }
}
