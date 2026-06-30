# The Life of an Email in FlowOne

A complete reference for how emails flow through the system -- from the moment they hit the server to how they appear on screen, and what happens when the user interacts with them. Use this document to trace bugs, understand race conditions, and reason about data flow.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Email Arrival: Server to Store](#2-email-arrival-server-to-store)
3. [Email Display: Store to Screen](#3-email-display-store-to-screen)
4. [Conversation Grouping](#4-conversation-grouping)
5. [User Actions](#5-user-actions)
6. [Real-Time Sync (WebSocket)](#6-real-time-sync-websocket)
7. [All Mail and Virtual Folders](#7-all-mail-and-virtual-folders)
8. [Desktop App (Electron) Specifics](#8-desktop-app-electron-specifics)
9. [Known Pitfalls and Race Conditions](#9-known-pitfalls-and-race-conditions)
10. [Quick Debugging Reference](#10-quick-debugging-reference)

---

## 1. Architecture Overview

```
                         INTERNET
                            |
                      [ Postfix SMTP ]
                            |
                      [ Dovecot LMTP ]
                            |
                     [ Maildir Storage ]
                            |
                      [ Dovecot IMAP ]
                       /          \
                      /            \
            [ PHP Backend ]    [ Node.js mailsync ]
              (REST API)        (WebSocket server)
                  |                    |
            [ Redis Cache ]     [ Redis Pub/Sub ]
                  |                    |
                  '--------+-----------'
                           |
              [ Frontend (Vue/Pinia) ]
             /        |          \
         Cloud    Electron    iOS/Android
         (Web)    (Desktop)   (Capacitor)
```

### Server Stack

| Component       | Role                                      |
|-----------------|-------------------------------------------|
| Postfix         | Receives SMTP, delivers via LMTP          |
| Dovecot         | Stores in Maildir, serves IMAP            |
| PHP Backend     | REST API, IMAP client, business logic     |
| Node.js mailsync| WebSocket server, IMAP IDLE, event relay  |
| Redis           | Message cache + Pub/Sub event bus         |
| MariaDB         | Conversations, pins, labels, filters, etc.|

### Frontend Architecture

All platforms (Cloud, Electron, iOS) share the same Vue frontend. The key stores:

| Store                | Responsibility                                    |
|----------------------|---------------------------------------------------|
| `mailbox.js`         | Messages, folders, flags, current message         |
| `conversations.js`   | DB-backed conversation metadata                   |
| `useConversationGrouping.js` | Merges DB conversations with canonical messages |
| `auth.js`            | Authentication tokens, user profile               |
| `spam.js`            | Spam reporting, sender blocking                   |

---

## 2. Email Arrival: Server to Store

### Step-by-step flow

```
Sender's mail server
        |
        v
   +---------+     Port 25, TLS verified
   | Postfix |     Passes SPF/DKIM/DMARC checks
   +---------+     Applies transport rules
        |
        | virtual_transport = lmtp:unix:private/dovecot-lmtp
        v
   +---------+
   | Dovecot |     Stores in Maildir: /var/mail/vhosts/{domain}/{user}/
   +---------+     Assigns IMAP UID on first access
        |          Updates UIDNEXT, folder status
        |
        +---> [ IMAP IDLE ] ---> [ mailsync Node.js ]
        |                              |
        |                              | Redis Pub/Sub
        |                              | channel: webmail:mailbox:{userEmail}
        |                              | event: MESSAGE_NEW
        |                              v
        |                     [ WebSocket Server ]
        |                              |
        |                              | sends to connected clients
        |                              v
        |                     [ Frontend Browser/App ]
        |                              |
        |                              | debouncedIncrementalFetch
        |                              v
        +---> [ PHP Backend ] <--- GET /mailbox/{folder}/messages/since?uid_gt=X
                    |
                    | UID FETCH {uids} (FLAGS HEADERS BODYSTRUCTURE)
                    v
              [ formatMessageOverview() ]
                    |
                    | Returns: uid, message_id, subject, from, to,
                    |   date, seen, flagged, has_attachment,
                    |   in_reply_to, references
                    v
              [ ConversationService ]
                    |
                    | assignMessagesToConversations()
                    | computeConversationId() via References/In-Reply-To/Subject
                    | Stores in: webmail_conversation_members
                    v
              [ Redis Cache ]
                    |
                    | Caches message list and folder status
                    v
              [ API Response ] ---> [ Frontend ]
                                        |
                                        | upsertMessages() into messagesByKey
                                        | folderViews.set(folder, keys)
                                        v
                                  [ Screen: Email List ]
```

### Key Data at Each Step

| Step                   | Key Fields                                                  |
|------------------------|-------------------------------------------------------------|
| IMAP Fetch             | uid, flags (\Seen, \Flagged), internaldate, size            |
| formatMessageOverview  | uid, message_id, subject, from[], to[], date, seen, flagged |
| Conversation assignment| conversation_id (md5 of root message_id), folder, uid       |
| Frontend upsert        | folder:uid key in messagesByKey (reactive Map)              |

### How UIDNEXT Drives New Message Detection

```
Frontend knows:  highest UID in local store = 1547
Server reports:  UIDNEXT = 1550

  --> 3 new messages (UIDs 1548, 1549, 1550)
  --> GET /mailbox/INBOX/messages/since?uid_gt=1547
  --> Server: UID SEARCH UID 1548:*
  --> Returns new messages only (incremental, no full reload)
```

---

## 3. Email Display: Store to Screen

### The Canonical Store: messagesByKey

Every message lives in one place: `messagesByKey` (a reactive Map).

```
Key format:   "{folder}:{uid}"
Examples:     "INBOX:1547"
              "Sent:892"
              "INBOX.Archive:301"

Value:        Full message object { uid, message_id, subject, from,
              seen, flagged, body_html, body_text, folder, ... }
```

All components read from this single source. When a message is updated anywhere (API response, WebSocket event, optimistic update), the canonical object is mutated in-place to preserve Vue reactivity.

### From Store to Email List

```
messagesByKey (canonical store)
        |
        | folderViews.get("INBOX") --> [key1, key2, key3, ...]
        v
  Resolve keys to message objects
        |
        v
  useConversationGrouping.js
        |
        |  If conversation view ON and regular folder:
        |    buildFromDb() merges DB conversations with canonical messages
        |    Computes: hasUnread, hasStarred, messageCount, threadLoaded
        |
        |  If conversation view OFF or virtual folder (ALL_MAIL):
        |    Returns flat list, each message as individual item
        |
        v
  conversations[] (computed)
        |
        v
  EmailList.vue renders each item
        |
        | Unread indicator: !item.seen || item.hasUnread
        | Bold text:        !item.seen || item.hasUnread
        | Star icon:        item.flagged || item.hasStarred
        | Pin indicator:    mailbox.isEmailPinned(uid, folder)
        | Attachment icon:  item.has_attachment
        v
  [ Screen ]
```

### Opening an Email (fetchMessage)

```
User clicks email in list
        |
        v
  fetchMessage(uid)
        |
        +---> Check canonical store for body (body_html/body_text)
        |         |
        |    [HIT] Use cached body instantly
        |         |
        |    [MISS] GET /mailbox/{folder}/messages/{uid}
        |              |
        |              | Backend: UID FETCH {uid} BODY.PEEK[]
        |              | (PEEK = does NOT auto-mark as read on IMAP)
        |              v
        |         upsertMessage(response, folder)
        |
        +---> Find conversation item for this UID
        |         |
        |    [FOUND] Set currentMessage with conversation context
        |           Start loadConversationMessagesBackground()
        |               |
        |               +---> fetchThreadMessages(convKey)
        |               |         |
        |               |         +-- Upsert conv.messages (from list data)
        |               |         +-- DB API: /conversations/{id}/messages/global
        |               |         +-- IMAP fallback: /mailbox/thread (searches all folders)
        |               |         +-- prefetchThreadBodies (batch fetch bodies)
        |               |         +-- Store keys in conversationKeys Map
        |               |
        |               +---> batch-multi API for remaining messages
        |               +---> Update currentMessage with all thread messages
        |
        |    [NOT FOUND] Display as single message
        |
        +---> markOpenedAsRead(uid, conversationItem)
                  |
                  | Only marks messages in CURRENT FOLDER as read
                  | Skips already-read messages (seen: true)
                  | Sets pending flag protection (30s TTL)
                  | POST /mailbox/{folder}/messages/{uid}/flag?flag=seen&value=1
```

---

## 4. Conversation Grouping

### How Conversations Are Built (Backend)

```
New message arrives with headers:
  Message-ID: <abc@example.com>
  In-Reply-To: <xyz@example.com>
  References: <first@example.com> <xyz@example.com>

ConversationService.computeConversationId():
        |
        +---> references[0] exists? --> root = "first@example.com"
        |         (first reference = thread root)
        |
        +---> else: in_reply_to? --> root = that ID
        |
        +---> else: subject match? --> findConversationBySubject()
        |         (strips Re:/Fwd:/FW:, matches existing conversations)
        |
        +---> else: message_id --> root = this message starts a new thread
        |
        v
  conversation_id = md5(normalize(root))
        |
        v
  INSERT INTO webmail_conversation_members
    (user_email, conversation_id, message_id, folder, uid, subject, ...)
```

### How Conversations Are Displayed (Frontend)

```
conversationsStore.getConversationsList("INBOX")
        |
        | Returns DB conversation objects with member lists
        v
  buildFromDb(dbConversations, msgs, folder, messagesByKey, conversationKeys)
        |
        | For each DB conversation:
        |   1. Resolve members to canonical messages (by UID, then message_id)
        |   2. Add thread messages from conversationKeys (cross-folder)
        |   3. Fall back to DB placeholders (seen:true, _isDbPlaceholder:true)
        |
        | Compute properties:
        |   hasUnread:  any current-folder message with seen=false?
        |   hasStarred: any message with flagged=true?
        |   messageCount: total messages across all folders
        |   threadLoaded: conversationKeys has entries for this conversation?
        |
        v
  Conversation item in email list
        |
        | The conversation's seen/unread status comes from
        | the FIRST message (newest in current folder),
        | with hasUnread as an ADDITIONAL check
```

### Important: hasUnread Only Checks Current Folder

```
Conversation in INBOX with 5 messages:
  - INBOX:1547 (seen: true)   <-- your folder
  - INBOX:1543 (seen: true)   <-- your folder
  - Sent:892   (seen: false)  <-- different folder, IGNORED for hasUnread
  - Sent:890   (seen: true)   <-- different folder, IGNORED for hasUnread
  - INBOX:1540 (seen: true)   <-- your folder

hasUnread = false  (only INBOX messages are checked)
```

This prevents Sent folder copies (which may lack the \Seen flag on some IMAP servers) from making a conversation appear unread.

---

## 5. User Actions

Every user action follows this pattern:

```
User Action
    |
    v
[ Optimistic UI Update ]  (immediate, local only)
    |
    v
[ Pending Flag Protection ]  (prevents stale server data from reverting)
    |
    v
[ API Call to Backend ]
    |
    +---> [ IMAP Command ]         (source of truth)
    +---> [ Redis Invalidation ]   (clear stale cache)
    +---> [ DB Update ]            (conversations, pins, etc.)
    +---> [ WebSocket Publish ]    (notify other sessions/devices)
    |
    v
[ Frontend handles success/failure ]
    |
    +---> Success: refresh folder counts, clear pending flags
    +---> Failure: revert optimistic update, clear pending flags
```

### 5.1 Mark Read / Mark Unread

```
User opens email (or swipes right)
    |
    v
markOpenedAsRead() / setFlag(uid, 'seen', true/false)
    |
    +---> Optimistic: msg.seen = true/false (immediate)
    +---> addPendingFlag(folder, uid, 'seen', value)  [30s TTL]
    +---> conversationsRefreshTrigger++ (recompute hasUnread)
    |
    v
POST /mailbox/{folder}/messages/{uid}/flag?flag=seen&value=1
    |
    +---> Backend: UID STORE {uid} +FLAGS (\Seen)  [or -FLAGS for unread]
    +---> Redis: invalidateMessage (clear cached version)
    +---> Redis: publishFlagsChanged (notify other sessions)
    +---> Redis: publishFolderCounts (update unread count)
    +---> DB: updateMemberReadStatus (conversation read tracking)
    |
    v
Frontend on success: fetchFolders(true) for updated counts
Frontend on failure: revert msg.seen, clearPendingFlag
```

**Pending flag protection in detail:**

```
Timeline:
  T=0ms    User opens email, msg.seen = true (optimistic)
  T=0ms    addPendingFlag("INBOX", 1547, "seen", true)
  T=50ms   API call sent: POST .../flag?flag=seen&value=1
  T=100ms  Background refresh arrives with old data (seen: false)
           |
           +---> upsertMessage checks getPendingFlags()
           +---> Pending flag exists: seen=true
           +---> Overwrites stale seen:false back to seen:true
           |
  T=500ms  API call succeeds, IMAP updated
  T=600ms  Next refresh arrives with correct data (seen: true)
           |
           +---> upsertMessage checks getPendingFlags()
           +---> Server value matches pending: allConfirmed = true
           +---> clearPendingFlag()
```

### 5.2 Star / Unstar

Same flow as Mark Read, but with `flag = 'flagged'` and IMAP flag `\Flagged`.

```
setFlag(uid, 'flagged', true/false)
    |
    +---> Optimistic: msg.flagged = value
    +---> Pending flag protection
    +---> POST .../flag?flag=flagged&value=1
    +---> Backend: UID STORE +FLAGS (\Flagged)
    +---> Redis invalidate + WebSocket publish
```

### 5.3 Pin / Unpin

Pins are NOT stored in IMAP -- they are app-level, stored in MariaDB.

```
pinEmail(uid, folder, messageData)
    |
    | (NO optimistic update -- waits for API success)
    v
POST /mailbox/{folder}/messages/{uid}/pin
    |
    +---> Backend: INSERT INTO pinned_emails (user_email, folder, uid, ...)
    +---> Redis: publishPinChanged
    |
    v
Frontend on success: update pinnedEmails list, msg.pinned = true

unpinEmail(uid, folder)
    |
    v
DELETE /mailbox/{folder}/messages/{uid}/pin
    |
    +---> Backend: DELETE FROM pinned_emails WHERE ...
    +---> Redis: publishPinChanged
```

### 5.4 Move to Folder

```
moveMessage(uid, sourceFolder, targetFolder)
    |
    | (NO optimistic update -- waits for API success)
    v
POST /mailbox/{sourceFolder}/messages/{uid}/move?target={targetFolder}
    |
    +---> Backend: imap_mail_move (or copy+delete fallback)
    |       New UID assigned in target folder
    +---> Redis: invalidateMessage (source), invalidateFolder (both)
    +---> DB: moveConversationMember (update folder + new UID)
    +---> Redis: publishMessageMoved
    |
    v
Frontend on success:
    +---> removeMessageFromList(uid, sourceFolder)
    +---> conversationsStore.removeMessageLocally(sourceFolder, uid)
    +---> Other sessions receive MESSAGE_MOVED via WebSocket
```

### 5.5 Delete

```
deleteMessage(uid, folder, permanent)
    |
    v
DELETE /mailbox/{folder}/messages/{uid}?permanent=true|false
    |
    +---> permanent=false (soft delete):
    |       Move to Trash folder (same as moveMessage)
    |       DB: moveConversationMember
    |       WebSocket: publishMessageMoved
    |
    +---> permanent=true (hard delete, from Trash):
    |       UID STORE +FLAGS (\Deleted), then EXPUNGE
    |       DB: deleteConversationMember
    |       WebSocket: publishMessageDeleted
    |
    +---> Redis: invalidateMessage, invalidateFolder
    |
    v
Frontend on success:
    +---> removeMessageFromList(uid, folder)
    +---> conversationsStore.removeMessageLocally
```

### 5.6 Report Spam

```
reportSpam(folder, uid, { train, block_sender })
    |
    v
POST /spam/report { folder, uid, train, block_sender }
    |
    +---> Backend: moveMessage to Spam folder
    +---> If train=true: submit to SpamAssassin (sa-learn --spam)
    +---> If block_sender=true: blockSender()
    +---> Redis: invalidate caches, publishMessageMoved
    |
    v
Frontend on success: fetchMessages() to refresh list

notSpam(folder, uid):
    +---> Move back to INBOX
    +---> If train: sa-learn --ham
    +---> If add_to_safe: add to safe senders list
```

### 5.7 Block Sender

```
blockSender(email, { block_domain, create_filter })
    |
    v
POST /spam/block-sender { email, block_domain, create_filter }
    |
    +---> DB: INSERT INTO blocked_senders
    +---> If create_filter:
    |       Connect to ManageSieve (Dovecot)
    |       Add Sieve rule to webmail_filters script:
    |         address :is "from" "sender@example.com" --> fileinto "Spam"; stop;
    |       OR (domain block):
    |         address :domain :is "from" "example.com" --> fileinto "Spam"; stop;
    |       Activate script
```

---

## 6. Real-Time Sync (WebSocket)

### Connection Lifecycle

```
Frontend loads
    |
    v
Connect to wss://flowone.pro/mailsync_ws
    |
    +---> Send: AUTHENTICATE { token }
    +---> Send: REPLAY_EVENTS { sinceVersion }  (catch up on missed events)
    |
    v
[ CONNECTED ]
    |
    +---> Heartbeat: PING every 25s, expect PONG within 50s
    +---> On disconnect: exponential backoff (1s, 2s, 4s, ... max 30s)
    +---> On auth failure (4001): refresh token, reconnect
    +---> On tab hidden: pause heartbeat, stale detection
    +---> On tab visible: reconcile (check UIDNEXT, fetch if needed)
```

### Event Flow (Backend to Frontend)

```
User Action (or IMAP IDLE detects new mail)
    |
    v
Backend / mailsync Node.js
    |
    v
Redis PUBLISH webmail:mailbox:{userEmail}
  {
    "type": "FLAGS_CHANGED",
    "payload": { "folder": "INBOX", "uid": 1547, "flags": { "flag": "seen", "value": true } },
    "timestamp": 1709913600000
  }
    |
    v
[ Node.js WebSocket Server ]
    |
    | Subscribes to Redis pattern: webmail:mailbox:*
    | Stores event in EventStore (for replay)
    | Broadcasts to all connected sessions of this user
    |
    v
[ Frontend Browser ] receives WebSocket message
    |
    v
useMailSyncIntegration.js event handler
```

### Event Handlers Summary

```
MESSAGE_NEW
    +---> Refresh folder counts (lightweight)
    +---> If current folder: incremental fetch (fetchMessagesSince)
    +---> If virtual folder (All Mail): full refresh
    +---> Evaluate Board Pro email auto-link rules

MESSAGE_DELETED
    +---> Remove from mailbox list and conversations locally
    +---> Refresh folder counts

MESSAGE_MOVED
    +---> Remove from source folder locally
    +---> If target = current folder: incremental fetch
    +---> Refresh folder counts

FLAGS_CHANGED
    +---> Check pending flags (skip if optimistic update in progress)
    +---> Update message flags in place (seen, flagged, etc.)
    +---> Refresh folder counts

FOLDER_COUNTS
    +---> Update folder metadata (unread, total, uidnext, uidvalidity)
    +---> UIDVALIDITY changed? --> full refresh (folder was rebuilt)
    +---> UIDNEXT advanced? --> incremental fetch
    +---> Total decreased? --> full refresh (messages expunged)

CONVERSATION_UPDATED
    +---> Re-fetch conversation metadata from DB
    +---> If current folder: incremental fetch

FOLDER_CHANGED
    +---> Create/delete/rename: update folder list
    +---> If current folder renamed: follow to new name

SETTINGS_CHANGED
    +---> Apply theme, accent, density, layout, perspective

PIN_CHANGED
    +---> Update pinnedEmails list and message pinned flags

LABELS_CHANGED
    +---> Update labels on matching messages
```

### Fallback: When WebSocket Is Down

```
[ WebSocket disconnected ]
    |
    v
Exponential backoff reconnection attempts
    |
    +---> Meanwhile: reconciliation runs every 2 minutes
    |       fetchFolders(true)
    |       checkFolderSyncState(currentFolder)
    |       Compare UIDNEXT: new messages? --> fetchMessagesSince
    |       Compare UIDVALIDITY: changed? --> full refresh
    |
    +---> On tab visibility change:
    |       Same UIDNEXT/UIDVALIDITY checks
    |
    +---> On reconnect:
            REPLAY_EVENTS { sinceVersion: lastKnownVersion }
            Server sends missed events in order
            SYNC_GAP_DETECTED if events were lost --> full refresh
```

---

## 7. All Mail and Virtual Folders

### 7.0 ALL_MAIL semantics (normative)

ALL_MAIL is a virtual aggregate, not a folder. The contract is:

* **Inclusion.** Every selectable folder visible in `imap_list` for the
  active account participates EXCEPT folders whose SPECIAL-USE flag is
  one of `\Sent`, `\Drafts`, `\Trash`, `\Junk`. Containers
  (`\Noselect`) are excluded. Custom user folders that are NOT
  flagged with one of those four SPECIAL-USE values participate, even
  when their display name happens to contain "sent" / "trash" /
  "junk". This is the bug we fix in Wave 2 by replacing substring
  matching with RFC 6154.
* **Exclusion override.** Folders whose state is `ignored` are skipped.
  Folders whose state is `quarantined` are skipped for the cooldown
  window and surfaced via `degraded_folders[]`. Folders whose state
  is `deleted` are skipped permanently and never re-listed.
* **Duplicate suppression.** Within one ALL_MAIL response, a message is
  identified by `(folder_id, uid)` once Wave 2 dual-write completes;
  during the dual-write window, the legacy `(folder, uid)` key is
  also accepted. Identical messages with the same `Message-Id` across
  multiple folders are NOT collapsed in ALL_MAIL; ALL_MAIL is a
  flat by-folder view, not a conversation view.
* **No silent drops.** Every folder visible in `imap_list` is
  represented in the response. Healthy folders contribute messages
  to `messages[]`; folders that fail any tier of the fetch ladder
  appear in `degraded_folders[]`. The invariant
  `count(folders_in_imap_list) == count(messages_folders ∪ degraded_folder_paths)`
  is asserted by the backend and emitted as `evt=allmail_invariant_violation`
  whenever it fails.
* **Pagination.** Global by `INTERNALDATE` descending. Pinned messages
  that fall off the active page are still included in the response so
  the pin shelf stays consistent.
* **Conversation grouping.** Disabled. Each message renders
  individually. The seen flag is per-message; cross-folder unread
  indicators do not apply to ALL_MAIL.
* **Caching.** Cached at the user-hash level under
  `webmail:{user_hash}:allmail:agg`; invalidated by
  `FolderCacheInvalidator` on rename / delete / content change with a
  3-second debounce per (account, scope).

### 7.1 Tiered fetch fallback

The backend uses `ImapService::getUidsWithTimestamps[OAuth]()` to
collect `(uid, timestamp)` per folder. The fetch ladder, in order:

```
full-range  -> binary split  -> 50-msg chunks  -> per-UID FT_UID
   ^                |              |                  |
   |                v              v                  v
   +--------- on imap_fetch_overview() returning false at any tier
```

Tunables (`ImapService` class constants):

```
SCAN_MIN_SPLIT_SIZE   = 200    // binary split bottom; below -> chunk_50
SCAN_MAX_SPLIT_DEPTH  = 12     // recursion guard
SCAN_CHUNK_SIZE       = 50     // chunk tier and per-UID tier window
SCAN_MAX_UID_TRACK    = 100000 // memory bound; emits truncation event
SCAN_MAX_BAD_UIDS_REPORTED = 500
SCAN_MAX_SEGMENTS_PENDING  = 32
```

A "parseable UID" is one where:

1. `uid` is a positive integer.
2. `INTERNALDATE` parses to a positive Unix timestamp, OR `Date:`
   header parses, OR we fall back to `now()` and annotate the meta.
3. The UID has not already been seen in this scan.
4. `mb_decode_mimeheader` on `subject` and `from` does not raise a
   `ValueError`.

Failures are recorded into the per-scan meta (`getLastScanMeta()`)
and surfaced via `degraded_folders[]` to the frontend:

```
{
  "folder_path": "INBOX.Work.WhiteRabbit",
  "folder_display": "WhiteRabbit",
  "folder_id": "01926f7d-8b21-7af5-9b3c-...",  // null in Wave 1
  "state": "degraded" | "quarantined",
  "total": 245,
  "retrieved": 240,
  "bad_uids": [183, 199, ...],
  "bad_uids_truncated_count": 0,
  "last_attempt_at": "2026-05-14T12:00:00Z",
  "retry_after": "2026-05-14T12:15:00Z" | null,
  "failure_reason": "imap_fetch_overview false; binary-split at depth 3 (range 1:500)",
  "fallback_stage": "binary_split" | "chunk_50" | "per_uid",
  "request_id": "req_01HZ...26charsulid"
}
```

### 7.2 Folder state machine + circuit breaker

States: `healthy` -> `degraded` -> `quarantined` -> `ignored` ->
`deleted`. Transitions are validated by `FolderStateMachine`; every
transition emits `evt=state_transition`. Circuit breaker
(`CircuitBreaker`) trips after 5 failures within a 10-minute sliding
window and quarantines the folder for 15 minutes (with +/-10% jitter to
prevent reconnect storms). Subsequent trips apply ladder backoff:
15m -> 30m -> 60m -> 4h.

### 7.3 Frontend flow (post-fetch)

```
fetchAllMail(page)
    |
    v
GET /mailbox/search?all_folders=true&page=1&limit=50
    |
    +---> Backend ladder + degraded_folders[] + request_id
    |
    v
upsertMessages(data.messages)
    +---> key by (folder_id, uid) when folder_id present
    +---> legacy fallback: key by (folder, uid)
folderViews.set("ALL_MAIL", keys)
allMailDegraded = data.degraded_folders   // banner reads this
    |
    v
Conversation grouping: DISABLED for ALL_MAIL
    |
    | Each message displayed individually
    | Unread status: only the message's own seen flag
    | No hasUnread, no cross-folder contamination
    |
    v
Clicking a message: fetchMessageFromFolder(uid, realFolder)
    |
    | Does NOT load conversation threads
    | markOpenedAsRead uses the real folder
```

### Search Results

Same as All Mail: conversation grouping disabled, messages displayed individually.

---

## 8. Desktop App (Electron) Specifics

### Bootstrap (App Startup)

```
Electron App.vue onMounted:
    |
    v
auth.isAuthenticated?
    |
    +---> YES: runBootstrap()
    |       fetchBootstrap() --> single API call for all initial data
    |       hydrateStores(data) --> populates auth, accounts, settings, etc.
    |       Then: fetchFolders, fetchMessages, connect WebSocket
    |
    +---> NO: show login screen
```

### Sync Architecture

```
[ Electron Main Process ]
    |
    +---> WebSocketClient.ts (ws library)
    |       Connects with token in URL
    |       SUBSCRIBE_ALL for all entity types
    |       REPLAY_EVENTS on reconnect
    |       Version persisted to disk (survives restart)
    |
    +---> SyncManager.ts
    |       Routes events to sync engines
    |       EMAIL events --> EmailSyncEngine
    |       fullSync() on network reconnect
    |       Periodic sync every 5 minutes
    |       Offline queue for pending changes
    |
    +---> IPC Bridge --> Renderer process (shared Vue frontend)
```

### Desktop vs. Cloud Differences

| Aspect          | Cloud (Web)              | Desktop (Electron)          |
|-----------------|--------------------------|------------------------------|
| Data store      | In-memory (Pinia)        | Local SQLite + Pinia         |
| Offline support | None                     | Offline queue, cached data   |
| WebSocket       | Browser WebSocket        | Node.js `ws` library         |
| Sync engines    | None (frontend polling)  | EmailSyncEngine, etc.        |
| Bootstrap       | bootstrap.js service     | Same, integrated in App.vue  |
| Auth store      | Standard                 | Electron override (+ tokens) |

---

## 9. Known Pitfalls and Race Conditions

### 9.1 Conversation hasUnread from Cross-Folder Messages

**Problem:** When loading conversation threads, messages from Sent/other folders are fetched. If those messages have `seen: false` (or `seen: undefined` from DB records that lack this column), the conversation appears unread even though all INBOX messages are read.

**Solution:** `hasUnread` only checks messages in the current folder. Cross-folder messages are ignored for unread status.

### 9.2 Optimistic Flag Overwrite by Stale Server Data

**Problem:** User marks email as read (optimistic: `seen: true`). Before the API call completes, a background refresh returns stale data with `seen: false`, reverting the UI.

**Solution:** Pending flag protection. `addPendingFlag()` stores the optimistic value with a 30s TTL. `upsertMessage()` checks pending flags and re-applies them over stale server data.

### 9.3 DB API Messages Without `seen` Field

**Problem:** The `webmail_conversation_members` table has no `seen` column. When thread messages are fetched from the DB API, they get upserted without `seen`. JavaScript `!undefined === true`, so they appear "unread".

**Solution:** DB API messages default to `seen: true` when the field is missing. Combined with hasUnread only checking current-folder messages.

### 9.4 Pending Flag TTL Expiry

**Problem:** If the flag API call takes longer than 30 seconds (network issues), the pending flag expires and a subsequent refresh could overwrite the optimistic value.

**Mitigation:** 30s is generous for normal API calls. If this becomes an issue, increase `PENDING_FLAG_TTL`.

### 9.5 UIDVALIDITY Change

**Problem:** When a folder is rebuilt on the IMAP server (restore, migration), all UIDs become invalid. Locally cached messages reference stale UIDs.

**Solution:** `checkFolderSyncState` compares UIDVALIDITY. On change: flush all caches for that folder and do a full refresh.

### 9.6 Conversation Member Stale UIDs After Move

**Problem:** After moving a message, it gets a new UID in the target folder. The conversation DB may still reference the old UID.

**Solution:** `moveConversationMember` updates the UID in the DB. Frontend `useConversationGrouping` falls back to `message_id` matching when UID lookup fails.

### 9.7 WebSocket Event Gap

**Problem:** If the WebSocket disconnects and events are missed, the frontend may have stale data.

**Solution:** On reconnect, `REPLAY_EVENTS` replays from `lastEventVersion`. If events were purged, `SYNC_GAP_DETECTED` triggers a full refresh.

---

## 10. Quick Debugging Reference

### "Email shows as unread when it should be read"

```
Check:
1. Is this a conversation or single message?
   - Conversation: check hasUnread computation
     - Are cross-folder messages affecting it? (should only check current folder)
     - Are DB placeholder messages missing `seen` field?
   - Single message: check the canonical object in messagesByKey
     - Is msg.seen actually false?
     - Is there a pending flag being overwritten?

2. Open browser DevTools, run:
   const mb = useMailboxStore()
   mb.messagesByKey.get("INBOX:1547")  // check .seen
   mb.getPendingFlags("INBOX", 1547)   // check pending protection

3. Check WebSocket events:
   - Was a FLAGS_CHANGED event received with seen:false?
   - Was a stale refresh triggered after mark-read?

4. Check backend:
   - IMAP flag actually set? UID FETCH {uid} (FLAGS) should show \Seen
   - Redis cache invalidated? Check message cache key
```

### "Email not showing up"

```
Check:
1. Is it in the right folder? Check IMAP directly (UID SEARCH ALL)
2. Is the folder view stale? Check folderViews.get("INBOX")
3. Was UIDNEXT detection working? Check folder status endpoint
4. Is conversation grouping hiding it?
   - Message might be grouped into a conversation with a different UID
   - Check conversationsStore for the message_id
5. WebSocket connected? Check connection state in DevTools
```

### "Email disappears then reappears"

```
Check:
1. Is there a UIDVALIDITY change? (folder rebuilt)
2. Is the message being moved and returning? (move + undo)
3. Is evictUnprotectedMessages() cleaning it up?
   - Only runs when messagesByKey exceeds 2000 entries
   - Only evicts messages not in current view or conversation
```

### "Conversation count is wrong (e.g., 5 then 2 then 7)"

```
Check:
1. Are conversation members synced? Check webmail_conversation_members in DB
2. Is the conversation being re-computed from DB vs thread data?
   - DB members: initial count from webmail_conversation_members
   - Thread load: adds cross-folder messages from IMAP search
   - These can differ until thread is fully loaded
3. Is a message appearing in multiple conversations?
   - Check computeConversationId for the message's References/In-Reply-To
```

### "Pin/star not syncing across devices"

```
Check:
1. Star (flagged): stored in IMAP, syncs via FLAGS_CHANGED WebSocket
2. Pin: stored in MariaDB, syncs via PIN_CHANGED WebSocket
3. Is WebSocket connected on both devices?
4. Is the publish call happening in the backend?
   - Star: publishFlagsChanged in setFlag
   - Pin: publishPinChanged in pinEmail/unpinEmail
```

---

*Last updated: 2026-03-09*
