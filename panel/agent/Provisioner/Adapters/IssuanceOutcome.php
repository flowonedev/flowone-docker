<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

/**
 * Categorical result of an SSL issuance attempt.
 *
 * Drives SslIssueStep's decision between three saga outcomes:
 *
 *   ISSUED / ALREADY_PRESENT  -> step succeeds, vhssl block flipped,
 *                                site lands as `active` with SSL on.
 *   SKIPPED_DNS /             -> step succeeds with a `ssl_deferred=true`
 *   SKIPPED_CHALLENGE /          flag in state. The site stays HTTP and
 *   RATE_LIMITED                 the operator can re-run issuance once
 *                                the precondition is fixed (DNS
 *                                propagated, firewall opened, rate-
 *                                limit window cleared).
 *   FAILED                    -> step succeeds with `ssl_enabled=false`
 *                                but with an error logged. Treated as a
 *                                soft failure because the site is still
 *                                serving HTTP - we don't want to nuke a
 *                                working site over a misclassified
 *                                certbot output.
 *
 * Why this is a separate file (not nested in SslAdapter.php):
 *   The agent's spl_autoload_register expects PSR-4 (one class per
 *   file at <classname>.php). Stuffing the enum into the adapter file
 *   would silently fail to load when SslIssueStep references the
 *   enum by short name.
 */
enum IssuanceOutcome: string
{
    case ISSUED = 'issued';
    case ALREADY_PRESENT = 'already_present';
    case SKIPPED_DNS = 'skipped_dns';
    case SKIPPED_CHALLENGE = 'skipped_challenge';
    case RATE_LIMITED = 'rate_limited';
    case FAILED = 'failed';
}
