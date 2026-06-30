<?php

declare(strict_types=1);

namespace Webmail\Services;

use PDO;
use Webmail\Core\Database;

/**
 * MailboxWriteService
 *
 * Phase 5 of "Finish Gmail-like": extracts the DB-first write pattern
 * (begin tx -> conv update -> outbox enqueue -> commit) out of
 * MailboxController so the controller methods become thin HTTP shims
 * and the write semantics live in one place.
 *
 * The previous MailboxController had ~6 methods (setFlag, batchSetFlag,
 * move, batchMove, delete, batchDelete) all reimplementing the same
 * "DDL-safe construction order + begin tx + commit + post-commit
 * pubsub" recipe, which is exactly the duplication that produced the
 * "Failed to set flag" 400 bug (DDL implicit-commit inside the tx).
 * Centralising that recipe here is the single biggest risk reduction
 * the controller refactor delivers.
 *
 * Contract:
 *   - Every commit* method opens its OWN transaction. Do not wrap a
 *     call to a commit* method inside another transaction.
 *   - The constructor up front instantiates ConversationService /
 *     OutboxService so any DDL in their constructors runs BEFORE the
 *     transaction opens. This is the fix for the implicit-commit bug.
 *   - All publish-and-post-commit helpers run outside any transaction
 *     and are best-effort (Redis failures do not undo the DB commit).
 */
final class MailboxWriteService
{
    private array $config;
    private PDO $db;
    private ConversationService $conv;
    private OutboxService $outbox;
    private RedisCacheService $cache;

    public function __construct(
        array $config,
        ?PDO $db = null,
        ?ConversationService $conv = null,
        ?OutboxService $outbox = null,
        ?RedisCacheService $cache = null
    ) {
        $this->config = $config;
        $this->db     = $db ?? Database::getConnection($config);
        // CRITICAL: instantiate ConversationService and OutboxService
        // here, BEFORE any caller begins a transaction. Their
        // constructors run CREATE TABLE IF NOT EXISTS / ALTER TABLE
        // (DDL) which implicit-commits any open MySQL/MariaDB tx.
        // This is the same root cause the controller had to work
        // around with manual hoisting of $this->getConversationService()
        // calls.
        $this->conv   = $conv   ?? new ConversationService($config);
        $this->outbox = $outbox ?? new OutboxService($config);
        $this->cache  = $cache  ?? new RedisCacheService($config);
    }

    // ========================================================================
    // FLAG
    // ========================================================================

    /**
     * Commit a single-UID flag op (set or clear).
     *
     * @return array{ok:bool,error?:string}
     */
    public function commitFlag(
        string $userEmail,
        string $folder,
        string $folderId,
        int $uid,
        string $flag,
        bool $value,
        string $nonce
    ): array {
        $flag = strtolower($flag);
        $imapFlag = '\\' . ucfirst($flag);

        try {
            $this->db->beginTransaction();

            if ($flag === 'seen') {
                $this->conv->updateMemberReadStatus($userEmail, $folder, $uid, $value);
            } elseif ($flag === 'flagged') {
                // Mirror the star into the DB so the list read (the source of
                // truth for synced folders) doesn't revert it before IMAP sync.
                $this->conv->updateMemberFlagStatus($userEmail, $folder, $uid, $value);
            }

            $this->outbox->enqueue([
                'user_email'    => $userEmail,
                'account_email' => $userEmail,
                'op'            => $value ? 'set_flag' : 'clear_flag',
                'folder_id'     => $folderId,
                'uid'           => $uid,
                'nonce'         => $nonce,
                'payload'       => [
                    'flag'        => $flag,
                    'value'       => $value,
                    'imap_flag'   => $imapFlag,
                    'source_path' => $folder,
                ],
            ]);

            $this->db->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[MailboxWriteService::commitFlag] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return ['ok' => false, 'error' => 'Failed to set flag'];
        }
    }

    /**
     * Commit a batched flag op across many UIDs grouped by source folder.
     *
     * @param array<string,array{folder_id:string,uids:int[]}> $byFolder
     * @return array{ok:bool,success:int,skipped:int,errors:string[]}
     */
    public function commitFlagBatch(
        string $userEmail,
        array $byFolder,
        string $flag,
        bool $value,
        string $nonce
    ): array {
        $flag = strtolower($flag);
        $imapFlag = '\\' . ucfirst($flag);
        $success = 0;
        $skipped = 0;
        $errors  = [];

        try {
            $this->db->beginTransaction();

            foreach ($byFolder as $folder => $entry) {
                $folderId = (string)($entry['folder_id'] ?? '');
                $uids     = $entry['uids'] ?? [];
                if ($folderId === '' || empty($uids)) {
                    $skipped += count($uids);
                    $errors[] = "no folder identity for {$folder}";
                    continue;
                }

                if ($flag === 'seen') {
                    $this->conv->updateMembersReadStatusBatch($userEmail, $folder, $uids, $value);
                } elseif ($flag === 'flagged') {
                    $this->conv->updateMembersFlagStatusBatch($userEmail, $folder, $uids, $value);
                }

                foreach ($uids as $uid) {
                    $this->outbox->enqueue([
                        'user_email'    => $userEmail,
                        'account_email' => $userEmail,
                        'op'            => $value ? 'set_flag' : 'clear_flag',
                        'folder_id'     => $folderId,
                        'uid'           => (int)$uid,
                        'nonce'         => $nonce,
                        'payload'       => [
                            'flag'        => $flag,
                            'value'       => $value,
                            'imap_flag'   => $imapFlag,
                            'source_path' => $folder,
                        ],
                    ]);
                    $success++;
                }
            }

            $this->db->commit();
            return ['ok' => true, 'success' => $success, 'skipped' => $skipped, 'errors' => $errors];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[MailboxWriteService::commitFlagBatch] ' . $e->getMessage());
            return [
                'ok'      => false,
                'success' => 0,
                'skipped' => 0,
                'errors'  => ['Batch flag failed: ' . $e->getMessage()],
            ];
        }
    }

    // ========================================================================
    // MOVE
    // ========================================================================

    /**
     * Commit a single-UID move op.
     *
     * @return array{ok:bool,error?:string}
     */
    public function commitMove(
        string $userEmail,
        string $folder,
        string $folderId,
        int $uid,
        string $targetFolder,
        string $targetFolderId,
        string $nonce,
        ?string $reason = null
    ): array {
        $payload = [
            'source_path' => $folder,
            'target_path' => $targetFolder,
        ];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        try {
            $this->db->beginTransaction();

            $this->conv->moveConversationMember($userEmail, $folder, $uid, $targetFolder, null);

            $this->outbox->enqueue([
                'user_email'       => $userEmail,
                'account_email'    => $userEmail,
                'op'               => 'move',
                'folder_id'        => $folderId,
                'uid'              => $uid,
                'target_folder_id' => $targetFolderId,
                'nonce'            => $nonce,
                'payload'          => $payload,
            ]);

            $this->db->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[MailboxWriteService::commitMove] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to move message'];
        }
    }

    /**
     * Commit a batched move op: many source folders -> one target.
     *
     * @param array<string,array{folder_id:string,uids:int[]}> $bySource
     * @return array{ok:bool,success:int,skipped:int,errors:string[]}
     */
    public function commitMoveBatch(
        string $userEmail,
        string $targetFolder,
        string $targetFolderId,
        array $bySource,
        string $nonce
    ): array {
        $success = 0;
        $skipped = 0;
        $errors  = [];

        try {
            $this->db->beginTransaction();

            foreach ($bySource as $folder => $entry) {
                $folderId = (string)($entry['folder_id'] ?? '');
                $uids     = $entry['uids'] ?? [];
                if ($folderId === '' || empty($uids)) {
                    $skipped += count($uids);
                    $errors[] = "no folder identity for {$folder}";
                    continue;
                }

                foreach ($uids as $uid) {
                    $this->conv->moveConversationMember($userEmail, $folder, (int)$uid, $targetFolder, null);
                    $this->outbox->enqueue([
                        'user_email'       => $userEmail,
                        'account_email'    => $userEmail,
                        'op'               => 'move',
                        'folder_id'        => $folderId,
                        'uid'              => (int)$uid,
                        'target_folder_id' => $targetFolderId,
                        'nonce'            => $nonce,
                        'payload'          => [
                            'source_path' => $folder,
                            'target_path' => $targetFolder,
                        ],
                    ]);
                    $success++;
                }
            }

            $this->db->commit();
            return ['ok' => true, 'success' => $success, 'skipped' => $skipped, 'errors' => $errors];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[MailboxWriteService::commitMoveBatch] ' . $e->getMessage());
            return [
                'ok'      => false,
                'success' => 0,
                'skipped' => 0,
                'errors'  => ['Batch move failed: ' . $e->getMessage()],
            ];
        }
    }

    // ========================================================================
    // DELETE
    // ========================================================================

    /**
     * Commit a single-UID delete op. Caller supplies the resolved
     * trash target (folder + folder_id) when this is a soft delete;
     * pass null for both when doing a permanent EXPUNGE.
     *
     * @return array{ok:bool,error?:string}
     */
    public function commitDelete(
        string $userEmail,
        string $folder,
        string $folderId,
        int $uid,
        string $nonce,
        ?string $trashFolder = null,
        ?string $trashFolderId = null
    ): array {
        $isHardDelete = ($trashFolder === null || $trashFolderId === null);

        try {
            $this->db->beginTransaction();

            if ($isHardDelete) {
                $this->conv->deleteConversationMember($userEmail, $folder, $uid);
                $this->outbox->enqueue([
                    'user_email'    => $userEmail,
                    'account_email' => $userEmail,
                    'op'            => 'delete',
                    'folder_id'     => $folderId,
                    'uid'           => $uid,
                    'nonce'         => $nonce,
                    'payload'       => ['source_path' => $folder],
                ]);
            } else {
                $this->conv->moveConversationMember($userEmail, $folder, $uid, $trashFolder, null);
                $this->outbox->enqueue([
                    'user_email'       => $userEmail,
                    'account_email'    => $userEmail,
                    'op'               => 'move',
                    'folder_id'        => $folderId,
                    'uid'              => $uid,
                    'target_folder_id' => $trashFolderId,
                    'nonce'            => $nonce,
                    'payload'          => [
                        'source_path' => $folder,
                        'target_path' => $trashFolder,
                        'reason'      => 'trash',
                    ],
                ]);
            }

            $this->db->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[MailboxWriteService::commitDelete] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Failed to delete message'];
        }
    }

    /**
     * Commit a batched delete op across many UIDs grouped by source
     * folder. Permanent deletes only; the trash-move flow goes through
     * commitMoveBatch with target=trash instead (see batchMove).
     *
     * @param array<string,array{folder_id:string,uids:int[]}> $byFolder
     * @return array{ok:bool,success:int,skipped:int,errors:string[]}
     */
    public function commitDeleteBatch(
        string $userEmail,
        array $byFolder,
        string $nonce
    ): array {
        $success = 0;
        $skipped = 0;
        $errors  = [];

        try {
            $this->db->beginTransaction();

            foreach ($byFolder as $folder => $entry) {
                $folderId = (string)($entry['folder_id'] ?? '');
                $uids     = array_values(array_map('intval', $entry['uids'] ?? []));
                if ($folderId === '' || empty($uids)) {
                    $skipped += count($uids);
                    $errors[] = "no folder identity for {$folder}";
                    continue;
                }

                // One bulk member-delete (+ tombstones + per-conversation
                // recompute) instead of 3+ queries per UID. Outbox rows stay
                // per-UID because the drainer keys IMAP work by UID.
                $this->conv->bulkDeleteConversationMembers($userEmail, $folder, $uids);
                foreach ($uids as $uid) {
                    $this->outbox->enqueue([
                        'user_email'    => $userEmail,
                        'account_email' => $userEmail,
                        'op'            => 'delete',
                        'folder_id'     => $folderId,
                        'uid'           => (int)$uid,
                        'nonce'         => $nonce,
                        'payload'       => ['source_path' => $folder],
                    ]);
                    $success++;
                }
            }

            $this->db->commit();
            return ['ok' => true, 'success' => $success, 'skipped' => $skipped, 'errors' => $errors];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[MailboxWriteService::commitDeleteBatch] ' . $e->getMessage());
            return [
                'ok'      => false,
                'success' => 0,
                'skipped' => 0,
                'errors'  => ['Batch delete failed: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * Commit a mixed batch delete (permanent hard-deletes + soft moves to
     * trash) in a SINGLE transaction.
     *
     * batchDelete used to call commitDeleteBatch and commitMoveBatch as two
     * independent transactions: if the first committed and the second threw,
     * half the user's selection was deleted and half was left in place, with
     * no way to tell the client which. Running both work lists under one tx
     * makes the whole "delete these N messages" action atomic in the DB
     * mirror (the IMAP side remains eventually-consistent via the outbox).
     *
     * @param array<string,array{folder_id:string,uids:int[]}> $hardByFolder permanent deletes
     * @param array<string,array{folder_id:string,uids:int[]}> $moveBySource soft deletes (-> trash)
     * @return array{ok:bool,success:int,skipped:int,errors:string[]}
     */
    public function commitDeleteAndMoveBatch(
        string $userEmail,
        array $hardByFolder,
        array $moveBySource,
        ?string $trashFolder,
        ?string $trashFolderId,
        string $nonce
    ): array {
        $success = 0;
        $skipped = 0;
        $errors  = [];

        try {
            $this->db->beginTransaction();

            // --- Permanent hard-deletes ---
            foreach ($hardByFolder as $folder => $entry) {
                $folderId = (string)($entry['folder_id'] ?? '');
                $uids     = array_values(array_map('intval', $entry['uids'] ?? []));
                if ($folderId === '' || empty($uids)) {
                    $skipped += count($uids);
                    $errors[] = "no folder identity for {$folder}";
                    continue;
                }
                $this->conv->bulkDeleteConversationMembers($userEmail, $folder, $uids);
                foreach ($uids as $uid) {
                    $this->outbox->enqueue([
                        'user_email'    => $userEmail,
                        'account_email' => $userEmail,
                        'op'            => 'delete',
                        'folder_id'     => $folderId,
                        'uid'           => (int)$uid,
                        'nonce'         => $nonce,
                        'payload'       => ['source_path' => $folder],
                    ]);
                    $success++;
                }
            }

            // --- Soft-deletes (move to trash) ---
            if (!empty($moveBySource) && $trashFolder !== null && $trashFolderId !== null) {
                foreach ($moveBySource as $folder => $entry) {
                    $folderId = (string)($entry['folder_id'] ?? '');
                    $uids     = array_values(array_map('intval', $entry['uids'] ?? []));
                    if ($folderId === '' || empty($uids)) {
                        $skipped += count($uids);
                        $errors[] = "no folder identity for {$folder}";
                        continue;
                    }
                    foreach ($uids as $uid) {
                        $this->conv->moveConversationMember($userEmail, $folder, (int)$uid, $trashFolder, null);
                        $this->outbox->enqueue([
                            'user_email'       => $userEmail,
                            'account_email'    => $userEmail,
                            'op'               => 'move',
                            'folder_id'        => $folderId,
                            'uid'              => (int)$uid,
                            'target_folder_id' => $trashFolderId,
                            'nonce'            => $nonce,
                            'payload'          => [
                                'source_path' => $folder,
                                'target_path' => $trashFolder,
                                'reason'      => 'trash',
                            ],
                        ]);
                        $success++;
                    }
                }
            }

            $this->db->commit();
            return ['ok' => true, 'success' => $success, 'skipped' => $skipped, 'errors' => $errors];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[MailboxWriteService::commitDeleteAndMoveBatch] ' . $e->getMessage());
            return [
                'ok'      => false,
                'success' => 0,
                'skipped' => 0,
                'errors'  => ['Batch delete failed: ' . $e->getMessage()],
            ];
        }
    }

    // ========================================================================
    // POST-COMMIT EVENTS (best-effort, never rolled back)
    // ========================================================================

    public function publishFlagEvent(string $userEmail, string $folder, int $uid, string $flag, bool $value, bool $batch = false): void
    {
        $flag = strtolower($flag);
        $imapFlag = '\\' . ucfirst($flag);
        try {
            $this->cache->publishFlagsChanged($userEmail, $folder, $uid, [
                'flag'      => $flag,
                'value'     => $value,
                'imapFlags' => $value ? [$imapFlag] : [],
                'batch'     => $batch,
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    public function publishMoveEvent(string $userEmail, string $folder, string $targetFolder, int $uid, ?int $newUid = null): void
    {
        try {
            $this->cache->invalidateMessage($userEmail, $folder, $uid);
            $this->cache->invalidateFolder($userEmail, $folder);
            $this->cache->invalidateFolder($userEmail, $targetFolder);
            $this->cache->publishMessageMoved($userEmail, $folder, $targetFolder, $uid, $newUid);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    public function publishDeleteEvent(string $userEmail, string $folder, int $uid): void
    {
        try {
            $this->cache->invalidateMessage($userEmail, $folder, $uid);
            $this->cache->invalidateFolder($userEmail, $folder);
            $this->cache->publishMessageDeleted($userEmail, $folder, $uid, true);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
