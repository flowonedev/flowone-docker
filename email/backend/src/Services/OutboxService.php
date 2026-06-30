<?php

declare(strict_types=1);

namespace Webmail\Services;

use PDO;
use PDOException;
use Webmail\Core\Database;

/**
 * OutboxService
 *
 * The durable queue between the PHP request path and the Node mailsync
 * worker. Phase 2 of the DB-as-truth refactor.
 *
 * Lifecycle of an outbox row:
 *
 *   pending -> running -> done
 *      \-> failed -> pending (backoff window) -> running -> ...
 *      \-> dead   (after MAX_ATTEMPTS exhausted)
 *
 * Backoff schedule (seconds): 1, 5, 30, 300, 1800, 3600, 3600, 3600.
 * After the 8th failure the row goes `dead` and surfaces in the UI's
 * "sync issues" banner. Dead rows are not auto-retried; the user must
 * explicitly retry (or the row is purged after 7 days by a janitor cron).
 *
 * Concurrency model:
 *   * `enqueue()` is called inline by the PHP controller, inside the
 *     same transaction as the DB-side state mutation. If the
 *     idempotency_key already exists, the INSERT is a no-op (handled
 *     by the UNIQUE index). Callers can detect that case via the
 *     return value.
 *   * `claim()` is called by the worker. It does an atomic UPDATE
 *     ... LIMIT N flipping status to `running` and stamping
 *     `claimed_at`. The same SELECT could be served to multiple
 *     workers; the UPDATE arbitrates. We then SELECT back the rows
 *     we just claimed.
 *   * `complete()` / `fail()` are called by the worker after the IMAP
 *     side completes or errors. They are simple status updates.
 *   * `reapStuck()` resets rows where `claimed_at` is older than the
 *     stuck threshold. Called by the supervisor every 5 minutes.
 *
 * All time-related fields are stored in DB-local time (UTC by config).
 * Backoff comparisons use NOW() in SQL so PHP clock skew is irrelevant.
 */
final class OutboxService
{
    private array $config;
    private ?PDO $db = null;
    private IdempotencyService $idempotency;

    /** Worker claim batch size. Larger = fewer roundtrips, more lag-spike risk on slow IMAP servers. */
    public const DEFAULT_BATCH_SIZE = 20;

    /** Max attempts before the row is marked `dead`. 8 covers ~2h cumulative backoff. */
    public const MAX_ATTEMPTS = 8;

    /** Seconds after which a `running` row is considered stuck (worker crash) and reset. */
    public const STUCK_THRESHOLD_SECONDS = 300;

    /** Exponential backoff schedule in seconds, indexed by attempt count (0 = first retry). */
    private const BACKOFF_SECONDS = [1, 5, 30, 300, 1800, 3600, 3600, 3600];

    public function __construct(array $config, ?IdempotencyService $idempotency = null)
    {
        $this->config = $config;
        $this->idempotency = $idempotency ?? new IdempotencyService();
    }

    /**
     * Enqueue an IMAP operation.
     *
     * Returns:
     *   ['id' => int, 'inserted' => bool, 'idempotency_key' => string]
     *
     * `inserted` is true when a new row was created, false when an
     * existing row with the same idempotency_key already existed
     * (caller's intent has already been queued or completed).
     *
     * This method does NOT manage its own transaction. The caller
     * (controller) must wrap enqueue + DB state writes in a single
     * transaction so a crash between them is impossible.
     *
     * @param array{
     *   user_email: string,
     *   account_email: string,
     *   op: string,
     *   folder_id?: ?string,
     *   uid?: ?int,
     *   target_folder_id?: ?string,
     *   payload?: array,
     *   nonce?: string,
     * } $params
     *
     * @return array{id:int,inserted:bool,idempotency_key:string}
     */
    public function enqueue(array $params): array
    {
        $this->ensureDb();

        $userEmail = $this->requireString($params, 'user_email');
        $accountEmail = $this->requireString($params, 'account_email');
        $op = $this->requireString($params, 'op');
        $this->assertValidOp($op);

        $folderId = $params['folder_id'] ?? null;
        $uid = $params['uid'] ?? null;
        $targetFolderId = $params['target_folder_id'] ?? null;
        $payload = $params['payload'] ?? [];
        $nonce = (string)($params['nonce'] ?? '');

        $key = $this->idempotency->computeKey(
            $userEmail,
            $accountEmail,
            $op,
            $folderId,
            $uid !== null ? (int)$uid : null,
            $targetFolderId,
            $nonce
        );

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            throw new \InvalidArgumentException('OutboxService::enqueue payload not JSON-encodable');
        }

        $sql = <<<'SQL'
INSERT INTO imap_outbox
    (user_email, account_email, op, folder_id, uid, target_folder_id,
     payload, idempotency_key, status, attempts, next_attempt_at)
VALUES
    (:user_email, :account_email, :op, :folder_id, :uid, :target_folder_id,
     :payload, :key, 'pending', 0, NOW())
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_email'       => strtolower($userEmail),
            ':account_email'    => strtolower($accountEmail),
            ':op'               => $op,
            ':folder_id'        => $folderId,
            ':uid'              => $uid,
            ':target_folder_id' => $targetFolderId,
            ':payload'          => $payloadJson,
            ':key'              => $key,
        ]);

        $id = (int)$this->db->lastInsertId();
        // rowCount() is 1 on a fresh insert, 2 on an ON DUPLICATE KEY UPDATE
        // hit (MySQL convention). We use rowCount === 1 to detect "this was
        // a new insertion" vs. "an existing row was found".
        $inserted = $stmt->rowCount() === 1;

        return [
            'id' => $id,
            'inserted' => $inserted,
            'idempotency_key' => $key,
        ];
    }

    /**
     * Worker claim. Atomically grabs up to $batch pending rows whose
     * next_attempt_at <= NOW(), flips them to `running`, and returns
     * the rows' contents for processing.
     *
     * The two-step (UPDATE then SELECT) lets us avoid SELECT ... FOR
     * UPDATE which is heavier on InnoDB and would hold row locks for
     * the duration of the SELECT round trip. The UPDATE is the
     * arbitration point; the SELECT just reads back what we won.
     *
     * @return array<int,array> Rows ready for IMAP processing.
     */
    public function claim(string $workerId, int $batch = self::DEFAULT_BATCH_SIZE): array
    {
        $this->ensureDb();

        // The marker pattern: stash the worker_id into last_error
        // *only* during this transaction so the SELECT can find rows
        // we just claimed without needing a separate cursor.
        // We use claimed_at = NOW(6) for microsecond precision so two
        // workers racing for the same millisecond bucket can still be
        // distinguished by their last_error marker.
        $marker = 'claim:' . $workerId . ':' . bin2hex(random_bytes(8));

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE imap_outbox
                    SET status = 'running',
                        claimed_at = NOW(),
                        last_error = :marker
                  WHERE status = 'pending'
                    AND next_attempt_at <= NOW()
                  ORDER BY id ASC
                  LIMIT {$batch}"
            );
            $upd->execute([':marker' => $marker]);

            if ($upd->rowCount() === 0) {
                $this->db->commit();
                return [];
            }

            $sel = $this->db->prepare(
                "SELECT id, user_email, account_email, op, folder_id, uid,
                        target_folder_id, payload, idempotency_key, attempts,
                        created_at
                   FROM imap_outbox
                  WHERE status = 'running'
                    AND last_error = :marker
                  ORDER BY id ASC"
            );
            $sel->execute([':marker' => $marker]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

            // Decode payload JSON before handing back to caller so the
            // worker doesn't need to know about the storage format.
            foreach ($rows as &$r) {
                $r['payload'] = json_decode($r['payload'] ?? '{}', true) ?: [];
                $r['attempts'] = (int)$r['attempts'];
                $r['uid'] = $r['uid'] !== null ? (int)$r['uid'] : null;
                $r['id'] = (int)$r['id'];
            }
            unset($r);

            // Clear the marker now that we have the rows in hand. If
            // we crash between here and the worker's complete() call,
            // the row stays in `running` with a stale claimed_at, and
            // the reaper resets it to `pending` after STUCK_THRESHOLD.
            $clr = $this->db->prepare(
                "UPDATE imap_outbox SET last_error = NULL
                  WHERE status = 'running' AND last_error = :marker"
            );
            $clr->execute([':marker' => $marker]);

            $this->db->commit();
            return $rows;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Mark a row done. Optionally record a result UID (for moves where
     * the new UID is returned by the IMAP server).
     */
    public function complete(int $id, ?int $resultUid = null): void
    {
        $this->ensureDb();
        $stmt = $this->db->prepare(
            "UPDATE imap_outbox
                SET status = 'done',
                    result_uid = :result_uid,
                    last_error = NULL
              WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $id,
            ':result_uid' => $resultUid,
        ]);
    }

    /**
     * Record a failure. Decides whether to retry (with backoff) or
     * promote to `dead` based on attempt count.
     */
    public function fail(int $id, string $error): void
    {
        $this->ensureDb();

        // Look up current attempt count to decide next state.
        $cur = $this->db->prepare('SELECT attempts FROM imap_outbox WHERE id = :id');
        $cur->execute([':id' => $id]);
        $attempts = (int)($cur->fetchColumn() ?: 0);
        $nextAttempts = $attempts + 1;

        if ($nextAttempts >= self::MAX_ATTEMPTS) {
            $stmt = $this->db->prepare(
                "UPDATE imap_outbox
                    SET status = 'dead',
                        attempts = :attempts,
                        last_error = :error
                  WHERE id = :id"
            );
            $stmt->execute([
                ':id' => $id,
                ':attempts' => $nextAttempts,
                ':error' => $this->truncateError($error),
            ]);
            return;
        }

        $backoffIdx = min($attempts, count(self::BACKOFF_SECONDS) - 1);
        $backoff = self::BACKOFF_SECONDS[$backoffIdx];

        $stmt = $this->db->prepare(
            "UPDATE imap_outbox
                SET status = 'pending',
                    attempts = :attempts,
                    next_attempt_at = DATE_ADD(NOW(), INTERVAL :backoff SECOND),
                    last_error = :error,
                    claimed_at = NULL
              WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $id,
            ':attempts' => $nextAttempts,
            ':backoff' => $backoff,
            ':error' => $this->truncateError($error),
        ]);
    }

    /**
     * Reset rows stuck in `running` past the threshold (worker crash).
     * Called periodically by the supervisor / a janitor cron.
     */
    public function reapStuck(): int
    {
        $this->ensureDb();
        $threshold = self::STUCK_THRESHOLD_SECONDS;
        $stmt = $this->db->prepare(
            "UPDATE imap_outbox
                SET status = 'pending',
                    claimed_at = NULL,
                    last_error = CONCAT('reaped after ', :threshold, 's stuck in running')
              WHERE status = 'running'
                AND claimed_at < DATE_SUB(NOW(), INTERVAL :threshold SECOND)"
        );
        $stmt->execute([':threshold' => $threshold]);
        return $stmt->rowCount();
    }

    /**
     * Janitor: drop completed rows older than $doneDays and dead rows older
     * than $deadDays so the table stays small. Done rows are pure history;
     * dead rows are kept longer so the user has a window to notice / retry
     * the "sync issues" banner before they vanish.
     *
     * @return int rows deleted
     */
    public function purge(int $doneDays = 1, int $deadDays = 7): int
    {
        $this->ensureDb();
        $stmt = $this->db->prepare(
            "DELETE FROM imap_outbox
              WHERE (status = 'done' AND updated_at < DATE_SUB(NOW(), INTERVAL :doneDays DAY))
                 OR (status = 'dead' AND updated_at < DATE_SUB(NOW(), INTERVAL :deadDays DAY))"
        );
        $stmt->execute([':doneDays' => $doneDays, ':deadDays' => $deadDays]);
        return $stmt->rowCount();
    }

    /**
     * Observability: per-user queue depth for the "sync issues" banner.
     *
     * @return array{pending:int,failed:int,dead:int,oldest_pending_age_sec:?int}
     */
    public function getUserQueueStats(string $userEmail): array
    {
        $this->ensureDb();
        $stmt = $this->db->prepare(
            "SELECT
                SUM(status='pending') AS pending,
                SUM(status='failed') AS failed,
                SUM(status='dead') AS dead,
                MIN(CASE WHEN status='pending' THEN TIMESTAMPDIFF(SECOND, created_at, NOW()) END) AS oldest_pending_age_sec
              FROM imap_outbox
             WHERE user_email = :u"
        );
        $stmt->execute([':u' => strtolower($userEmail)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'pending' => (int)($row['pending'] ?? 0),
            'failed'  => (int)($row['failed'] ?? 0),
            'dead'    => (int)($row['dead'] ?? 0),
            'oldest_pending_age_sec' => isset($row['oldest_pending_age_sec']) && $row['oldest_pending_age_sec'] !== null
                ? (int)$row['oldest_pending_age_sec']
                : null,
        ];
    }

    /**
     * Return the subset of $uids that currently have an UNCONFIRMED flag op
     * (set_flag / clear_flag still pending or running) for this folder.
     *
     * Used by the CONDSTORE delta endpoint to suppress IMAP flag states that
     * would otherwise contradict a local write the user just made but which
     * the drainer has not yet pushed to IMAP. Without this filter, polling
     * IMAP during the in-flight window re-applies the stale (pre-write) flag
     * and the message visibly "jumps back" to its old read state.
     *
     * @param int[] $uids
     * @return int[] uids with an in-flight flag op
     */
    public function pendingFlagUids(string $userEmail, string $folderId, array $uids): array
    {
        if (empty($uids)) {
            return [];
        }
        $this->ensureDb();
        $uids = array_values(array_unique(array_map('intval', $uids)));
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $sql = "SELECT DISTINCT uid
                  FROM imap_outbox
                 WHERE user_email = ?
                   AND folder_id = ?
                   AND op IN ('set_flag','clear_flag')
                   AND status IN ('pending','running')
                   AND uid IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([strtolower($userEmail), $folderId], $uids));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Inject a PDO connection for tests (so the test runner can use a
     * dedicated transaction it can roll back).
     */
    public function setDb(PDO $db): void
    {
        $this->db = $db;
    }

    private function ensureDb(): void
    {
        if ($this->db === null) {
            $this->db = Database::getConnection($this->config);
        }
    }

    private function requireString(array $arr, string $key): string
    {
        if (!isset($arr[$key]) || !is_string($arr[$key]) || $arr[$key] === '') {
            throw new \InvalidArgumentException("OutboxService::enqueue missing required field: $key");
        }
        return $arr[$key];
    }

    private const VALID_OPS = [
        'set_flag', 'clear_flag', 'move', 'copy', 'delete',
        'rename_folder', 'create_folder', 'delete_folder',
    ];

    private function assertValidOp(string $op): void
    {
        if (!in_array($op, self::VALID_OPS, true)) {
            throw new \InvalidArgumentException("OutboxService::enqueue invalid op: $op");
        }
    }

    private function truncateError(string $error): string
    {
        // TEXT column; soft-cap at 4KB so a verbose stack trace can't
        // bloat the table or DoS the JSON encoder downstream.
        if (strlen($error) > 4096) {
            return substr($error, 0, 4093) . '...';
        }
        return $error;
    }
}
