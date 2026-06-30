<?php

namespace Webmail\Services;

/**
 * FolderCacheInvalidator - documented invalidation rules with debouncing.
 *
 * Wave 2 explicitly assigns ownership for cache invalidation. Three rules:
 *
 *   1. Folder rename detected (path or display_name change with the same
 *      folder_id) -> invalidate the folder's message-list cache, refresh
 *      folder list, refresh the All Mail aggregate.
 *
 *   2. Folder deleted from upstream IMAP -> invalidate the folder's
 *      message-list cache, mark webmail_folder_identity.state = deleted,
 *      schedule the cleanup cron 30 days out (folder-deleted-gc.php).
 *
 *   3. Folder content changed (new messages arrived, flags toggled) ->
 *      bump the folder version counter; this is a soft invalidation so
 *      ETags become stale and the next read re-fetches.
 *
 * Each rule is funneled through `invalidate()` which debounces requests
 * for the same (account_id, scope) tuple to a 3-second window. 50 ms of
 * back-pressure is acceptable for UX; a thundering herd of invalidations
 * is not. The debounce uses Redis SETNX-with-TTL so multiple workers
 * coalesce automatically.
 */
class FolderCacheInvalidator
{
    public const REASON_RENAME = 'rename';
    public const REASON_DELETED = 'deleted';
    public const REASON_CONTENT = 'content';

    private const DEBOUNCE_SECONDS = 3;

    private RedisCacheService $redis;

    public function __construct(RedisCacheService $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Request invalidation. Returns true if this call actually invalidated;
     * false if the call was coalesced into a previous request still inside
     * the 3s debounce window.
     */
    public function invalidate(string $accountId, string $folderPath, string $reason): bool
    {
        if (!$this->redis->isAvailable()) {
            return false;
        }
        if (!in_array($reason, [self::REASON_RENAME, self::REASON_DELETED, self::REASON_CONTENT], true)) {
            return false;
        }

        $debounceKey = sprintf('cache_invalidate:%s:%s:%s', $accountId, $reason, md5($folderPath));
        // SETNX with TTL: if the key already exists, another worker already
        // invalidated within the window and we coalesce.
        $first = $this->redis->setIfNotExists($debounceKey, '1', self::DEBOUNCE_SECONDS);
        if (!$first) {
            return false;
        }

        $this->applyRule($accountId, $folderPath, $reason);
        return true;
    }

    private function applyRule(string $accountId, string $folderPath, string $reason): void
    {
        $userHash = $this->redis->getUserHash($accountId);
        $folderSafe = str_replace(['/', '\\', ':'], '_', $folderPath);

        switch ($reason) {
            case self::REASON_RENAME:
                $this->redis->delete("{$userHash}:conv:{$folderSafe}");
                $this->redis->delete("{$userHash}:folder:{$folderSafe}:status");
                $this->redis->deletePattern("{$userHash}:msg:{$folderSafe}:*");
                $this->redis->delete("{$userHash}:folders");
                $this->redis->delete("{$userHash}:allmail:agg");
                break;

            case self::REASON_DELETED:
                $this->redis->deletePattern("{$userHash}:msg:{$folderSafe}:*");
                $this->redis->delete("{$userHash}:conv:{$folderSafe}");
                $this->redis->delete("{$userHash}:folder:{$folderSafe}:status");
                $this->redis->delete("{$userHash}:folders");
                $this->redis->delete("{$userHash}:allmail:agg");
                break;

            case self::REASON_CONTENT:
                // Soft invalidation: bump the folder version counter so
                // downstream consumers (ETags, cache readers) treat the
                // folder as changed. Heavy message-body keys are NOT purged.
                $this->redis->incrementVersion($accountId, 'folder', $folderSafe);
                $this->redis->delete("{$userHash}:folder:{$folderSafe}:status");
                // The conversations LIST is cheap to rebuild and MUST reflect a
                // new message in a thread, otherwise the thread row stays stale
                // (wrong snippet/unread, or a brand-new conversation missing)
                // until the conv cache TTL lapses. The 3s debounce above keeps
                // this from thundering on a busy folder.
                $this->redis->delete("{$userHash}:conv:{$folderSafe}");
                break;
        }

        StructuredLog::emit('cache_invalidated', [
            'account_id' => $accountId,
            'folder_path' => $folderPath,
            'reason' => $reason,
        ]);
    }
}
