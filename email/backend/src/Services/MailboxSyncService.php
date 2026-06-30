<?php

declare(strict_types=1);

namespace Webmail\Services;

use PDO;
use Webmail\Core\Database;

/**
 * MailboxSyncService
 *
 * Phase 2 of "Finish Gmail-like": the missing faithful IMAP -> DB mirror.
 *
 * Today the mirror in webmail_conversation_members is populated LAZILY
 * (only when the user opens a folder via the request path) and INBOX is
 * watched in real time by the Node IDLE worker. Other folders silently
 * drift - that is the root cause of the stale-UID "imap_msgno(N)=0" log
 * lines and the half-built DB-as-truth bugs.
 *
 * This service runs in the background (cron/sync-mailbox.php) and is the
 * single owner of webmail_folder_sync_state. It does NOT touch IMAP
 * directly - the caller (the cron) is responsible for opening / closing
 * the connection and selecting the folder before each phase call. That
 * keeps this class testable against a mock and lets the cron pool a
 * single IMAP connection across many folders for one account.
 *
 * Four phases, each idempotent and safe to retry:
 *
 *   1. initialSync()       - walks UIDs into the mirror in batches. Sets
 *                            status='initial_syncing' until it reaches
 *                            UIDNEXT, then 'synced'.
 *   2. incrementalSync()   - CHANGEDSINCE flag delta + new UIDs above
 *                            highest_uid. Cheap; runs every pass.
 *   3. expungeReconcile()  - UID SEARCH ALL vs mirror rows; deletes
 *                            mirror rows whose UID no longer exists on
 *                            IMAP. Kills the stale-UID class of bugs.
 *   4. handleUidvalidityReset() - server-side UIDVALIDITY changed (rare:
 *                            folder rebuilt, mailbox restored). Purges
 *                            the folder's mirror rows and resets the
 *                            sync_state so the next pass restarts at 0.
 *
 * Outbox-conflict guard: every flag write goes through
 * OutboxService::pendingFlagUids() so in-flight local writes win over
 * stale IMAP echoes. This mirrors the existing read-path overlay rule
 * in MailboxController::reconcileSeen.
 */
final class MailboxSyncService
{
    public const INITIAL_BATCH_SIZE     = 200;
    public const FAILURE_BACKOFF_BASE   = 60;     // seconds
    public const FAILURE_BACKOFF_MAX    = 3600;   // seconds
    public const EXPUNGE_INTERVAL_SECONDS = 1800; // run expunge sweep at most every 30m per folder

    // Real-time new-mail pushes are fanned out one MESSAGE_NEW per message. A
    // healthy steady-state pass sees 1-2 new messages; a batch bigger than this
    // means the folder fell behind (creds expired, sync paused) and is catching
    // up. We suppress the per-message fan-out for catch-up batches so a single
    // recovered folder can't storm every device with hundreds of pushes.
    public const REALTIME_PUSH_MAX_BATCH = 5;

    private array $config;
    private PDO $db;
    private ConversationService $conv;
    private OutboxService $outbox;
    private FolderIndexService $folderIndex;
    private ?RedisCacheService $cache;

    public function __construct(
        array $config,
        ?PDO $db = null,
        ?ConversationService $conv = null,
        ?OutboxService $outbox = null,
        ?FolderIndexService $folderIndex = null,
        ?RedisCacheService $cache = null
    ) {
        $this->config      = $config;
        $this->db          = $db ?? Database::getConnection($config);
        $this->conv        = $conv ?? new ConversationService($config);
        $this->outbox      = $outbox ?? new OutboxService($config);
        $this->folderIndex = $folderIndex ?? new FolderIndexService($config);
        $this->cache       = $cache;

        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTableExists());
    }

    /**
     * Idempotent fallback if migration 181 has not run yet. Matches the
     * schema in migrations/181_folder_sync_state.sql verbatim.
     */
    private function ensureTableExists(): void
    {
        try {
            // Tombstone trail: feeds delta()'s deletedUids array. See
            // migration 182 for the canonical schema; this is the
            // belt-and-suspenders mirror.
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS webmail_folder_tombstones (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(191) NOT NULL,
                    folder_id CHAR(36) NOT NULL,
                    uid INT UNSIGNED NOT NULL,
                    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    source ENUM('local_delete','imap_expunge','uidvalidity_reset') NOT NULL DEFAULT 'local_delete',
                    INDEX idx_folder_recent (user_email, folder_id, deleted_at),
                    INDEX idx_purge (deleted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS webmail_folder_sync_state (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(191) NOT NULL,
                    folder_id CHAR(36) NOT NULL,
                    account_email VARCHAR(191) NOT NULL,
                    folder_path VARCHAR(255) NOT NULL,
                    status ENUM('pending','initial_syncing','synced','uidvalidity_reset','failed') NOT NULL DEFAULT 'pending',
                    uidvalidity BIGINT UNSIGNED NULL,
                    highest_uid INT UNSIGNED NOT NULL DEFAULT 0,
                    highest_modseq BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    message_count INT UNSIGNED NOT NULL DEFAULT 0,
                    last_full_sync_at DATETIME NULL,
                    last_incremental_sync_at DATETIME NULL,
                    last_expunge_sync_at DATETIME NULL,
                    last_error TEXT NULL,
                    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    next_attempt_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_user_folder (user_email, folder_id),
                    INDEX idx_status_next (status, next_attempt_at),
                    INDEX idx_account_status (account_email, status),
                    INDEX idx_user (user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] ensureTableExists: ' . $e->getMessage());
        }
    }

    /** Expose the underlying DB connection - test-only. */
    public function setDb(PDO $db): void
    {
        $this->db = $db;
    }

    // ========================================================================
    // STATE CRUD
    // ========================================================================

    /**
     * Upsert a sync-state row for a (user, account, folder) tuple. Called
     * by the cron once per folder discovered in listFolders so the cron
     * pickup query has something to find. Idempotent - second call on
     * the same row is a no-op (the timestamp updates but progress
     * counters are preserved).
     */
    public function registerFolder(
        string $userEmail,
        string $accountEmail,
        string $folderId,
        string $folderPath
    ): void {
        $userEmail = strtolower($userEmail);
        $accountEmail = strtolower($accountEmail);

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO webmail_folder_sync_state
                    (user_email, account_email, folder_id, folder_path, status)
                 VALUES (?, ?, ?, ?, 'pending')
                 ON DUPLICATE KEY UPDATE
                    account_email = VALUES(account_email),
                    folder_path = VALUES(folder_path),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([$userEmail, $accountEmail, $folderId, $folderPath]);
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] registerFolder ' . $userEmail . '/' . $folderPath . ': ' . $e->getMessage());
        }
    }

    /**
     * Fetch the sync state for a folder. Returns the raw row or null.
     */
    public function getState(string $userEmail, string $folderId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM webmail_folder_sync_state
              WHERE user_email = ? AND folder_id = ?
              LIMIT 1"
        );
        $stmt->execute([strtolower($userEmail), $folderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Whether the folder has been fully mirrored and is safe to read from
     * the DB instead of live IMAP. Phase 3 read-switch gate.
     */
    public function isFolderSynced(string $userEmail, string $folderId): bool
    {
        $state = $this->getState($userEmail, $folderId);
        return $state !== null
            && (string)($state['status'] ?? '') === 'synced';
    }

    /**
     * Cron picker: claim N (user, account, folder) tuples that are
     * eligible to run this pass. "Eligible" means status in (pending,
     * initial_syncing, synced, uidvalidity_reset) AND next_attempt_at is
     * null or due. Failed rows are excluded unless their backoff has
     * elapsed.
     *
     * Returns rows sorted by status priority so initial sync drains
     * before incremental noise: pending/uidvalidity_reset/initial_syncing
     * are catch-up work, synced is steady-state.
     */
    public function claimDue(int $limit = 50, ?string $userEmail = null, ?string $accountEmail = null, bool $syncedOnly = false): array
    {
        // Two claim modes:
        //  - Default: every lifecycle status, prioritised so catch-up work
        //    (uidvalidity_reset > pending > initial_syncing > failed) runs
        //    before steady-state synced folders.
        //  - syncedOnly: ONLY already-synced folders, stalest incremental
        //    first. Used by the dedicated 1-minute incremental tick so
        //    new-mail detection on active mailboxes can NEVER be starved by a
        //    large initial-sync backlog. In the default order 'synced' is last,
        //    so a big pending/initial_syncing backlog monopolises every pass
        //    and synced folders can go hours/days without an incremental pass
        //    (= no MESSAGE_NEW = no email push). This mode sidesteps that.
        if ($syncedOnly) {
            $sql = "SELECT * FROM webmail_folder_sync_state
                     WHERE status = 'synced'
                       AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())";
        } else {
            $sql = "SELECT * FROM webmail_folder_sync_state
                     WHERE (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                       AND status IN ('pending','initial_syncing','synced','uidvalidity_reset','failed')";
        }
        $params = [];
        if ($userEmail !== null) {
            $sql .= ' AND user_email = ?';
            $params[] = strtolower($userEmail);
        }
        if ($accountEmail !== null) {
            $sql .= ' AND account_email = ?';
            $params[] = strtolower($accountEmail);
        }
        if ($syncedOnly) {
            // Stalest incremental first (MySQL sorts NULL before values in ASC),
            // so every active folder gets a fair, prompt refresh in rotation.
            $sql .= " ORDER BY last_incremental_sync_at ASC
                      LIMIT " . max(1, $limit);
        } else {
            $sql .= " ORDER BY FIELD(status,'uidvalidity_reset','pending','initial_syncing','failed','synced'),
                              updated_at ASC
                      LIMIT " . max(1, $limit);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ========================================================================
    // PHASE 1: INITIAL SYNC
    // ========================================================================

    /**
     * Walk one batch of UIDs from $state['highest_uid'] upward and persist
     * envelopes into webmail_conversation_members. Returns the number of
     * new envelopes persisted in this pass.
     *
     * The caller is responsible for opening + selecting the folder on
     * $imap BEFORE this method; that keeps this class IMAP-mock-friendly.
     *
     * Sets status to 'initial_syncing' on first work and 'synced' once
     * uidnext is reached.
     */
    public function initialSyncBatch(\Webmail\Services\ImapService $imap, array $state, int $batchSize = self::INITIAL_BATCH_SIZE): int
    {
        $userEmail = (string)$state['user_email'];
        $folderId  = (string)$state['folder_id'];
        $folder    = (string)$state['folder_path'];
        $sinceUid  = (int)$state['highest_uid'];

        // The cron has already SELECTed the folder via selectFolderWithCondstore,
        // so $imap->getMessagesSince can ride on the same connection.
        $result = $imap->getMessagesSince($folder, $sinceUid, $batchSize);
        $messages = $result['messages'] ?? [];
        $uidnext  = (int)($result['uidnext'] ?? 0);
        $uidvalidity = (int)($result['uidvalidity'] ?? 0);
        $total    = (int)($result['total'] ?? 0);

        if ($uidvalidity > 0) {
            $stateUidvalidity = $state['uidvalidity'] !== null ? (int)$state['uidvalidity'] : 0;
            if ($stateUidvalidity > 0 && $stateUidvalidity !== $uidvalidity) {
                // The folder was rebuilt server-side. Stop this pass; the
                // caller decides whether to call handleUidvalidityReset().
                $this->markUidvalidityReset($userEmail, $folderId, $uidvalidity);
                return 0;
            }
        }

        if (empty($messages)) {
            // Either folder is empty OR we have already mirrored everything.
            $this->finishInitialIfCaughtUp($userEmail, $folderId, $sinceUid, $uidnext, $uidvalidity, $total);
            return 0;
        }

        $persisted = $this->persistEnvelopes($userEmail, $folder, $messages);

        // persistEnvelopes returns -1 when the DB write threw (e.g. the
        // connection dropped mid-batch -- "MySQL server has gone away" shows
        // up on these long passes). Do NOT advance the high-water mark or
        // touch status in that case: advancing would skip this UID range
        // forever and freeze the mirror short of the folder's real size (the
        // "Inbox shows 81 of 358" bug). Leave the row untouched so the next
        // pass retries the exact same range. (A return of 0 is fine -- that
        // just means everything in the batch was already mirrored.)
        if ($persisted < 0) {
            return 0;
        }

        // Advance high-water mark to the highest UID we just ingested.
        $maxUidThisBatch = 0;
        foreach ($messages as $m) {
            $u = (int)($m['uid'] ?? 0);
            if ($u > $maxUidThisBatch) $maxUidThisBatch = $u;
        }
        $newHighestUid = max($sinceUid, $maxUidThisBatch);

        // Completion = reaching UIDNEXT, the only reliable end-of-folder
        // signal. The old heuristic also accepted "this batch returned fewer
        // than batchSize rows" as done -- but a batch can be short because
        // imap_fetch_overview failed for some UIDs (transient IMAP/DB
        // errors), not because we hit the end. That shortcut is what marked a
        // folder 'synced' while its mirror held only a fraction of the
        // messages. We only fall back to the short-batch heuristic when the
        // server gave us no usable UIDNEXT to aim at.
        $caughtUp = $uidnext > 0
            ? ($newHighestUid + 1 >= $uidnext)
            : (count($messages) < $batchSize);

        $this->advanceProgress(
            $userEmail,
            $folderId,
            $newHighestUid,
            $uidvalidity,
            $total,
            $caughtUp ? 'synced' : 'initial_syncing',
            'full'
        );

        return $persisted;
    }

    /**
     * Edge case: folder is empty or sinceUid is already at UIDNEXT-1.
     * If we believed we still had work to do, mark it done.
     */
    private function finishInitialIfCaughtUp(string $userEmail, string $folderId, int $sinceUid, int $uidnext, int $uidvalidity, int $total): void
    {
        if ($uidnext > 0 && $sinceUid + 1 >= $uidnext) {
            $this->advanceProgress($userEmail, $folderId, $sinceUid, $uidvalidity, $total, 'synced', 'full');
        }
    }

    // ========================================================================
    // PHASE 2: INCREMENTAL SYNC
    // ========================================================================

    /**
     * Apply CHANGEDSINCE flag delta and pick up any new UIDs above
     * highest_uid. The caller selects the folder first (so we have
     * HIGHESTMODSEQ to pass) and threads the result through.
     *
     * Returns ['flag_changes' => N, 'new_messages' => N].
     */
    public function incrementalSync(\Webmail\Services\ImapService $imap, array $state): array
    {
        $userEmail    = (string)$state['user_email'];
        $accountEmail = (string)($state['account_email'] ?? $userEmail);
        $folderId     = (string)$state['folder_id'];
        $folder       = (string)$state['folder_path'];
        $sinceUid     = (int)$state['highest_uid'];
        $sinceModseq  = (int)$state['highest_modseq'];

        $result = [
            'flag_changes' => 0,
            'new_messages' => 0,
        ];

        // --- Flag delta ---------------------------------------------------
        if ($sinceModseq > 0) {
            try {
                $delta = $imap->fetchFlagChangesSince($folder, $sinceModseq, $sinceUid);
                $changes = $delta['changes'] ?? [];
                $newHighestModseq = (int)($delta['highest_modseq'] ?? $sinceModseq);

                if (!empty($changes)) {
                    $changes = $this->filterAgainstOutbox($userEmail, $folderId, $changes);
                    $this->conv->updateMembersFlagsBatch($userEmail, $folder, $changes);
                    $result['flag_changes'] = count($changes);
                }

                if ($newHighestModseq > $sinceModseq) {
                    $this->writeHighestModseq($userEmail, $folderId, $newHighestModseq);
                }
            } catch (\Throwable $e) {
                error_log('[MailboxSyncService] flag-delta ' . $folder . ': ' . $e->getMessage());
            }
        } else {
            // Seed highest_modseq from the SELECT (CONDSTORE) baseline so the
            // efficient CHANGEDSINCE flag-delta above engages on the NEXT pass
            // instead of being skipped forever. Without this, a folder that
            // started at modseq=0 never advances (the only writer lives inside
            // the sinceModseq>0 branch). Best-effort: getFolderSyncState() only
            // reports a modseq for CONDSTORE-aware SELECTs (OAuth today);
            // password/c-client SELECTs report 0, which is harmless now that the
            // mirror is a cache only (its is_seen is never served).
            try {
                $seedModseq = (int)($imap->getFolderSyncState()['highest_modseq'] ?? 0);
                if ($seedModseq > 0) {
                    $this->writeHighestModseq($userEmail, $folderId, $seedModseq);
                }
            } catch (\Throwable $e) {
                // Non-fatal: this only warms the mirror cache.
            }
        }

        // --- New messages above highest_uid ------------------------------
        try {
            $fetched = $imap->getMessagesSince($folder, $sinceUid, self::INITIAL_BATCH_SIZE);
            $messages = $fetched['messages'] ?? [];
            $uidnext  = (int)($fetched['uidnext'] ?? 0);
            $uidvalidity = (int)($fetched['uidvalidity'] ?? 0);
            $total    = (int)($fetched['total'] ?? 0);

            // UIDVALIDITY safety net here too: anything that reaches
            // incremental on a freshly-rebuilt folder must not advance
            // counters into the new universe.
            if ($uidvalidity > 0 && $state['uidvalidity'] !== null
                && (int)$state['uidvalidity'] !== $uidvalidity) {
                $this->markUidvalidityReset($userEmail, $folderId, $uidvalidity);
                return $result;
            }

            if (!empty($messages)) {
                $persisted = $this->persistEnvelopes($userEmail, $folder, $messages);
                // Hard DB write failure -> don't advance the watermark (we'd
                // skip these new UIDs permanently); retry on the next pass.
                if ($persisted < 0) {
                    return $result;
                }
                $result['new_messages'] = $persisted;

                $maxUidThisBatch = 0;
                foreach ($messages as $m) {
                    $u = (int)($m['uid'] ?? 0);
                    if ($u > $maxUidThisBatch) $maxUidThisBatch = $u;
                }
                $newHighestUid = max($sinceUid, $maxUidThisBatch);
                $this->advanceProgress($userEmail, $folderId, $newHighestUid, $uidvalidity, $total, 'synced', 'incremental');

                // Real-time fan-out: the mirror row + sync state are committed
                // above, so any client waking up on this event and hitting
                // /delta will already see the new UID. Best-effort: a Redis
                // hiccup must never affect sync correctness.
                //
                // Targets every FlowOne primary user who has linked this
                // account (NOT just the sync-state's user_email). The
                // sync-state's user_email is whatever was in the OAuth/
                // password registration row, which may differ from the
                // browser's logged-in primary user. Publishing to extra
                // channels with no subscribers is a no-op ([0/0 clients]).
                if ($this->cache && $persisted > 0) {
                    try {
                        // A catch-up batch (folder fell behind, now draining many
                        // UIDs at once) must NOT fan out one push per message, or
                        // every device gets stormed with hundreds of notifications
                        // (the "168 pushes after a 12-day gap" bug). For catch-up
                        // batches we publish only FOLDER_COUNTS, which refreshes the
                        // unread badge in the UI but never triggers a push.
                        $isCatchUp = count($messages) > self::REALTIME_PUSH_MAX_BATCH;
                        // Sent / Drafts / Outbox / Junk / Trash are NOT received
                        // mail: new UIDs there are the user's own outgoing copies
                        // (sending a message lands a copy in Sent, saving lands
                        // one in Drafts) or noise, and must never raise a "new
                        // email" push. Only INBOX + custom folders notify. The
                        // unread badge still stays correct via FOLDER_COUNTS below.
                        $suppressPush = self::isSystemNonInboxFolder($folder);
                        $subscribers = $this->resolveSubscribers($accountEmail, $userEmail);
                        foreach ($subscribers as $subscriber) {
                            if (!$isCatchUp && !$suppressPush) {
                                foreach ($messages as $m) {
                                    $uid = (int)($m['uid'] ?? 0);
                                    if ($uid <= 0) continue;
                                    // Dedup across concurrent passes: the */5
                                    // cache-warmer and the per-minute incremental
                                    // tick both fire on minute marks divisible by
                                    // 5 and can detect the same fresh UID before
                                    // highest_uid advances. Claim each (folder,uid)
                                    // once so only the first pass publishes, or the
                                    // user gets the same notification 2-3x.
                                    if (!$this->cache->claimNewMailPublish($subscriber, $folder, $uid)) {
                                        continue;
                                    }
                                    $this->cache->publishNewMessage($subscriber, $folder, $uid, [
                                        // formatMessageOverview() returns `from`
                                        // as a [{name,email},...] array; pushing
                                        // it raw makes the device notification read
                                        // "[object Object]". Send a plain display
                                        // string instead.
                                        'from'    => $this->displayFrom($m),
                                        'subject' => $m['subject'] ?? null,
                                        'date'    => $m['date'] ?? null,
                                    ]);
                                }
                            }
                            // Folder-counts ping so badges, All-Mail, and clients
                            // not currently viewing this folder still pick up the
                            // change (UIDNEXT advance is what the frontend keys on).
                            if ($uidnext > 0) {
                                $this->cache->publishFolderCounts(
                                    $subscriber,
                                    $folder,
                                    $total,
                                    0,
                                    $uidnext,
                                    $uidvalidity
                                );
                            }
                        }
                        if ($isCatchUp) {
                            error_log(sprintf(
                                '[MailboxSyncService] catch-up: suppressed %d per-message pushes for %s (%s); FOLDER_COUNTS only',
                                count($messages), $folder, $userEmail
                            ));
                        } elseif ($suppressPush) {
                            error_log(sprintf(
                                '[MailboxSyncService] non-inbox folder %s (%s): %d new UID(s) synced, no push (FOLDER_COUNTS only)',
                                $folder, $userEmail, count($messages)
                            ));
                        }
                    } catch (\Throwable $e) {
                        error_log('[MailboxSyncService] realtime publish ' . $folder . ': ' . $e->getMessage());
                    }
                }
            } else {
                // No new mail but we still need to bump the timestamp +
                // refresh message_count from the server.
                $this->advanceProgress($userEmail, $folderId, $sinceUid, $uidvalidity, $total, 'synced', 'incremental');
            }
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] new-mail ' . $folder . ': ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Classify a folder as a system, NON-receiving mailbox: Sent, Drafts,
     * Outbox, Junk/Spam, or Trash/Deleted. New UIDs appearing in these folders
     * are the user's own outgoing copies or noise — they must never raise a
     * "new email" push. Only genuinely *received* mail (INBOX and custom
     * folders) does.
     *
     * Strategy: trust the IMAP SPECIAL-USE attribute first (authoritative,
     * locale-independent), then fall back to a leaf-name heuristic for the many
     * providers (e.g. Dovecot) that don't advertise SPECIAL-USE. \All / \Archive
     * / \Flagged are deliberately NOT suppressed (those still hold received mail).
     *
     * Pure + static so it is unit-testable without a live IMAP/DB connection.
     */
    public static function isSystemNonInboxFolder(string $folderPath, ?string $specialUse = null): bool
    {
        $su = strtolower(trim((string)$specialUse));
        if ($su !== '') {
            foreach (['\\sent', '\\drafts', '\\junk', '\\trash'] as $role) {
                if (str_contains($su, $role)) {
                    return true;
                }
            }
        }

        // Compare only the leaf segment: providers nest system folders under
        // "INBOX." (Dovecot) or "[Gmail]/" (Gmail), so the last path component
        // is what identifies the role.
        $leaf = $folderPath;
        foreach (['/', '.'] as $delim) {
            $pos = strrpos($leaf, $delim);
            if ($pos !== false) {
                $leaf = substr($leaf, $pos + 1);
            }
        }
        $leaf = strtolower(trim($leaf));

        if ($leaf === '' || $leaf === 'inbox') {
            return false;
        }

        static $systemNames = [
            'sent', 'sent mail', 'sent items', 'sent messages',
            'outbox',
            'drafts', 'draft',
            'junk', 'junk email', 'junk e-mail', 'spam', 'bulk', 'bulk mail',
            'trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'recycle bin',
        ];
        return in_array($leaf, $systemNames, true);
    }

    /**
     * Reduce a formatMessageOverview() row to a single human-readable sender
     * string for a push notification title. Prefers the parsed display name,
     * then the email, then the first entry of the structured `from` array.
     * Returns null when nothing usable is present so the consumer can fall
     * back to "Unknown sender".
     */
    private function displayFrom(array $m): ?string
    {
        $name = trim((string)($m['from_name'] ?? ''));
        if ($name !== '') return $name;

        $email = trim((string)($m['from_email'] ?? ''));
        if ($email !== '') return $email;

        $from = $m['from'] ?? null;
        if (is_array($from) && isset($from[0])) {
            $first = $from[0];
            if (is_array($first)) {
                $n = trim((string)($first['name'] ?? ''));
                if ($n !== '') return $n;
                $e = trim((string)($first['email'] ?? ''));
                return $e !== '' ? $e : null;
            }
            if (is_string($first) && trim($first) !== '') return trim($first);
        }
        if (is_string($from) && trim($from) !== '') return trim($from);

        return null;
    }

    /**
     * Resolve every FlowOne primary user email that should receive realtime
     * fan-out events for the given account. A single account_email can be
     * linked to multiple primary users (a user added it under one login and
     * also views it from another, or registration history left the OAuth
     * token bound to a different primary than the user's current session).
     *
     * Always includes the sync-state's user_email and the account_email
     * itself as a belt-and-suspenders fallback. Publishing to a channel with
     * no subscribers is a Redis no-op ([0/0 clients]) so over-fanning is
     * harmless; under-fanning means missed real-time updates - which is
     * exactly the bug this fixes.
     *
     * @return string[] lowercased, de-duped list of subscriber emails
     */
    private function resolveSubscribers(string $accountEmail, string $userEmailFallback): array
    {
        $set = [];
        $accountLc = strtolower($accountEmail);

        // Always include the fallbacks first so a DB hiccup still publishes
        // to at least the historical target.
        if ($accountEmail !== '') $set[$accountLc] = true;
        if ($userEmailFallback !== '') $set[strtolower($userEmailFallback)] = true;

        try {
            $stmt = $this->db->prepare(
                'SELECT DISTINCT primary_email FROM webmail_oauth_tokens
                  WHERE LOWER(oauth_email) = ?
                    AND COALESCE(health, "healthy") != "revoked"'
            );
            $stmt->execute([$accountLc]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $email) {
                $email = strtolower((string)$email);
                if ($email !== '') $set[$email] = true;
            }
        } catch (\Throwable $e) {
            // Table may not exist yet; silently fall back.
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT DISTINCT primary_email FROM webmail_accounts
                  WHERE LOWER(account_email) = ?'
            );
            $stmt->execute([$accountLc]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $email) {
                $email = strtolower((string)$email);
                if ($email !== '') $set[$email] = true;
            }
        } catch (\Throwable $e) {
            // Table may not exist yet; silently fall back.
        }

        return array_keys($set);
    }

    // ========================================================================
    // PHASE 3: EXPUNGE RECONCILIATION
    // ========================================================================

    /**
     * Compare the mirror's UID set vs IMAP's UID set and delete mirror
     * rows whose UID no longer exists on the server. This is what kills
     * the stale-UID class of bugs ("imap_msgno(N)=0" in the logs).
     *
     * Throttled: only runs if last_expunge_sync_at is older than
     * EXPUNGE_INTERVAL_SECONDS. The caller may force-run by passing
     * $force=true.
     *
     * Returns the number of mirror rows deleted.
     */
    public function expungeReconcile(\Webmail\Services\ImapService $imap, array $state, bool $force = false): int
    {
        $userEmail = (string)$state['user_email'];
        $folderId  = (string)$state['folder_id'];
        $folder    = (string)$state['folder_path'];

        if (!$force && !empty($state['last_expunge_sync_at'])) {
            $age = time() - strtotime((string)$state['last_expunge_sync_at']);
            if ($age < self::EXPUNGE_INTERVAL_SECONDS) {
                return 0;
            }
        }

        $imapUids = $imap->searchAllUids($folder);
        if ($imapUids === false) {
            // Couldn't enumerate UIDs - don't delete anything; we'd
            // rather keep stale rows than nuke a healthy folder on a
            // transient SELECT failure.
            return 0;
        }

        $imapSet = array_flip(array_map('intval', $imapUids));

        $stmt = $this->db->prepare(
            "SELECT uid FROM webmail_conversation_members
              WHERE user_email = ? AND folder_id = ?"
        );
        $stmt->execute([strtolower($userEmail), $folderId]);
        $mirrorUids = array_map(fn($r) => (int)$r['uid'], $stmt->fetchAll());

        $toDelete = [];
        foreach ($mirrorUids as $uid) {
            if ($uid > 0 && !isset($imapSet[$uid])) {
                $toDelete[] = $uid;
            }
        }

        if (empty($toDelete)) {
            $this->touchExpungeTimestamp($userEmail, $folderId);
            return 0;
        }

        // Use ConversationService's bulk path so unread/conversation
        // counts recompute properly.
        try {
            $deleted = $this->conv->bulkDeleteConversationMembers($userEmail, $folder, $toDelete);
            // Record tombstones so the delta() endpoint can surface
            // deletedUids on the client's next poll.
            $this->recordTombstones($userEmail, $folderId, $toDelete, 'imap_expunge');
            $this->touchExpungeTimestamp($userEmail, $folderId);
            return $deleted;
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] expunge ' . $folder . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Append a tombstone row per UID. Called by the sync engine on
     * IMAP-side expunge and by ConversationService on local delete.
     * Idempotent within a row (deleted_at is just CURRENT_TIMESTAMP so
     * a duplicate adds a fresh row; the read side de-dupes by uid).
     */
    public function recordTombstones(string $userEmail, string $folderId, array $uids, string $source = 'local_delete'): void
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), fn($u) => $u > 0)));
        if (empty($uids)) {
            return;
        }
        try {
            $values = implode(',', array_fill(0, count($uids), '(?, ?, ?, NOW(), ?)'));
            $stmt = $this->db->prepare(
                "INSERT INTO webmail_folder_tombstones
                    (user_email, folder_id, uid, deleted_at, source)
                 VALUES {$values}"
            );
            $params = [];
            foreach ($uids as $uid) {
                $params[] = strtolower($userEmail);
                $params[] = $folderId;
                $params[] = $uid;
                $params[] = $source;
            }
            $stmt->execute($params);
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] recordTombstones: ' . $e->getMessage());
        }
    }

    /**
     * Janitor: drop tombstones older than $keepDays. Called from the
     * sync cron at most once per pass so the table stays small.
     */
    public function purgeTombstones(int $keepDays = 7): int
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM webmail_folder_tombstones
                  WHERE deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([$keepDays]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] purgeTombstones: ' . $e->getMessage());
            return 0;
        }
    }

    // ========================================================================
    // PHASE 4: UIDVALIDITY RESET
    // ========================================================================

    /**
     * Tear down the folder's mirror and reset progress. Called when
     * UIDVALIDITY changes - the IMAP universe is fundamentally new, any
     * UID we cached is meaningless.
     */
    public function handleUidvalidityReset(string $userEmail, string $folderId): void
    {
        $userEmail = strtolower($userEmail);
        try {
            // Collect the UIDs we're about to vaporise so the next /delta
            // poll can tell the client to drop them. Done BEFORE the
            // DELETE so we don't lose the list.
            $uidsToTombstone = [];
            try {
                $sel = $this->db->prepare(
                    "SELECT uid FROM webmail_conversation_members
                      WHERE user_email = ? AND folder_id = ?"
                );
                $sel->execute([$userEmail, $folderId]);
                foreach ($sel->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    $uidsToTombstone[] = (int)$uid;
                }
            } catch (\Throwable $e) {
                // Not fatal - reset still proceeds.
            }

            $this->db->beginTransaction();
            $del = $this->db->prepare(
                "DELETE FROM webmail_conversation_members
                  WHERE user_email = ? AND folder_id = ?"
            );
            $del->execute([$userEmail, $folderId]);

            $reset = $this->db->prepare(
                "UPDATE webmail_folder_sync_state
                    SET status = 'pending',
                        highest_uid = 0,
                        highest_modseq = 0,
                        message_count = 0,
                        last_error = NULL,
                        attempts = 0,
                        next_attempt_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE user_email = ? AND folder_id = ?"
            );
            $reset->execute([$userEmail, $folderId]);

            $this->db->commit();

            if (!empty($uidsToTombstone)) {
                $this->recordTombstones($userEmail, $folderId, $uidsToTombstone, 'uidvalidity_reset');
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[MailboxSyncService] handleUidvalidityReset: ' . $e->getMessage());
        }
    }

    /**
     * Mark the row needs a UIDVALIDITY reset on the next cron pass. We
     * don't reset in-line because the caller may be mid-batch with other
     * folders queued up - clean up at the next opportunity.
     */
    private function markUidvalidityReset(string $userEmail, string $folderId, int $newUidvalidity): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE webmail_folder_sync_state
                    SET status = 'uidvalidity_reset',
                        uidvalidity = ?,
                        last_error = 'UIDVALIDITY changed - folder rebuilt server-side',
                        updated_at = CURRENT_TIMESTAMP
                  WHERE user_email = ? AND folder_id = ?"
            );
            $stmt->execute([$newUidvalidity, strtolower($userEmail), $folderId]);
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] markUidvalidityReset: ' . $e->getMessage());
        }
    }

    // ========================================================================
    // FAILURE HANDLING
    // ========================================================================

    /**
     * Record a per-folder failure with exponential backoff.
     */
    public function markFailure(string $userEmail, string $folderId, string $message): void
    {
        $userEmail = strtolower($userEmail);
        try {
            $state = $this->getState($userEmail, $folderId);
            $attempts = $state ? (int)$state['attempts'] + 1 : 1;
            $backoff = min(self::FAILURE_BACKOFF_MAX, self::FAILURE_BACKOFF_BASE * (2 ** min($attempts, 8)));

            $stmt = $this->db->prepare(
                "UPDATE webmail_folder_sync_state
                    SET status = 'failed',
                        last_error = ?,
                        attempts = ?,
                        next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                        updated_at = CURRENT_TIMESTAMP
                  WHERE user_email = ? AND folder_id = ?"
            );
            $stmt->execute([
                mb_substr($message, 0, 500),
                $attempts,
                $backoff,
                $userEmail,
                $folderId,
            ]);
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] markFailure: ' . $e->getMessage());
        }
    }

    /**
     * Delete every sync-state row for a (user, account) pair.
     *
     * Used by two callers:
     *   - AccountTeardownService, when a linked account is removed (so the
     *     cron stops claiming its folders forever), and
     *   - the sync cron self-heal, when a sync-state row has no resolvable
     *     credential row left anywhere (orphan left behind by a pre-fix delete).
     *
     * Scoped to (user_email, account_email) so it can never touch another
     * account's rows. Returns the number of rows removed.
     */
    public function deleteAccountState(string $userEmail, string $accountEmail): int
    {
        $userEmail    = strtolower($userEmail);
        $accountEmail = strtolower($accountEmail);
        if ($userEmail === '' || $accountEmail === '') {
            return 0;
        }
        try {
            $stmt = $this->db->prepare(
                'DELETE FROM webmail_folder_sync_state
                  WHERE LOWER(user_email) = ? AND LOWER(account_email) = ?'
            );
            $stmt->execute([$userEmail, $accountEmail]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] deleteAccountState: ' . $e->getMessage());
            return 0;
        }
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * Drop CHANGEDSINCE rows that conflict with an in-flight outbox op
     * for the same UID. Mirrors the read-path overlay in
     * MailboxController::filterFlagChangesAgainstOutbox.
     */
    private function filterAgainstOutbox(string $userEmail, string $folderId, array $changes): array
    {
        if (empty($changes)) {
            return $changes;
        }
        $uids = [];
        foreach ($changes as $c) {
            $uid = (int)($c['uid'] ?? 0);
            if ($uid > 0) $uids[] = $uid;
        }
        if (empty($uids)) {
            return $changes;
        }
        try {
            $pending = array_flip($this->outbox->pendingFlagUids($userEmail, $folderId, $uids));
        } catch (\Throwable $e) {
            // Conservative: drop the whole batch rather than risk
            // clobbering pending intent if the guard service is down.
            return [];
        }
        return array_values(array_filter($changes, function ($c) use ($pending) {
            $uid = (int)($c['uid'] ?? 0);
            return $uid > 0 && !isset($pending[$uid]);
        }));
    }

    /**
     * Write envelope batch through ConversationService so threading +
     * conversation rollups stay coherent.
     */
    private function persistEnvelopes(string $userEmail, string $folder, array $messages): int
    {
        try {
            $assigned = $this->conv->assignMessagesToConversations($userEmail, $folder, $messages);
            return count($assigned);
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] persistEnvelopes ' . $folder . ': ' . $e->getMessage());
            // Sentinel: signal a HARD write failure (vs. "0 rows were new").
            // Callers must NOT advance the high-water mark on -1, or the
            // unpersisted UID range would be skipped permanently.
            return -1;
        }
    }

    /**
     * Advance progress counters in webmail_folder_sync_state. Used by
     * initialSync and incrementalSync.
     */
    private function advanceProgress(
        string $userEmail,
        string $folderId,
        int $highestUid,
        int $uidvalidity,
        int $messageCount,
        string $status,
        string $phase
    ): void {
        $userEmail = strtolower($userEmail);
        $phaseField = $phase === 'full' ? 'last_full_sync_at' : 'last_incremental_sync_at';
        try {
            $stmt = $this->db->prepare(
                "UPDATE webmail_folder_sync_state
                    SET status = ?,
                        highest_uid = GREATEST(highest_uid, ?),
                        uidvalidity = CASE WHEN ? > 0 THEN ? ELSE uidvalidity END,
                        message_count = CASE WHEN ? >= 0 THEN ? ELSE message_count END,
                        {$phaseField} = NOW(),
                        last_error = NULL,
                        attempts = 0,
                        next_attempt_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE user_email = ? AND folder_id = ?"
            );
            $stmt->execute([
                $status,
                $highestUid,
                $uidvalidity, $uidvalidity,
                $messageCount, $messageCount,
                $userEmail,
                $folderId,
            ]);
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] advanceProgress: ' . $e->getMessage());
        }
    }

    private function writeHighestModseq(string $userEmail, string $folderId, int $modseq): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE webmail_folder_sync_state
                    SET highest_modseq = GREATEST(highest_modseq, ?),
                        updated_at = CURRENT_TIMESTAMP
                  WHERE user_email = ? AND folder_id = ?"
            );
            $stmt->execute([$modseq, strtolower($userEmail), $folderId]);
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] writeHighestModseq: ' . $e->getMessage());
        }
    }

    private function touchExpungeTimestamp(string $userEmail, string $folderId): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE webmail_folder_sync_state
                    SET last_expunge_sync_at = NOW(), updated_at = CURRENT_TIMESTAMP
                  WHERE user_email = ? AND folder_id = ?"
            );
            $stmt->execute([strtolower($userEmail), $folderId]);
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] touchExpungeTimestamp: ' . $e->getMessage());
        }
    }

    // ========================================================================
    // OBSERVABILITY
    // ========================================================================

    /**
     * Per-user health snapshot for the sync-health endpoint (phase 4).
     */
    public function getUserSyncStats(string $userEmail): array
    {
        $userEmail = strtolower($userEmail);
        $defaults = [
            'synced'             => 0,
            'pending'            => 0,
            'initial_syncing'    => 0,
            'failed'             => 0,
            'uidvalidity_reset'  => 0,
            'total_folders'      => 0,
            'attention_folders'  => [],
        ];

        try {
            $stmt = $this->db->prepare(
                "SELECT status, COUNT(*) as cnt
                   FROM webmail_folder_sync_state
                  WHERE user_email = ?
                  GROUP BY status"
            );
            $stmt->execute([$userEmail]);
            $byStatus = $defaults;
            $total = 0;
            foreach ($stmt->fetchAll() as $row) {
                $key = (string)$row['status'];
                $cnt = (int)$row['cnt'];
                if (array_key_exists($key, $byStatus)) {
                    $byStatus[$key] = $cnt;
                }
                $total += $cnt;
            }
            $byStatus['total_folders'] = $total;

            // Attention folders: anything that is not "happily synced
            // and recent" - this is what the frontend reconciler logs
            // and what an ops dashboard would graph.
            $att = $this->db->prepare(
                "SELECT folder_path, status, attempts, last_error,
                        TIMESTAMPDIFF(SECOND, last_incremental_sync_at, NOW()) AS lag_s,
                        TIMESTAMPDIFF(SECOND, next_attempt_at, NOW()) AS due_in_s
                   FROM webmail_folder_sync_state
                  WHERE user_email = ?
                    AND (status IN ('failed','uidvalidity_reset','pending','initial_syncing')
                         OR (status = 'synced' AND last_incremental_sync_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
                        )
                  ORDER BY FIELD(status,'failed','uidvalidity_reset','pending','initial_syncing','synced'),
                           attempts DESC,
                           last_incremental_sync_at ASC
                  LIMIT 25"
            );
            $att->execute([$userEmail]);
            $rows = $att->fetchAll();
            foreach ($rows as &$r) {
                $r['attempts'] = (int)($r['attempts'] ?? 0);
                $r['lag_s']    = $r['lag_s'] !== null ? (int)$r['lag_s'] : null;
                $r['due_in_s'] = $r['due_in_s'] !== null ? (int)$r['due_in_s'] : null;
            }
            unset($r);
            $byStatus['attention_folders'] = $rows;

            return $byStatus;
        } catch (\Throwable $e) {
            error_log('[MailboxSyncService] getUserSyncStats: ' . $e->getMessage());
            return $defaults;
        }
    }
}
