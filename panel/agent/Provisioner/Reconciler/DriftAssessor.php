<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Reconciler;

/**
 * Decides what (if anything) to do given a SiteHealthProbe + the
 * site's declared state.
 *
 * The decision rules are explicit and testable. They live here rather
 * than embedded in the ReconcilerService so test cases can drive
 * scenarios without standing up the queue.
 *
 * Rule outline:
 *   1. desired_state in {deleted, archived} -> RECONCILE is not allowed;
 *      drift in those rows surfaces as DEGRADE_ONLY for operator review.
 *   2. desired_state=active and actual_state in {provisioning, restoring}
 *      -> SKIP. A saga is already in flight or about to run.
 *   3. desired_state=active and actual_state=suspended -> SKIP. Suspended
 *      sites are intentionally offline; auto-resume is an operator
 *      decision, not a reconciler decision.
 *   4. desired_state=active and any of {vhost, home, document_root}
 *      missing -> RECONCILE/high. These are core artifacts.
 *   5. desired_state=active and any of {database, database_user,
 *      sftp_user, sftp_group} missing -> RECONCILE/medium.
 *   6. desired_state=active and all probed subsystems present -> HEALTHY.
 *   7. probe.errors not empty -> recommendation downgrades to SKIP with
 *      the probe-error context (we never act on incomplete data).
 *
 * Why we are conservative about declaring drift: the reconciler runs
 * on a timer. A false-positive RECONCILE re-runs the CREATE saga, which
 * (intentionally) writes to OLS and possibly restarts it. We accept
 * missing remediation in ambiguous cases over running unwanted sagas.
 */
final class DriftAssessor
{
    /**
     * @param array<string, mixed> $siteRow
     */
    public function assess(array $siteRow, SiteHealthProbe $probe): DriftAssessment
    {
        $domain = (string) ($siteRow['domain'] ?? $probe->domain);
        $desired = (string) ($siteRow['desired_state'] ?? 'active');
        $actual = (string) ($siteRow['actual_state'] ?? 'absent');
        $now = microtime(true);

        // Rule 7: incomplete data -> SKIP.
        if ($probe->errors !== []) {
            return new DriftAssessment(
                domain: $domain,
                recommendation: DriftAssessment::RECOMMEND_SKIP,
                severity: DriftAssessment::SEVERITY_NONE,
                reasons: array_merge(['probe returned errors'], $probe->errors),
                missing: $probe->missing(),
                unevaluated: $probe->unevaluated(),
                skipReason: 'probe_errors',
                assessedAtUnix: $now,
            );
        }

        // Rule 1: desired_state intent is to be gone -> never auto-reconcile.
        if (in_array($desired, ['deleted', 'archived'], true)) {
            if ($probe->present() === []) {
                return new DriftAssessment(
                    domain: $domain,
                    recommendation: DriftAssessment::RECOMMEND_HEALTHY,
                    severity: DriftAssessment::SEVERITY_NONE,
                    reasons: ["desired_state={$desired} and no artifacts present"],
                    missing: [],
                    unevaluated: $probe->unevaluated(),
                    assessedAtUnix: $now,
                );
            }
            return new DriftAssessment(
                domain: $domain,
                recommendation: DriftAssessment::RECOMMEND_DEGRADE,
                severity: DriftAssessment::SEVERITY_HIGH,
                reasons: [
                    "desired_state={$desired} but artifacts still present",
                    'manual cleanup needed: ' . implode(',', $probe->present()),
                ],
                missing: $probe->missing(),
                unevaluated: $probe->unevaluated(),
                skipReason: 'cannot_auto_delete',
                assessedAtUnix: $now,
            );
        }

        // Rule 2: saga already in flight -> SKIP. (provisioning,
        // restoring, deleting all represent an active worker run.)
        if (in_array($actual, ['provisioning', 'restoring', 'deleting'], true)) {
            return new DriftAssessment(
                domain: $domain,
                recommendation: DriftAssessment::RECOMMEND_SKIP,
                severity: DriftAssessment::SEVERITY_NONE,
                reasons: ["actual_state={$actual}: in-flight saga"],
                missing: $probe->missing(),
                unevaluated: $probe->unevaluated(),
                skipReason: 'in_flight',
                assessedAtUnix: $now,
            );
        }

        // Rule 2b: pending_dns -> reconcile to retry SSL.
        //
        // pending_dns is NOT in-flight; it's a "core provisioning is
        // done, SSL issuance was deferred because DNS hadn't yet
        // propagated" landing zone. The reconciler's job for these
        // sites is to enqueue a RECONCILE every tick (which re-runs
        // the CREATE saga; check() short-circuits all completed steps
        // and SslIssueStep re-probes DNS + retries certbot). The
        // assessor reports this as low-severity drift so the worker
        // can run higher-priority operator-initiated work first.
        //
        // If core artifacts are missing on top of pending_dns
        // (something else broke), the regular rule-4/5 path below
        // captures it at higher severity.
        if ($actual === 'pending_dns') {
            $coreMissing = array_intersect(
                $probe->missing(),
                ['vhost', 'home', 'document_root']
            );
            if ($coreMissing !== []) {
                // Fall through to rule 4 so core drift bumps severity.
            } else {
                return new DriftAssessment(
                    domain: $domain,
                    recommendation: DriftAssessment::RECOMMEND_RECONCILE,
                    severity: DriftAssessment::SEVERITY_LOW,
                    reasons: ['pending_dns: retry SSL issuance after propagation'],
                    missing: $probe->missing(),
                    unevaluated: $probe->unevaluated(),
                    assessedAtUnix: $now,
                );
            }
        }

        if ($actual === 'suspended') {
            return new DriftAssessment(
                domain: $domain,
                recommendation: DriftAssessment::RECOMMEND_SKIP,
                severity: DriftAssessment::SEVERITY_NONE,
                reasons: ['site is suspended; reconciler does not auto-resume'],
                missing: $probe->missing(),
                unevaluated: $probe->unevaluated(),
                skipReason: 'suspended',
                assessedAtUnix: $now,
            );
        }

        // Rules 4-5-6: drift evaluation when desired=active.
        $missing = $probe->missing();
        if ($missing === []) {
            return new DriftAssessment(
                domain: $domain,
                recommendation: DriftAssessment::RECOMMEND_HEALTHY,
                severity: DriftAssessment::SEVERITY_NONE,
                reasons: ['all probed subsystems present'],
                missing: [],
                unevaluated: $probe->unevaluated(),
                assessedAtUnix: $now,
            );
        }

        $coreMissing = array_intersect($missing, ['vhost', 'home', 'document_root']);
        $severity = $coreMissing !== []
            ? DriftAssessment::SEVERITY_HIGH
            : DriftAssessment::SEVERITY_MEDIUM;

        return new DriftAssessment(
            domain: $domain,
            recommendation: DriftAssessment::RECOMMEND_RECONCILE,
            severity: $severity,
            reasons: array_merge(
                ['drift detected: ' . count($missing) . ' subsystem(s) missing'],
                array_map(static fn(string $s) => "missing: {$s}", $missing),
            ),
            missing: $missing,
            unevaluated: $probe->unevaluated(),
            assessedAtUnix: $now,
        );
    }
}
