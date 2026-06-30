# Cutover Audit -- Legacy `folder` Column References

This document enumerates every code site touching the legacy `folder` column
on `pinned_emails`, `webmail_conversation_members`, or `webmail_conversations`
that must change before the column drop in `166_canonical_identity_cutover.sql`.

> The cleaned versions of every affected file are pre-staged in
> `email/backend/cutover/code-patches/` mirroring the production
> directory layout. `apply-patches.sh` copies them into place.

**Out of scope** (these tables are NOT being modified):
- `webmail_folder_index.folder` -- separate per-account search-indexing flag table.
- `webmail_email_attachments.folder` -- attachment cache, separate.

## Translation patterns

### Pattern A: `WHERE folder = ? AND uid = ?`
Resolve to folder_id once at top of method; rewrite WHERE to use folder_id:
```php
$folderId = $this->resolveFolderId($userEmail, $folder);
if ($folderId === null) { /* 404 or skip */ }
"WHERE user_email = ? AND folder_id = ? AND uid = ?"
```

### Pattern B: `WHERE folder = ? AND message_id_hash = ?`
Same pre-resolve, swap to `folder_id`. Index `unique_msg_id (user_email, folder_id, message_id)` covers `message_id`; `message_id_hash` queries are covered by `idx_msgid (message_id_hash)` which exists already (see line 240 of ConversationService::ensureTablesExist).

### Pattern C: `INSERT (..., folder, folder_id, ...)` -> `INSERT (..., folder_id, ...)`
Drop the `folder` column from the column list and the corresponding bind value. Drop the `ON DUPLICATE KEY UPDATE folder_id = COALESCE(...)` since folder_id is now mandatory.

### Pattern D: `SELECT cm.folder, ...`  (projection)
JOIN `webmail_folder_identity` to recover the path string for display:
```sql
SELECT fi.current_path AS folder, ...
  FROM webmail_conversation_members cm
  LEFT JOIN webmail_folder_identity fi ON fi.id = cm.folder_id
```

### Pattern E: Folder rename cascade UPDATEs
Becomes a no-op. `folder_id` is stable across renames; the rename only
updates `webmail_folder_identity.current_path` + `webmail_folder_path_intervals`
which is already done by `FolderIndexService::renameByPath` (or whatever the
caller is).

---

## File-by-file inventory

### Hot path

**`email/backend/src/Services/ConversationService.php`** (~30 sites)

| Method | Lines | Pattern | Notes |
|--------|------|---------|-------|
| `ensureTablesExist` | 221-265 | C, D | CREATE TABLE without `folder`, drop legacy indexes, add `folder_id` indexes |
| `migrateColumns` | 297-355 | E | Drop `idx_norm_subject (user_email, folder, normalized_subject)`; the cutover migration creates the new shape |
| `findConversationBySubject` | 414-436 | A | Resolve folder_id, WHERE folder_id = ? |
| `getMessageConversation` | 460-471 | B | Resolve folder_id, WHERE folder_id = ? AND message_id_hash = ? |
| `assignMessageToConversation` | 477-645 | C | Strip the 3-tier try/catch; single INSERT without legacy `folder` column |
| `assignMessagesToConversations` | 651-730 | B | Bulk SELECT WHERE folder_id = ? |
| `updateMemberUidIfZero` | 736-749 | A, B | UPDATE WHERE folder_id = ? AND message_id_hash = ? |
| `findRelatedConversation` | 755-792 | (no folder filter) | Already correct; no change |
| `moveMessageToConversation` | 797-833 | B | UPDATE WHERE folder_id = ? AND message_id_hash = ? |
| `splitMessageToNewConversation` | 839-871 | B | UPDATE WHERE folder_id = ? AND message_id_hash = ? |
| `mergeMessagesToConversation` | 877-923 | B | UPDATE WHERE folder_id = ? AND message_id_hash IN (?, ?) |
| `resetMessageOverride` | 928-953 | B | DELETE WHERE folder_id = ? AND message_id_hash = ? |
| `updateConversationMetadata` | 959-1139 | C, D | INSERT without legacy folder; sibling-folder-cache-invalidation switches to GROUP BY folder_id JOINed to identity table for path |
| `getConversationsForFolder` | 1146-1301 | A, D | WHERE m.folder_id = ?; GROUP_CONCAT projects path via JOIN to identity |
| `getConversationsWithGlobalCounts` | 1384-1452 | A, D | Same as above |
| `getConversationIdForMessage` | 1526-1553 | A | WHERE folder_id = ? AND uid = ? |
| `migrateFromJsonSplits` | 1558-1586 | (no folder filter) | No change |
| `deleteConversationMember` | 1626-1667 | A | SELECT/DELETE WHERE folder_id = ? AND uid = ? |
| `moveConversationMember` | 1680-1784 | A, B | Pre-resolve old + new folder_id; WHERE folder_id = ? AND uid = ?; `SET folder_id = ?` instead of `SET folder = ?` |
| `updateMemberReadStatus` | 1796-1868 | A | SELECT/UPDATE WHERE folder_id = ? AND uid = ? |
| `deleteAllFolderMembers` | 1903-1943 | A | SELECT DISTINCT/DELETE WHERE folder_id = ? |
| `clearUserConversations` | 1948-1955 | (no folder filter) | No change |
| `invalidateFolderIndex` | 2119-2158 | A | DELETE WHERE folder_id = ? |
| `purgeFolderData` | 2165-2234 | A, E | Becomes "find all child folder_ids" via identity table prefix scan; cascade deletes by folder_id IN(...) |
| `updateFolderName` | 2240-2312 | E | **Whole method becomes a no-op.** Folder_id is stable across renames; `FolderIndexService::renameByPath` already updates the path interval and bumps folder_identity_version. The cascade UPDATEs disappear. Method retained as a no-op shim returning 0 (callers may still invoke it; no behavioural regression). |

**`email/backend/src/Controllers/MailboxController.php`** (6 sites)

| Method | Lines | Pattern | Notes |
|--------|------|---------|-------|
| `init` | ~315-330 | D | SELECT pinned: project `fi.current_path AS folder` via JOIN |
| `getPinnedEmails` | ~1599-1611 | D | Same as init's pinned list |
| `pinEmail` | 1622-1685 | A, C | Strip dual-read fallback; INSERT (user_email, folder_id, uid, message_id, subject); drop bumpDualWrite/bumpLegacyWrite branch; folder_id required (return 409 if null) |
| `unpinEmail` | 1695-1741 | A | Strip dual-delete fallback; single DELETE WHERE folder_id = ? AND uid = ? |
| `isPinned` | 1751-1791 | A | Strip dual-read fallback; single SELECT WHERE folder_id = ? AND uid = ? |
| `search` (`is:pinned` operator) | ~2974-2989 | D | SELECT folder_id, uid; whitelist key shape changes from "folder:uid" to "folder_id:uid". Caller post-filter on line 3283 ff. uses `$msg['folder']:$msg['uid']` which is inbox messages; need to also key by folder_id since the inbox messages now also surface folder_id |
| `search` page-1 pinned-fold-in | ~3271-3290 | D | Same shape; SELECT folder_id; use folder_id keys |

**`email/backend/src/Services/ClientService.php`** (5 sites)

| Lines | Pattern | Notes |
|-------|---------|-------|
| 1031, 1383 | D | Project `fi.current_path AS folder` from JOIN |
| 1045 | D | Project path; this is the list of messages in a conversation |
| 1397 | D | Same |
| 3206 | D | `getRecentEmailRefsForDomain`; project path |

**`email/backend/src/Services/SearchIndexerService.php`** (2 sites)

| Lines | Pattern | Notes |
|-------|---------|-------|
| 1610, 1617-1620 | D | `m.folder` projection: JOIN identity, project `fi.current_path AS folder` |
| 1776, 1782-1785 | D | Same pattern in second query block |

### Cron jobs

**`email/backend/cron/register-attachments.php`**

| Lines | Pattern | Notes |
|-------|---------|-------|
| 230 | A | UPDATE webmail_conversation_members SET has_attachment=0 WHERE folder_id = ? AND uid = ?. Caller path: at this point the iteration has the path from $msg['folder']; resolve it once per message-batch via FolderIndexService. |
| 346 | D | SELECT m.folder, ...: project `fi.current_path AS folder` |

**`email/backend/cron/reconcile-mailboxes.php`**

| Lines | Pattern | Notes |
|-------|---------|-------|
| 137 | A | SELECT id, uid WHERE folder_id = ? -- the iteration loop already has the folder path; resolve once |

### Telemetry / readiness

**`email/backend/src/Services/DualWriteTelemetry.php`** -- delete dead methods

Delete:
- `bumpLegacyRead()`
- `bumpLegacyWrite()`
- `bumpDualWrite()`
- `bumpLegacyRouteHit()`
- `bumpCanonicalRouteHit()`

Keep:
- `recordResolveCompare()` (regression guard)
- `bumpFolderIdentityVersion()` / `getFolderIdentityVersion()` (still used by frontend invalidation)
- All Redis key constants for the resolve-compare counters

**`email/backend/src/Controllers/BaseController.php::getResolvedFolder()`**

Remove the `bumpLegacyRouteHit` / `bumpCanonicalRouteHit` block (lines 670-678). Keep the `compareResolve` sampled call as a regression guard.

**`email/backend/cron/dual-write-readiness.php`**

Slim the output: remove `legacy_reads`, `legacy_writes`, `backfill_pending`, `dual_writes`, `legacy_route_hits`, `canonical_route_hits` (all guaranteed 0 post-cutover). Keep the `compare(samples=N ok=N div=N partial=N)` line and the streak (now an ongoing regression-guard streak rather than a cutover gate).

**`email/backend/routes.php`**

Delete 23 legacy `/mailbox/{folder:[^/]+(?:/[^/]+)*}/...` routes (lines 229-263). Keep:
- The 22 canonical `/folders/{folder_id:[0-9a-f-]+}/...` routes
- All `/mailbox/folders` (POST createFolder, GET identity-version, POST status)
- `/mailbox/init`, `/mailbox/folders`, `/mailbox/cache/*`
- `/mailbox/search`, `/mailbox/thread`, `/mailbox/pinned`, `/mailbox/image-proxy`
- `/mailbox/messages/batch-multi`, `/mailbox/batch-move`, `/mailbox/batch-delete`
- `/mailbox/clean-folder`, `/mailbox/unsubscribe`, `/mailbox/save-attachments-to-drive`

### Tests

**`email/backend/tests/folder-identity-test.php`**
- Cleanup queries (lines 173-200): drop the `OR folder LIKE 'flowone_test_%'` legacy-fallback portions
- Migration assertion: `pinned_emails.folder` no longer expected; verify column ABSENT instead
- Dual-write/dual-read tests: drop entirely (compare_resolve test stays as regression guard)

**`email/backend/tests/pinned-smart-view-test.php`**
- Replace all `INSERT INTO pinned_emails (user_email, folder, uid, ...)` with `(user_email, folder_id, uid, ...)`
- Replace `SELECT folder, uid FROM pinned_emails` with `SELECT folder_id, uid` (test code adapts)

**`email/backend/tests/oauth-flag-sync-test.php`**
- Line 528: `INSERT INTO webmail_conversations (..., folder, ...)` -> drop folder, supply folder_id

**`email/backend/tests/mailbox-operations-test.php`**
- No `folder=` references; only `WHERE message_id_hash = ?` cleanup. No change needed.

### Scripts (manual / one-off)

**`email/backend/scripts/normalize_snippets.php`**
- Lines 178-180: SELECT/UPDATE pinned_emails.subject WHERE folder=? AND uid=? -> WHERE folder_id=? AND uid=? (need to JOIN identity to derive folder_id from the path; or simpler, scope by id PK)

**`email/backend/scripts/backfill_attachments.php`**
- Lines 91, 113-115: SELECT DISTINCT folder / SELECT WHERE folder=? -> JOIN identity for folder list, WHERE folder_id=?
- Lines 145-146: UPDATE WHERE folder=? AND uid=? -> WHERE folder_id=? AND uid=?
- Lines 209+: subquery -- folder_id pre-resolution

**`email/backend/scripts/diagnose_encoding.php`**
- Line 79: id_cols `user_email, folder, uid` -> `user_email, folder_id, uid`

**`email/backend/scripts/debug_attachment_index.php`**
- No `WHERE folder=` references in the snippets reviewed. Only JOINs by user_email/conversation_id. No change needed.

### Frontend

**`email/frontend/src/services/mailRouteService.js`**
- `folderCollectionUrl` and `folderResourceUrl`: drop legacy fallback path; throw if folder_id missing.
- Deferred deploy. Not part of the cutover apply-patches.sh; ship as a normal frontend rebuild after the backend cutover succeeds.

---

## Index coverage check

Indexes verified to cover post-cutover query patterns:

| Table | Index | Covered queries |
|-------|-------|----------------|
| pinned_emails | unique_pin_id (user_email, folder_id, uid) | All pin/unpin/isPinned by (folder_id, uid) |
| webmail_conversation_members | unique_msg_id (user_email, folder_id, message_id) | message_id lookups |
| webmail_conversation_members | idx_folder_id_conv (user_email, folder_id, conversation_id) | conversation grouping queries |
| webmail_conversation_members | idx_msgid (message_id_hash) | findRelatedConversation |
| webmail_conversation_members | idx_user_folder_id_uid (user_email, folder_id, uid) | UID lookups |
| webmail_conversations | idx_latest_id (user_email, folder_id, latest_date) | folder-scoped conversation list |
| webmail_conversations | unique_conv (user_email, conversation_id) | direct conversation lookups |

Gap analysis: queries on `(user_email, folder_id, message_id_hash)` (e.g. ConversationService::getMessageConversation) need an index. `idx_msgid (message_id_hash)` alone is too broad (missing user_email + folder_id prefix). Adding **`idx_user_folder_id_msghash (user_email, folder_id, message_id_hash)`** to the cutover migration. This covers ~10 query sites in ConversationService.

For `webmail_conversations.normalized_subject` queries (findConversationBySubject), the existing `idx_norm_subject (user_email, folder, normalized_subject)` (added at runtime by migrateColumns) needs to be replaced with a folder_id-keyed version. Adding **`idx_norm_subject_id (user_email, folder_id, normalized_subject)`** to the cutover migration.
