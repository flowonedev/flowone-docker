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
 * Drop the PowerDNS zone for a site being deleted/archived.
 *
 * Inverse of DnsZoneCreateStep. Removes ALL records for the zone
 * (including any operator-added ones - the saga is the authoritative
 * lifecycle owner once it took on the zone) and the dns_domains row.
 *
 * Native-table cleanup:
 *   Servers migrated from the original PowerDNS gmysql schema carry
 *   a SECOND copy of zones in the native `domains` / `records` /
 *   `domainmetadata` tables (testsite.hu leftover incident, June
 *   2026). After the panel tables are cleaned we best-effort delete
 *   the same zone from the native tables when they exist. Fresh
 *   installs don't have them - that's fine, the cleanup is skipped.
 *
 * Idempotence:
 *   - check() returns true iff no dns_domains row AND no native
 *     domains row exists for the domain. So a second pass after a
 *     successful run is a no-op.
 *   - execute() short-circuits when the zone is already gone.
 *
 * Compensation: DEGRADE_ONLY.
 *   Re-creating the deleted zone with the same record set is the
 *   purview of the RESTORE saga (DnsZoneCreateStep re-seeds from the
 *   template). Compensation here would either be a no-op (records
 *   lost) or a partial reconstruction that misleads the operator.
 *   Mark degraded; let the operator drive recovery.
 *
 * Notify-on-delete:
 *   PowerDNS serves NXDOMAIN automatically once the SOA row is gone,
 *   but its packet/query caches can keep answering for up to the
 *   cache TTL. We best-effort `pdns_control purge <domain>$` so the
 *   zone disappears from resolution immediately.
 */
final class DnsZoneRemoveStep extends AbstractStep
{
    public function name(): string
    {
        return StepName::DNS_ZONE_REMOVE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $domain = $ctx->domain();
        if ($domain === '') {
            return true;
        }
        $pdo = $ctx->database->pdo();
        $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ? LIMIT 1");
        $stmt->execute([$domain]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }
        return $this->nativeZoneId($pdo, $domain) === null;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $domain = $ctx->domain();
        $pdo = $ctx->database->pdo();
        $events = [];

        $stmt = $pdo->prepare("SELECT id FROM dns_domains WHERE name = ? LIMIT 1");
        $stmt->execute([$domain]);
        $zoneId = $stmt->fetchColumn();

        $recordCount = 0;
        if ($zoneId === false) {
            $events[] = StepEvent::info('dns zone already absent in panel tables', ['domain' => $domain]);
        } else {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM dns_records WHERE domain_id = ?");
            $countStmt->execute([(int) $zoneId]);
            $recordCount = (int) $countStmt->fetchColumn();

            try {
                $pdo->prepare("DELETE FROM dns_records WHERE domain_id = ?")->execute([(int) $zoneId]);
                $pdo->prepare("DELETE FROM dns_domains WHERE id = ?")->execute([(int) $zoneId]);
            } catch (\Throwable $e) {
                return StepResult::failure(
                    $state,
                    "dropZone failed: " . $e->getMessage(),
                    [StepEvent::error('dns zone drop failed', [
                        'domain' => $domain,
                        'zone_id' => (int) $zoneId,
                    ])],
                );
            }
            $events[] = StepEvent::info('dns zone dropped', [
                'domain' => $domain,
                'zone_id' => (int) $zoneId,
                'records' => $recordCount,
            ]);
        }

        // Legacy native PowerDNS tables (domains/records/domainmetadata).
        // Best-effort: absent on fresh installs, required on migrated
        // servers where the zone exists in BOTH table pairs.
        $nativeRemoved = $this->dropNativeZone($pdo, $domain, $events);

        // Purge the pdns packet/query cache so the deleted zone stops
        // resolving immediately instead of after the cache TTL.
        // Best-effort: a missing pdns_control binary (test sandboxes,
        // DNS-less installs) must never fail the saga.
        $adapters = $ctx->adapters();
        if ($adapters !== null && ($zoneId !== false || $nativeRemoved > 0)) {
            try {
                $purge = $adapters->runner->run('pdns_control', ['purge', $domain . '$'], null, 5);
                $events[] = $purge->isSuccess()
                    ? StepEvent::info('pdns cache purged', ['domain' => $domain])
                    : StepEvent::warning('pdns cache purge failed (non-fatal)', ['domain' => $domain]);
            } catch (\Throwable $e) {
                $events[] = StepEvent::warning('pdns cache purge unavailable (non-fatal)', [
                    'domain' => $domain,
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        return StepResult::success(
            $state->mergeData([
                'dropped_zone_id' => $zoneId === false ? null : (int) $zoneId,
                'removed_records' => $recordCount,
                'removed_native_records' => $nativeRemoved,
            ])->withCompleted(),
            $events,
            ['removed' => $recordCount + $nativeRemoved],
        );
    }

    /**
     * Delete the zone from the legacy native PowerDNS tables when they
     * exist. Returns the number of native rows removed (0 when the
     * tables are absent or hold no such zone). Never throws: a native-
     * table hiccup must not fail the saga - the panel tables are the
     * source of truth and validateDeletion catches stragglers.
     *
     * @param list<StepEvent> $events Appended by reference.
     */
    private function dropNativeZone(\PDO $pdo, string $domain, array &$events): int
    {
        try {
            $zoneId = $this->nativeZoneId($pdo, $domain);
            if ($zoneId === null) {
                return 0;
            }

            $records = $pdo->prepare("DELETE FROM records WHERE domain_id = ?");
            $records->execute([$zoneId]);
            $removed = $records->rowCount();
            try {
                $pdo->prepare("DELETE FROM domainmetadata WHERE domain_id = ?")->execute([$zoneId]);
            } catch (\Throwable) {
                // domainmetadata may not exist; harmless.
            }
            $domains = $pdo->prepare("DELETE FROM domains WHERE id = ?");
            $domains->execute([$zoneId]);
            $removed += $domains->rowCount();

            $events[] = StepEvent::info('native pdns zone dropped', [
                'domain' => $domain,
                'zone_id' => $zoneId,
                'rows' => $removed,
            ]);
            return $removed;
        } catch (\Throwable $e) {
            $events[] = StepEvent::warning('native pdns zone cleanup skipped', [
                'domain' => $domain,
                'detail' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Zone id in the legacy native `domains` table, or null when the
     * table is missing (fresh install) or holds no such zone.
     */
    private function nativeZoneId(\PDO $pdo, string $domain): ?int
    {
        try {
            $stmt = $pdo->prepare("SELECT id FROM domains WHERE name = ? LIMIT 1");
            $stmt->execute([$domain]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        } catch (\Throwable) {
            return null;
        }
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: dns zone NOT recreated (DEGRADE_ONLY); zone is gone',
                ['domain' => $ctx->domain()]
            )]
        );
    }
}
