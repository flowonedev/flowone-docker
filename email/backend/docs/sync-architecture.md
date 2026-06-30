# Email sync architecture

This document describes the **IMAP-single-source-of-truth** model the
mailbox runs on. It supersedes the earlier "DB-as-truth" design (whose
competing producers of read-state and counts caused the unread badge to
jump `0 -> 4 -> 0` and read messages to flash back to unread).

## TL;DR for new contributors

* **Live IMAP is the source of truth for reads.** Every read fact — what
  messages exist, whether a message is read, and the unread counts — is
  answered by a live IMAP call (`/messages`, `/messages-since`, `/delta`
  via `getMessages` / `getFolderStatus`; the account badge via `STATUS
  INBOX (UNSEEN)`). There is exactly **one producer per fact**, so counts
  cannot disagree with themselves and a read cannot un-read itself.
* **MariaDB is a cache, never an authority.** `webmail_conversation_members`
  stores envelopes for conversation grouping/threading and feeds search;
  it is warmed by the read path and the background cron, but it is **never
  served as read-state or counts.** It is safe to wipe and rebuild.
* **Redis is an accelerator, not an authority.** The unread-count cache
  holds the last IMAP-derived value with a short TTL; on miss/stale we
  recompute from IMAP, never from the DB (except the degraded fallback
  below).
* **Writes are DB-first + async push to IMAP.** A mark-read/move/delete
  commits to MariaDB and enqueues an `imap_outbox` row in one transaction,
  returns 200 immediately, and the **`drain-outbox.php` cron** pushes it to
  IMAP. An **in-flight overlay** keeps a just-made change from flashing
  back until the push lands (see below).
* **External changes (phone, Thunderbird, rules)** flow in via the mailsync
  worker's IMAP IDLE + the `/delta` CONDSTORE pull.

## Why DB-as-truth was abandoned

The old model served lists, read-state, and counts from the DB mirror and
relied on a background reconciler to keep that mirror equal to IMAP. That
created **two producers** for every volatile fact (the reconciler/sync
engine AND the request path), computed at different moments. When they
disagreed — a sync pass mid-flight, a transient mirror `is_seen`, a
`FOLDER_COUNTS` websocket pushed at a different instant than a fetch — the
badge jumped and read mail reappeared as unread. The fix was not "better
reconciliation"; it was removing the second producer entirely.

## Read path (authoritative)

```text
Browser asks for a folder / delta
    |
    v
MailboxController::messages | messagesSince | delta
    |
    | (1) live IMAP: getMessages / getMessagesSince / getFolderStatus
    |     -> messages, total, unread (UNSEEN), uidnext, uidvalidity
    |
    | (2) warm the cache (best-effort, never trusted):
    |     ConversationService::assignMessagesToConversations  (grouping)
    |
    | (3) in-flight overlay: applyReadStateOverlay()
    |     reconcileSeen(imapSeen, dbSeen, pending):
    |        pending  -> dbSeen   (user's un-drained intent wins)
    |        else     -> imapSeen || dbSeen
    v
Response (list + conversation rollup)
```

`AccountController::getUnreadCounts` reads the primary INBOX unread from
`STATUS INBOX (UNSEEN)` on the primary mailbox. The DB count
(`computePrimaryUnreadFromDb`) is kept ONLY as a degraded fallback for an
OAuth-only primary (no password to open IMAP) or when IMAP is unreachable.

## Write path (DB-first, async to IMAP)

Unchanged by the IMAP-truth cutover and still essential — it is how a
user's own action reaches IMAP:

```text
Browser optimistic mutation (msg.seen = true, folder.unread--)
    |
    v
MailboxController (write) : BEGIN TX
    UPDATE webmail_conversation_members SET is_seen = 1
    UPDATE webmail_conversations SET unread_count = (SUM recompute)
    INSERT INTO imap_outbox (op='set_flag', clientOpId, ...) ON DUP KEY ...
  COMMIT  ->  200 OK (<50ms, IMAP-latency independent)
    |
    v
drain-outbox.php cron : claim -> resolve creds -> UID STORE/MOVE/EXPUNGE
    -> complete (or backoff/fail) -> publish confirmed event
```

The **in-flight overlay closes the gap** between the COMMIT and the drain:
while an outbox flag op is pending/running for a UID, `reconcileSeen`
trusts the DB for that UID so the just-read message does not reappear as
unread on a refresh; once the op drains, IMAP reflects it and IMAP wins
again. `OutboxService::pendingFlagUids` is the shared guard for both the
read overlay (`applyReadStateOverlay`) and the `/delta` write-back
(`persistImapFlagChanges`).

## Background cron: cache-warmer only

`cron/sync-mailbox.php` + `MailboxSyncService` are a **cache warmer and
new-mail notifier**, NOT a source of truth:

* `initialSyncBatch` / `incrementalSync` — page envelopes into the mirror
  (resume point = `highest_uid`); publish `MESSAGE_NEW` and a
  `FOLDER_COUNTS` ping.
* `expungeReconcile` — prune mirror rows IMAP no longer has.
* CONDSTORE flag-delta (`incrementalSync`) — best-effort warm of the
  cache's `is_seen`; never served.

It does **not** reconcile read-state or counts into the mirror. The
`FOLDER_COUNTS` websocket event carries no authoritative number — the
frontend uses it purely as a "something changed, go re-read IMAP" trigger.

## Real-time (mailsync Node worker)

* `ImapIdleManager` (QRESYNC IDLE) emits `MESSAGE_NEW`, `MESSAGE_DELETED`,
  and `FLAGS_CHANGED`. The browser applies these AND re-reads the
  authoritative count from IMAP; the event never sets the badge directly.

## Tables (all cache except the outbox)

| Table | Role |
|-------|------|
| `webmail_conversation_members` | Envelope/grouping **cache**. `is_seen` is a cache column, never served as read-state. Wipeable. |
| `webmail_conversations` | Cached conversation rollups. |
| `webmail_folder_sync_state` | Cache-warmer bookkeeping (`highest_uid`, `highest_modseq`, status). |
| `webmail_folder_tombstones` | Feeds `/delta` deletedUids between IDLE flushes. |
| `imap_outbox` | The durable write queue — the one table that is operational state, not cache. |

## What was removed in the IMAP-truth cutover

* `MailboxMirrorReadService` (the entire mirror-as-truth read service) and
  its three call sites in `MailboxController::messages/messagesSince/delta`.
* `MailboxSyncService::reconcileReadState` and `reconcileFolderHealth`
  (the read-state rewrite and the drift-triggered destructive rebuild) plus
  the now-dead `filterUidsAgainstOutbox` helper.
* The `read_source` config flag, `BaseController::readSource()`, the
  `MAIL_READ_SOURCE=mirror` rollback switch, and all `read_source==='mirror'`
  branches. There is no longer a rollback to a DB-truth read path.

## What was deliberately KEPT

* The mirror tables + the cron's cache-warming (grouping + search depend on
  envelopes).
* The DB-first write path + `imap_outbox` + `drain-outbox.php` (this is how
  local writes reach IMAP).
* `applyReadStateOverlay` / `reconcileSeen` — the in-flight overlay is part
  of the correct IMAP-truth+outbox design, not mirror-as-truth.
* `AccountController::computePrimaryUnreadFromDb` — degraded fallback only.

## Operational runbook

* **Sync cron** (cache warmer): `*/5 * * * *` with `flock` + `timeout`.
  Logs a `cache-warmer mode: ...` line at the start of every run. Healthy
  steady state is `initial_pages=0` with light `incremental`/`expunged`.
* **Outbox drainer**: `drain-outbox.php` per minute (self-loops). Monitor
  `SELECT status, COUNT(*) FROM imap_outbox GROUP BY status`; alert on
  `dead > 0` or oldest `pending` older than ~1h.
* The request path NEVER blocks on IMAP writes; if the drainer stops, reads
  stay correct (live IMAP) and the UI stays snappy until IMAP-side
  divergence becomes noticeable.
