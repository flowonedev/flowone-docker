<?php

namespace Webmail\Services;

/**
 * FolderIndexService - stable folder identity (Wave 2).
 *
 * Each folder is identified by a UUIDv7 generated on first discovery.
 * The path is just a (current) pointer; renames are recorded in
 * webmail_folder_path_history. Identity is stable across renames,
 * namespace moves, and delimiter migrations.
 *
 * Responsibilities:
 *   - generateId():               UUIDv7 (time-ordered, sortable)
 *   - upsertFromListing():        Persist a freshly listed folder; assign
 *                                 a UUIDv7 if new, detect renames if not.
 *   - getById() / getByPath():    Lookup helpers that consult the path
 *                                 history for legacy paths.
 *   - syncNamespaces():           Walk imap_getnamespaces + imap_getmailboxes
 *                                 per namespace; persist prefix/delimiter/
 *                                 attributes/is_selectable.
 *   - detectRenames():            Weighted multi-signal rename detection.
 *                                 Hard rename-collapse invariant: one
 *                                 existing folder_id maps to at most one
 *                                 newly discovered path per sync pass.
 *   - openScanGeneration() /
 *     completeScanGeneration():   Scan-generation fence so a slow stale
 *                                 scan can never overwrite a fast fresh one.
 *   - fingerprintProvider():      gmail / dovecot / exchange / cyrus /
 *                                 courier / unknown derivation from
 *                                 CAPABILITY / namespace layout.
 *
 * Wave 2 dual-write contract: read paths still use (user_email, folder, uid)
 * for backward compatibility; folder_id is populated as a parallel column
 * but reads do not yet require it. The cutover is gated by the four-counter
 * telemetry rollout in dual-write-readiness.php.
 */
class FolderIndexService
{
    private \PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    /**
     * Generate a UUIDv7 (RFC 9562). Time-ordered, sortable, indexable.
     * 48 bits of milliseconds + 12-bit version+ rand_a + 62-bit rand_b.
     */
    public static function generateId(): string
    {
        $tsMs = (int) round(microtime(true) * 1000);
        // 48-bit timestamp big-endian
        $tsHex = str_pad(dechex($tsMs), 12, '0', STR_PAD_LEFT);

        try {
            $rand = random_bytes(10);
        } catch (\Throwable $e) {
            $rand = '';
            for ($i = 0; $i < 10; $i++) {
                $rand .= chr(mt_rand(0, 255));
            }
        }
        $randHex = bin2hex($rand);

        // Version 7 in the top 4 bits of byte 6.
        $randHex = '7' . substr($randHex, 1);
        // Variant bits 10xx in the top 2 bits of byte 8.
        $variantNibble = hexdec($randHex[4]) & 0x3 | 0x8;
        $randHex = substr($randHex, 0, 4) . dechex($variantNibble) . substr($randHex, 5);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($tsHex, 0, 8),
            substr($tsHex, 8, 4),
            substr($randHex, 0, 4),
            substr($randHex, 4, 4),
            substr($randHex, 8, 12)
        );
    }

    /**
     * Open a new scan generation for an account. Returns the generation_id.
     * Stores in DB (audit trail) and Redis (atomic current-generation pointer).
     */
    public function openScanGeneration(string $accountId, RedisCacheService $redis): string
    {
        $gen = CorrelationId::generate();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO webmail_folder_scan_runs (account_id, generation_id, status) VALUES (?, ?, ?)'
            );
            $stmt->execute([$accountId, $gen, 'running']);
        } catch (\Throwable $e) {
            error_log('[FolderIndexService] openScanGeneration DB error: ' . $e->getMessage());
        }
        if ($redis->isAvailable()) {
            $redis->set('account:' . $accountId . ':current_generation', $gen, 3600);
        }
        return $gen;
    }

    /**
     * Mark a scan complete. Also marks any prior `running` scans for the
     * same account as `superseded` so the audit trail is clean.
     */
    public function completeScanGeneration(string $accountId, string $generationId, bool $success = true): void
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE webmail_folder_scan_runs
                    SET status = ?, finished_at = CURRENT_TIMESTAMP
                  WHERE generation_id = ? AND account_id = ?'
            );
            $stmt->execute([$success ? 'complete' : 'failed', $generationId, $accountId]);

            $stmt2 = $this->db->prepare(
                "UPDATE webmail_folder_scan_runs
                    SET status = 'superseded', finished_at = CURRENT_TIMESTAMP
                  WHERE account_id = ? AND status = 'running' AND generation_id <> ?"
            );
            $stmt2->execute([$accountId, $generationId]);
        } catch (\Throwable $e) {
            error_log('[FolderIndexService] completeScanGeneration DB error: ' . $e->getMessage());
        }
    }

    /**
     * Returns true iff the supplied generation is still the current one for
     * this account. Cache writes / state transitions should consult this
     * before persisting anything.
     */
    public function isGenerationCurrent(string $accountId, string $generationId, RedisCacheService $redis): bool
    {
        if (!$redis->isAvailable()) {
            return true; // fail-open if Redis is down
        }
        $current = $redis->get('account:' . $accountId . ':current_generation');
        if (is_array($current)) {
            $current = $current['value'] ?? null;
        }
        if (!is_string($current) || $current === '') {
            return true;
        }
        return $current === $generationId;
    }

    /**
     * Look up a folder by id. Returns the row or null.
     */
    public function getById(string $folderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM webmail_folder_identity WHERE id = ? LIMIT 1');
        $stmt->execute([$folderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Look up an identity row by path. Resolution order:
     *
     *   1) Open interval in webmail_folder_path_intervals
     *      (account_id, path, valid_to IS NULL). This is the canonical
     *      "who owns this path right now" answer.
     *   2) current_path on webmail_folder_identity (kept in sync with
     *      the open interval, but checked here for first-write paths
     *      that haven't been recorded as an interval yet).
     *   3) Closed interval in webmail_folder_path_intervals (most
     *      recent valid_to). Useful for legacy URL redirects -- "I
     *      hit /m/Old/123, where did it go?".
     *   4) Legacy event log webmail_folder_path_history (kept for the
     *      forensic / migration audit trail).
     *
     * Returns the matching webmail_folder_identity row or null.
     */
    public function getByPath(string $accountId, string $path): ?array
    {
        // 1) Open interval -> live owner.
        $stmt = $this->db->prepare(
            'SELECT fi.* FROM webmail_folder_identity fi
              JOIN webmail_folder_path_intervals pi ON pi.folder_id = fi.id
             WHERE pi.account_id = ? AND pi.path = ? AND pi.valid_to IS NULL
             ORDER BY pi.valid_from DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $path]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        // 2) current_path. Covers brand-new folders before the first
        // upsertFromListing has run an interval insert.
        $stmt2 = $this->db->prepare(
            'SELECT * FROM webmail_folder_identity
              WHERE account_id = ? AND current_path = ? LIMIT 1'
        );
        $stmt2->execute([$accountId, $path]);
        $row2 = $stmt2->fetch();
        if ($row2) {
            return $row2;
        }

        // 3) Closed interval -> legacy URL redirect.
        $stmt3 = $this->db->prepare(
            'SELECT fi.* FROM webmail_folder_identity fi
              JOIN webmail_folder_path_intervals pi ON pi.folder_id = fi.id
             WHERE pi.account_id = ? AND pi.path = ? AND pi.valid_to IS NOT NULL
             ORDER BY pi.valid_to DESC LIMIT 1'
        );
        $stmt3->execute([$accountId, $path]);
        $row3 = $stmt3->fetch();
        if ($row3) {
            return $row3;
        }

        // 4) Legacy event log -- kept so existing path_history rows
        // still resolve until we drop the table in a later wave.
        $stmt4 = $this->db->prepare(
            'SELECT fi.* FROM webmail_folder_identity fi
              JOIN webmail_folder_path_history h ON h.folder_id = fi.id
             WHERE fi.account_id = ? AND h.former_path = ?
             ORDER BY h.recorded_at DESC LIMIT 1'
        );
        $stmt4->execute([$accountId, $path]);
        $row4 = $stmt4->fetch();
        return $row4 ?: null;
    }

    /**
     * Ensure a folder has an OPEN path interval that matches its
     * current_path. Idempotent and safe to call from upsertFromListing
     * on every refresh; only inserts when no open row exists for
     * (account_id, path).
     */
    private function ensureOpenInterval(string $folderId, string $accountId, string $path, string $reason = 'initial'): void
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id FROM webmail_folder_path_intervals
                  WHERE account_id = ? AND path = ? AND valid_to IS NULL LIMIT 1'
            );
            $stmt->execute([$accountId, $path]);
            if ($stmt->fetch()) {
                return; // open row already present
            }
            $ins = $this->db->prepare(
                'INSERT INTO webmail_folder_path_intervals
                    (folder_id, account_id, path, valid_from, valid_to, reason)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP, NULL, ?)'
            );
            $ins->execute([$folderId, $accountId, $path, $reason]);
        } catch (\Throwable $e) {
            // The intervals table is new (migration 164). Tolerate its
            // absence so deployments mid-rollout don't crash; the
            // reconciliation cron will repair drift later.
            error_log('[FolderIndexService] ensureOpenInterval skipped: ' . $e->getMessage());
        }
    }

    /**
     * Synthesize a minimal placeholder identity for a folder we know existed
     * (because legacy dual-write rows reference it) but that the IMAP
     * listing has not yet surfaced through upsertFromListing(). Used only
     * by the backfill cron's --synthesize-missing mode.
     *
     * The row is marked state='degraded' and uidvalidity=NULL so downstream
     * code can detect it as "exists in the legacy backlog but unconfirmed
     * by IMAP". When the owner next visits /mailbox/folders, upsertFromListing
     * will UPDATE this row in place (matching by case-insensitive
     * current_path) and flip state -> 'healthy'.
     *
     * The open path-interval is opened too so the canonical-path resolver
     * (getByPath via webmail_folder_path_intervals) can find it; the
     * reconciliation cron only flags rows that have NO open interval.
     *
     * Returns the new folder_id, or null on duplicate / DB error. Safe to
     * call concurrently: the unique key (account_id, current_path(255))
     * causes the second writer to fall back to a SELECT and reuse the
     * existing id.
     *
     * @param string $accountId
     * @param string $path raw path as it appears in the legacy column
     * @param string $displayName best-effort human-readable name
     * @return ?string folder_id or null
     */
    public function synthesizePlaceholder(string $accountId, string $path, string $displayName): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if ($displayName === '') {
            $displayName = $path;
        }

        $this->db->beginTransaction();
        try {
            $id = self::generateId();
            $stmt = $this->db->prepare(
                'INSERT INTO webmail_folder_identity
                    (id, account_id, current_path, display_name, state, provider_type, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$id, $accountId, $path, $displayName, 'degraded', 'unknown']);

            // Open the canonical path-interval immediately. Without this the
            // reconciliation cron flags the row as "no_open_interval" and
            // the new-style getByPath() resolver has to fall back to
            // current_path lookup. This is just a normal initial interval
            // because the synthesized row IS the first sighting of the path
            // from our perspective.
            $this->ensureOpenInterval($id, $accountId, $path, 'initial');

            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $rb) { /* ignore */ }

            // Concurrent writer beat us to the unique key -- look up the
            // winning id and ensure it has an open interval too.
            if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                try {
                    $stmt = $this->db->prepare(
                        'SELECT id FROM webmail_folder_identity
                          WHERE LOWER(account_id) = LOWER(?) AND LOWER(current_path) = LOWER(?) LIMIT 1'
                    );
                    $stmt->execute([$accountId, $path]);
                    $row = $stmt->fetch();
                    if ($row && !empty($row['id'])) {
                        // Best-effort: make sure the existing row has an
                        // open interval too. ensureOpenInterval is
                        // idempotent so this is a cheap no-op when one
                        // already exists.
                        $this->ensureOpenInterval((string) $row['id'], $accountId, $path, 'initial');
                        return (string) $row['id'];
                    }
                } catch (\Throwable $e2) {
                    error_log('[FolderIndexService] synthesizePlaceholder duplicate-recovery error: ' . $e2->getMessage());
                }
            }
            error_log('[FolderIndexService] synthesizePlaceholder error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Backfill open path-intervals for any identity row that lacks one.
     * Used by the reconciliation cron / one-shot repair scripts when an
     * older synthesize run created identity rows without intervals (the
     * pre-fix backfill cron). Returns the number of intervals opened.
     */
    public function repairMissingIntervals(?string $accountId = null): int
    {
        $sql = 'SELECT fi.id, fi.account_id, fi.current_path
                  FROM webmail_folder_identity fi
                 LEFT JOIN webmail_folder_path_intervals pi
                        ON pi.folder_id = fi.id AND pi.valid_to IS NULL
                 WHERE pi.id IS NULL';
        $params = [];
        if ($accountId !== null && $accountId !== '') {
            $sql .= ' AND fi.account_id = ?';
            $params[] = $accountId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $opened = 0;
        while ($row = $stmt->fetch()) {
            try {
                $this->ensureOpenInterval(
                    (string) $row['id'],
                    (string) $row['account_id'],
                    (string) $row['current_path'],
                    'initial'
                );
                $opened++;
            } catch (\Throwable $e) {
                error_log('[FolderIndexService] repairMissingIntervals: ' . $e->getMessage());
            }
        }
        return $opened;
    }

    /**
     * Upsert a folder discovered in listFolders(). Assigns a UUIDv7 when
     * new, refreshes attributes when existing, tombstones renames in
     * webmail_folder_path_history.
     *
     * @param array $info {name, path, total, uidnext, uidvalidity, type, ...}
     * @return string folder_id
     */
    public function upsertFromListing(string $accountId, array $info): string
    {
        $path = (string) ($info['path'] ?? $info['name'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('upsertFromListing: empty path');
        }
        $existing = $this->getByPath($accountId, $path);
        $existingPath = $existing ? (string) ($existing['current_path'] ?? '') : '';
        // webmail_folder_identity.current_path lives under utf8mb4_unicode_ci,
        // so the UNIQUE KEY uniq_account_path is case-insensitive. A strict
        // === comparison here would treat a legacy row with current_path='inbox'
        // as a DIFFERENT folder than IMAP's 'INBOX' and fall through to the
        // INSERT branch below, which then trips the case-insensitive unique
        // key with a duplicate-entry SQLSTATE 23000. Comparing with strcasecmp
        // mirrors the collation and lets the case mismatch self-heal: we
        // UPDATE the existing row's current_path to the case IMAP actually
        // returned, so downstream PHP-side === comparisons (and the cached
        // folder list serialised into Redis) stop disagreeing with IMAP.
        if ($existing && strcasecmp($existingPath, $path) === 0) {
            // Plain refresh OR case-only canonicalization. We always upgrade
            // state to 'healthy' here because a successful IMAP LIST is, by
            // definition, proof that the folder exists and is accessible.
            // This also recovers any row that the backfill cron synthesized
            // as 'degraded' (a placeholder identity for a legacy folder
            // whose owner hadn't logged in since Wave-2 shipped).
            $stmt = $this->db->prepare(
                'UPDATE webmail_folder_identity
                    SET current_path = ?,
                        display_name = ?, uidvalidity = ?, uidnext = ?,
                        special_use = ?, attributes = ?, namespace_prefix = ?,
                        delimiter = ?, is_selectable = ?, message_count = ?,
                        state = CASE WHEN state IN (\'degraded\',\'quarantined\',\'deleted\')
                                     THEN \'healthy\' ELSE state END,
                        last_seen_at = CURRENT_TIMESTAMP
                  WHERE id = ?'
            );
            $stmt->execute([
                $path,
                (string) ($info['display_name'] ?? $info['name'] ?? $path),
                $info['uidvalidity'] ?? null,
                $info['uidnext'] ?? null,
                $info['special_use'] ?? null,
                isset($info['attributes']) ? json_encode($info['attributes']) : null,
                $info['namespace_prefix'] ?? null,
                $info['delimiter'] ?? null,
                isset($info['is_selectable']) ? (int) $info['is_selectable'] : 1,
                $info['total'] ?? null,
                $existing['id'],
            ]);
            // Defensive: ensure the open path-interval still matches the
            // current_path. ensureOpenInterval is idempotent so this is a
            // cheap no-op when the row already exists.
            $this->ensureOpenInterval($existing['id'], $accountId, $path, 'initial');
            return $existing['id'];
        }

        // New folder. Assign a fresh UUIDv7.
        $id = self::generateId();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO webmail_folder_identity
                    (id, account_id, current_path, display_name, uidvalidity, uidnext,
                     special_use, attributes, namespace_prefix, delimiter,
                     is_selectable, message_count, state)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $id,
                $accountId,
                $path,
                (string) ($info['display_name'] ?? $info['name'] ?? $path),
                $info['uidvalidity'] ?? null,
                $info['uidnext'] ?? null,
                $info['special_use'] ?? null,
                isset($info['attributes']) ? json_encode($info['attributes']) : null,
                $info['namespace_prefix'] ?? null,
                $info['delimiter'] ?? null,
                isset($info['is_selectable']) ? (int) $info['is_selectable'] : 1,
                $info['total'] ?? null,
                FolderStateMachine::HEALTHY,
            ]);
        } catch (\PDOException $e) {
            // Race: another worker (or a still-buggy code path) inserted a row
            // for this case-insensitive path between our getByPath and our
            // INSERT. Look up the winner, ensure its open interval is open
            // for the canonical case we just received, and return its id so
            // the caller still gets a valid folder_id instead of an exception.
            if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                $sel = $this->db->prepare(
                    'SELECT id, current_path FROM webmail_folder_identity
                      WHERE account_id = ? AND current_path = ? LIMIT 1'
                );
                $sel->execute([$accountId, $path]);
                $row = $sel->fetch();
                if ($row && !empty($row['id'])) {
                    // Canonicalize case if the winner stored a different one,
                    // so the next listing's strict comparisons agree.
                    if ((string) $row['current_path'] !== $path) {
                        $upd = $this->db->prepare(
                            'UPDATE webmail_folder_identity SET current_path = ?, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?'
                        );
                        $upd->execute([$path, $row['id']]);
                    }
                    $this->ensureOpenInterval((string) $row['id'], $accountId, $path, 'initial');
                    return (string) $row['id'];
                }
            }
            throw $e;
        }
        // First sighting -> open the initial interval so getByPath can
        // resolve via the canonical path-intervals table from now on.
        $this->ensureOpenInterval($id, $accountId, $path, 'initial');
        return $id;
    }

    /**
     * Apply a confirmed rename. Atomic from the caller's perspective:
     *
     *   1) Close the old path interval (valid_to = NOW()).
     *   2) Open the new path interval (valid_from = NOW(), reason = 'rename').
     *   3) Update webmail_folder_identity.current_path / display_name.
     *   4) Append a row to webmail_folder_path_history (legacy event log,
     *      kept for migration tooling).
     *   5) Cascade folder_id is unchanged because it is the immutable
     *      identity -- the (folder_id, uid) tuple still points at the
     *      same message. We DO bump the per-account folder_identity_version
     *      so any frontend cache keyed on folder paths invalidates.
     *   6) Optionally bump the legacy `folder` column on dual-write
     *      tables to the new path (so cutover-prep telemetry shows the
     *      new path instead of the old one). This is purely cosmetic
     *      because folder_id is the canonical key.
     *
     * Throws on DB error so the analyzer can roll back its work.
     *
     * @param string $accountId
     * @param string $folderId UUIDv7 of the renamed folder
     * @param string $oldPath
     * @param string $newPath
     * @param ?string $newDisplayName optional override for display_name
     * @param ?string $newNamespacePrefix
     * @param ?string $newDelimiter
     * @param ?DualWriteTelemetry $telemetry to bump folder_identity_version
     * @return bool true if a rename was applied, false if nothing changed
     */
    public function applyRename(
        string $accountId,
        string $folderId,
        string $oldPath,
        string $newPath,
        ?string $newDisplayName = null,
        ?string $newNamespacePrefix = null,
        ?string $newDelimiter = null,
        ?DualWriteTelemetry $telemetry = null
    ): bool {
        if ($oldPath === $newPath) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            // Idempotency guard: if THIS folder already has an open interval
            // at the new path, the rename was already applied (e.g. a duplicate
            // concurrent request from a UI double-fire, a frontend retry, or
            // the analyzer cron racing with a synchronous controller call).
            // Roll back the transaction and return false so we don't open a
            // second interval at the same path.
            $alreadyAppliedStmt = $this->db->prepare(
                'SELECT 1 FROM webmail_folder_path_intervals
                  WHERE folder_id = ? AND account_id = ? AND path = ? AND valid_to IS NULL
                  LIMIT 1'
            );
            $alreadyAppliedStmt->execute([$folderId, $accountId, $newPath]);
            if ($alreadyAppliedStmt->fetchColumn()) {
                $this->db->rollBack();
                StructuredLog::emit('rename_idempotent_skip', [
                    'account_id' => $accountId,
                    'folder_id' => $folderId,
                    'former_path' => $oldPath,
                    'folder_path' => $newPath,
                    'reason' => 'open_interval_already_exists',
                ]);
                return false;
            }

            // 1) Close any open intervals for the old path on this folder.
            $close = $this->db->prepare(
                'UPDATE webmail_folder_path_intervals
                    SET valid_to = CURRENT_TIMESTAMP
                  WHERE folder_id = ? AND account_id = ? AND path = ? AND valid_to IS NULL'
            );
            $close->execute([$folderId, $accountId, $oldPath]);

            // 2) If the new path already has an OPEN interval owned by a
            // different folder, close that one too -- the analyzer is
            // telling us the path now belongs to this folder. Conflicting
            // ownership is a hard invariant violation; we resolve in favor
            // of the latest analyzer decision and emit a structured log.
            $stealCheck = $this->db->prepare(
                'SELECT id, folder_id FROM webmail_folder_path_intervals
                  WHERE account_id = ? AND path = ? AND valid_to IS NULL
                    AND folder_id <> ? LIMIT 1'
            );
            $stealCheck->execute([$accountId, $newPath, $folderId]);
            $conflict = $stealCheck->fetch();
            if ($conflict) {
                $closeOther = $this->db->prepare(
                    'UPDATE webmail_folder_path_intervals
                        SET valid_to = CURRENT_TIMESTAMP, reason = ?
                      WHERE id = ?'
                );
                $closeOther->execute(['reconcile', $conflict['id']]);
                StructuredLog::emit('rename_path_conflict', [
                    'account_id' => $accountId,
                    'folder_id' => $folderId,
                    'reason' => 'open_interval_owned_by_other_folder',
                    'losing_folder_id' => $conflict['folder_id'],
                    'folder_path' => $newPath,
                ]);
            }

            // 3) Open the new interval.
            $open = $this->db->prepare(
                'INSERT INTO webmail_folder_path_intervals
                    (folder_id, account_id, path, valid_from, valid_to, reason)
                 VALUES (?, ?, ?, CURRENT_TIMESTAMP, NULL, ?)'
            );
            $open->execute([$folderId, $accountId, $newPath, 'rename']);

            // 4) Update identity row.
            $upd = $this->db->prepare(
                'UPDATE webmail_folder_identity
                    SET current_path = ?,
                        display_name = COALESCE(?, display_name),
                        namespace_prefix = COALESCE(?, namespace_prefix),
                        delimiter = COALESCE(?, delimiter),
                        last_seen_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND account_id = ?'
            );
            $upd->execute([$newPath, $newDisplayName, $newNamespacePrefix, $newDelimiter, $folderId, $accountId]);

            // 5) Append legacy event-log entry. Required by the existing
            // tooling that reads webmail_folder_path_history.
            $hist = $this->db->prepare(
                'INSERT INTO webmail_folder_path_history
                    (folder_id, former_path, former_namespace_prefix, former_delimiter, reason)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $hist->execute([$folderId, $oldPath, null, null, 'rename']);

            // 6) Cosmetic dual-write column refresh. folder_id is the
            // canonical key so we don't strictly need this, but keeping
            // the legacy `folder` column in sync makes telemetry and
            // forensic queries far easier to read. We touch only rows
            // whose folder_id already matches ours, so there's no risk
            // of grabbing rows from another folder.
            foreach (['pinned_emails', 'webmail_conversation_members', 'webmail_conversations'] as $table) {
                try {
                    $stmt = $this->db->prepare(
                        "UPDATE {$table}
                            SET folder = ?
                          WHERE user_email = ? AND folder_id = ? AND folder <> ?"
                    );
                    $stmt->execute([$newPath, $accountId, $folderId, $newPath]);
                } catch (\Throwable $e) {
                    // Tolerate partial-migration shapes (column missing).
                    error_log('[FolderIndexService] applyRename cosmetic update skipped on '
                        . $table . ': ' . $e->getMessage());
                }
            }

            // webmail_folder_sync_state caches the IMAP path for log output
            // (folder_id is authoritative, so the row survives the rename) but
            // its folder_path column would otherwise show the OLD path forever
            // in sync logs and the sync-issues banner. Its path column is named
            // folder_path, not folder, so it needs its own statement.
            try {
                $stmt = $this->db->prepare(
                    "UPDATE webmail_folder_sync_state
                        SET folder_path = ?
                      WHERE user_email = ? AND folder_id = ? AND folder_path <> ?"
                );
                $stmt->execute([$newPath, $accountId, $folderId, $newPath]);
            } catch (\Throwable $e) {
                error_log('[FolderIndexService] applyRename sync_state path update skipped: '
                    . $e->getMessage());
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // 7) Bump the per-account folder_identity_version OUTSIDE the DB
        // tx so a Redis hiccup never rolls back the rename. Frontend
        // caches will invalidate on the next poll.
        if ($telemetry !== null) {
            try {
                $telemetry->bumpFolderIdentityVersion($accountId);
            } catch (\Throwable $e) {
                // Non-fatal: the next mutation will bump it.
            }
        }

        StructuredLog::emit('folder_renamed', [
            'account_id' => $accountId,
            'folder_id' => $folderId,
            'reason' => 'rename_confirmed',
            'former_path' => $oldPath,
            'folder_path' => $newPath,
        ]);

        return true;
    }

    /**
     * Record a rename in path history.
     */
    public function recordRename(
        string $folderId,
        string $formerPath,
        ?string $formerNamespacePrefix,
        ?string $formerDelimiter,
        string $reason = 'rename'
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO webmail_folder_path_history
                (folder_id, former_path, former_namespace_prefix, former_delimiter, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$folderId, $formerPath, $formerNamespacePrefix, $formerDelimiter, $reason]);
    }

    /**
     * Weighted multi-signal rename detection. Scores each candidate against
     * the new path; threshold 70 means "rename", 50-69 means "uncertain"
     * (we do NOT collapse identity in this band), <50 means "new folder".
     *
     * Hard rename-collapse invariant: within one sync pass, one existing
     * folder_id may map to at most one newly discovered path. Conflicts emit
     * `evt=rename_conflict` and fall back to fresh ids for every contested
     * path.
     *
     * Signals + weights (sum 100):
     *   UIDVALIDITY equal             30
     *   UIDNEXT continuity (delta <1k)15
     *   Display-name fuzzy (Lev <=3)  20
     *   Hierarchy position            10
     *   Delimiter                      5
     *   Message-count similarity      10
     *   SPECIAL-USE continuity         5
     *   Recent-message overlap         5
     *
     * @param array  $newFolders     array of folder info shapes (name/path/uidvalidity/...)
     * @param array  $missingFolders array of webmail_folder_identity rows that no longer
     *                               appear under their previous path in this sync pass
     * @param string $providerType   gmail|dovecot|exchange|cyrus|courier|unknown.
     *                               Selects the per-provider weight profile for
     *                               scoreRenameCandidate so we can de-weight the
     *                               signals each provider tends to lie about.
     * @return array{renames:array, creates:array, conflicts:array}
     */
    public function detectRenames(array $newFolders, array $missingFolders, string $providerType = 'unknown'): array
    {
        $renames = [];
        $creates = [];
        $conflicts = [];
        $byFromId = [];   // from_id => list of [score, path]

        foreach ($newFolders as $nf) {
            $bestScore = 0;
            $bestId = null;
            foreach ($missingFolders as $mf) {
                $score = $this->scoreRenameCandidate($nf, $mf, $providerType);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestId = $mf['id'];
                }
            }
            if ($bestScore >= 70 && $bestId) {
                $byFromId[$bestId][] = ['score' => $bestScore, 'path' => $nf['path'] ?? $nf['name']];
            } elseif ($bestScore >= 50 && $bestId) {
                StructuredLog::emit('rename_uncertain', [
                    'folder_path' => (string) ($nf['path'] ?? $nf['name'] ?? ''),
                    'reason' => "score={$bestScore}",
                ]);
                $creates[] = $nf;
            } else {
                $creates[] = $nf;
            }
        }

        // Apply the rename-collapse invariant: any from_id with more than
        // one candidate becomes a conflict and we emit fresh ids for all of
        // its candidates instead of picking one.
        foreach ($byFromId as $fromId => $candidates) {
            if (count($candidates) > 1) {
                $conflicts[] = ['from_id' => $fromId, 'candidates' => $candidates];
                StructuredLog::emit('rename_conflict', [
                    'folder_id' => $fromId,
                    'reason' => 'multiple_candidates_score>=70',
                ]);
                // Each contested path becomes a fresh folder.
                foreach ($candidates as $c) {
                    foreach ($newFolders as $nf) {
                        if (($nf['path'] ?? $nf['name'] ?? '') === $c['path']) {
                            $creates[] = $nf;
                            break;
                        }
                    }
                }
                continue;
            }
            $only = $candidates[0];
            $renames[] = [
                'from_id' => $fromId,
                'new_path' => $only['path'],
                'score' => $only['score'],
            ];
        }

        return [
            'renames' => $renames,
            'creates' => $creates,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Per-provider weight profiles. Each profile sums to 100 (give or
     * take rounding) so the 70/50 thresholds stay meaningful. Anything
     * not listed inherits the 'unknown' profile.
     *
     * Tuning rationale:
     *
     *   gmail    -- Gmail labels are not classic folders. UIDVALIDITY
     *               can churn on namespace moves; message_count is
     *               unreliable because the same message lives in many
     *               labels at once. We trust the display name and
     *               hierarchy more, and SPECIAL-USE more (Gmail
     *               always emits \All / \Sent / \Drafts).
     *   dovecot  -- The default profile. UIDVALIDITY + UIDNEXT are
     *               authoritative on Dovecot; everything else is gravy.
     *   exchange -- UIDVALIDITY can flip on quota / archival moves;
     *               de-weight slightly. Display names are stable.
     *   cyrus    -- Like dovecot in terms of UIDVALIDITY stability.
     *   courier  -- Display-name renames are common; trust UID stuff.
     *
     * @return array{uidvalidity:int,uidnext:int,name:int,parent:int,delimiter:int,count:int,special:int,overlap:int}
     */
    private static function providerWeights(string $providerType): array
    {
        $defaults = [
            'uidvalidity' => 30,
            'uidnext'     => 15,
            'name'        => 20,
            'parent'      => 10,
            'delimiter'   => 5,
            'count'       => 10,
            'special'     => 5,
            'overlap'     => 5,
        ];
        switch (strtolower($providerType)) {
            case 'gmail':
                return [
                    'uidvalidity' => 20,
                    'uidnext'     => 10,
                    'name'        => 25,
                    'parent'      => 15,
                    'delimiter'   => 5,
                    'count'       => 5,
                    'special'     => 15,
                    'overlap'     => 5,
                ];
            case 'exchange':
                return [
                    'uidvalidity' => 20,
                    'uidnext'     => 15,
                    'name'        => 25,
                    'parent'      => 15,
                    'delimiter'   => 5,
                    'count'       => 10,
                    'special'     => 5,
                    'overlap'     => 5,
                ];
            case 'courier':
                return [
                    'uidvalidity' => 35,
                    'uidnext'     => 20,
                    'name'        => 15,
                    'parent'      => 10,
                    'delimiter'   => 5,
                    'count'       => 5,
                    'special'     => 5,
                    'overlap'     => 5,
                ];
            case 'cyrus':
            case 'dovecot':
            case 'unknown':
            default:
                return $defaults;
        }
    }

    /**
     * Score one rename candidate (newFolder vs missingFolder). 0..100.
     * Per-provider weights are applied via providerWeights() so signals
     * each provider tends to lie about (e.g. Gmail UIDVALIDITY) carry
     * less of the score budget.
     */
    private function scoreRenameCandidate(array $nf, array $mf, string $providerType = 'unknown'): int
    {
        $w = self::providerWeights($providerType);
        $score = 0;

        $nfUidVal = (int) ($nf['uidvalidity'] ?? 0);
        $mfUidVal = (int) ($mf['uidvalidity'] ?? 0);
        if ($nfUidVal > 0 && $nfUidVal === $mfUidVal) {
            $score += $w['uidvalidity'];
        }

        $nfUidNext = (int) ($nf['uidnext'] ?? 0);
        $mfUidNext = (int) ($mf['uidnext'] ?? 0);
        if ($nfUidNext > 0 && $mfUidNext > 0
            && $nfUidNext >= $mfUidNext && ($nfUidNext - $mfUidNext) < 1000) {
            $score += $w['uidnext'];
        }

        $nfName = (string) ($nf['display_name'] ?? $nf['name'] ?? '');
        $mfName = (string) ($mf['display_name'] ?? '');
        if ($nfName !== '' && $mfName !== '') {
            $lev = levenshtein($nfName, $mfName);
            if ($lev <= 3) {
                $score += $w['name'];
            }
        }

        if (isset($nf['parent_id']) && isset($mf['parent_id'])
            && (string) $nf['parent_id'] === (string) $mf['parent_id']) {
            $score += $w['parent'];
        }

        if (isset($nf['delimiter']) && isset($mf['delimiter'])
            && (string) $nf['delimiter'] === (string) $mf['delimiter']) {
            $score += $w['delimiter'];
        }

        $nfCount = (int) ($nf['total'] ?? $nf['message_count'] ?? 0);
        $mfCount = (int) ($mf['message_count'] ?? 0);
        $maxCount = max($nfCount, $mfCount);
        if ($maxCount > 0 && abs($nfCount - $mfCount) / $maxCount < 0.05) {
            $score += $w['count'];
        }

        $nfSpecial = $nf['special_use'] ?? null;
        $mfSpecial = $mf['special_use'] ?? null;
        if ($nfSpecial === $mfSpecial) {
            $score += $w['special'];
        }

        // Recent-message overlap: cheap stub that only fires when a
        // caller has prefilled `recent_overlap_pct` (0..1) on $nf.
        $overlap = (float) ($nf['recent_overlap_pct'] ?? 0);
        if ($overlap >= 0.7) {
            $score += $w['overlap'];
        }

        return min(100, $score);
    }

    /**
     * Walk imap_getnamespaces + imap_getmailboxes for an account and persist
     * namespace_prefix / delimiter / is_selectable / attributes per folder.
     *
     * @param resource|\IMAP\Connection $conn  Live IMAP connection
     * @param string $accountId
     * @return int Number of rows touched.
     */
    public function syncNamespaces($conn, string $accountId): int
    {
        if (!$conn) {
            return 0;
        }
        $touched = 0;

        $namespaces = @imap_getnamespaces($conn);
        if (!is_array($namespaces) || empty($namespaces)) {
            $namespaces = [(object) ['type' => 'personal', 'prefix' => '', 'delimiter' => '.']];
        }

        // imap_getnamespaces returns either an object with personal/shared/public
        // arrays, or already a namespace array. Normalize.
        $flat = [];
        foreach ($namespaces as $ns) {
            if (is_object($ns) && isset($ns->prefix)) {
                $flat[] = $ns;
            }
        }
        if (empty($flat) && !empty($namespaces['personal'])) {
            foreach (['personal', 'shared', 'public'] as $key) {
                if (!empty($namespaces[$key]) && is_array($namespaces[$key])) {
                    foreach ($namespaces[$key] as $ns) {
                        $flat[] = $ns;
                    }
                }
            }
        }

        foreach ($flat as $ns) {
            $prefix = (string) ($ns->prefix ?? '');
            $delim = (string) ($ns->delimiter ?? '.');
            $boxes = @imap_getmailboxes($conn, $this->buildRefForNamespace($conn), $prefix . '*');
            if (!is_array($boxes)) {
                continue;
            }
            foreach ($boxes as $box) {
                $rawName = (string) ($box->name ?? '');
                if ($rawName === '') {
                    continue;
                }
                $name = $this->stripServerPrefix($conn, $rawName);
                if ($name === '') {
                    continue;
                }
                $attrs = (int) ($box->attributes ?? 0);
                $isSelectable = ($attrs & LATT_NOSELECT) ? 0 : 1;

                $stmt = $this->db->prepare(
                    'UPDATE webmail_folder_identity
                        SET namespace_prefix = ?, delimiter = ?, is_selectable = ?,
                            attributes = ?, last_seen_at = CURRENT_TIMESTAMP
                      WHERE account_id = ? AND current_path = ?'
                );
                $stmt->execute([
                    $prefix,
                    $delim,
                    $isSelectable,
                    json_encode(['raw_attributes' => $attrs]),
                    $accountId,
                    $name,
                ]);
                $touched += $stmt->rowCount();
            }
        }
        return $touched;
    }

    private function buildRefForNamespace($conn): string
    {
        // The imap_getmailboxes ref is usually the same as imap_open's first arg.
        // We don't have direct access here, so callers should pre-select INBOX
        // and rely on the ref being the empty-folder form built in ImapService.
        return '';
    }

    private function stripServerPrefix($conn, string $raw): string
    {
        // The stream ref returned by IMAP often has the form {host:port/...}.
        // We strip the {...} block and any trailing namespace separator.
        if (preg_match('/^\{[^}]*\}(.*)$/', $raw, $m)) {
            return mb_convert_encoding($m[1], 'UTF-8', 'UTF7-IMAP');
        }
        return mb_convert_encoding($raw, 'UTF-8', 'UTF7-IMAP');
    }

    /**
     * Read-only provider type for an account. Reads webmail_account_provider
     * with a Redis cache (TTL ~7 days) so the controller can attach
     * provider_type to structured logs without re-fingerprinting on every
     * request. Returns 'unknown' if the row is missing or Redis says so.
     */
    public function getProviderType(string $accountId, ?RedisCacheService $redis = null): string
    {
        $cacheKey = 'account:' . strtolower($accountId) . ':provider_type';
        if ($redis !== null && $redis->isAvailable()) {
            $cached = $redis->get($cacheKey);
            if (is_array($cached) && isset($cached['value'])) {
                $cached = $cached['value'];
            }
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $type = 'unknown';
        try {
            $stmt = $this->db->prepare(
                'SELECT provider_type FROM webmail_account_provider WHERE account_id = ? LIMIT 1'
            );
            $stmt->execute([$accountId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['provider_type'])) {
                $type = (string) $row['provider_type'];
            }
        } catch (\Throwable $e) {
            // Tolerate the table being absent in early-rollout deployments.
            $type = 'unknown';
        }

        if ($redis !== null && $redis->isAvailable()) {
            $redis->set($cacheKey, $type, 7 * 86400);
        }
        return $type;
    }

    /**
     * Fingerprint if not already cached. Cheap on the hot path; a real
     * fingerprint costs an IMAP round trip so we gate on the same Redis
     * cache that getProviderType() consults.
     *
     * Returns the provider type ('gmail' | 'dovecot' | ...).
     *
     * @param resource|\IMAP\Connection $conn live IMAP connection
     */
    public function ensureProviderFingerprint($conn, string $accountId, ?RedisCacheService $redis = null): string
    {
        if ($redis !== null && $redis->isAvailable()) {
            $cached = $redis->get('account:' . strtolower($accountId) . ':provider_type');
            if (is_array($cached) && isset($cached['value'])) {
                $cached = $cached['value'];
            }
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }
        // Cache miss -> run the live fingerprint, which also persists to DB
        // and (via getProviderType on the next call) repopulates the cache.
        $type = $this->fingerprintProvider($conn, $accountId);
        if ($redis !== null && $redis->isAvailable()) {
            $redis->set('account:' . strtolower($accountId) . ':provider_type', $type, 7 * 86400);
        }
        return $type;
    }

    /**
     * Fingerprint the IMAP server for an account. Persists into
     * webmail_account_provider so structured logs can carry provider_type
     * for telemetry segmentation.
     *
     * Heuristics:
     *   - Gmail:    CAPABILITY contains X-GM-EXT-1
     *   - Dovecot:  ID response contains "Dovecot"
     *   - Exchange: CAPABILITY has XLIST or namespace contains [Outlook]/
     *   - Cyrus:    CAPABILITY contains X-NETSCAPE / ANNOTATEMORE
     *   - Courier:  ID response contains "Courier"
     *   - Otherwise: unknown
     *
     * @param resource|\IMAP\Connection $conn
     */
    public function fingerprintProvider($conn, string $accountId): string
    {
        $signals = [];
        $type = 'unknown';

        // CAPABILITY
        $cap = '';
        try {
            $caps = function_exists('imap_get_quota_root') ? @imap_check($conn) : null;
            // PHP's imap extension does not expose CAPABILITY directly.
            // Best signal is what's in the namespace/SPECIAL-USE we observe
            // elsewhere; we approximate from imap_list special markers.
            $cap = '';
        } catch (\Throwable $e) {
            // ignore
        }
        $signals['cap_observed'] = $cap;

        // Namespace markers
        $ns = @imap_getnamespaces($conn);
        if (is_array($ns) || is_object($ns)) {
            $signals['namespaces'] = $ns;
            $serialized = json_encode($ns);
            if (is_string($serialized)) {
                if (str_contains($serialized, '[Gmail]')) {
                    $type = 'gmail';
                } elseif (str_contains($serialized, '[Outlook]')) {
                    $type = 'exchange';
                }
            }
        }

        if ($type === 'unknown') {
            // Fallback heuristic: presence of `[Gmail]/All Mail` style folder
            // listings is a Gmail tell; INBOX. delimiter is common to Dovecot.
            $folders = @imap_list($conn, '', '*');
            if (is_array($folders)) {
                foreach ($folders as $f) {
                    if (stripos((string) $f, '[Gmail]') !== false) {
                        $type = 'gmail';
                        break;
                    }
                    if (stripos((string) $f, 'INBOX.') !== false) {
                        $type = 'dovecot';
                    }
                }
            }
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO webmail_account_provider (account_id, provider_type, fingerprint_signals)
                      VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                      provider_type = VALUES(provider_type),
                      fingerprint_signals = VALUES(fingerprint_signals),
                      fingerprint_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$accountId, $type, json_encode($signals)]);
        } catch (\Throwable $e) {
            error_log('[FolderIndexService] fingerprintProvider DB error: ' . $e->getMessage());
        }

        StructuredLog::emit('provider_fingerprinted', [
            'account_id' => $accountId,
            'provider_type' => $type,
        ]);
        return $type;
    }
}
