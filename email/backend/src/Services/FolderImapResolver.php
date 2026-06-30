<?php

namespace Webmail\Services;

/**
 * FolderImapResolver - canonicalize folder paths for non-HTTP callers.
 *
 * Sibling to FolderInputResolver. Where FolderInputResolver bridges the
 * legacy /mailbox/{folder} URL shape and the canonical /folders/{id} URL
 * shape for HTTP requests, this class serves cron jobs and background
 * indexers that read folder strings directly from DB tables and call
 * ImapService without going through BaseController::getResolvedFolder.
 *
 * The HTTP layer canonicalizes via BaseController::getResolvedFolder:
 *
 *     request param 'folder' (any case)
 *       -> FolderInputResolver::resolve
 *       -> FolderIndexService::getByPath
 *       -> request param 'folder' rewritten to current_path (server's real case)
 *       -> ImapService::selectFolder gets the right name
 *
 * Cron jobs and background indexers (e.g. cron/index-attachments.php,
 * cron/register-attachments.php, SearchIndexerService::indexAttachmentContentBatch)
 * don't go through BaseController. They read $row['folder'] from a DB
 * table (typically webmail_email_attachments or webmail_conversation_members)
 * and pass it straight to ImapService. When the stored folder string is
 * stale-cased (legacy lowercased row from a long-fixed writer bug) or
 * stale-named (folder was renamed after the row was written), the IMAP
 * call fails. This class is the equivalent normalization step for those
 * callers.
 *
 * Usage:
 *
 *     $resolver = new FolderImapResolver($config);
 *     foreach ($rows as $row) {
 *         $canonical = $resolver->resolveForImap($userEmail, $row['folder']);
 *         $imap->getAttachment($canonical, $row['uid'], $row['part']);
 *     }
 *
 * Caching: the resolver memoizes (accountId, lowercase(path)) -> canonical
 * for the lifetime of the instance, so a 50-row batch hits the DB at most
 * once per distinct path. Call clearCache() if the active account changes
 * or after a known folder rename.
 *
 * Failure mode: if the FolderIndexService lookup throws or returns null,
 * the caller's input is returned unchanged so legacy behavior is preserved
 * (and the existing selectFolder() guard in the indexer loop will then
 * mark the row as failed if IMAP rejects it).
 */
final class FolderImapResolver
{
    private array $config;
    private ?FolderIndexService $svc = null;
    private array $cache = [];
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Resolve a raw folder path to the server's canonical (current_path)
     * form for the given account. Case-insensitive: 'inbox.work.foo',
     * 'INBOX.WORK.FOO', and 'INBOX.Work.Foo' all return the same canonical
     * string if any of them maps to an open interval in the identity table.
     *
     * Returns the input as-is when the path is empty, when no identity row
     * exists for it, or when the FolderIndexService lookup fails. This
     * preserves legacy behavior for first-write paths and for accounts
     * that haven't been seen by Wave-2 yet.
     */
    public function resolveForImap(string $accountId, string $rawPath): string
    {
        if ($rawPath === '' || $accountId === '') {
            return $rawPath;
        }

        $key = strtolower($rawPath);
        if (isset($this->cache[$accountId][$key])) {
            $this->hits++;
            return $this->cache[$accountId][$key];
        }
        $this->misses++;

        $canonical = $rawPath;
        try {
            $svc = $this->getService();
            if ($svc !== null) {
                $row = $svc->getByPath($accountId, $rawPath);
                if ($row !== null && !empty($row['current_path'])) {
                    $canonical = (string) $row['current_path'];
                }
            }
        } catch (\Throwable $e) {
            // Never let a folder-identity lookup failure escape; the caller
            // is in a tight loop and we'd rather pass through the raw path
            // and let selectFolder()'s guard handle it.
            error_log("[FolderImapResolver] getByPath failed for account='{$accountId}' path='{$rawPath}': " . $e->getMessage());
        }

        $this->cache[$accountId][$key] = $canonical;
        return $canonical;
    }

    /**
     * Drop the cache. Call after a known folder rename within a long-running
     * cron, or when switching accounts inside the same process.
     */
    public function clearCache(?string $accountId = null): void
    {
        if ($accountId === null) {
            $this->cache = [];
            return;
        }
        unset($this->cache[$accountId]);
    }

    /**
     * Diagnostic counters. Useful for cron summaries: "resolved X paths,
     * Y cache hits, Z DB lookups".
     */
    public function stats(): array
    {
        return [
            'cache_hits'   => $this->hits,
            'cache_misses' => $this->misses,
            'cache_size'   => array_sum(array_map('count', $this->cache)),
        ];
    }

    private function getService(): ?FolderIndexService
    {
        if ($this->svc === null) {
            try {
                $this->svc = new FolderIndexService($this->config);
            } catch (\Throwable $e) {
                error_log('[FolderImapResolver] FolderIndexService init failed: ' . $e->getMessage());
                return null;
            }
        }
        return $this->svc;
    }
}
