<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Support;

use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Pulls denormalized columns out of the saga's StepState map and
 * writes them back to the `sites` row.
 *
 * Why this exists:
 *   The state machine's transition() method only touches actual_state +
 *   updated_at. The denormalized columns (home_dir, document_root,
 *   sftp_user, db_name, db_user, php_version, dns_enabled) are
 *   populated by the legacy SiteController create flow but the V2 saga
 *   left them as NULL after a successful run. The legacy
 *
 *   We deliberately do NOT cache sftp_group: every site's group name
 *   is byte-identical to its sftp_user (both flow from
 *   ResourceNameDeriver::sftpName($domain)) so a separate column
 *   would be dead state. Reconcilers that need the group name
 *   recompute it from sftp_user; that's cheaper than a column.
 *   list view (SitesView.vue) and reconciler both read those columns
 *   directly, so a V2-created site was effectively "invisible" to the
 *   legacy UI even though its actual_state was 'active'.
 *
 *   Rather than push every step to write its own column, the runner
 *   collects the entire state map after a successful saga and asks
 *   this helper to do one targeted UPDATE. Single source of truth for
 *   what-state-feeds-what-column.
 *
 * Idempotency:
 *   The UPDATE only sets columns we have a value for - existing
 *   non-null columns are preserved if a re-run can't find the data
 *   (e.g. a partial saga state from a crashed prior run). This is
 *   important for recovery sweeps where we re-enter the runner with
 *   an already-populated row.
 *
 * Failure policy:
 *   The backfill MUST NOT block the saga's terminal transition. If
 *   the UPDATE fails for any reason (locked row, schema drift, etc.)
 *   the helper logs to error_log and returns without throwing. The
 *   site is still functionally correct (vhost.conf written, DB
 *   created, DNS seeded); the only consequence of a missed backfill
 *   is the legacy UI showing "—" in those columns until the next
 *   reconciler tick.
 */
final class SiteRowBackfiller
{
    public function __construct(
        private readonly PanelDatabase $database,
    ) {
    }

    /**
     * Pull the columns out of $stepStates + $payload and UPDATE the
     * sites row. Only call this on a SUCCEEDED CREATE / RESTORE saga.
     *
     * @param array<string, StepState> $stepStates Keyed by StepName::*.
     * @param array<string, mixed>     $payload    Original job payload.
     * @param string|null              $domain     Canonical site domain
     *        ($ctx->domain()). When provided alongside a created DB, the
     *        backfiller also upserts a `database_links` row so the panel
     *        can associate the per-site `flowone_<domain>` schema with
     *        the site (the Databases tab + orphan detection read this
     *        table). Optional so existing callers/tests keep working.
     * @return list<string>            list of column names actually written
     */
    public function backfill(int $siteId, array $stepStates, array $payload, ?string $domain = null): array
    {
        if ($siteId <= 0) {
            return [];
        }

        $values = $this->extractValues($stepStates, $payload);
        if (empty($values)) {
            return [];
        }

        $setFragments = [];
        $params = ['id' => $siteId];
        foreach ($values as $col => $val) {
            $setFragments[] = "{$col} = :{$col}";
            $params[$col] = $val;
        }

        $sql = 'UPDATE sites SET ' . implode(', ', $setFragments)
             . ', updated_at = NOW() WHERE id = :id';

        try {
            $stmt = $this->database->pdo()->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[SiteRowBackfiller] failed to backfill site_id=%d: %s | sql=%s',
                $siteId,
                $e->getMessage(),
                $sql,
            ));
            return [];
        }

        // Best-effort: record the DB<->site link so the panel can find
        // the provisioned schema (named flowone_<domain>, which no
        // prefix heuristic would otherwise match). A failure here must
        // NOT undo the successful backfill above.
        $linkDomain = ($domain !== null && $domain !== '')
            ? $domain
            : (isset($payload['domain']) && is_string($payload['domain']) ? $payload['domain'] : null);
        if ($linkDomain !== null && $linkDomain !== '' && isset($values['db_name'])) {
            $this->recordDatabaseLink(
                $linkDomain,
                (string) $values['db_name'],
                isset($values['db_user']) ? (string) $values['db_user'] : null,
            );
        }

        return array_keys($values);
    }

    /**
     * Upsert a `database_links` row (db_name <-> domain) so the panel's
     * DatabaseController (Databases tab, orphan detection, per-site DB
     * lookup) can associate a saga-provisioned schema with its site.
     *
     * Idempotent via the unique (db_name, domain) key. Best-effort:
     * swallows + logs any error so it can never block the saga.
     */
    private function recordDatabaseLink(string $domain, string $dbName, ?string $dbUser): void
    {
        try {
            $pdo = $this->database->pdo();
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS database_links (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    db_name VARCHAR(64) NOT NULL,
                    db_user VARCHAR(64),
                    domain VARCHAR(255) NOT NULL,
                    db_host VARCHAR(255) NOT NULL DEFAULT 'localhost',
                    created_by INT,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_db_domain (db_name, domain),
                    INDEX idx_domain (domain),
                    INDEX idx_db_name (db_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $stmt = $pdo->prepare("
                INSERT INTO database_links (db_name, db_user, domain, db_host, notes)
                VALUES (:db_name, :db_user, :domain, 'localhost', 'Auto-linked by site provisioning')
                ON DUPLICATE KEY UPDATE
                    db_user = VALUES(db_user),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'domain' => $domain,
            ]);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[SiteRowBackfiller] failed to record database_link %s -> %s: %s',
                $dbName,
                $domain,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Build the column => value map from the step states + payload.
     *
     * Logic per column:
     *   - home_dir: HOME_DIR_CREATE.data.home (preferred) or
     *               SFTP_USER_CREATE.data.home (the fallback path
     *               when home dir step adopts a pre-existing home).
     *   - document_root: home_dir + '/public_html'.
     *   - sftp_user: SFTP_USER_CREATE.data.user.
     *   - db_name: DATABASE_CREATE.data.db_name.
     *   - db_user: DATABASE_USER_CREATE.data.user.
     *   - php_version: payload.php_version (or php_lsapi alias).
     *   - dns_enabled: 1 iff DnsZoneCreateStep ran without skipping
     *                  (state.data.dns_skipped is null AND
     *                  state.data.dns_zone_id is set).
     *
     * Returns ONLY columns we have positive values for. Empty / null
     * stays out of the UPDATE so we don't clobber prior good data.
     *
     * @param array<string, StepState> $stepStates
     * @param array<string, mixed>     $payload
     * @return array<string, scalar>
     */
    public function extractValues(array $stepStates, array $payload): array
    {
        $out = [];

        $home = $this->str($stepStates, StepName::HOME_DIR_CREATE, 'home')
            ?? $this->str($stepStates, StepName::SFTP_USER_CREATE, 'home');
        if ($home !== null) {
            $out['home_dir'] = $home;
            $out['document_root'] = $home . '/public_html';
        }

        $sftpUser = $this->str($stepStates, StepName::SFTP_USER_CREATE, 'user');
        if ($sftpUser !== null) {
            $out['sftp_user'] = $sftpUser;
        }

        $dbName = $this->str($stepStates, StepName::DATABASE_CREATE, 'db_name');
        if ($dbName !== null) {
            $out['db_name'] = $dbName;
        }

        $dbUser = $this->str($stepStates, StepName::DATABASE_USER_CREATE, 'user');
        if ($dbUser !== null) {
            $out['db_user'] = $dbUser;
        }

        $php = null;
        if (isset($payload['php_version']) && is_string($payload['php_version'])) {
            $php = $payload['php_version'];
        } elseif (isset($payload['php_lsapi']) && is_string($payload['php_lsapi'])) {
            $php = $payload['php_lsapi'];
        }
        if ($php !== null && $php !== '') {
            $out['php_version'] = $php;
        }

        // DNS state: only mark dns_enabled=1 when the saga actually
        // seeded a zone. A skipped DNS step (single-label, opted-out)
        // means the column stays at its previous value.
        $dnsState = $stepStates[StepName::DNS_ZONE_CREATE] ?? null;
        if ($dnsState instanceof StepState) {
            $skipped = $dnsState->data['dns_skipped'] ?? null;
            $zoneId = $dnsState->data['dns_zone_id'] ?? null;
            if ($skipped === null && is_int($zoneId) && $zoneId > 0) {
                $out['dns_enabled'] = 1;
            }
        }

        return $out;
    }

    /**
     * Read a string value out of a step's state.data map. Returns null
     * if the step didn't run, the key is missing, or the value isn't
     * a non-empty string.
     *
     * @param array<string, StepState> $stepStates
     */
    private function str(array $stepStates, string $stepName, string $key): ?string
    {
        $state = $stepStates[$stepName] ?? null;
        if (!$state instanceof StepState) {
            return null;
        }
        $val = $state->data[$key] ?? null;
        if (!is_string($val) || $val === '') {
            return null;
        }
        return $val;
    }
}
